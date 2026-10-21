<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `entrega_cinturon`.
 * Confirmación de entrega física del cinturón tras aprobar un examen (RN-5.7).
 * Llave primaria: sesion_examen_id, deportista_documento.
 */
class EntregaCinturonModel
{
    private int $sesionExamenId;
    private string $deportistaDocumento;
    private bool $cinturonEntregado;
    private ?string $fechaEntrega;
    private ?string $confirmadoPor;

    public function __construct(
        int $sesionExamenId = 0,
        string $deportistaDocumento = '',
        bool $cinturonEntregado = false,
        ?string $fechaEntrega = null,
        ?string $confirmadoPor = null
    ) {
        $this->sesionExamenId = $sesionExamenId;
        $this->deportistaDocumento = $deportistaDocumento;
        $this->cinturonEntregado = $cinturonEntregado;
        $this->fechaEntrega = $fechaEntrega;
        $this->confirmadoPor = $confirmadoPor;
    }

    public function obtenerSesionExamenId(): int
    {
        return $this->sesionExamenId;
    }

    public function establecerSesionExamenId(int $sesionExamenId): void
    {
        $this->sesionExamenId = $sesionExamenId;
    }

    public function obtenerDeportistaDocumento(): string
    {
        return $this->deportistaDocumento;
    }

    public function establecerDeportistaDocumento(string $deportistaDocumento): void
    {
        $this->deportistaDocumento = $deportistaDocumento;
    }

    public function obtenerCinturonEntregado(): bool
    {
        return $this->cinturonEntregado;
    }

    public function establecerCinturonEntregado(bool $cinturonEntregado): void
    {
        $this->cinturonEntregado = $cinturonEntregado;
    }

    public function obtenerFechaEntrega(): ?string
    {
        return $this->fechaEntrega;
    }

    public function establecerFechaEntrega(?string $fechaEntrega): void
    {
        $this->fechaEntrega = $fechaEntrega;
    }

    public function obtenerConfirmadoPor(): ?string
    {
        return $this->confirmadoPor;
    }

    public function establecerConfirmadoPor(?string $confirmadoPor): void
    {
        $this->confirmadoPor = $confirmadoPor;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `entrega_cinturon`).
     */
    public function toArray(): array
    {
        return [
            'sesion_examen_id' => $this->sesionExamenId,
            'deportista_documento' => $this->deportistaDocumento,
            'cinturon_entregado' => $this->cinturonEntregado,
            'fecha_entrega' => $this->fechaEntrega,
            'confirmado_por' => $this->confirmadoPor,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     * Los TINYINT(1) llegan de PDO como entero y los DECIMAL como texto: se
     * convierten explicitamente porque, con strict_types, el constructor
     * lanzaria TypeError.
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            sesionExamenId: (int) ($datos['sesion_examen_id'] ?? 0),
            deportistaDocumento: (string) ($datos['deportista_documento'] ?? ''),
            cinturonEntregado: (bool) ($datos['cinturon_entregado'] ?? false),
            fechaEntrega: $datos['fecha_entrega'] ?? null,
            confirmadoPor: $datos['confirmado_por'] ?? null,
        );
    }
}
