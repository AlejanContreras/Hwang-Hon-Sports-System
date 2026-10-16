<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `pago`.
 * Registro de pago (mensualidad o matrícula) de un deportista (RN-8.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class PagoRepository extends BaseRepository
{
    protected const TABLA = 'pago';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'id', 'deportista_documento', 'tipo', 'anio', 'mes', 'semana',
    ];

    protected const ORDEN = 'anio, mes, id';

    /**
     * Obtiene una fila de `pago` por su llave primaria `id`.
     */
    public function obtenerPorId(string|int $id): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM pago WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Inserta una nueva fila en `pago` a partir de un PagoModel y devuelve el id
     * generado (columna AUTO_INCREMENT). El id del modelo no se envia: lo
     * asigna la base de datos.
     */
    public function crear(PagoModel $modelo): int
    {
        $datos = $modelo->toArray();
        unset($datos['id']);
        return $this->insertarYObtenerId(
            'INSERT INTO pago (deportista_documento, tipo, anio, mes, semana) VALUES (:deportista_documento, :tipo, :anio, :mes, :semana)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `pago` a partir de un PagoModel.
     */
    public function actualizar(PagoModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE pago SET deportista_documento = :deportista_documento, tipo = :tipo, anio = :anio, mes = :mes, semana = :semana WHERE id = :id',
            $datos
        );
    }

    /**
     * Elimina una fila de `pago` por su llave primaria `id`.
     */
    public function eliminar(string|int $id): bool
    {
        return $this->ejecutar(
            'DELETE FROM pago WHERE id = :id',
            ['id' => $id]
        );
    }
}
