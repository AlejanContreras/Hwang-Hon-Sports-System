<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `sesion_examen`.
 * Sesión de examen de ascenso de grado (RN-5.x).
 * Llave primaria: id.
 *
 * Ubicación: backend/Src/Models/SesionExamenModel.php
 */
class SesionExamenModel
{
    private int $id;
    private string $fecha;
    private string $lugar;

    public function __construct(
        int $id = 0,
        string $fecha = '',
        string $lugar = ''
    ) {
        $this->id = $id;
        $this->fecha = $fecha;
        $this->lugar = $lugar;
    }

    public function obtenerId(): int
    {
        return $this->id;
    }

    public function establecerId(int $id): void
    {
        $this->id = $id;
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

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `sesion_examen`).
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha,
            'lugar' => $this->lugar,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            id: $datos['id'] ?? 0,
            fecha: $datos['fecha'] ?? '',
            lugar: $datos['lugar'] ?? '',
        );
    }
}
