<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo UsuarioAdministrativo.
 * Gestion de cuentas administrativas (RN-T.10/RN-T.11/RN-T.12).
 *
 * Public/api.php ya aplico la politica 'solo_administrativa' (sesion
 * administrativa valida y cuenta activa). Que la cuenta sea principal
 * (es_administrador_principal) lo valida UsuarioAdministrativoService en
 * cada accion: depende de la identidad, no del ambito. Este Controller solo
 * despacha segun la accion y delega toda logica de negocio al Service.
 *
 * Acciones: listar y consultar (GET); crear (POST); actualizar, desactivar,
 * reactivar y restablecerContrasena (PUT, con ?correo=). No existe
 * "eliminar": las cuentas se desactivan, no se borran (RN-T.11).
 */
class UsuarioAdministrativoController
{
    private ?UsuarioAdministrativoService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): UsuarioAdministrativoService
    {
        return $this->servicio ??= new UsuarioAdministrativoService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'listar' => $this->manejarListar($metodo, $parametros),
            'consultar' => $this->manejarConsultar($metodo, $parametros),
            'crear' => $this->manejarCrear($metodo, $parametros),
            'actualizar' => $this->manejarActualizar($metodo, $parametros),
            'desactivar' => $this->manejarDesactivar($metodo, $parametros),
            'reactivar' => $this->manejarReactivar($metodo, $parametros),
            'restablecerContrasena' => $this->manejarRestablecerContrasena($metodo, $parametros),
            default => Respuesta::error(404, "Accion no reconocida: {$accion}"),
        };
    }

    private function manejarListar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        $resultado = $this->servicio()->listar();
        Respuesta::exito($resultado);
    }

    private function manejarConsultar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        $resultado = $this->servicio()->consultar($parametros);
        if ($resultado === null) {
            Respuesta::error(404, 'No encontrado.');
        }
        Respuesta::exito($resultado);
    }

    private function manejarCrear(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        $datos = Solicitud::cuerpoJson();
        $resultado = $this->servicio()->crear($datos);
        Respuesta::exito($resultado, 201);
    }

    private function manejarActualizar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('PUT', $metodo);
        $datos = Solicitud::cuerpoJson();
        $resultado = $this->servicio()->actualizar($parametros, $datos);
        Respuesta::exito($resultado);
    }

    private function manejarDesactivar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('PUT', $metodo);
        $resultado = $this->servicio()->desactivar($parametros);
        Respuesta::exito($resultado);
    }

    private function manejarReactivar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('PUT', $metodo);
        $resultado = $this->servicio()->reactivar($parametros);
        Respuesta::exito($resultado);
    }

    private function manejarRestablecerContrasena(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('PUT', $metodo);
        $datos = Solicitud::cuerpoJson();
        $this->servicio()->restablecerContrasena($parametros, $datos);
        Respuesta::exito(['contrasena_restablecida' => true]);
    }
}
