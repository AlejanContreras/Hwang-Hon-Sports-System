<?php

declare(strict_types=1);

/**
 * Capa de logica de negocio del modulo Reporte.
 * Modulo de agregacion/exportacion (RN-10.x): reportes, sin tabla propia.
 *
 * Los metodos de este archivo son firmas (contratos) definidas en la Etapa 4
 * (Diseno de arquitectura backend, fase "Contratos por modulo"); la
 * implementacion real de las reglas de negocio corresponde a la Etapa 5
 * (Desarrollo backend), segun la Especificacion de Logica de Negocio.
 *
 * Ubicacion: backend/Src/Services/ReporteService.php
 */
class ReporteService
{
    private DeportistaRepository $deportista;
    private AsistenciaRepository $asistencia;
    private PagoRepository $pago;
    private DetalleEvaluacionRepository $detalleEvaluacion;
    private ParticipacionCompetenciaRepository $participacionCompetencia;

    public function __construct()
    {
        $this->deportista = new DeportistaRepository();
        $this->asistencia = new AsistenciaRepository();
        $this->pago = new PagoRepository();
        $this->detalleEvaluacion = new DetalleEvaluacionRepository();
        $this->participacionCompetencia = new ParticipacionCompetenciaRepository();
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function generar(array $parametros = []): array
    {
        throw new RuntimeException(
            'ReporteService::generar() pendiente de implementar en Etapa 5.'
        );
    }
}
