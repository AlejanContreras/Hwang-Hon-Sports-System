<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `evento_calendario`.
 * Evento general del calendario del club: competencia, evaluación, reunión administrativa o evento extra (RN-3.1/RN-9.x).
 * Llave primaria: id.
 *
 * Ubicación: backend/Src/Models/EventoCalendarioModel.php
 */
class EventoCalendarioModel
{
    private int $id;
    private string $nombre;
    private string $tipo;
    private string $fecha;
    private string $lugar;
    private string $visibilidad;
    private ?string $observaciones;

    public function __construct(
        int $id = 0,
        string $nombre = '',
        string $tipo = '',
        string $fecha = '',
        string $lugar = '',
        string $visibilidad = '',
        ?string $observaciones = null
    ) {
        $this->id = $id;
        $this->nombre = $nombre;
        $this->tipo = $tipo;
        $this->fecha = $fecha;
        $this->lugar = $lugar;
        $this->visibilidad = $visibilidad;
        $this->observaciones = $observaciones;
    }

    public function obtenerId(): int
    {
        return $this->id;
    }

    public function establecerId(int $id): void
    {
        $this->id = $id;
    }

    public function obtenerNombre(): string
    {
        return $this->nombre;
    }

    public function establecerNombre(string $nombre): void
    {
        $this->nombre = $nombre;
    }

    public function obtenerTipo(): string
    {
        return $this->tipo;
    }

    public function establecerTipo(string $tipo): void
    {
        $this->tipo = $tipo;
    }

    public function obtenerFecha(): string
    {
        return $this->fecha;
    }

    public function establecerFecha(string $fecha): void
    {
        $this->fecha = $fecha;
    }

    public function obtenerLugar(): string
    {
        return $this->lugar;
    }

    public function establecerLugar(string $lugar): void
    {
        $this->lugar = $lugar;
    }

    public function obtenerVisibilidad(): string
    {
        return $this->visibilidad;
    }

    public function establecerVisibilidad(string $visibilidad): void
    {
        $this->visibilidad = $visibilidad;
    }

    public function obtenerObservaciones(): ?string
    {
        return $this->observaciones;
    }

    public function establecerObservaciones(?string $observaciones): void
    {
        $this->observaciones = $observaciones;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `evento_calendario`).
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'fecha' => $this->fecha,
            'lugar' => $this->lugar,
            'visibilidad' => $this->visibilidad,
            'observaciones' => $this->observaciones,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            id: $datos['id'] ?? 0,
            nombre: $datos['nombre'] ?? '',
            tipo: $datos['tipo'] ?? '',
            fecha: $datos['fecha'] ?? '',
            lugar: $datos['lugar'] ?? '',
            visibilidad: $datos['visibilidad'] ?? '',
            observaciones: $datos['observaciones'] ?? null,
        );
    }
}
