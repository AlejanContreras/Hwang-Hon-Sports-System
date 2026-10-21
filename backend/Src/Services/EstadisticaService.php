<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del módulo Estadística: pestaña «Gráficas» de la
 * pantalla «Estadísticas e historial» (Contexto Base §35.1 y §35.4). Módulo
 * de agregación de solo lectura: no tiene tabla propia.
 *
 *   - deportista: perfil, medallas por tipo de torneo, récord acumulado,
 *     curva de posiciones, pruebas físicas (PROVISIONAL: depende de la
 *     plantilla de examen de Aldrin), exámenes y porcentaje de asistencia.
 *     Reutiliza el Historial (mismas reglas de ámbito, fechas y
 *     visibilidad), el récord de ParticipacionCompetenciaRepository y el
 *     porcentaje de AsistenciaService, sin reescribirlos.
 *   - torneo: resumen de un torneo ya realizado; solo administrativo.
 *   - Series en orden ascendente (para graficar en el tiempo).
 *   - La comparación de dos deportistas la arma el front consultando dos
 *     veces; aquí no hay estadísticas del club ni rankings.
 */
class EstadisticaService
{
    private const ZONA_HORARIA = 'America/Bogota';
    private const MEDALLAS = ['oro', 'plata', 'bronce'];

    private DeportistaRepository $deportista;
    private CompetenciaRepository $competencia;
    private ParticipacionCompetenciaRepository $participacionCompetencia;

    public function __construct()
    {
        $this->deportista = new DeportistaRepository();
        $this->competencia = new CompetenciaRepository();
        $this->participacionCompetencia = new ParticipacionCompetenciaRepository();
    }

    /**
     * Gráficas de un deportista (?deportista=; el público puede omitirlo y
     * solo puede pedir el suyo). Sin datos: series vacías y ceros.
     */
    public function deportista(array $parametros): array
    {
        $usuario = $this->usuario();
        $documento = array_key_exists('deportista', $parametros)
            ? Validador::texto($parametros['deportista'], 'deportista', 20, true)
            : null;
        if ($usuario['ambito'] === 'publica') {
            if ($documento !== null && $documento !== $usuario['identificador']) {
                throw new ExcepcionNegocio('Deportista no encontrado.', 404);
            }
            $documento = $usuario['identificador'];
        } elseif ($documento === null) {
            throw new ExcepcionNegocio('El parámetro "deportista" es obligatorio.', 400);
        }

        $deportista = $this->deportista->obtenerPorId($documento);
        if ($deportista === null) {
            throw new ExcepcionNegocio('Deportista no encontrado.', 404);
        }

        $historial = new HistorialService();
        // El historial viene en orden descendente; las series van ascendentes.
        // usort es estable (PHP 8): dentro de un mismo torneo o sesión se
        // conserva el orden del historial, cosa que array_reverse invertiría.
        $torneos = $historial->torneos(['deportista' => $documento]);
        usort($torneos, fn (array $a, array $b): int => [$a['fecha'], $a['evento_id']] <=> [$b['fecha'], $b['evento_id']]);
        $ascensos = $historial->ascensos(['deportista' => $documento]);
        usort($ascensos, fn (array $a, array $b): int => [$a['fecha_grado'], $a['sesion_id']] <=> [$b['fecha_grado'], $b['sesion_id']]);

        $asistencia = ['documento' => $documento];
        if (array_key_exists('ventana', $parametros)) {
            $asistencia['ventana'] = $parametros['ventana'];
        }

        $edad = DeportistaService::edadEnFecha($deportista['fecha_nacimiento'], $this->hoy());
        return [
            'perfil' => [
                'documento' => (string) $deportista['documento'],
                'nombre' => $deportista['nombre'],
                'grado_actual' => $deportista['grado_actual'],
                'edad' => $edad,
                'categoria_edad' => DeportistaService::categoriaPorEdad($edad),
                'fecha_ingreso' => $deportista['fecha_ingreso'],
                'estado' => $deportista['estado'],
            ],
            'medallas' => self::medallas($torneos),
            'record' => $this->record($documento),
            'posiciones' => $this->posiciones($torneos),
            'pruebas_fisicas' => $this->pruebasFisicas($ascensos),
            'examenes' => $this->examenes($ascensos),
            'asistencia' => (new AsistenciaService())->porcentaje($asistencia),
        ];
    }

