<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Auth.
 * Modulo transversal de autenticacion (RN-T.10 a RN-T.13). No tiene Model/Repository propio: usa UsuarioAdministrativoRepository y DeportistaRepository para validar credenciales de cada ambito.
 *
 * Es el unico recurso con politica 'publica' en Public/api.php: recibe la
 * solicitud SIN sesion previa (justamente para iniciarla), despacha segun
 * la accion solicitada y delega toda logica de negocio a AuthService.
 */
class AuthController
{
    private ?AuthService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): AuthService
    {
        return $this->servicio ??= new AuthService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'iniciarSesionAdministrativa' => $this->manejarIniciarSesionAdministrativa($metodo, $parametros),
            'iniciarSesionPublica' => $this->manejarIniciarSesionPublica($metodo, $parametros),
            'cerrarSesion' => $this->manejarCerrarSesion($metodo, $parametros),
            'sesionActual' => $this->manejarSesionActual($metodo, $parametros),
            default => Respuesta::error(404, "Accion no reconocida: {$accion}"),
        };
    }

    private function manejarIniciarSesionAdministrativa(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        $credenciales = Solicitud::cuerpoJson();
        $resultado = $this->servicio()->iniciarSesionAdministrativa($credenciales);
        Respuesta::exito($resultado);
    }

    private function manejarIniciarSesionPublica(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        $credenciales = Solicitud::cuerpoJson();
        $resultado = $this->servicio()->iniciarSesionPublica($credenciales);
        Respuesta::exito($resultado);
    }

    private function manejarCerrarSesion(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        $this->servicio()->cerrarSesion();
        Respuesta::exito(['sesionCerrada' => true]);
    }

    private function manejarSesionActual(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        $resultado = $this->servicio()->sesionActual();
        Respuesta::exito($resultado);
    }
}
