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
    protected const TABLA = 'acudiente_tutor';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'documento', 'nombre', 'relacion', 'telefono', 'correo',
    ];

    protected const ORDEN = 'nombre, documento';

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
     * Indica si existe un acudiente con ese documento (sin leer la fila).
     */
    public function existePorId(string $documento): bool
    {
        return $this->existe(
            'SELECT 1 FROM acudiente_tutor WHERE documento = :documento LIMIT 1',
            ['documento' => $documento]
        );
    }

    /**
     * Inserta una nueva fila en `acudiente_tutor` a partir de un AcudienteTutorModel.
     */
    public function crear(AcudienteTutorModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO acudiente_tutor (documento, nombre, relacion, telefono, correo) VALUES (:documento, :nombre, :relacion, :telefono, :correo)',
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
            'UPDATE acudiente_tutor SET nombre = :nombre, relacion = :relacion, telefono = :telefono, correo = :correo WHERE documento = :documento',
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
