<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `competencia`.
 * Datos específicos de un evento de tipo competencia (RN-3.x).
 * Llave primaria: evento_id.
 */
class CompetenciaModel
{
    private int $eventoId;
    private ?string $plataforma;
    private string $tipoTorneo;

    public function __construct(
        int $eventoId = 0,
        ?string $plataforma = null,
        string $tipoTorneo = ''
    ) {
        $this->eventoId = $eventoId;
        $this->plataforma = $plataforma;
        $this->tipoTorneo = $tipoTorneo;
    }

    public function obtenerEventoId(): int
    {
        return $this->eventoId;
    }

    public function establecerEventoId(int $eventoId): void
    {
        $this->eventoId = $eventoId;
    }

    public function obtenerPlataforma(): ?string
    {
        return $this->plataforma;
    }

    public function establecerPlataforma(?string $plataforma): void
    {
        $this->plataforma = $plataforma;
    }

    public function obtenerTipoTorneo(): string
    {
        return $this->tipoTorneo;
    }

    public function establecerTipoTorneo(string $tipoTorneo): void
    {
        $this->tipoTorneo = $tipoTorneo;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `competencia`).
     */
    public function toArray(): array
    {
        return [
            'evento_id' => $this->eventoId,
            'plataforma' => $this->plataforma,
            'tipo_torneo' => $this->tipoTorneo,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            eventoId: $datos['evento_id'] ?? 0,
            plataforma: $datos['plataforma'] ?? null,
            tipoTorneo: $datos['tipo_torneo'] ?? '',
        );
    }
}
