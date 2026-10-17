<?php

declare(strict_types=1);

/**
 * Manejo de la sesion de usuario, centralizado en un solo lugar.
 * Es el UNICO archivo del backend que llama session_start() y toca $_SESSION:
 * AuthService escribe la sesion (iniciar/cerrar), los Middleware y los
 * Services la leen (usuarioActual).
 *
 * La sesion guarda tres datos (RN-T.1/RN-T.2/RN-T.3, RN-T.17):
 *   - ambito: 'administrativa' o 'publica'.
 *   - identificador: el correo (ambito administrativo) o el documento del
 *     deportista (ambito publico).
 *   - ultima_actividad: marca de tiempo de la ultima peticion. La sesion
 *     expira por inactividad (2 h administrativa, 30 min publica), medida
 *     desde la ultima peticion y no desde el inicio de sesion (B.7).
 *
 * Los archivos de sesion se guardan en backend/Almacenamiento/sesiones/
 * (ignorado por Git y bloqueado por .htaccess), no en la carpeta compartida
 * de PHP: alli el recolector de otra aplicacion del mismo XAMPP, con su
 * gc_maxlifetime de 24 minutos, borraria sesiones administrativas validas.
 */
class Sesion
{
    private const NOMBRE_SESION = 'HWANGHON_SESION';
    private const CLAVE_AMBITO = 'ambito';
    private const CLAVE_IDENTIFICADOR = 'identificador';
    private const CLAVE_ULTIMA_ACTIVIDAD = 'ultima_actividad';
    private const AMBITOS_VALIDOS = ['administrativa', 'publica'];

    /**
     * Segundos de inactividad permitidos por ambito (RN-T.17).
     */
    private const LIMITE_INACTIVIDAD = [
        'administrativa' => 7200,
        'publica' => 1800,
    ];

    /**
     * Vida minima de un archivo de sesion para el recolector de PHP: debe
     * cubrir el limite de inactividad mas largo.
     */
    private const VIDA_ARCHIVO_SESION = 7200;

    /**
     * Guarda el usuario en la sesion despues de un inicio de sesion valido.
     * Cambia el id de la sesion (session_regenerate_id) para que un id
     * anterior, conocido por un atacante, no sirva despues del login
     * (fijacion de sesion).
     */
    public static function iniciar(string $ambito, string $identificador): void
    {
        if (!in_array($ambito, self::AMBITOS_VALIDOS, true)) {
            // Error de programacion: el ambito solo lo fija AuthService.
            throw new LogicException("Ambito de sesion no valido: {$ambito}");
        }

        self::arrancar();
        session_regenerate_id(true);

        $_SESSION[self::CLAVE_AMBITO] = $ambito;
        $_SESSION[self::CLAVE_IDENTIFICADOR] = $identificador;
        $_SESSION[self::CLAVE_ULTIMA_ACTIVIDAD] = time();
    }

    /**
     * Cierra la sesion por completo: borra los datos, invalida la cookie del
     * navegador y destruye la sesion en el servidor.
     */
    public static function cerrar(): void
    {
        if (!self::arrancarSiHayCookie()) {
            return;
        }

        $_SESSION = [];

        $parametros = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $parametros['path'],
            'domain' => $parametros['domain'],
            'secure' => $parametros['secure'],
            'httponly' => $parametros['httponly'],
            'samesite' => $parametros['samesite'],
        ]);

        session_destroy();
    }

    /**
     * Devuelve el usuario de la sesion activa, o null si no hay sesion.
     * Si la peticion no trae cookie de sesion, devuelve null SIN arrancar
     * sesion: asi una peticion anonima no crea archivos ni cookies nuevas.
     * Si la sesion supero su limite de inactividad, la cierra y devuelve
     * null; si no, renueva la marca de ultima actividad.
     *
     * @return array{ambito: string, identificador: string}|null
     */
    public static function usuarioActual(): ?array
    {
        if (!self::arrancarSiHayCookie()) {
            return null;
        }

        $ambito = $_SESSION[self::CLAVE_AMBITO] ?? null;
        $identificador = $_SESSION[self::CLAVE_IDENTIFICADOR] ?? null;

        if (!is_string($ambito) || !is_string($identificador)) {
            // Cookie de una sesion que ya no existe (cerrada o vencida): con
            // use_strict_mode PHP abrio una vacia; se descarta junto con la
            // cookie para no dejar archivos de sesion vacios.
            self::cerrar();
            return null;
        }

        $ultimaActividad = $_SESSION[self::CLAVE_ULTIMA_ACTIVIDAD] ?? null;
        $limite = self::LIMITE_INACTIVIDAD[$ambito] ?? 0;
        if (!is_int($ultimaActividad) || time() - $ultimaActividad > $limite) {
            self::cerrar();
            return null;
        }
        $_SESSION[self::CLAVE_ULTIMA_ACTIVIDAD] = time();

        return [
            'ambito' => $ambito,
            'identificador' => $identificador,
        ];
    }

    /**
     * Arranca la sesion solo si la peticion trae la cookie de sesion (o si ya
     * esta activa). Devuelve false si no hay sesion que leer.
     */
    private static function arrancarSiHayCookie(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[self::NOMBRE_SESION])) {
            return false;
        }
        self::arrancar();
        return true;
    }

    /**
     * Arranca la sesion una sola vez por peticion, con la cookie protegida.
     * Usa un nombre propio (HWANGHON_SESION) para no chocar con otras
     * aplicaciones en el mismo dominio (ej. localhost), que usan PHPSESSID.
     *   - httponly: el JavaScript del navegador no puede leer la cookie.
     *   - samesite Lax: el navegador no envia la cookie en peticiones POST/PUT/
     *     DELETE que vengan de otro sitio (proteccion basica contra CSRF).
     *   - secure: solo por HTTPS, cuando el sitio se sirve por HTTPS.
     *   - use_strict_mode: PHP rechaza ids de sesion que el no creo.
     * Antes fija la carpeta propia de sesiones y su gc_maxlifetime.
     */
    private static function arrancar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $usaHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        self::usarCarpetaPropia();
        ini_set('session.use_strict_mode', '1');
        session_name(self::NOMBRE_SESION);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $usaHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /**
     * Guarda las sesiones en backend/Almacenamiento/sesiones/ con un
     * gc_maxlifetime que cubre la sesion administrativa. Si la carpeta no se
     * puede crear, lo registra y sigue con la configuracion por defecto de PHP
     * (la sesion funciona, pero queda expuesta al recolector compartido).
     */
    private static function usarCarpetaPropia(): void
    {
        $carpeta = dirname(__DIR__, 2) . '/Almacenamiento/sesiones';
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0700, true) && !is_dir($carpeta)) {
            error_log("Sesion: no se pudo crear la carpeta de sesiones {$carpeta}; se usa la de PHP por defecto.");
            return;
        }

        ini_set('session.save_path', $carpeta);
        ini_set('session.gc_maxlifetime', (string) self::VIDA_ARCHIVO_SESION);
    }
}
