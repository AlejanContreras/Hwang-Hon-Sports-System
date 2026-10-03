<?php

declare(strict_types=1);

/**
 * Capa de logica de negocio del modulo Historial.
 * Modulo de agregacion (RN-2.x): compone el historial de un deportista a partir de otros repositorios: no tiene tabla propia.
 *
 * Los metodos de este archivo son firmas (contratos) definidas en la Etapa 4
 * (Diseno de arquitectura backend, fase "Contratos por modulo"); la
 * implementacion real de las reglas de negocio corresponde a la Etapa 5
 * (Desarrollo backend), segun la Especificacion de Logica de Negocio.
 */
class HistorialService
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
    public function consultar(array $identificador): ?array
    {
        throw new RuntimeException(
            'HistorialService::consultar() pendiente de implementar en Etapa 5.'
        );
    }
}
