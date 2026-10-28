<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Asistencia (RN-7.x, RN-1.11).
 *
 * Recibe la solicitud ya autenticada (politica 'autenticada' de
 * Public/api.php), despacha segun la accion y delega toda logica de negocio
 * a AsistenciaService, que decide que puede hacer cada ambito.
 *
 * Acciones: listarPantalla, listar, consultar, resumenDia y porcentaje (GET);
 * tomar y revisarInactivos (POST); corregir (PUT ?documento=&fecha=);
 * eliminarMarca (DELETE ?documento=&fecha=) y eliminarFecha (DELETE ?fecha=).
 */
class AsistenciaController
{
    private ?AsistenciaService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): AsistenciaService
    {
        return $this->servicio ??= new AsistenciaService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'tomar' => $this->manejarTomar($metodo, $parametros),
            'listarPantalla' => $this->manejarListarPantalla($metodo, $parametros),
            'listar' => $this->manejarListar($metodo, $parametros),
            'consultar' => $this->manejarConsultar($metodo, $parametros),
            'resumenDia' => $this->manejarResumenDia($metodo, $parametros),
            'corregir' => $this->manejarCorregir($metodo, $parametros),
            'eliminarMarca' => $this->manejarEliminarMarca($metodo, $parametros),
            'eliminarFecha' => $this->manejarEliminarFecha($metodo, $parametros),
            'porcentaje' => $this->manejarPorcentaje($metodo, $parametros),
            'revisarInactivos' => $this->manejarRevisarInactivos($metodo, $parametros),
            default => Respuesta::error(404, "Acción no reconocida: {$accion}"),
        };
    }

    private function manejarTomar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($this->servicio()->tomar(Solicitud::cuerpoJson()), 201);
    }

    private function manejarListarPantalla(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->listarPantalla($parametros));
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

    private function manejarResumenDia(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->resumenDia());
    }

    private function manejarCorregir(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('PUT', $metodo);
        Respuesta::exito($this->servicio()->corregir($parametros, Solicitud::cuerpoJson()));
    }

    private function manejarEliminarMarca(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('DELETE', $metodo);
        $this->servicio()->eliminarMarca($parametros);
        Respuesta::exito(['eliminado' => true]);
    }

    private function manejarEliminarFecha(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('DELETE', $metodo);
        Respuesta::exito($this->servicio()->eliminarFecha($parametros));
    }

    private function manejarPorcentaje(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->porcentaje($parametros));
    }

    private function manejarRevisarInactivos(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($this->servicio()->revisarInactivos());
    }
}
