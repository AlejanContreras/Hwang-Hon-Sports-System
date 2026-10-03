<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Reporte.
 * Modulo de agregacion/exportacion (RN-10.x): reportes, sin tabla propia.
 *
 * Recibe la solicitud ya autenticada (segun la politica de acceso de
 * Public/api.php), despacha segun la accion solicitada y delega toda
 * logica de negocio a ReporteService.
 */
class ReporteController
{
    private ?ReporteService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): ReporteService
    {
        return $this->servicio ??= new ReporteService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'generar' => $this->manejarGenerar($metodo, $parametros),
            default => Respuesta::error(404, "Accion no reconocida: {$accion}"),
        };
    }

    private function manejarGenerar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        $resultado = $this->servicio()->generar($parametros);
        Respuesta::exito($resultado);
    }
}
