<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `participacion_competencia`.
 * Participación de un deportista en una modalidad de una competencia (RN-3.x).
 * Llave primaria: deportista_documento, competencia_evento_id, modalidad.
 *
 * Ubicación: backend/Src/Models/ParticipacionCompetenciaModel.php
 */
class ParticipacionCompetenciaModel
{
    private string $deportistaDocumento;
    private int $competenciaEventoId;
    private string $modalidad;
    private string $categoriaEdad;
    private ?string $categoriaPeso;
    private ?string $resultado;
    private ?string $medalla;
    private ?int $combatesGanados;
    private ?int $combatesPerdidos;
    private ?string $notasOponente;

    public function __construct(
        string $deportistaDocumento = '',
        int $competenciaEventoId = 0,
        string $modalidad = '',
        string $categoriaEdad = '',
        ?string $categoriaPeso = null,
        ?string $resultado = null,
        ?string $medalla = null,
        ?int $combatesGanados = null,
        ?int $combatesPerdidos = null,
        ?string $notasOponente = null
    ) {
        $this->deportistaDocumento = $deportistaDocumento;
        $this->competenciaEventoId = $competenciaEventoId;
        $this->modalidad = $modalidad;
        $this->categoriaEdad = $categoriaEdad;
        $this->categoriaPeso = $categoriaPeso;
        $this->resultado = $resultado;
        $this->medalla = $medalla;
        $this->combatesGanados = $combatesGanados;
        $this->combatesPerdidos = $combatesPerdidos;
        $this->notasOponente = $notasOponente;
    }

    public function obtenerDeportistaDocumento(): string
    {
        return $this->deportistaDocumento;
    }

    public function establecerDeportistaDocumento(string $deportistaDocumento): void
    {
        $this->deportistaDocumento = $deportistaDocumento;
    }

    public function obtenerCompetenciaEventoId(): int
    {
        return $this->competenciaEventoId;
    }

    public function establecerCompetenciaEventoId(int $competenciaEventoId): void
    {
        $this->competenciaEventoId = $competenciaEventoId;
    }

    public function obtenerModalidad(): string
    {
        return $this->modalidad;
    }

    public function establecerModalidad(string $modalidad): void
    {
        $this->modalidad = $modalidad;
    }

    public function obtenerCategoriaEdad(): string
    {
        return $this->categoriaEdad;
    }

    public function establecerCategoriaEdad(string $categoriaEdad): void
    {
        $this->categoriaEdad = $categoriaEdad;
    }

    public function obtenerCategoriaPeso(): ?string
    {
        return $this->categoriaPeso;
    }

    public function establecerCategoriaPeso(?string $categoriaPeso): void
    {
        $this->categoriaPeso = $categoriaPeso;
    }

    public function obtenerResultado(): ?string
    {
        return $this->resultado;
    }

    public function establecerResultado(?string $resultado): void
    {
        $this->resultado = $resultado;
    }

    public function obtenerMedalla(): ?string
    {
        return $this->medalla;
    }

    public function establecerMedalla(?string $medalla): void
    {
        $this->medalla = $medalla;
    }

    public function obtenerCombatesGanados(): ?int
    {
        return $this->combatesGanados;
    }

    public function establecerCombatesGanados(?int $combatesGanados): void
    {
        $this->combatesGanados = $combatesGanados;
    }

    public function obtenerCombatesPerdidos(): ?int
    {
        return $this->combatesPerdidos;
    }

    public function establecerCombatesPerdidos(?int $combatesPerdidos): void
    {
        $this->combatesPerdidos = $combatesPerdidos;
    }

    public function obtenerNotasOponente(): ?string
    {
        return $this->notasOponente;
    }

    public function establecerNotasOponente(?string $notasOponente): void
    {
        $this->notasOponente = $notasOponente;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `participacion_competencia`).
     */
    public function toArray(): array
    {
        return [
            'deportista_documento' => $this->deportistaDocumento,
            'competencia_evento_id' => $this->competenciaEventoId,
            'modalidad' => $this->modalidad,
            'categoria_edad' => $this->categoriaEdad,
            'categoria_peso' => $this->categoriaPeso,
            'resultado' => $this->resultado,
            'medalla' => $this->medalla,
            'combates_ganados' => $this->combatesGanados,
            'combates_perdidos' => $this->combatesPerdidos,
            'notas_oponente' => $this->notasOponente,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            deportistaDocumento: $datos['deportista_documento'] ?? '',
            competenciaEventoId: $datos['competencia_evento_id'] ?? 0,
            modalidad: $datos['modalidad'] ?? '',
            categoriaEdad: $datos['categoria_edad'] ?? '',
            categoriaPeso: $datos['categoria_peso'] ?? null,
            resultado: $datos['resultado'] ?? null,
            medalla: $datos['medalla'] ?? null,
            combatesGanados: $datos['combates_ganados'] ?? null,
            combatesPerdidos: $datos['combates_perdidos'] ?? null,
            notasOponente: $datos['notas_oponente'] ?? null,
        );
    }
}
