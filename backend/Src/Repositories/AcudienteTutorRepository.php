<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `acudiente_tutor`.
 * Acudiente o tutor legal de un deportista menor de edad.
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class AcudienteTutorRepository extends BaseRepository
{
    /**
     * Obtiene una fila de `acudiente_tutor` por su llave primaria `documento`.
     */
    public function obtenerPorId(string|int $documento): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM acudiente_tutor WHERE documento = :documento',
            ['documento' => $documento]
        );
    }

    /**
     * Lista filas de `acudiente_tutor`. Filtro genérico por columna=valor (sin lógica de negocio).
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

        $sql = 'SELECT * FROM acudiente_tutor';
        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $sql .= ' LIMIT :limite OFFSET :desplazamiento';
        $parametros['limite'] = $limite;
        $parametros['desplazamiento'] = $desplazamiento;

        return $this->consultar($sql, $parametros);
    }

    /**
     * Inserta una nueva fila en `acudiente_tutor` a partir de un AcudienteTutorModel.
     */
    public function crear(AcudienteTutorModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO acudiente_tutor (documento, nombre, relacion) VALUES (:documento, :nombre, :relacion)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `acudiente_tutor` a partir de un AcudienteTutorModel.
     */
    public function actualizar(AcudienteTutorModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE acudiente_tutor SET nombre = :nombre, relacion = :relacion WHERE documento = :documento',
            $datos
        );
    }

    /**
     * Elimina una fila de `acudiente_tutor` por su llave primaria `documento`.
     */
    public function eliminar(string|int $documento): bool
    {
        return $this->ejecutar(
            'DELETE FROM acudiente_tutor WHERE documento = :documento',
            ['documento' => $documento]
        );
    }
}
