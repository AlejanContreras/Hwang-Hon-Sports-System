<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `detalle_evaluacion`.
 * Evaluación de un deportista en una modalidad dentro de una sesión de examen (RN-5.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class DetalleEvaluacionRepository extends BaseRepository
{
    protected const TABLA = 'detalle_evaluacion';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'sesion_examen_id', 'deportista_documento', 'modalidad', 'grado_evaluado',
        'estado', 'fecha_completado', 'nota', 'criterios_evaluados', 'puntajes',
        'valor_medible', 'aspectos_fallidos', 'observaciones', 'recomendaciones',
        'aprobado',
    ];

    protected const ORDEN = 'sesion_examen_id, deportista_documento, modalidad';

    /**
     * Obtiene una fila de `detalle_evaluacion` por su llave primaria compuesta (sesion_examen_id, deportista_documento, modalidad).
     */
    public function obtenerPorId(string|int $sesionExamenId, string|int $deportistaDocumento, string|int $modalidad): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM detalle_evaluacion WHERE sesion_examen_id = :sesion_examen_id AND deportista_documento = :deportista_documento AND modalidad = :modalidad',
            [
                'sesion_examen_id' => $sesionExamenId,
                'deportista_documento' => $deportistaDocumento,
                'modalidad' => $modalidad,
            ]
        );
    }

    /**
     * Inserta una nueva fila en `detalle_evaluacion` a partir de un DetalleEvaluacionModel.
     */
    public function crear(DetalleEvaluacionModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO detalle_evaluacion (sesion_examen_id, deportista_documento, modalidad, grado_evaluado, estado, fecha_completado, nota, criterios_evaluados, puntajes, valor_medible, aspectos_fallidos, observaciones, recomendaciones, aprobado) VALUES (:sesion_examen_id, :deportista_documento, :modalidad, :grado_evaluado, :estado, :fecha_completado, :nota, :criterios_evaluados, :puntajes, :valor_medible, :aspectos_fallidos, :observaciones, :recomendaciones, :aprobado)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `detalle_evaluacion` a partir de un DetalleEvaluacionModel.
     */
    public function actualizar(DetalleEvaluacionModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE detalle_evaluacion SET grado_evaluado = :grado_evaluado, estado = :estado, fecha_completado = :fecha_completado, nota = :nota, criterios_evaluados = :criterios_evaluados, puntajes = :puntajes, valor_medible = :valor_medible, aspectos_fallidos = :aspectos_fallidos, observaciones = :observaciones, recomendaciones = :recomendaciones, aprobado = :aprobado WHERE sesion_examen_id = :sesion_examen_id AND deportista_documento = :deportista_documento AND modalidad = :modalidad',
            $datos
        );
    }

    /**
     * Elimina una fila de `detalle_evaluacion` por su llave primaria compuesta (sesion_examen_id, deportista_documento, modalidad).
     */
    public function eliminar(string|int $sesionExamenId, string|int $deportistaDocumento, string|int $modalidad): bool
    {
        return $this->ejecutar(
            'DELETE FROM detalle_evaluacion WHERE sesion_examen_id = :sesion_examen_id AND deportista_documento = :deportista_documento AND modalidad = :modalidad',
            [
                'sesion_examen_id' => $sesionExamenId,
                'deportista_documento' => $deportistaDocumento,
                'modalidad' => $modalidad,
            ]
        );
    }
}
