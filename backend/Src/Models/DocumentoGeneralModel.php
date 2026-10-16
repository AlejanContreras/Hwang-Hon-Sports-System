<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `documento_general`.
 * Documento del club visible para todos, sin deportista asociado ni filtro por
 * grado (ej. teoría de ascenso) (RN-6.8).
 * Llave primaria: id.
 */
class DocumentoGeneralModel
{
    private int $id;
    private string $titulo;
    private string $archivo;
    private string $nombreOriginal;
    private string $fechaCarga;

    public function __construct(
        int $id = 0,
        string $titulo = '',
        string $archivo = '',
        string $nombreOriginal = '',
        string $fechaCarga = ''
    ) {
        $this->id = $id;
        $this->titulo = $titulo;
        $this->archivo = $archivo;
        $this->nombreOriginal = $nombreOriginal;
        $this->fechaCarga = $fechaCarga;
    }

    public function obtenerId(): int
    {
        return $this->id;
    }

    public function establecerId(int $id): void
    {
        $this->id = $id;
    }

    public function obtenerTitulo(): string
    {
        return $this->titulo;
    }

    public function establecerTitulo(string $titulo): void
    {
        $this->titulo = $titulo;
    }

    public function obtenerArchivo(): string
    {
        return $this->archivo;
    }

    public function establecerArchivo(string $archivo): void
    {
        $this->archivo = $archivo;
    }

    public function obtenerNombreOriginal(): string
    {
        return $this->nombreOriginal;
    }

    public function establecerNombreOriginal(string $nombreOriginal): void
    {
        $this->nombreOriginal = $nombreOriginal;
    }

    public function obtenerFechaCarga(): string
    {
        return $this->fechaCarga;
    }

    public function establecerFechaCarga(string $fechaCarga): void
    {
        $this->fechaCarga = $fechaCarga;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `documento_general`).
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'archivo' => $this->archivo,
            'nombre_original' => $this->nombreOriginal,
            'fecha_carga' => $this->fechaCarga,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            id: $datos['id'] ?? 0,
            titulo: $datos['titulo'] ?? '',
            archivo: $datos['archivo'] ?? '',
            nombreOriginal: $datos['nombre_original'] ?? '',
            fechaCarga: $datos['fecha_carga'] ?? '',
        );
    }
}
