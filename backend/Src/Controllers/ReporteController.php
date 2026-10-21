<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Reportes y exportacion (RN-10.x; Contexto Base
 * 35.13). Solo administrativo (politica 'solo_administrativa' de
 * Public/api.php).
 *
 * Acciones (todas GET), que responden con el archivo (Respuesta::archivo) y
 * no con JSON; los errores si salen como JSON:
 *   deportistaFicha (?deportista=, PDF), deportistas (?estado=),
 *   asistencia (?desde=&hasta=&estado=), pagos (?anio=&estado=),
 *   torneos (?desde=&hasta=) y sesionExamen (?id=), en Excel.
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
            'deportistaFicha' => $this->descargar($metodo, fn () => $this->servicio()->deportistaFicha($parametros)),
            'deportistas' => $this->descargar($metodo, fn () => $this->servicio()->deportistas($parametros)),
            'asistencia' => $this->descargar($metodo, fn () => $this->servicio()->asistencia($parametros)),
            'pagos' => $this->descargar($metodo, fn () => $this->servicio()->pagos($parametros)),
            'torneos' => $this->descargar($metodo, fn () => $this->servicio()->torneos($parametros)),
            'sesionExamen' => $this->descargar($metodo, fn () => $this->servicio()->sesionExamen($parametros)),
            default => Respuesta::error(404, "Accion no reconocida: {$accion}"),
        };
    }

    /**
     * Exige GET y envia el archivo que arma el Service. Si el Service lanza
     * una excepcion, todavia no se ha enviado nada: api.php responde el
     * error en JSON.
     */
    private function descargar(string $metodo, callable $operacion): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        $archivo = $operacion();
        Respuesta::archivo($archivo['contenido'], $archivo['nombre'], $archivo['tipo']);
    }
}
