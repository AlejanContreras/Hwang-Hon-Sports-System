<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `asistencia`.
 * Registro de asistencia de un deportista a una fecha de entrenamiento (RN-7.x).
 * Llave primaria: deportista_documento, fecha.
 *
 * Ubicación: backend/Src/Models/AsistenciaModel.php
 */
class AsistenciaModel
{
    private string $deportistaDocumento;
    private string $fecha;
    private bool $asistio;

    public function __construct(
        string $deportistaDocumento = '',
        string $fecha = '',
        bool $asistio = false
    ) {
        $this->deportistaDocumento = $deportistaDocumento;
        $this->fecha = $fecha;
        $this->asistio = $asistio;
    }

    public function obtenerDeportistaDocumento(): string
    {
        return $this->deportistaDocumento;
    }

    public function establecerDeportistaDocumento(string $deportistaDocumento): void
    {
        $this->deportistaDocumento = $deportistaDocumento;
    }

    public function obtenerFecha(): string
    {
        return $this->fecha;
    }

    public function establecerFecha(string $fecha): void
    {
        $this->fecha = $fecha;
    }

    public function obtenerAsistio(): bool
    {
        return $this->asistio;
    }

    public function establecerAsistio(bool $asistio): void
    {
        $this->asistio = $asistio;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `asistencia`).
     */
    public function toArray(): array
    {
        return [
            'deportista_documento' => $this->deportistaDocumento,
            'fecha' => $this->fecha,
            'asistio' => $this->asistio,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            deportistaDocumento: $datos['deportista_documento'] ?? '',
            fecha: $datos['fecha'] ?? '',
            asistio: $datos['asistio'] ?? false,
        );
    }
}
