<?php

declare(strict_types=1);

/**
 * Middleware de autenticación: verifica que exista una sesión activa
 * (administrativa o pública) antes de continuar hacia el Controller.
 *
 * La sesión se identifica por $_SESSION['ambito'] ('administrativa' | 'publica')
 * más $_SESSION['identificador'] (una sola llave: guarda el correo cuando el
 * ámbito es administrativo, o el documento cuando es público/deportista-tutor),
 * establecidos por AuthController al iniciar sesión.
 */
class AuthMiddleware
{
    /**
     * Exige una sesión activa (de cualquier ámbito). Responde 401 y detiene
     * la ejecución si no hay sesión.
     *
     * @return array{ambito: string, identificador: string}
     */
    public static function verificar(): array
    {
        $usuario = self::obtenerUsuario();

        if ($usuario === null) {
            Respuesta::error(401, 'No autenticado. Inicie sesión para continuar.');
        }

        return $usuario;
    }

    /**
     * Obtiene los datos de la sesión activa sin bloquear la ejecución.
     * Devuelve null si no hay sesión.
     *
     * @return array{ambito: string, identificador: string}|null
     */
    public static function obtenerUsuario(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $ambito = $_SESSION['ambito'] ?? null;
        $identificador = $_SESSION['identificador'] ?? null;

        if ($ambito === null || $identificador === null) {
            return null;
        }

        return [
            'ambito' => $ambito,
            'identificador' => $identificador,
        ];
    }
}
