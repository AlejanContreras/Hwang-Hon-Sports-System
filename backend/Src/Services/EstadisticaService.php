<?php

declare(strict_types=1);

/**
 * Capa de logica de negocio del modulo Estadistica.
 * Modulo de agregacion (RN-4.x): estadisticas calculadas, sin tabla propia.
 *
 * Los metodos de este archivo son firmas (contratos) definidas en la Etapa 4
 * (Diseno de arquitectura backend, fase "Contratos por modulo"); la
 * implementacion real de las reglas de negocio corresponde a la Etapa 5
 * (Desarrollo backend), segun la Especificacion de Logica de Negocio.
 *
 * Ubicacion: backend/Src/Services/EstadisticaService.php
 */
class EstadisticaService
{
    private DeportistaRepository $deportista;
    private AsistenciaRepository $asistencia;
    private PagoRepository $pago;
    private ParticipacionCompetenciaRepository $participacionCompetencia;

    public function __construct()
    {
        $this->deportista = new DeportistaRepository();
        $this->asistencia = new AsistenciaRepository();
        $this->pago = new PagoRepository();
        $this->participacionCompetencia = new ParticipacionCompetenciaRepository();
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function consultar(array $identificador): ?array
    {
        throw new RuntimeException(
            'EstadisticaService::consultar() pendiente de implementar en Etapa 5.'
        );
    }
}
