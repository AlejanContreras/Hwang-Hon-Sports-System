<?php

declare(strict_types=1);

/**
 * Middleware de autenticación: verifica que exista una sesión activa
 * (administrativa o pública) antes de continuar hacia el Controller.
 *
 * Lee la sesión a través de Sesion::usuarioActual() (ámbito + identificador,
 * establecidos por AuthService al iniciar sesión); no toca $_SESSION directamente.
 * La expiración por inactividad (RN-T.17) ya la aplica Sesion::usuarioActual().
 */
class AuthMiddleware
{
    /**
     * Exige una sesión activa (de cualquier ámbito). Responde 401 y detiene
     * la ejecución si no hay sesión.
     *
     * En el ámbito administrativo consulta además la cuenta en cada petición:
     * si ya no existe o fue desactivada, cierra la sesión y responde 401
     * (RN-T.11: la sesión de una cuenta desactivada deja de valer en la
     * siguiente petición). AmbitoMiddleware pasa por aquí, así que el
     * chequeo vale también para las rutas solo administrativas.
     *
     * @return array{ambito: string, identificador: string}
     */
    public static function verificar(): array
    {
        $usuario = Sesion::usuarioActual();

        if ($usuario === null) {
            Respuesta::error(401, 'No autenticado. Inicie sesión para continuar.');
        }

        if ($usuario['ambito'] === 'administrativa') {
            $cuenta = (new UsuarioAdministrativoRepository())->obtenerPorId($usuario['identificador']);
            if ($cuenta === null || !(bool) $cuenta['activa']) {
                Sesion::cerrar();
                Respuesta::error(401, 'Tu sesión ya no es válida.');
            }
        }

        return $usuario;
    }
}
