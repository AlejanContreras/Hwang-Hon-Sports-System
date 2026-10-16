<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `documento_general`.
 * Documento del club visible para todos, sin deportista asociado (RN-6.8).
 * CRUD genérico únicamente — sin reglas de negocio (esas viven en DocumentoService).
 */
class DocumentoGeneralRepository extends BaseRepository
{
    protected const TABLA = 'documento_general';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'id', 'titulo', 'archivo', 'nombre_original', 'fecha_carga',
    ];

    protected const ORDEN = 'fecha_carga, id';

    /**
     * Obtiene una fila de `documento_general` por su llave primaria `id`.
     */
    public function obtenerPorId(string|int $id): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM documento_general WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Inserta una nueva fila en `documento_general` a partir de un DocumentoGeneralModel y devuelve el id
     * generado (columna AUTO_INCREMENT). El id del modelo no se envia: lo
     * asigna la base de datos.
     */
    public function crear(DocumentoGeneralModel $modelo): int
    {
        $datos = $modelo->toArray();
        unset($datos['id']);
        return $this->insertarYObtenerId(
            'INSERT INTO documento_general (titulo, archivo, nombre_original, fecha_carga) VALUES (:titulo, :archivo, :nombre_original, :fecha_carga)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `documento_general` a partir de un DocumentoGeneralModel.
     */
    public function actualizar(DocumentoGeneralModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE documento_general SET titulo = :titulo, archivo = :archivo, nombre_original = :nombre_original, fecha_carga = :fecha_carga WHERE id = :id',
            $datos
        );
    }

    /**
     * Elimina una fila de `documento_general` por su llave primaria `id`.
     */
    public function eliminar(string|int $id): bool
    {
        return $this->ejecutar(
            'DELETE FROM documento_general WHERE id = :id',
            ['id' => $id]
        );
    }
}