    /**
     * Resumen de un torneo ya realizado (?id=). Solo administrativo: el
     * público recibe 403 sin consultar nada. Inexistente, sin competencia o
     * con fecha futura: 404. Nunca incluye notas_oponente.
     */
    public function torneo(array $parametros): array
    {
        if ($this->usuario()['ambito'] !== 'administrativa') {
            throw new ExcepcionNegocio('Solo el ámbito administrativo puede consultar el resumen de un torneo.', 403);
        }
        $id = $parametros['id'] ?? null;
        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }
        if (!is_int($id) || $id < 1) {
            throw new ExcepcionNegocio('El parámetro "id" debe ser un número entero positivo.', 400);
        }

        $torneo = $this->competencia->obtenerTorneo($id);
        if ($torneo === null || $torneo['fecha'] > $this->hoy()) {
            throw new ExcepcionNegocio('Torneo no encontrado.', 404);
        }

        $participantes = array_map(fn (array $fila): array => [
            'documento' => (string) $fila['deportista_documento'],
            'nombre' => $fila['nombre'],
            'modalidad' => $fila['modalidad'],
            'categoria_edad' => $fila['categoria_edad'],
            'categoria_peso' => $fila['categoria_peso'],
            'puesto' => $fila['puesto'] === null ? null : (int) $fila['puesto'],
            'resultado' => $fila['resultado'],
            'medalla' => $fila['medalla'],
            'combates_ganados' => $fila['combates_ganados'] === null ? null : (int) $fila['combates_ganados'],
            'combates_perdidos' => $fila['combates_perdidos'] === null ? null : (int) $fila['combates_perdidos'],
        ], $this->participacionCompetencia->listarConDeportista($id));

        // Por modalidad (orden del ENUM) y puesto, con los puestos nulos al
        // final; a igual puesto, por nombre y documento.
        $ordenModalidad = array_flip(CompetenciaService::MODALIDADES);
        usort($participantes, fn (array $a, array $b): int => [$ordenModalidad[$a['modalidad']], $a['puesto'] === null, $a['puesto'], $a['nombre'], $a['documento']]
            <=> [$ordenModalidad[$b['modalidad']], $b['puesto'] === null, $b['puesto'], $b['nombre'], $b['documento']]);

        $porModalidad = [];
        foreach ($participantes as $participante) {
            $modalidad = $participante['modalidad'];
            $porModalidad[$modalidad] ??= ['modalidad' => $modalidad, 'participantes' => 0, 'oro' => 0, 'plata' => 0, 'bronce' => 0, 'combates_ganados' => 0, 'combates_perdidos' => 0];
            $porModalidad[$modalidad]['participantes']++;
            if (in_array($participante['medalla'], self::MEDALLAS, true)) {
                $porModalidad[$modalidad][$participante['medalla']]++;
            }
            $porModalidad[$modalidad]['combates_ganados'] += $participante['combates_ganados'] ?? 0;
            $porModalidad[$modalidad]['combates_perdidos'] += $participante['combates_perdidos'] ?? 0;
        }

        $total = ['oro' => 0, 'plata' => 0, 'bronce' => 0];
        foreach ($porModalidad as $fila) {
            foreach (self::MEDALLAS as $medalla) {
                $total[$medalla] += $fila[$medalla];
            }
        }

