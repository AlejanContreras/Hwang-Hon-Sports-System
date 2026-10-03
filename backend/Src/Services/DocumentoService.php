<?php

declare(strict_types=1);

/**
 * Capa de logica de negocio del modulo Documento.
 * Documentos del expediente de un deportista (RN-6.x).
 *
 * Los metodos de este archivo son firmas (contratos) definidas en la Etapa 4
 * (Diseno de arquitectura backend, fase "Contratos por modulo"); la
 * implementacion real de las reglas de negocio corresponde a la Etapa 5
 * (Desarrollo backend), segun la Especificacion de Logica de Negocio.
 */
class DocumentoService
{
    private DocumentoRepository $documento;

    public function __construct()
    {
        $this->documento = new DocumentoRepository();
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function listar(array $filtros = []): array
    {
        throw new RuntimeException(
            'DocumentoService::listar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function consultar(array $identificador): ?array
    {
        throw new RuntimeException(
            'DocumentoService::consultar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function crear(array $datos): array
    {
        throw new RuntimeException(
            'DocumentoService::crear() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function actualizar(array $identificador, array $datos): array
    {
        throw new RuntimeException(
            'DocumentoService::actualizar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function eliminar(array $identificador): bool
    {
        throw new RuntimeException(
            'DocumentoService::eliminar() pendiente de implementar en Etapa 5.'
        );
    }
}
