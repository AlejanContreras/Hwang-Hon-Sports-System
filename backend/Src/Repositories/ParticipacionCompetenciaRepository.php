<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `participacion_competencia`.
 * Participación de un deportista en una modalidad de una competencia (RN-3.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class ParticipacionCompetenciaRepository extends BaseRepository
{
    /**
     * Obtiene una fila de `participacion_competencia` por su llave primaria compuesta (deportista_documento, competencia_evento_id, modalidad).
     */
    public function obtenerPorId(string|int $deportistaDocumento, string|int $competenciaEventoId, string|int $modalidad): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM participacion_competencia WHERE deportista_documento = :deportista_documento AND competencia_evento_id = :competencia_evento_id AND modalidad = :modalidad',
            [
                'deportista_documento' => $deportistaDocumento,
                'competencia_evento_id' => $competenciaEventoId,
                'modalidad' => $modalidad,
            ]
        );
    }

    /**
     * Lista filas de `participacion_competencia`. Filtro genérico por columna=valor (sin lógica de negocio).
     *
     * @param array<string, mixed> $filtros
     */
    public function listar(array $filtros = [], int $limite = 100, int $desplazamiento = 0): array
    {
        $condiciones = [];
        $parametros = [];
        foreach ($filtros as $columna => $valor) {
            $condiciones[] = "{$columna} = :{$columna}";
            $parametros[$columna] = $valor;
        }

        $sql = 'SELECT * FROM participacion_competencia';
        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $sql .= ' LIMIT :limite OFFSET :desplazamiento';
        $parametros['limite'] = $limite;
        $parametros['desplazamiento'] = $desplazamiento;

        return $this->consultar($sql, $parametros);
    }

    /**
     * Inserta una nueva fila en `participacion_competencia` a partir de un ParticipacionCompetenciaModel.
     */
    public function crear(ParticipacionCompetenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO participacion_competencia (deportista_documento, competencia_evento_id, modalidad, categoria_edad, categoria_peso, resultado, medalla, combates_ganados, combates_perdidos, notas_oponente) VALUES (:deportista_documento, :competencia_evento_id, :modalidad, :categoria_edad, :categoria_peso, :resultado, :medalla, :combates_ganados, :combates_perdidos, :notas_oponente)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `participacion_competencia` a partir de un ParticipacionCompetenciaModel.
     */
    public function actualizar(ParticipacionCompetenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE participacion_competencia SET categoria_edad = :categoria_edad, categoria_peso = :categoria_peso, resultado = :resultado, medalla = :medalla, combates_ganados = :combates_ganados, combates_perdidos = :combates_perdidos, notas_oponente = :notas_oponente WHERE deportista_documento = :deportista_documento AND competencia_evento_id = :competencia_evento_id AND modalidad = :modalidad',
            $datos
        );
    }

    /**
     * Elimina una fila de `participacion_competencia` por su llave primaria compuesta (deportista_documento, competencia_evento_id, modalidad).
     */
    public function eliminar(string|int $deportistaDocumento, string|int $competenciaEventoId, string|int $modalidad): bool
    {
        return $this->ejecutar(
            'DELETE FROM participacion_competencia WHERE deportista_documento = :deportista_documento AND competencia_evento_id = :competencia_evento_id AND modalidad = :modalidad',
            [
                'deportista_documento' => $deportistaDocumento,
                'competencia_evento_id' => $competenciaEventoId,
                'modalidad' => $modalidad,
            ]
        );
    }
}
