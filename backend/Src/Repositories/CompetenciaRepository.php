<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `competencia`.
 * Datos específicos de un evento de tipo competencia (RN-3.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 *
 * Ubicación: backend/Src/Repositories/CompetenciaRepository.php
 */
class CompetenciaRepository extends BaseRepository
{
    /**
     * Obtiene una fila de `competencia` por su llave primaria `evento_id`.
     */
    public function obtenerPorId(string|int $eventoId): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM competencia WHERE evento_id = :evento_id',
            ['evento_id' => $eventoId]
        );
    }

    /**
     * Lista filas de `competencia`. Filtro genérico por columna=valor (sin lógica de negocio).
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

        $sql = 'SELECT * FROM competencia';
        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $sql .= ' LIMIT :limite OFFSET :desplazamiento';
        $parametros['limite'] = $limite;
        $parametros['desplazamiento'] = $desplazamiento;

        return $this->consultar($sql, $parametros);
    }

    /**
     * Inserta una nueva fila en `competencia` a partir de un CompetenciaModel.
     */
    public function crear(CompetenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO competencia (evento_id, plataforma, tipo_torneo) VALUES (:evento_id, :plataforma, :tipo_torneo)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `competencia` a partir de un CompetenciaModel.
     */
    public function actualizar(CompetenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE competencia SET plataforma = :plataforma, tipo_torneo = :tipo_torneo WHERE evento_id = :evento_id',
            $datos
        );
    }

    /**
     * Elimina una fila de `competencia` por su llave primaria `evento_id`.
     */
    public function eliminar(string|int $eventoId): bool
    {
        return $this->ejecutar(
            'DELETE FROM competencia WHERE evento_id = :evento_id',
            ['evento_id' => $eventoId]
        );
    }
}
