<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `detalle_evaluacion`.
 * Evaluación de un deportista en una modalidad dentro de una sesión de examen (RN-5.x).
 * Llave primaria: sesion_examen_id, deportista_documento, modalidad.
 */
class DetalleEvaluacionModel
{
    private int $sesionExamenId;
    private string $deportistaDocumento;
    private string $modalidad;
    private string $gradoEvaluado;
    private string $estado;
    private ?string $fechaCompletado;
    private ?float $nota;
    private ?string $criteriosEvaluados;
    private ?string $puntajes;
    private ?float $valorMedible;
    private ?string $aspectosFallidos;
    private ?string $observaciones;
    private ?string $recomendaciones;
    private ?bool $aprobado;

    public function __construct(
        int $sesionExamenId = 0,
        string $deportistaDocumento = '',
        string $modalidad = '',
        string $gradoEvaluado = '',
        string $estado = '',
        ?string $fechaCompletado = null,
        ?float $nota = null,
        ?string $criteriosEvaluados = null,
        ?string $puntajes = null,
        ?float $valorMedible = null,
        ?string $aspectosFallidos = null,
        ?string $observaciones = null,
        ?string $recomendaciones = null,
        ?bool $aprobado = null
    ) {
        $this->sesionExamenId = $sesionExamenId;
        $this->deportistaDocumento = $deportistaDocumento;
        $this->modalidad = $modalidad;
        $this->gradoEvaluado = $gradoEvaluado;
        $this->estado = $estado;
        $this->fechaCompletado = $fechaCompletado;
        $this->nota = $nota;
        $this->criteriosEvaluados = $criteriosEvaluados;
        $this->puntajes = $puntajes;
        $this->valorMedible = $valorMedible;
        $this->aspectosFallidos = $aspectosFallidos;
        $this->observaciones = $observaciones;
        $this->recomendaciones = $recomendaciones;
        $this->aprobado = $aprobado;
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

    public function obtenerModalidad(): string
    {
        return $this->modalidad;
    }

    public function establecerModalidad(string $modalidad): void
    {
        $this->modalidad = $modalidad;
    }

    public function obtenerGradoEvaluado(): string
    {
        return $this->gradoEvaluado;
    }

    public function establecerGradoEvaluado(string $gradoEvaluado): void
    {
        $this->gradoEvaluado = $gradoEvaluado;
    }

    public function obtenerEstado(): string
    {
        return $this->estado;
    }

    public function establecerEstado(string $estado): void
    {
        $this->estado = $estado;
    }

    public function obtenerFechaCompletado(): ?string
    {
        return $this->fechaCompletado;
    }

    public function establecerFechaCompletado(?string $fechaCompletado): void
    {
        $this->fechaCompletado = $fechaCompletado;
    }

    public function obtenerNota(): ?float
    {
        return $this->nota;
    }

    public function establecerNota(?float $nota): void
    {
        $this->nota = $nota;
    }

    public function obtenerCriteriosEvaluados(): ?string
    {
        return $this->criteriosEvaluados;
    }

    public function establecerCriteriosEvaluados(?string $criteriosEvaluados): void
    {
        $this->criteriosEvaluados = $criteriosEvaluados;
    }

    public function obtenerPuntajes(): ?string
    {
        return $this->puntajes;
    }

    public function establecerPuntajes(?string $puntajes): void
    {
        $this->puntajes = $puntajes;
    }

    public function obtenerValorMedible(): ?float
    {
        return $this->valorMedible;
    }

    public function establecerValorMedible(?float $valorMedible): void
    {
        $this->valorMedible = $valorMedible;
    }

    public function obtenerAspectosFallidos(): ?string
    {
        return $this->aspectosFallidos;
    }

    public function establecerAspectosFallidos(?string $aspectosFallidos): void
    {
        $this->aspectosFallidos = $aspectosFallidos;
    }

    public function obtenerObservaciones(): ?string
    {
        return $this->observaciones;
    }

    public function establecerObservaciones(?string $observaciones): void
    {
        $this->observaciones = $observaciones;
    }

    public function obtenerRecomendaciones(): ?string
    {
        return $this->recomendaciones;
    }

    public function establecerRecomendaciones(?string $recomendaciones): void
    {
        $this->recomendaciones = $recomendaciones;
    }

    public function obtenerAprobado(): ?bool
    {
        return $this->aprobado;
    }

    public function establecerAprobado(?bool $aprobado): void
    {
        $this->aprobado = $aprobado;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `detalle_evaluacion`).
     */
    public function toArray(): array
    {
        return [
            'sesion_examen_id' => $this->sesionExamenId,
            'deportista_documento' => $this->deportistaDocumento,
            'modalidad' => $this->modalidad,
            'grado_evaluado' => $this->gradoEvaluado,
            'estado' => $this->estado,
            'fecha_completado' => $this->fechaCompletado,
            'nota' => $this->nota,
            'criterios_evaluados' => $this->criteriosEvaluados,
            'puntajes' => $this->puntajes,
            'valor_medible' => $this->valorMedible,
            'aspectos_fallidos' => $this->aspectosFallidos,
            'observaciones' => $this->observaciones,
            'recomendaciones' => $this->recomendaciones,
            'aprobado' => $this->aprobado,
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
            sesionExamenId: $datos['sesion_examen_id'] ?? 0,
            deportistaDocumento: $datos['deportista_documento'] ?? '',
            modalidad: $datos['modalidad'] ?? '',
            gradoEvaluado: $datos['grado_evaluado'] ?? '',
            estado: $datos['estado'] ?? '',
            fechaCompletado: $datos['fecha_completado'] ?? null,
            nota: isset($datos['nota']) ? (float) $datos['nota'] : null,
            criteriosEvaluados: $datos['criterios_evaluados'] ?? null,
            puntajes: $datos['puntajes'] ?? null,
            valorMedible: isset($datos['valor_medible']) ? (float) $datos['valor_medible'] : null,
            aspectosFallidos: $datos['aspectos_fallidos'] ?? null,
            observaciones: $datos['observaciones'] ?? null,
            recomendaciones: $datos['recomendaciones'] ?? null,
            aprobado: isset($datos['aprobado']) ? (bool) $datos['aprobado'] : null,
        );
    }
}
