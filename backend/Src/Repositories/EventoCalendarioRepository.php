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

    protected const ORDEN = 'fecha, nombre, id';

    /**
     * Eventos con filtros opcionales: rango de fechas (ambos extremos
     * incluidos), tipo y visibilidad. Columnas fijas; solo los valores vienen
     * de afuera. Orden por fecha y nombre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarFiltrado(?string $desde, ?string $hasta, ?string $tipo, ?string $visibilidad): array
    {
        $condiciones = [];
        $parametros = [];
        if ($desde !== null) {
            $condiciones[] = 'fecha >= :desde';
            $parametros['desde'] = $desde;
        }
        if ($hasta !== null) {
            $condiciones[] = 'fecha <= :hasta';
            $parametros['hasta'] = $hasta;
        }
        if ($tipo !== null) {
            $condiciones[] = 'tipo = :tipo';
            $parametros['tipo'] = $tipo;
        }
        if ($visibilidad !== null) {
            $condiciones[] = 'visibilidad = :visibilidad';
            $parametros['visibilidad'] = $visibilidad;
        }
        $donde = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);
        return $this->consultar('SELECT * FROM evento_calendario' . $donde . ' ORDER BY ' . self::ORDEN, $parametros);
    }

    /**
     * Indica si ya hay un evento con ese nombre en esa fecha (otro que no
     * sea $exceptoId). La comparacion la hace la collation de la tabla
     * (utf8mb4_unicode_ci): no distingue mayusculas ni tildes.
     */
    public function existeNombreFecha(string $nombre, string $fecha, int $exceptoId = 0): bool
    {
        return $this->existe(
            'SELECT 1 FROM evento_calendario WHERE nombre = :nombre AND fecha = :fecha AND id <> :excepto LIMIT 1',
            ['nombre' => $nombre, 'fecha' => $fecha, 'excepto' => $exceptoId]
        );
    }

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
