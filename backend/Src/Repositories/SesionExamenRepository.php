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
        'id', 'fecha', 'fecha_grado', 'lugar', 'estado', 'evento_examen_id',
        'evento_grado_id',
    ];

    protected const ORDEN = 'fecha, id';

    /**
     * Sesiones con filtros opcionales (estado, rango de la fecha de inicio,
     * deportista inscrito) y conteos en la misma consulta: deportistas
     * distintos, modalidades completadas y modalidades totales (subconsultas
     * correlacionadas, sin N+1). Orden: fecha de inicio descendente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarSesiones(?string $estado, ?string $desde, ?string $hasta, ?string $deportista): array
    {
        $condiciones = [];
        $parametros = [];
        if ($estado !== null) {
            $condiciones[] = 's.estado = :estado';
            $parametros['estado'] = $estado;
        }
        if ($desde !== null) {
            $condiciones[] = 's.fecha >= :desde';
            $parametros['desde'] = $desde;
        }
        if ($hasta !== null) {
            $condiciones[] = 's.fecha <= :hasta';
            $parametros['hasta'] = $hasta;
        }
        if ($deportista !== null) {
            $condiciones[] = 'EXISTS(SELECT 1 FROM detalle_evaluacion dd WHERE dd.sesion_examen_id = s.id AND dd.deportista_documento = :deportista)';
            $parametros['deportista'] = $deportista;
        }
        $donde = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);
        return $this->consultar(
            'SELECT s.*, '
            . '(SELECT COUNT(DISTINCT d.deportista_documento) FROM detalle_evaluacion d WHERE d.sesion_examen_id = s.id) AS num_participantes, '
            . "(SELECT COUNT(*) FROM detalle_evaluacion d WHERE d.sesion_examen_id = s.id AND d.estado = 'completado') AS num_completados, "
            . '(SELECT COUNT(*) FROM detalle_evaluacion d WHERE d.sesion_examen_id = s.id) AS total_modalidades '
            . 'FROM sesion_examen s' . $donde . ' ORDER BY s.fecha DESC, s.id DESC',
            $parametros
        );
    }

    /**
     * La sesión anterior a una fecha de inicio: la de fecha_grado más reciente
     * que sea menor que $fecha (excluyendo $exceptoId), o null.
     */
    public function sesionAnterior(string $fecha, int $exceptoId = 0): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM sesion_examen WHERE fecha_grado < :fecha AND id <> :excepto ORDER BY fecha_grado DESC, id DESC LIMIT 1',
            ['fecha' => $fecha, 'excepto' => $exceptoId]
        );
    }

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
     * asigna la base de datos. Envia siempre estado (ENUM NOT NULL).
     */
    public function crear(SesionExamenModel $modelo): int
    {
        $datos = $modelo->toArray();
        unset($datos['id']);
        return $this->insertarYObtenerId(
            'INSERT INTO sesion_examen (fecha, fecha_grado, lugar, estado, evento_examen_id, evento_grado_id) VALUES (:fecha, :fecha_grado, :lugar, :estado, :evento_examen_id, :evento_grado_id)',
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
            'UPDATE sesion_examen SET fecha = :fecha, fecha_grado = :fecha_grado, lugar = :lugar, estado = :estado, evento_examen_id = :evento_examen_id, evento_grado_id = :evento_grado_id WHERE id = :id',
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
