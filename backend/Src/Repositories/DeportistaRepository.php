<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `deportista`.
 * Deportista inscrito en el club, con sus datos personales, deportivos y de estado (RN-1.x).
 * Solo acceso a datos — sin reglas de negocio (esas viven en DeportistaService).
 */
class DeportistaRepository extends BaseRepository
{
    protected const TABLA = 'deportista';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'documento', 'nombre', 'fecha_nacimiento', 'sexo', 'estatura', 'peso',
        'discapacidad', 'estado_animico', 'sede_entrenamiento', 'autorizacion_datos',
        'grado_actual', 'fecha_ingreso', 'estado', 'estado_registro',
        'acudiente_documento', 'validado_por_correo',
    ];

    protected const ORDEN = 'nombre';

    /**
     * Obtiene una fila de `deportista` por su llave primaria `documento`.
     */
    public function obtenerPorId(string|int $documento): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM deportista WHERE documento = :documento',
            ['documento' => $documento]
        );
    }

    /**
     * Inserta una nueva fila en `deportista` a partir de un DeportistaModel.
     * Envia siempre todas las columnas, incluidos sexo, estado y
     * estado_registro: son ENUM NOT NULL y, si se omiten, MySQL los completaria
     * en silencio con su primer valor.
     */
    public function crear(DeportistaModel $modelo): bool
    {
        return $this->insertar(
            'INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, discapacidad, estado_animico, sede_entrenamiento, autorizacion_datos, grado_actual, fecha_ingreso, estado, estado_registro, acudiente_documento, validado_por_correo) VALUES (:documento, :nombre, :fecha_nacimiento, :sexo, :estatura, :peso, :discapacidad, :estado_animico, :sede_entrenamiento, :autorizacion_datos, :grado_actual, :fecha_ingreso, :estado, :estado_registro, :acudiente_documento, :validado_por_correo)',
            $modelo->toArray()
        );
    }

    /**
     * Actualiza una fila existente de `deportista` a partir de un DeportistaModel.
     */
    public function actualizar(DeportistaModel $modelo): bool
    {
        return $this->ejecutar(
            'UPDATE deportista SET nombre = :nombre, fecha_nacimiento = :fecha_nacimiento, sexo = :sexo, estatura = :estatura, peso = :peso, discapacidad = :discapacidad, estado_animico = :estado_animico, sede_entrenamiento = :sede_entrenamiento, autorizacion_datos = :autorizacion_datos, grado_actual = :grado_actual, fecha_ingreso = :fecha_ingreso, estado = :estado, estado_registro = :estado_registro, acudiente_documento = :acudiente_documento, validado_por_correo = :validado_por_correo WHERE documento = :documento',
            $modelo->toArray()
        );
    }

    /**
     * Elimina una fila de `deportista` por su llave primaria `documento`.
     */
    public function eliminar(string|int $documento): bool
    {
        return $this->ejecutar(
            'DELETE FROM deportista WHERE documento = :documento',
            ['documento' => $documento]
        );
    }
}
