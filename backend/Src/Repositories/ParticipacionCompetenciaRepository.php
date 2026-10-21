<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `participacion_competencia`.
 * Participación de un deportista en una modalidad de una competencia (RN-3.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class ParticipacionCompetenciaRepository extends BaseRepository
{
    protected const TABLA = 'participacion_competencia';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'deportista_documento', 'competencia_evento_id', 'modalidad', 'categoria_edad',
        'categoria_peso', 'puesto', 'resultado', 'medalla', 'combates_ganados',
        'combates_perdidos', 'notas_oponente',
    ];

    protected const ORDEN = 'competencia_evento_id, deportista_documento, modalidad';

    /**
     * Obtiene una fila de `participacion_competencia` por su llave primaria compuesta (deportista_documento, competencia_evento_id, modalidad).
     */
    public function obtenerPorId(string|int $deportistaDocumento, string|int $competenciaEventoId, string|int $modalidad): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM participacion_competencia WHERE deportista_documento = :deportista_documento AND competencia_evento_id = :competencia_evento_id AND modalidad = :modalidad',
            [
                'deportista_documento' => $deportistaDocumento,
                'competencia_evento_id' => $competenciaEventoId,
                'modalidad' => $modalidad,
            ]
        );
    }

    /**
     * Participaciones de un torneo con el nombre del deportista, por nombre y
     * modalidad. Con $deportistaDocumento, solo las de ese deportista.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarConDeportista(int $competenciaEventoId, ?string $deportistaDocumento = null): array
    {
        $parametros = ['evento_id' => $competenciaEventoId];
        $filtro = '';
        if ($deportistaDocumento !== null) {
            $filtro = ' AND p.deportista_documento = :deportista_documento';
            $parametros['deportista_documento'] = $deportistaDocumento;
        }
        return $this->consultar(
            'SELECT p.*, d.nombre FROM participacion_competencia p JOIN deportista d ON d.documento = p.deportista_documento '
            . 'WHERE p.competencia_evento_id = :evento_id' . $filtro . ' ORDER BY d.nombre, p.deportista_documento, p.modalidad',
            $parametros
        );
    }

    /**
     * Participaciones con los datos del torneo y el nombre del deportista,
     * para el historial y las gráficas. Solo torneos con fecha <= $hasta (el
     * Service pasa como máximo hoy); con $soloPublicos, solo eventos de
     * visibilidad pública. participantes_en_modalidad cuenta, en una
     * subconsulta, los inscritos del club en ese torneo y modalidad. Nunca
     * selecciona notas_oponente. Orden descendente (fecha más reciente
     * primero).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarHistorial(?string $deportistaDocumento, ?string $desde, string $hasta, bool $soloPublicos): array
    {
        $condiciones = ['e.fecha <= :hasta'];
        $parametros = ['hasta' => $hasta];
        if ($deportistaDocumento !== null) {
            $condiciones[] = 'p.deportista_documento = :documento';
            $parametros['documento'] = $deportistaDocumento;
        }
        if ($desde !== null) {
            $condiciones[] = 'e.fecha >= :desde';
            $parametros['desde'] = $desde;
        }
        if ($soloPublicos) {
            $condiciones[] = "e.visibilidad = 'publica'";
        }
        return $this->consultar(
            'SELECT e.id AS evento_id, e.nombre AS torneo, e.fecha, e.lugar, c.tipo_torneo, c.plataforma, '
            . 'p.deportista_documento, d.nombre, p.modalidad, p.categoria_edad, p.categoria_peso, p.puesto, p.resultado, '
            . 'p.medalla, p.combates_ganados, p.combates_perdidos, '
            . '(SELECT COUNT(*) FROM participacion_competencia pm WHERE pm.competencia_evento_id = p.competencia_evento_id AND pm.modalidad = p.modalidad) AS participantes_en_modalidad '
            . 'FROM participacion_competencia p '
            . 'JOIN competencia c ON c.evento_id = p.competencia_evento_id '
            . 'JOIN evento_calendario e ON e.id = c.evento_id '
            . 'JOIN deportista d ON d.documento = p.deportista_documento '
            . 'WHERE ' . implode(' AND ', $condiciones)
            . ' ORDER BY e.fecha DESC, e.id DESC, d.nombre, p.deportista_documento, p.modalidad',
            $parametros
        );
    }

    /**
     * Record de combates de un deportista: suma de combates_ganados y
     * combates_perdidos de todas sus participaciones. Una sola consulta
     * agregada sin GROUP BY (valida con ONLY_FULL_GROUP_BY); COALESCE deja 0
     * cuando no hay filas o todas son NULL.
     *
     * @return array{combates_ganados: int, combates_perdidos: int}
     */
    public function recordDeCombates(string $deportistaDocumento): array
    {
        $fila = $this->consultarUno(
            'SELECT COALESCE(SUM(combates_ganados), 0) AS combates_ganados, COALESCE(SUM(combates_perdidos), 0) AS combates_perdidos '
            . 'FROM participacion_competencia WHERE deportista_documento = :deportista_documento',
            ['deportista_documento' => $deportistaDocumento]
        );
        return [
            'combates_ganados' => (int) ($fila['combates_ganados'] ?? 0),
            'combates_perdidos' => (int) ($fila['combates_perdidos'] ?? 0),
        ];
    }

    /**
     * Inserta una nueva fila en `participacion_competencia` a partir de un ParticipacionCompetenciaModel.
     */
    public function crear(ParticipacionCompetenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO participacion_competencia (deportista_documento, competencia_evento_id, modalidad, categoria_edad, categoria_peso, puesto, resultado, medalla, combates_ganados, combates_perdidos, notas_oponente) VALUES (:deportista_documento, :competencia_evento_id, :modalidad, :categoria_edad, :categoria_peso, :puesto, :resultado, :medalla, :combates_ganados, :combates_perdidos, :notas_oponente)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `participacion_competencia` a partir de un ParticipacionCompetenciaModel.
     */
    public function actualizar(ParticipacionCompetenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE participacion_competencia SET categoria_edad = :categoria_edad, categoria_peso = :categoria_peso, puesto = :puesto, resultado = :resultado, medalla = :medalla, combates_ganados = :combates_ganados, combates_perdidos = :combates_perdidos, notas_oponente = :notas_oponente WHERE deportista_documento = :deportista_documento AND competencia_evento_id = :competencia_evento_id AND modalidad = :modalidad',
            $datos
        );
    }

    /**
     * Elimina una fila de `participacion_competencia` por su llave primaria compuesta (deportista_documento, competencia_evento_id, modalidad).
     */
    public function eliminar(string|int $deportistaDocumento, string|int $competenciaEventoId, string|int $modalidad): bool
    {
        return $this->ejecutar(
            'DELETE FROM participacion_competencia WHERE deportista_documento = :deportista_documento AND competencia_evento_id = :competencia_evento_id AND modalidad = :modalidad',
            [
                'deportista_documento' => $deportistaDocumento,
                'competencia_evento_id' => $competenciaEventoId,
                'modalidad' => $modalidad,
            ]
        );
    }
}
