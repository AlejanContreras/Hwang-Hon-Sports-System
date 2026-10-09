<?php

declare(strict_types=1);

/**
 * Middleware de autenticación: verifica que exista una sesión activa
 * (administrativa o pública) antes de continuar hacia el Controller.
 *
 * Lee la sesión a través de Sesion::usuarioActual() (ámbito + identificador,
 * establecidos por AuthService al iniciar sesión); no toca $_SESSION directamente.
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
        $usuario = Sesion::usuarioActual();

        if ($usuario === null) {
            Respuesta::error(401, 'No autenticado. Inicie sesión para continuar.');
        }

        return $usuario;
    }
}
