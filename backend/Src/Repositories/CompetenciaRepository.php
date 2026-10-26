<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `competencia`.
 * Datos específicos de un evento de tipo competencia (RN-3.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class CompetenciaRepository extends BaseRepository
{
    protected const TABLA = 'competencia';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'evento_id', 'plataforma', 'tipo_torneo',
    ];

    protected const ORDEN = 'evento_id';

    /**
     * Columnas de un torneo: evento + competencia + numero de deportistas
     * distintos inscritos (subconsulta correlacionada: sigue siendo UNA
     * consulta para todos los torneos, sin N+1).
     */
    private const SELECT_TORNEO = 'SELECT e.id AS evento_id, e.nombre, e.fecha, e.lugar, e.visibilidad, e.observaciones, '
        . 'c.plataforma, c.tipo_torneo, '
        . '(SELECT COUNT(DISTINCT p.deportista_documento) FROM participacion_competencia p WHERE p.competencia_evento_id = c.evento_id) AS num_participantes';

    /**
     * Torneos con filtros opcionales: deportista inscrito, rango de fechas,
     * lugar (contiene el texto, literal: los comodines de LIKE se escapan),
     * tipo de torneo y visibilidad del evento. Con $participaDocumento agrega
     * la columna "participa" (si ese deportista está inscrito). Orden: fecha
     * descendente, nombre, id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarTorneos(?string $deportista, ?string $desde, ?string $hasta, ?string $lugar, ?string $tipoTorneo, ?string $visibilidad, ?string $participaDocumento): array
    {
        $columnas = self::SELECT_TORNEO;
        $condiciones = [];
        $parametros = [];
        if ($participaDocumento !== null) {
            $columnas .= ', EXISTS(SELECT 1 FROM participacion_competencia pp WHERE pp.competencia_evento_id = c.evento_id AND pp.deportista_documento = :participa) AS participa';
            $parametros['participa'] = $participaDocumento;
        }
        if ($deportista !== null) {
            $condiciones[] = 'EXISTS(SELECT 1 FROM participacion_competencia pd WHERE pd.competencia_evento_id = c.evento_id AND pd.deportista_documento = :deportista)';
            $parametros['deportista'] = $deportista;
        }
        if ($desde !== null) {
            $condiciones[] = 'e.fecha >= :desde';
            $parametros['desde'] = $desde;
        }
        if ($hasta !== null) {
            $condiciones[] = 'e.fecha <= :hasta';
            $parametros['hasta'] = $hasta;
        }
        if ($lugar !== null) {
            $condiciones[] = "e.lugar LIKE :lugar ESCAPE '\\\\'";
            $parametros['lugar'] = '%' . addcslashes($lugar, '\\%_') . '%';
        }
        if ($tipoTorneo !== null) {
            $condiciones[] = 'c.tipo_torneo = :tipo_torneo';
            $parametros['tipo_torneo'] = $tipoTorneo;
        }
        if ($visibilidad !== null) {
            $condiciones[] = 'e.visibilidad = :visibilidad';
            $parametros['visibilidad'] = $visibilidad;
        }
        $donde = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);
        return $this->consultar(
            $columnas . ' FROM competencia c JOIN evento_calendario e ON e.id = c.evento_id' . $donde . ' ORDER BY e.fecha DESC, e.nombre, e.id',
            $parametros
        );
    }

    /**
     * Un torneo (evento + competencia + num_participantes), o null.
     */
    public function obtenerTorneo(int $eventoId): ?array
    {
        return $this->consultarUno(
            self::SELECT_TORNEO . ' FROM competencia c JOIN evento_calendario e ON e.id = c.evento_id WHERE c.evento_id = :evento_id',
            ['evento_id' => $eventoId]
        );
    }

    /**
     * Primer torneo con fecha >= $hoy (el más cercano), o null.
     */
    public function proximoTorneo(string $hoy): ?array
    {
        return $this->consultarUno(
            self::SELECT_TORNEO . ' FROM competencia c JOIN evento_calendario e ON e.id = c.evento_id WHERE e.fecha >= :hoy ORDER BY e.fecha, e.nombre, e.id LIMIT 1',
            ['hoy' => $hoy]
        );
    }

    /**
     * Obtiene una fila de `competencia` por su llave primaria `evento_id`.
     */
    public function obtenerPorId(string|int $eventoId): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM competencia WHERE evento_id = :evento_id',
            ['evento_id' => $eventoId]
        );
    }

    /**
     * Inserta una nueva fila en `competencia` a partir de un CompetenciaModel.
     */
    public function crear(CompetenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO competencia (evento_id, plataforma, tipo_torneo) VALUES (:evento_id, :plataforma, :tipo_torneo)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `competencia` a partir de un CompetenciaModel.
     */
    public function actualizar(CompetenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE competencia SET plataforma = :plataforma, tipo_torneo = :tipo_torneo WHERE evento_id = :evento_id',
            $datos
        );
    }
}
