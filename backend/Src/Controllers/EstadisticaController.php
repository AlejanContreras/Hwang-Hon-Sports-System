<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Estadistica (pestana Graficas de "Estadisticas e
 * historial"; Contexto Base 35.4). Solo lectura.
 *
 * Acciones (todas GET): deportista (?deportista=&ventana=) y torneo (?id=,
 * solo administrativo).
 *
 * Recibe la solicitud ya autenticada (politica 'autenticada' de
 * Public/api.php) y delega toda logica de negocio a EstadisticaService.
 */
class EstadisticaController
{
    private ?EstadisticaService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): EstadisticaService
    {
        return $this->servicio ??= new EstadisticaService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'deportista' => $this->leer($metodo, fn () => $this->servicio()->deportista($parametros)),
            'torneo' => $this->leer($metodo, fn () => $this->servicio()->torneo($parametros)),
            default => Respuesta::error(404, "Accion no reconocida: {$accion}"),
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
