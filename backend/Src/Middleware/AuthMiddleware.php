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
     * si ya no existe, fue desactivada o su contraseña cambió desde que se
     * inició la sesión (huella distinta), cierra la sesión y responde 401
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
            // También compara la huella de la contraseña guardada al iniciar
            // sesión: si la contraseña cambió (restablecida por quien gestiona
            // cuentas, recuperada por correo o cambiada desde otra sesión),
            // esta sesión deja de valer.
            $cuenta = (new UsuarioAdministrativoRepository())->obtenerParaAutenticacion($usuario['identificador']);
            $huella = Sesion::huellaActual();
            if ($cuenta === null || !(bool) $cuenta['activa']
                || $huella === null || !hash_equals(Sesion::huellaCredencial((string) $cuenta['contrasena']), $huella)) {
                Sesion::cerrar();
                Respuesta::error(401, 'Tu sesión ya no es válida.');
            }
        }

        return $usuario;
    }
}
