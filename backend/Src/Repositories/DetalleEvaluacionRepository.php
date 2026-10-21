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
     * Filas de detalle de una sesión con el nombre y el grado_actual del
     * deportista. Con $deportistaDocumento, solo las de ese deportista.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarDeSesion(int $sesionExamenId, ?string $deportistaDocumento = null): array
    {
        $parametros = ['sesion' => $sesionExamenId];
        $filtro = '';
        if ($deportistaDocumento !== null) {
            $filtro = ' AND de.deportista_documento = :documento';
            $parametros['documento'] = $deportistaDocumento;
        }
        return $this->consultar(
            'SELECT de.*, d.nombre, d.grado_actual FROM detalle_evaluacion de JOIN deportista d ON d.documento = de.deportista_documento '
            . 'WHERE de.sesion_examen_id = :sesion' . $filtro . ' ORDER BY d.nombre, de.deportista_documento, de.modalidad',
            $parametros
        );
    }

    /**
     * Filas de detalle de las sesiones CERRADAS, con los datos de la sesión,
     * el nombre del deportista y su entrega de cinturón (LEFT JOIN: puede no
     * haberla). Filtros opcionales: deportista y rango sobre fecha_grado.
     * Una fila por modalidad: el Service las agrupa por (sesión, deportista).
     * Orden descendente por fecha_grado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarDeSesionesCerradas(?string $deportistaDocumento, ?string $desde, ?string $hasta): array
    {
        $condiciones = ["s.estado = 'cerrada'"];
        $parametros = [];
        if ($deportistaDocumento !== null) {
            $condiciones[] = 'de.deportista_documento = :documento';
            $parametros['documento'] = $deportistaDocumento;
        }
        if ($desde !== null) {
            $condiciones[] = 's.fecha_grado >= :desde';
            $parametros['desde'] = $desde;
        }
        if ($hasta !== null) {
            $condiciones[] = 's.fecha_grado <= :hasta';
            $parametros['hasta'] = $hasta;
        }
        return $this->consultar(
            'SELECT s.id AS sesion_id, s.fecha, s.fecha_grado, s.lugar, de.deportista_documento, d.nombre, '
            . 'de.modalidad, de.grado_evaluado, de.nota, de.aprobado, de.valor_medible, de.aspectos_fallidos, '
            . 'de.observaciones, de.recomendaciones, '
            . 'ec.cinturon_entregado, ec.fecha_entrega, ec.confirmado_por, (ec.sesion_examen_id IS NOT NULL) AS tiene_entrega '
            . 'FROM detalle_evaluacion de '
            . 'JOIN sesion_examen s ON s.id = de.sesion_examen_id '
            . 'JOIN deportista d ON d.documento = de.deportista_documento '
            . 'LEFT JOIN entrega_cinturon ec ON ec.sesion_examen_id = de.sesion_examen_id AND ec.deportista_documento = de.deportista_documento '
            . 'WHERE ' . implode(' AND ', $condiciones)
            . ' ORDER BY s.fecha_grado DESC, s.id DESC, d.nombre, de.deportista_documento, de.modalidad',
            $parametros
        );
    }

    /**
     * Cambia grado_evaluado en todas las filas de un participante.
     */
    public function actualizarGradoEvaluado(int $sesionExamenId, string $deportistaDocumento, string $gradoEvaluado): void
    {
        $this->ejecutar(
            'UPDATE detalle_evaluacion SET grado_evaluado = :grado WHERE sesion_examen_id = :sesion AND deportista_documento = :documento',
            ['grado' => $gradoEvaluado, 'sesion' => $sesionExamenId, 'documento' => $deportistaDocumento]
        );
    }

    /**
     * Borra todas las filas de un participante en una sesión.
     */
    public function eliminarParticipante(int $sesionExamenId, string $deportistaDocumento): void
    {
        $this->ejecutar(
            'DELETE FROM detalle_evaluacion WHERE sesion_examen_id = :sesion AND deportista_documento = :documento',
            ['sesion' => $sesionExamenId, 'documento' => $deportistaDocumento]
        );
    }

    /**
     * Borra todas las filas de una sesión.
     */
    public function eliminarDeSesion(int $sesionExamenId): void
    {
        $this->ejecutar('DELETE FROM detalle_evaluacion WHERE sesion_examen_id = :sesion', ['sesion' => $sesionExamenId]);
    }

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
