<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `documento`.
 * Documento cargado en el expediente de un deportista (RN-6.1).
 * Llave primaria: id.
 *
 * Ubicación: backend/Src/Models/DocumentoModel.php
 */
class DocumentoModel
{
    private int $id;
    private string $deportistaDocumento;
    private string $tipoDocumento;
    private string $archivo;
    private string $fechaCarga;
    private string $estado;

    public function __construct(
        int $id = 0,
        string $deportistaDocumento = '',
        string $tipoDocumento = '',
        string $archivo = '',
        string $fechaCarga = '',
        string $estado = ''
    ) {
        $this->id = $id;
        $this->deportistaDocumento = $deportistaDocumento;
        $this->tipoDocumento = $tipoDocumento;
        $this->archivo = $archivo;
        $this->fechaCarga = $fechaCarga;
        $this->estado = $estado;
    }

    public function obtenerId(): int
    {
        return $this->id;
    }

    public function establecerId(int $id): void
    {
        $this->id = $id;
    }

    public function obtenerDeportistaDocumento(): string
    {
        return $this->deportistaDocumento;
    }

    public function establecerDeportistaDocumento(string $deportistaDocumento): void
    {
        $this->deportistaDocumento = $deportistaDocumento;
    }

    public function obtenerTipoDocumento(): string
    {
        return $this->tipoDocumento;
    }

    public function establecerTipoDocumento(string $tipoDocumento): void
    {
        $this->tipoDocumento = $tipoDocumento;
    }

    public function obtenerArchivo(): string
    {
        return $this->archivo;
    }

    public function establecerArchivo(string $archivo): void
    {
        $this->archivo = $archivo;
    }

    public function obtenerFechaCarga(): string
    {
        return $this->fechaCarga;
    }

    public function establecerFechaCarga(string $fechaCarga): void
    {
        $this->fechaCarga = $fechaCarga;
    }

    public function obtenerEstado(): string
    {
        return $this->estado;
    }

    public function establecerEstado(string $estado): void
    {
        $this->estado = $estado;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `documento`).
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'deportista_documento' => $this->deportistaDocumento,
            'tipo_documento' => $this->tipoDocumento,
            'archivo' => $this->archivo,
            'fecha_carga' => $this->fechaCarga,
            'estado' => $this->estado,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            id: $datos['id'] ?? 0,
            deportistaDocumento: $datos['deportista_documento'] ?? '',
            tipoDocumento: $datos['tipo_documento'] ?? '',
            archivo: $datos['archivo'] ?? '',
            fechaCarga: $datos['fecha_carga'] ?? '',
            estado: $datos['estado'] ?? '',
        );
    }
}
