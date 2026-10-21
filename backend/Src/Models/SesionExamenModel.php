<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `sesion_examen`.
 * Sesión de examen de ascenso de grado (RN-5.x).
 * Llave primaria: id.
 */
class SesionExamenModel
{
    private int $id;
    private string $fecha;
    private string $fechaGrado;
    private string $lugar;
    private string $estado;
    private ?int $eventoExamenId;
    private ?int $eventoGradoId;

    public function __construct(
        int $id = 0,
        string $fecha = '',
        string $fechaGrado = '',
        string $lugar = '',
        string $estado = 'abierta',
        ?int $eventoExamenId = null,
        ?int $eventoGradoId = null
    ) {
        $this->id = $id;
        $this->fecha = $fecha;
        $this->fechaGrado = $fechaGrado;
        $this->lugar = $lugar;
        $this->estado = $estado;
        $this->eventoExamenId = $eventoExamenId;
        $this->eventoGradoId = $eventoGradoId;
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

    public function obtenerFechaGrado(): string
    {
        return $this->fechaGrado;
    }

    public function establecerFechaGrado(string $fechaGrado): void
    {
        $this->fechaGrado = $fechaGrado;
    }

    public function obtenerLugar(): string
    {
        return $this->lugar;
    }

    public function establecerLugar(string $lugar): void
    {
        $this->lugar = $lugar;
    }

    public function obtenerEstado(): string
    {
        return $this->estado;
    }

    public function establecerEstado(string $estado): void
    {
        $this->estado = $estado;
    }

    public function obtenerEventoExamenId(): ?int
    {
        return $this->eventoExamenId;
    }

    public function establecerEventoExamenId(?int $eventoExamenId): void
    {
        $this->eventoExamenId = $eventoExamenId;
    }

    public function obtenerEventoGradoId(): ?int
    {
        return $this->eventoGradoId;
    }

    public function establecerEventoGradoId(?int $eventoGradoId): void
    {
        $this->eventoGradoId = $eventoGradoId;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `sesion_examen`).
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha,
            'fecha_grado' => $this->fechaGrado,
            'lugar' => $this->lugar,
            'estado' => $this->estado,
            'evento_examen_id' => $this->eventoExamenId,
            'evento_grado_id' => $this->eventoGradoId,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     */
    public static function fromArray(array $datos): self
    {
        // Conversion explicita: con strict_types, un id que llegue como texto
        // lanzaria TypeError.
        return new self(
            id: (int) ($datos['id'] ?? 0),
            fecha: (string) ($datos['fecha'] ?? ''),
            fechaGrado: (string) ($datos['fecha_grado'] ?? ''),
            lugar: (string) ($datos['lugar'] ?? ''),
            estado: (string) ($datos['estado'] ?? 'abierta'),
            eventoExamenId: isset($datos['evento_examen_id']) ? (int) $datos['evento_examen_id'] : null,
            eventoGradoId: isset($datos['evento_grado_id']) ? (int) $datos['evento_grado_id'] : null,
        );
    }
}
