<?php

declare(strict_types=1);

/**
 * Capa de logica de negocio del modulo Calendario.
 * Eventos generales del calendario (RN-9.x). Comparte la tabla evento_calendario con el modulo Competencia; el Service filtra por evento_calendario.visibilidad.
 *
 * Los metodos de este archivo son firmas (contratos) definidas en la Etapa 4
 * (Diseno de arquitectura backend, fase "Contratos por modulo"); la
 * implementacion real de las reglas de negocio corresponde a la Etapa 5
 * (Desarrollo backend), segun la Especificacion de Logica de Negocio.
 *
 * Ubicacion: backend/Src/Services/CalendarioService.php
 */
class CalendarioService
{
    private EventoCalendarioRepository $eventoCalendario;

    public function __construct()
    {
        $this->eventoCalendario = new EventoCalendarioRepository();
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function listar(array $filtros = []): array
    {
        throw new RuntimeException(
            'CalendarioService::listar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function consultar(array $identificador): ?array
    {
        throw new RuntimeException(
            'CalendarioService::consultar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function crear(array $datos): array
    {
        throw new RuntimeException(
            'CalendarioService::crear() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function actualizar(array $identificador, array $datos): array
    {
        throw new RuntimeException(
            'CalendarioService::actualizar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function eliminar(array $identificador): bool
    {
        throw new RuntimeException(
            'CalendarioService::eliminar() pendiente de implementar en Etapa 5.'
        );
    }
}
