<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `asistencia`.
 * Registro de asistencia de un deportista a una fecha de entrenamiento (RN-7.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class AsistenciaRepository extends BaseRepository
{
    protected const TABLA = 'asistencia';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'deportista_documento', 'fecha', 'asistio',
    ];

    protected const ORDEN = 'fecha, deportista_documento';

    /**
     * Obtiene una fila de `asistencia` por su llave primaria compuesta (deportista_documento, fecha).
     */
    public function obtenerPorId(string|int $deportistaDocumento, string|int $fecha): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM asistencia WHERE deportista_documento = :deportista_documento AND fecha = :fecha',
            [
                'deportista_documento' => $deportistaDocumento,
                'fecha' => $fecha,
            ]
        );
    }

    /**
     * Indica si la fecha ya tiene asistencia tomada (al menos una fila).
     */
    public function existeFecha(string $fecha): bool
    {
        return $this->existe('SELECT 1 FROM asistencia WHERE fecha = :fecha LIMIT 1', ['fecha' => $fecha]);
    }

    /**
     * Filas por deportista y/o rango de fechas (ambos extremos incluidos).
     * Los nombres de columna son fijos; solo los valores vienen de afuera.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarFiltrado(?string $deportistaDocumento, ?string $desde, ?string $hasta): array
    {
        $condiciones = [];
        $parametros = [];
        if ($deportistaDocumento !== null) {
            $condiciones[] = 'deportista_documento = :deportista_documento';
            $parametros['deportista_documento'] = $deportistaDocumento;
        }
        if ($desde !== null) {
            $condiciones[] = 'fecha >= :desde';
            $parametros['desde'] = $desde;
        }
        if ($hasta !== null) {
            $condiciones[] = 'fecha <= :hasta';
            $parametros['hasta'] = $hasta;
        }
        $donde = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);
        return $this->consultar('SELECT * FROM asistencia' . $donde . ' ORDER BY ' . self::ORDEN, $parametros);
    }

    /**
     * Total de filas y presentes de una fecha.
     *
     * @return array{total: int, presentes: int}
     */
    public function resumenDeFecha(string $fecha): array
    {
        $fila = $this->consultarUno(
            'SELECT COUNT(*) AS total, COALESCE(SUM(asistio), 0) AS presentes FROM asistencia WHERE fecha = :fecha',
            ['fecha' => $fecha]
        );
        return ['total' => (int) $fila['total'], 'presentes' => (int) $fila['presentes']];
    }

    /**
     * Última fecha con asistencia tomada hasta $fecha (incluida), o null.
     */
    public function ultimaFechaHasta(string $fecha): ?string
    {
        $fila = $this->consultarUno('SELECT MAX(fecha) AS ultima FROM asistencia WHERE fecha <= :fecha', ['fecha' => $fecha]);
        return $fila['ultima'] ?? null;
    }

    /**
     * Sesiones registradas y presentes de un deportista desde $desde (incluido).
     *
     * @return array{sesiones: int, presentes: int}
     */
    public function conteoDesde(string $deportistaDocumento, string $desde): array
    {
        $fila = $this->consultarUno(
            'SELECT COUNT(*) AS sesiones, COALESCE(SUM(asistio), 0) AS presentes FROM asistencia '
            . 'WHERE deportista_documento = :deportista_documento AND fecha >= :desde',
            ['deportista_documento' => $deportistaDocumento, 'desde' => $desde]
        );
        return ['sesiones' => (int) $fila['sesiones'], 'presentes' => (int) $fila['presentes']];
    }

    /**
     * Fecha de la última asistencia con asistio = 1 de cada deportista que
     * haya asistido alguna vez, en una sola consulta.
     *
     * @return array<string, string> documento => fecha
     */
    public function ultimasAsistencias(): array
    {
        $resultado = [];
        foreach ($this->consultar('SELECT deportista_documento, MAX(fecha) AS ultima FROM asistencia WHERE asistio = 1 GROUP BY deportista_documento') as $fila) {
            $resultado[(string) $fila['deportista_documento']] = $fila['ultima'];
        }
        return $resultado;
    }

    /**
     * Fechas con asistio = 1 de todos los deportistas dentro del rango
     * (ambos extremos incluidos), en una sola consulta, ordenadas.
     *
     * @return array<string, array<int, string>> documento => [fechas]
     */
    public function fechasPresentesEnRango(string $desde, string $hasta): array
    {
        $resultado = [];
        $filas = $this->consultar(
            'SELECT deportista_documento, fecha FROM asistencia WHERE asistio = 1 AND fecha >= :desde AND fecha <= :hasta ORDER BY deportista_documento, fecha',
            ['desde' => $desde, 'hasta' => $hasta]
        );
        foreach ($filas as $fila) {
            $resultado[(string) $fila['deportista_documento']][] = $fila['fecha'];
        }
        return $resultado;
    }

    /**
     * Filas de asistencia del rango con el nombre del deportista, para la
     * planilla del reporte (una sola consulta para todos). Solo deportistas
     * validados; con $estado, solo los de ese estado actual. Orden: nombre,
     * documento y fecha.
     *
     * @return array<int, array<string, mixed>>
     */
    public function planillaEnRango(string $desde, string $hasta, ?string $estado): array
    {
        $parametros = ['desde' => $desde, 'hasta' => $hasta];
        $filtro = '';
        if ($estado !== null) {
            $filtro = ' AND d.estado = :estado';
            $parametros['estado'] = $estado;
        }
        return $this->consultar(
            'SELECT a.deportista_documento, d.nombre, a.fecha, a.asistio FROM asistencia a '
            . 'JOIN deportista d ON d.documento = a.deportista_documento '
            . "WHERE a.fecha >= :desde AND a.fecha <= :hasta AND d.estado_registro = 'validado'" . $filtro
            . ' ORDER BY d.nombre, a.deportista_documento, a.fecha',
            $parametros
        );
    }

    /**
     * Borra todas las filas de una fecha y devuelve cuántas borró.
     */
    public function eliminarFecha(string $fecha): int
    {
        return $this->ejecutarYContarFilas('DELETE FROM asistencia WHERE fecha = :fecha', ['fecha' => $fecha]);
    }

    /**
     * Inserta una nueva fila en `asistencia` a partir de un AsistenciaModel.
     */
    public function crear(AsistenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES (:deportista_documento, :fecha, :asistio)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `asistencia` a partir de un AsistenciaModel.
     */
    public function actualizar(AsistenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE asistencia SET asistio = :asistio WHERE deportista_documento = :deportista_documento AND fecha = :fecha',
            $datos
        );
    }

    /**
     * Elimina una fila de `asistencia` por su llave primaria compuesta (deportista_documento, fecha).
     */
    public function eliminar(string|int $deportistaDocumento, string|int $fecha): bool
    {
        return $this->ejecutar(
            'DELETE FROM asistencia WHERE deportista_documento = :deportista_documento AND fecha = :fecha',
            [
                'deportista_documento' => $deportistaDocumento,
                'fecha' => $fecha,
            ]
        );
    }
}
