<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Historial (pestana Registros de "Estadisticas e
 * historial"; Contexto Base 35.4). Solo lectura.
 *
 * Acciones (todas GET): torneos y ascensos (?deportista=&desde=&hasta=),
 * permanencia (?deportista=&estado=). El filtro deportista solo aplica al
 * ambito administrativo; el publico siempre ve lo propio.
 *
 * Recibe la solicitud ya autenticada (politica 'autenticada' de
 * Public/api.php) y delega toda logica de negocio a HistorialService.
 */
class HistorialController
{
    private ?HistorialService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): HistorialService
    {
        return $this->servicio ??= new HistorialService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'torneos' => $this->leer($metodo, fn () => $this->servicio()->torneos($parametros)),
            'ascensos' => $this->leer($metodo, fn () => $this->servicio()->ascensos($parametros)),
            'permanencia' => $this->leer($metodo, fn () => $this->servicio()->permanencia($parametros)),
            default => Respuesta::error(404, "Acción no reconocida: {$accion}"),
        };
    }

    /**
     * Exige GET y responde con lo que devuelva el Service.
     */
    private function leer(string $metodo, callable $operacion): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($operacion());
    }
}
