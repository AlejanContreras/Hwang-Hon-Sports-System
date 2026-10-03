<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `acudiente_tutor`.
 * Acudiente o tutor legal de un deportista menor de edad.
 * Llave primaria: documento.
 */
class AcudienteTutorModel
{
    private string $documento;
    private string $nombre;
    private string $relacion;

    public function __construct(
        string $documento = '',
        string $nombre = '',
        string $relacion = ''
    ) {
        $this->documento = $documento;
        $this->nombre = $nombre;
        $this->relacion = $relacion;
    }

    public function obtenerDocumento(): string
    {
        return $this->documento;
    }

    public function establecerDocumento(string $documento): void
    {
        $this->documento = $documento;
    }

    public function obtenerNombre(): string
    {
        return $this->nombre;
    }

    public function establecerNombre(string $nombre): void
    {
        $this->nombre = $nombre;
    }

    public function obtenerRelacion(): string
    {
        return $this->relacion;
    }

    public function establecerRelacion(string $relacion): void
    {
        $this->relacion = $relacion;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `acudiente_tutor`).
     */
    public function toArray(): array
    {
        return [
            'documento' => $this->documento,
            'nombre' => $this->nombre,
            'relacion' => $this->relacion,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            documento: $datos['documento'] ?? '',
            nombre: $datos['nombre'] ?? '',
            relacion: $datos['relacion'] ?? '',
        );
    }
}
