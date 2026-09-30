<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `sesion_examen`.
 * Sesión de examen de ascenso de grado (RN-5.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 *
 * Ubicación: backend/Src/Repositories/SesionExamenRepository.php
 */
class SesionExamenRepository extends BaseRepository
{
    /**
     * Obtiene una fila de `sesion_examen` por su llave primaria `id`.
     */
    public function obtenerPorId(string|int $id): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM sesion_examen WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Lista filas de `sesion_examen`. Filtro genérico por columna=valor (sin lógica de negocio).
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

        $sql = 'SELECT * FROM sesion_examen';
        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $sql .= ' LIMIT :limite OFFSET :desplazamiento';
        $parametros['limite'] = $limite;
        $parametros['desplazamiento'] = $desplazamiento;

        return $this->consultar($sql, $parametros);
    }

    /**
     * Inserta una nueva fila en `sesion_examen` a partir de un SesionExamenModel.
     */
    public function crear(SesionExamenModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO sesion_examen (id, fecha, lugar) VALUES (:id, :fecha, :lugar)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `sesion_examen` a partir de un SesionExamenModel.
     */
    public function actualizar(SesionExamenModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE sesion_examen SET fecha = :fecha, lugar = :lugar WHERE id = :id',
            $datos
        );
    }

    /**
     * Elimina una fila de `sesion_examen` por su llave primaria `id`.
     */
    public function eliminar(string|int $id): bool
    {
        return $this->ejecutar(
            'DELETE FROM sesion_examen WHERE id = :id',
            ['id' => $id]
        );
    }
}
