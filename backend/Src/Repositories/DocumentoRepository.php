<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `documento`.
 * Documento cargado en el expediente de un deportista (RN-6.1).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class DocumentoRepository extends BaseRepository
{
    /**
     * Obtiene una fila de `documento` por su llave primaria `id`.
     */
    public function obtenerPorId(string|int $id): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM documento WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Lista filas de `documento`. Filtro genérico por columna=valor (sin lógica de negocio).
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

        $sql = 'SELECT * FROM documento';
        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $sql .= ' LIMIT :limite OFFSET :desplazamiento';
        $parametros['limite'] = $limite;
        $parametros['desplazamiento'] = $desplazamiento;

        return $this->consultar($sql, $parametros);
    }

    /**
     * Inserta una nueva fila en `documento` a partir de un DocumentoModel.
     */
    public function crear(DocumentoModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO documento (id, deportista_documento, tipo_documento, archivo, fecha_carga, estado) VALUES (:id, :deportista_documento, :tipo_documento, :archivo, :fecha_carga, :estado)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `documento` a partir de un DocumentoModel.
     */
    public function actualizar(DocumentoModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE documento SET deportista_documento = :deportista_documento, tipo_documento = :tipo_documento, archivo = :archivo, fecha_carga = :fecha_carga, estado = :estado WHERE id = :id',
            $datos
        );
    }

    /**
     * Elimina una fila de `documento` por su llave primaria `id`.
     */
    public function eliminar(string|int $id): bool
    {
        return $this->ejecutar(
            'DELETE FROM documento WHERE id = :id',
            ['id' => $id]
        );
    }
}
