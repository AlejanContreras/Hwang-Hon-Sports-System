<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `evento_calendario`.
 * Evento general del calendario del club: competencia, evaluación, grado (ceremonia de entrega de cinturones), reunión administrativa o evento extra (RN-3.1/RN-9.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class EventoCalendarioRepository extends BaseRepository
{
    protected const TABLA = 'evento_calendario';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'id', 'nombre', 'tipo', 'fecha', 'lugar', 'visibilidad', 'observaciones',
    ];

    protected const ORDEN = 'fecha, id';

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
     * Inserta una nueva fila en `evento_calendario` a partir de un EventoCalendarioModel y devuelve el id
     * generado (columna AUTO_INCREMENT). El id del modelo no se envia: lo
     * asigna la base de datos.
     */
    public function crear(EventoCalendarioModel $modelo): int
    {
        $datos = $modelo->toArray();
        unset($datos['id']);
        return $this->insertarYObtenerId(
            'INSERT INTO evento_calendario (nombre, tipo, fecha, lugar, visibilidad, observaciones) VALUES (:nombre, :tipo, :fecha, :lugar, :visibilidad, :observaciones)',
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
