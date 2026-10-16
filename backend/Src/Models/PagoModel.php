<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `pago`.
 * Registro de pago (mensualidad o matrícula) de un deportista (RN-8.x).
 * mes: 0 = matrícula; 1 a 12 = mes de la mensualidad. semana: 1 a 4 o null.
 * Solo se guardan pagos hechos: la deuda se calcula por ausencia de fila.
 * Llave primaria: id.
 */
class PagoModel
{
    private int $id;
    private string $deportistaDocumento;
    private string $tipo;
    private int $anio;
    private int $mes;
    private ?int $semana;

    public function __construct(
        int $id = 0,
        string $deportistaDocumento = '',
        string $tipo = '',
        int $anio = 0,
        int $mes = 0,
        ?int $semana = null
    ) {
        $this->id = $id;
        $this->deportistaDocumento = $deportistaDocumento;
        $this->tipo = $tipo;
        $this->anio = $anio;
        $this->mes = $mes;
        $this->semana = $semana;
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

    public function obtenerTipo(): string
    {
        return $this->tipo;
    }

    public function establecerTipo(string $tipo): void
    {
        $this->tipo = $tipo;
    }

    public function obtenerAnio(): int
    {
        return $this->anio;
    }

    public function establecerAnio(int $anio): void
    {
        $this->anio = $anio;
    }

    public function obtenerMes(): int
    {
        return $this->mes;
    }

    public function establecerMes(int $mes): void
    {
        $this->mes = $mes;
    }

    public function obtenerSemana(): ?int
    {
        return $this->semana;
    }

    public function establecerSemana(?int $semana): void
    {
        $this->semana = $semana;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `pago`).
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'deportista_documento' => $this->deportistaDocumento,
            'tipo' => $this->tipo,
            'anio' => $this->anio,
            'mes' => $this->mes,
            'semana' => $this->semana,
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
            tipo: $datos['tipo'] ?? '',
            anio: $datos['anio'] ?? 0,
            mes: $datos['mes'] ?? 0,
            semana: $datos['semana'] ?? null,
        );
    }
}
