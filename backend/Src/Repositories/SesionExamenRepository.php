<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `sesion_examen`.
 * Sesión de examen de ascenso de grado (RN-5.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class SesionExamenRepository extends BaseRepository
{
    protected const TABLA = 'sesion_examen';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'id', 'fecha', 'lugar',
    ];

    protected const ORDEN = 'fecha, id';

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
     * Inserta una nueva fila en `sesion_examen` a partir de un SesionExamenModel y devuelve el id
     * generado (columna AUTO_INCREMENT). El id del modelo no se envia: lo
     * asigna la base de datos.
     */
    public function crear(SesionExamenModel $modelo): int
    {
        $datos = $modelo->toArray();
        unset($datos['id']);
        return $this->insertarYObtenerId(
            'INSERT INTO sesion_examen (fecha, lugar) VALUES (:fecha, :lugar)',
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
