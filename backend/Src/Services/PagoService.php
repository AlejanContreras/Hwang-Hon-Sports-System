<?php

declare(strict_types=1);

/**
 * Capa de logica de negocio del modulo Pago.
 * Pagos de mensualidad y matricula (RN-8.x).
 *
 * Los metodos de este archivo son firmas (contratos) definidas en la Etapa 4
 * (Diseno de arquitectura backend, fase "Contratos por modulo"); la
 * implementacion real de las reglas de negocio corresponde a la Etapa 5
 * (Desarrollo backend), segun la Especificacion de Logica de Negocio.
 *
 * Ubicacion: backend/Src/Services/PagoService.php
 */
class PagoService
{
    private PagoRepository $pago;

    public function __construct()
    {
        $this->pago = new PagoRepository();
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function listar(array $filtros = []): array
    {
        throw new RuntimeException(
            'PagoService::listar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function consultar(array $identificador): ?array
    {
        throw new RuntimeException(
            'PagoService::consultar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function crear(array $datos): array
    {
        throw new RuntimeException(
            'PagoService::crear() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function actualizar(array $identificador, array $datos): array
    {
        throw new RuntimeException(
            'PagoService::actualizar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function eliminar(array $identificador): bool
    {
        throw new RuntimeException(
            'PagoService::eliminar() pendiente de implementar en Etapa 5.'
        );
    }
}
