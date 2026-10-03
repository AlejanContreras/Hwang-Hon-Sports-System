<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `asistencia`.
 * Registro de asistencia de un deportista a una fecha de entrenamiento (RN-7.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class AsistenciaRepository extends BaseRepository
{
    /**
     * Obtiene una fila de `asistencia` por su llave primaria compuesta (deportista_documento, fecha).
     */
    public function obtenerPorId(string|int $deportistaDocumento, string|int $fecha): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM asistencia WHERE deportista_documento = :deportista_documento AND fecha = :fecha',
            [
                'deportista_documento' => $deportistaDocumento,
                'fecha' => $fecha,
            ]
        );
    }

    /**
     * Lista filas de `asistencia`. Filtro genérico por columna=valor (sin lógica de negocio).
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

        $sql = 'SELECT * FROM asistencia';
        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $sql .= ' LIMIT :limite OFFSET :desplazamiento';
        $parametros['limite'] = $limite;
        $parametros['desplazamiento'] = $desplazamiento;

        return $this->consultar($sql, $parametros);
    }

    /**
     * Inserta una nueva fila en `asistencia` a partir de un AsistenciaModel.
     */
    public function crear(AsistenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES (:deportista_documento, :fecha, :asistio)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `asistencia` a partir de un AsistenciaModel.
     */
    public function actualizar(AsistenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE asistencia SET asistio = :asistio WHERE deportista_documento = :deportista_documento AND fecha = :fecha',
            $datos
        );
    }

    /**
     * Elimina una fila de `asistencia` por su llave primaria compuesta (deportista_documento, fecha).
     */
    public function eliminar(string|int $deportistaDocumento, string|int $fecha): bool
    {
        return $this->ejecutar(
            'DELETE FROM asistencia WHERE deportista_documento = :deportista_documento AND fecha = :fecha',
            [
                'deportista_documento' => $deportistaDocumento,
                'fecha' => $fecha,
            ]
        );
    }
}
