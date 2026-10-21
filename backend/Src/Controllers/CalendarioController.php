<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Calendario (RN-9.x).
 *
 * Recibe la solicitud ya autenticada (politica 'autenticada' de
 * Public/api.php), despacha segun la accion y delega toda logica de negocio
 * a CalendarioService, que decide que ve y que puede hacer cada ambito.
 *
 * Acciones: listar, consultar y vista (GET); crear (POST); actualizar
 * (PUT ?id=); importar (POST multipart, campo "archivo"). No existe
 * "eliminar": los eventos se crean y se actualizan (RN-9.2).
 */
class CalendarioController
{
    private ?CalendarioService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): CalendarioService
    {
        return $this->servicio ??= new CalendarioService();
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
            'vista' => $this->manejarVista($metodo, $parametros),
            'importar' => $this->manejarImportar($metodo, $parametros),
            default => Respuesta::error(404, "Accion no reconocida: {$accion}"),
        };
    }

    private function manejarListar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->listar($parametros));
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
        Respuesta::exito($this->servicio()->crear(Solicitud::cuerpoJson()), 201);
    }

    private function manejarActualizar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('PUT', $metodo);
        Respuesta::exito($this->servicio()->actualizar($parametros, Solicitud::cuerpoJson()));
    }

    private function manejarVista(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->vista($parametros));
    }

    private function manejarImportar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($this->servicio()->importar());
    }
}
