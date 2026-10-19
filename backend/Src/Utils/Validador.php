<?php

declare(strict_types=1);

/**
 * Validaciones de datos de entrada compartidas por varios Services. Nació con
 * el segundo consumidor (AuthService y UsuarioAdministrativoService). Cada
 * método devuelve el valor ya limpio o lanza ExcepcionNegocio 400 con un
 * mensaje para el usuario.
 *
 * Los límites de longitud son los del esquema v4: la conexión usa modo
 * estricto, así que un valor que no cabe se rechaza aquí con 400 en vez de
 * llegar a la base de datos y volverse un 500.
 */
final class Validador
{
    private const MAXIMO_CORREO = 150;
    private const MINIMO_CONTRASENA = 8;
    /** Límite de bcrypt: ignora en silencio los bytes después del 72. */
    private const MAXIMO_BYTES_CONTRASENA = 72;

    private function __construct()
    {
    }

    /**
     * Correo recortado y en minúsculas, con formato válido y que quepa en la
     * columna (150).
     */
    public static function correo(mixed $valor, string $campo = 'correo'): string
    {
        if (!is_string($valor) || trim($valor) === '') {
            throw new ExcepcionNegocio("El campo \"{$campo}\" es obligatorio.", 400);
        }
        $correo = mb_strtolower(trim($valor));
        if (mb_strlen($correo) > self::MAXIMO_CORREO || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser un correo electrónico válido de máximo 150 caracteres.", 400);
        }
        return $correo;
    }

    /**
     * Igual que correo(), pero vacío o null es válido y devuelve null (ej. el
     * correo del deportista o del acudiente, que es opcional).
     */
    public static function correoOpcional(mixed $valor, string $campo = 'correo'): ?string
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }
        return self::correo($valor, $campo);
    }

    /**
     * Contraseña tal como la escribió la persona (sin recortar espacios).
     * Sirve para la contraseña que se compara (login, contraseña actual).
     */
    public static function contrasenaRecibida(mixed $valor, string $campo): string
    {
        if (!is_string($valor) || $valor === '') {
            throw new ExcepcionNegocio("El campo \"{$campo}\" es obligatorio.", 400);
        }
        return $valor;
    }

    /**
     * Contraseña que se va a guardar (RN-T.18, B.8): mínimo 8 caracteres y
     * máximo 72 bytes (límite de bcrypt).
     */
    public static function contrasenaNueva(mixed $valor, string $campo = 'contrasena_nueva'): string
    {
        $contrasena = self::contrasenaRecibida($valor, $campo);
        if (mb_strlen($contrasena) < self::MINIMO_CONTRASENA) {
            throw new ExcepcionNegocio('La contraseña debe tener al menos 8 caracteres.', 400);
        }
        if (strlen($contrasena) > self::MAXIMO_BYTES_CONTRASENA) {
            throw new ExcepcionNegocio('La contraseña no puede superar 72 caracteres.', 400);
        }
        return $contrasena;
    }

    /**
     * Texto recortado de hasta $maximo caracteres. Vacío o null: error si es
     * obligatorio; si no, devuelve null. Acepta también un entero (ej. un
     * documento enviado como número en el JSON) y lo convierte a texto.
     */
    public static function texto(mixed $valor, string $campo, int $maximo, bool $obligatorio): ?string
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            if ($obligatorio) {
                throw new ExcepcionNegocio("El campo \"{$campo}\" es obligatorio.", 400);
            }
            return null;
        }
        if (!is_string($valor) && !is_int($valor)) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser texto.", 400);
        }
        $texto = trim((string) $valor);
        if (mb_strlen($texto) > $maximo) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" admite máximo {$maximo} caracteres.", 400);
        }
        return $texto;
    }
}
