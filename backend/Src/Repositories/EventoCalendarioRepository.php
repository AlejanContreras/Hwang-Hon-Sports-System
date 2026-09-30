<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `evento_calendario`.
 * Evento general del calendario del club: competencia, evaluación, reunión administrativa o evento extra (RN-3.1/RN-9.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 *
 * Ubicación: backend/Src/Repositories/EventoCalendarioRepository.php
 */
class EventoCalendarioRepository extends BaseRepository
{
    /**
     * Obtiene una fila de `evento_calendario` por su llave primaria `id`.
     */
    public function obtenerPorId(string|int $id): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM evento_calendario WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Lista filas de `evento_calendario`. Filtro genérico por columna=valor (sin lógica de negocio).
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

        $sql = 'SELECT * FROM evento_calendario';
        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $sql .= ' LIMIT :limite OFFSET :desplazamiento';
        $parametros['limite'] = $limite;
        $parametros['desplazamiento'] = $desplazamiento;

        return $this->consultar($sql, $parametros);
    }

    /**
     * Inserta una nueva fila en `evento_calendario` a partir de un EventoCalendarioModel.
     */
    public function crear(EventoCalendarioModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO evento_calendario (id, nombre, tipo, fecha, lugar, visibilidad, observaciones) VALUES (:id, :nombre, :tipo, :fecha, :lugar, :visibilidad, :observaciones)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `evento_calendario` a partir de un EventoCalendarioModel.
     */
    public function actualizar(EventoCalendarioModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE evento_calendario SET nombre = :nombre, tipo = :tipo, fecha = :fecha, lugar = :lugar, visibilidad = :visibilidad, observaciones = :observaciones WHERE id = :id',
            $datos
        );
    }

    /**
     * Elimina una fila de `evento_calendario` por su llave primaria `id`.
     */
    public function eliminar(string|int $id): bool
    {
        return $this->ejecutar(
            'DELETE FROM evento_calendario WHERE id = :id',
            ['id' => $id]
        );
    }
}
