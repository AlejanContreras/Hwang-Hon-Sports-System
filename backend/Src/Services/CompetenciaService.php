<?php

declare(strict_types=1);

/**
 * Capa de logica de negocio del modulo Competencia.
 * Competencias y participacion de deportistas (RN-3.x). Usa tres tablas relacionadas.
 *
 * Los metodos de este archivo son firmas (contratos) definidas en la Etapa 4
 * (Diseno de arquitectura backend, fase "Contratos por modulo"); la
 * implementacion real de las reglas de negocio corresponde a la Etapa 5
 * (Desarrollo backend), segun la Especificacion de Logica de Negocio.
 */
class CompetenciaService
{
    private EventoCalendarioRepository $eventoCalendario;
    private CompetenciaRepository $competencia;
    private ParticipacionCompetenciaRepository $participacionCompetencia;

    public function __construct()
    {
        $this->eventoCalendario = new EventoCalendarioRepository();
        $this->competencia = new CompetenciaRepository();
        $this->participacionCompetencia = new ParticipacionCompetenciaRepository();
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function listar(array $filtros = []): array
    {
        throw new RuntimeException(
            'CompetenciaService::listar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function consultar(array $identificador): ?array
    {
        throw new RuntimeException(
            'CompetenciaService::consultar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function crear(array $datos): array
    {
        throw new RuntimeException(
            'CompetenciaService::crear() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function actualizar(array $identificador, array $datos): array
    {
        throw new RuntimeException(
            'CompetenciaService::actualizar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function eliminar(array $identificador): bool
    {
        throw new RuntimeException(
            'CompetenciaService::eliminar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function registrarParticipacion(array $datos): array
    {
        throw new RuntimeException(
            'CompetenciaService::registrarParticipacion() pendiente de implementar en Etapa 5.'
        );
    }
}