        return [
            'evento_id' => (int) $torneo['evento_id'],
            'nombre' => $torneo['nombre'],
            'fecha' => $torneo['fecha'],
            'lugar' => $torneo['lugar'],
            'visibilidad' => $torneo['visibilidad'],
            'observaciones' => $torneo['observaciones'],
            'plataforma' => $torneo['plataforma'],
            'tipo_torneo' => $torneo['tipo_torneo'],
            'num_participantes' => (int) $torneo['num_participantes'],
            'por_modalidad' => array_values($porModalidad),
            'medallas_total' => $total + ['total' => array_sum($total)],
            'participantes' => $participantes,
        ];
    }

    // ------------------------------------------------------------------
    // Series de la gráfica de un deportista
    // ------------------------------------------------------------------

    /**
     * Medallas por tipo de torneo y totales; 'ninguna' y null no suman.
     * Pública y estática: la reutiliza el reporte de torneos (medallería).
     *
     * @param array<int, array<string, mixed>> $torneos filas de HistorialService::torneos
     */
    public static function medallas(array $torneos): array
    {
        $ceros = array_fill_keys(self::MEDALLAS, 0);
        $porTipo = array_fill_keys(CompetenciaService::TIPOS_TORNEO, $ceros);
        $totales = $ceros;
        foreach ($torneos as $fila) {
            if (in_array($fila['medalla'], self::MEDALLAS, true)) {
                $porTipo[$fila['tipo_torneo']][$fila['medalla']]++;
                $totales[$fila['medalla']]++;
            }
        }
        return ['por_tipo_torneo' => $porTipo, 'totales' => $totales + ['total' => array_sum($totales)]];
    }

    /**
     * Récord acumulado con recordDeCombates (todas las participaciones);
     * porcentaje de victorias a 2 decimales, null si no hay combates.
     */
    private function record(string $documento): array
    {
        $record = $this->participacionCompetencia->recordDeCombates($documento);
        $total = $record['combates_ganados'] + $record['combates_perdidos'];
        return [
            'ganados' => $record['combates_ganados'],
            'perdidos' => $record['combates_perdidos'],
            'total' => $total,
            'porcentaje_victorias' => $total === 0 ? null : round($record['combates_ganados'] * 100 / $total, 2),
        ];
    }

    /**
     * Curva de posiciones: participaciones con puesto, en orden ascendente.
     */
    private function posiciones(array $torneos): array
    {
        $serie = [];
        foreach ($torneos as $fila) {
            if ($fila['puesto'] !== null) {
                $serie[] = [
                    'evento_id' => $fila['evento_id'],
                    'torneo' => $fila['torneo'],
                    'fecha' => $fila['fecha'],
                    'modalidad' => $fila['modalidad'],
                    'puesto' => $fila['puesto'],
                    'medalla' => $fila['medalla'],
                    'participantes_en_modalidad' => $fila['participantes_en_modalidad'],
                ];
            }
        }
        return $serie;
    }

    /**
     * PROVISIONAL (plantilla de Aldrin, §35.4 vacío 2): valor_medible de la
     * modalidad fisico por sesión cerrada, solo si no es nulo.
     */
    private function pruebasFisicas(array $ascensos): array
    {
        $serie = [];
        foreach ($ascensos as $ascenso) {
            foreach ($ascenso['modalidades'] as $modalidad) {
                if ($modalidad['modalidad'] === 'fisico' && $modalidad['valor_medible'] !== null) {
                    $serie[] = [
                        'sesion_id' => $ascenso['sesion_id'],
                        'fecha_grado' => $ascenso['fecha_grado'],
                        'grado_evaluado' => $ascenso['grado_evaluado'],
                        'valor_medible' => $modalidad['valor_medible'],
                        'nota' => $modalidad['nota'],
                    ];
                }
            }
        }
        return $serie;
    }

    /**
     * Exámenes: promedio, aprobado y nota de cada modalidad por sesión cerrada.
     */
    private function examenes(array $ascensos): array
    {
        return array_map(fn (array $ascenso): array => [
            'sesion_id' => $ascenso['sesion_id'],
            'fecha_grado' => $ascenso['fecha_grado'],
            'grado_evaluado' => $ascenso['grado_evaluado'],
            'promedio' => $ascenso['resultado_general']['promedio'],
            'aprobado' => $ascenso['resultado_general']['aprobado'],
            'notas' => array_column($ascenso['modalidades'], 'nota', 'modalidad'),
        ], $ascensos);
    }

    // ------------------------------------------------------------------
    // Apoyo
    // ------------------------------------------------------------------

    /**
     * @return array{ambito: string, identificador: string}
     */
    private function usuario(): array
    {
        $usuario = Sesion::usuarioActual();
        if ($usuario === null) {
            throw new ExcepcionNegocio('No autenticado. Inicie sesión para continuar.', 401);
        }
        return $usuario;
    }

    private function hoy(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone(self::ZONA_HORARIA)))->format('Y-m-d');
    }
}
