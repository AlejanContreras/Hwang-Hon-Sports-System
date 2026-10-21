<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del módulo Reportes y exportación (RN-10.x;
 * Contexto Base §35.13). Sin tabla propia: agrega datos de otros módulos
 * reutilizando sus Services y Repositories, sin reescribir sus reglas.
 *
 *   - Solo administrativo (la ruta ya es solo_administrativa; aquí se
 *     vuelve a exigir por defensa en profundidad).
 *   - Cada acción valida TODO antes de generar: los errores salen como JSON
 *     con su código y nunca a medio archivo.
 *   - Los archivos se generan en memoria y se devuelven; nunca se guardan
 *     en el servidor (datos de menores y de salud).
 *   - Consultas en número constante: los datos de todos los deportistas se
 *     traen en bloque y se cruzan en PHP.
 *   - Texto digitado por usuarios: siempre como cadena (nunca fórmula) con
 *     el estilo ESTILO_CELDA_TEXTO, que lleva quotePrefix (inyección de
 *     fórmulas neutralizada aunque la celda se edite).
 *   - Nunca se exportan campos excluidos (RN-1.6), notas_oponente,
 *     contraseñas ni datos de salud.
 *
 * Cada método devuelve {contenido, nombre, tipo}; el Controller lo envía
 * con Respuesta::archivo.
 */
class ReporteService
{
    private const ZONA_HORARIA = 'America/Bogota';
    public const TIPO_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const TIPO_PDF = 'application/pdf';
    private const NOMBRE_CLUB = 'Club Deportivo de Hapkido Hwang Hon';
    private const ESTADOS = ['activo', 'inactivo'];
    /** Tope del rango del reporte de asistencia (un año y un día bisiesto). */
    private const MAXIMO_DIAS_ASISTENCIA = 366;
    private const ULTIMOS_TORNEOS_FICHA = 5;
    private const ANIO_MINIMO = 2020;
    /** Límite de caracteres de una celda de Excel. */
    private const MAXIMO_TEXTO_CELDA = 32767;
    /** PROVISIONAL (plantilla de Aldrin): nota por debajo de la cual una modalidad se marca «por mejorar». */
    private const NOTA_MINIMA_MODALIDAD = 5;
    private const MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    private const DIAS = [1 => 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    private const NOMBRES_MODALIDAD = [
        'teorico' => 'Teórico', 'fisico' => 'Físico', 'combate' => 'Combate',
        'rompimiento' => 'Rompimiento', 'formas' => 'Formas', 'llaves' => 'Llaves',
    ];

    /*
     * Planilla de asistencia: formato PROPIO estilo IMRDS (PROVISIONAL, la
     * plantilla oficial no se ha conseguido). Todo el diseño vive en estas
     * constantes y en hojaPlanillaAsistencia(), para reemplazarlo cuando
     * llegue la oficial sin tocar la obtención de datos.
     */
    private const PLANILLA_TITULO = 'Planilla de asistencia';
    private const PLANILLA_MARCA_PRESENTE = 'X';
    private const PLANILLA_MARCA_AUSENTE = 'F';
    private const PLANILLA_LEYENDA = 'X = asistió · F = faltó · vacío = sin registro ese día (aún no ingresaba o no estaba activo)';

    private DeportistaRepository $deportista;
    private AsistenciaRepository $asistencia;
    private PagoRepository $pago;
    private AcudienteTutorRepository $acudiente;

    public function __construct()
    {
        $this->deportista = new DeportistaRepository();
        $this->asistencia = new AsistenciaRepository();
        $this->pago = new PagoRepository();
        $this->acudiente = new AcudienteTutorRepository();
    }

    // ------------------------------------------------------------------
    // A) Ficha consolidada del deportista (PDF)
    // ------------------------------------------------------------------

    /**
     * Ficha en PDF (?deportista=): perfil, contacto (y acudiente si es
     * menor), último examen, aspectos por mejorar deducidos de los datos,
     * últimos torneos, asistencia y permanencia. Solo HECHOS: no hay
     * «aptitud para torneos» (RN-10.3) ni texto libre adicional.
     */
    public function deportistaFicha(array $parametros): array
    {
        $this->exigirAdministrativo();
        $documento = Validador::texto($parametros['deportista'] ?? null, 'deportista', 20, true);
        $deportista = $this->deportista->obtenerPorId($documento);
        if ($deportista === null) {
            throw new ExcepcionNegocio('Deportista no encontrado.', 404);
        }

        $hoy = $this->hoy();
        $edad = DeportistaService::edadEnFecha($deportista['fecha_nacimiento'], $hoy);
        $acudiente = null;
        if ($edad < DeportistaService::MAYORIA_EDAD && $deportista['acudiente_documento'] !== null) {
            $acudiente = $this->acudiente->obtenerPorId($deportista['acudiente_documento']);
        }

        $historial = new HistorialService();
        $examen = $historial->ascensos(['deportista' => $documento])[0] ?? null;
        $torneos = array_slice($historial->torneos(['deportista' => $documento]), 0, self::ULTIMOS_TORNEOS_FICHA);
        $permanencia = $historial->permanencia(['deportista' => $documento])[0] ?? null;
        $asistencia = new AsistenciaService();
        $asistencia90 = $asistencia->porcentaje(['documento' => $documento]);
        $asistenciaTotal = $asistencia->porcentaje(['documento' => $documento, 'ventana' => 'todo']);

        $secciones = [
            ['titulo' => 'Perfil', 'pares' => [
                ['Nombre', $deportista['nombre']],
                ['Documento', (string) $deportista['documento']],
                ['Edad', "{$edad} años"],
                ['Categoría de edad', DeportistaService::categoriaPorEdad($edad) ?? 'Sin categoría (menor de 8 años)'],
                ['Grado actual', $deportista['grado_actual'] ?? 'Ingresado (sin cinturón)'],
                ['Fecha de ingreso', $deportista['fecha_ingreso']],
                ['Estado', ucfirst($deportista['estado'])],
            ]],
            ['titulo' => 'Contacto', 'pares' => array_merge(
                [
                    ['Teléfono', $deportista['telefono'] ?? '—'],
                    ['Correo', $deportista['correo'] ?? '—'],
                    ['Dirección', $deportista['direccion'] ?? '—'],
                ],
                $acudiente === null ? [] : [
                    ['Acudiente', "{$acudiente['nombre']} ({$acudiente['relacion']})"],
                    ['Teléfono del acudiente', $acudiente['telefono'] ?? '—'],
                    ['Correo del acudiente', $acudiente['correo'] ?? '—'],
                ]
            )],
            $this->seccionExamen($examen),
            $this->seccionPorMejorar($examen),
            $this->seccionTorneos($torneos),
            ['titulo' => 'Asistencia', 'pares' => [
                ['Últimos 90 días', $this->textoAsistencia($asistencia90)],
                ['Desde el ingreso', $this->textoAsistencia($asistenciaTotal)],
            ]],
            ['titulo' => 'Permanencia', 'pares' => [
                ['Fecha de ingreso', $deportista['fecha_ingreso']],
                ['Meses pagados', (string) ($permanencia['meses_pagados'] ?? 0)],
                ['Primer mes pagado', $permanencia['primer_mes_pagado'] ?? '—'],
                ['Último mes pagado', $permanencia['ultimo_mes_pagado'] ?? '—'],
            ]],
        ];

        // FPDF se carga solo aquí: las demás rutas no pagan su costo.
        require_once __DIR__ . '/../Lib/FPDF/fpdf.php';
        require_once __DIR__ . '/../Utils/FichaDeportistaPdf.php';
        $pdf = new FichaDeportistaPdf(self::NOMBRE_CLUB, 'Ficha consolidada del deportista', $hoy);
        return [
            'contenido' => $pdf->generar($secciones),
            'nombre' => "ficha_{$documento}_{$hoy}.pdf",
            'tipo' => self::TIPO_PDF,
        ];
    }

    // ------------------------------------------------------------------
    // B) Listado de deportistas (xlsx)
    // ------------------------------------------------------------------

    /**
     * Listado (?estado=activo|inactivo). Sin filtro: todos los VALIDADOS;
     * los pendientes de validación nunca salen (aún no son deportistas del
     * club; se gestionan en su propia lista de Deportistas).
     */
    public function deportistas(array $parametros): array
    {
        $this->exigirAdministrativo();
        $estado = $this->estado($parametros);
        $filas = $this->deportista->listar(['estado_registro' => 'validado'] + ($estado === null ? [] : ['estado' => $estado]));
        $hoy = $this->hoy();

        $x = $this->libroNuevo();
        $h = $x->agregarHoja('Deportistas');
        $titulos = ['N°', 'Documento', 'Nombre', 'Fecha de nacimiento', 'Edad', 'Categoría de edad', 'Grado actual', 'Estado', 'Fecha de ingreso', 'Teléfono', 'Correo'];
        $fila = $this->encabezado($x, $h, 'Listado de deportistas', ['Estado: ' . ($estado ?? 'todos (validados)')], $titulos, [6, 16, 34, 14, 7, 18, 20, 11, 14, 16, 32]);
        foreach ($filas as $i => $d) {
            $edad = DeportistaService::edadEnFecha($d['fecha_nacimiento'], $hoy);
            $this->numero($x, $h, 0, $fila, $i + 1);
            $this->texto($x, $h, 1, $fila, (string) $d['documento']);
            $this->texto($x, $h, 2, $fila, $d['nombre']);
            $this->fecha($x, $h, 3, $fila, $d['fecha_nacimiento']);
            $this->numero($x, $h, 4, $fila, $edad);
            $this->texto($x, $h, 5, $fila, DeportistaService::categoriaPorEdad($edad) ?? 'Sin categoría');
            $this->texto($x, $h, 6, $fila, $d['grado_actual'] ?? 'Ingresado');
            $this->texto($x, $h, 7, $fila, ucfirst($d['estado']));
            $this->fecha($x, $h, 8, $fila, $d['fecha_ingreso']);
            $this->texto($x, $h, 9, $fila, $d['telefono']);
            $this->texto($x, $h, 10, $fila, $d['correo']);
            $fila++;
        }
        $sufijo = $estado === null ? '' : "_{$estado}s";
        return $this->xlsx($x, "deportistas{$sufijo}_{$hoy}.xlsx");
    }

    // ------------------------------------------------------------------
    // C) Asistencia por rango (xlsx, planilla propia estilo IMRDS)
    // ------------------------------------------------------------------

    /**
     * Planilla de asistencia (?desde&hasta obligatorios, ?estado opcional).
     * Filas: deportistas con registros en el rango; columnas: fechas de
     * sesión del rango. El % de cada deportista usa la misma regla que
     * AsistenciaService::porcentaje (calcularPorcentaje), sobre las fechas
     * del rango en que tiene registro. Una sola consulta.
     */
    public function asistencia(array $parametros): array
    {
        $this->exigirAdministrativo();
        foreach (['desde', 'hasta'] as $campo) {
            if (!array_key_exists($campo, $parametros)) {
                throw new ExcepcionNegocio("El parámetro \"{$campo}\" es obligatorio.", 400);
            }
        }
        [$desde, $hasta] = $this->rango($parametros);
        $estado = $this->estado($parametros);
        $dias = (new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->days + 1;
        if ($dias > self::MAXIMO_DIAS_ASISTENCIA) {
            throw new ExcepcionNegocio('El rango de la planilla de asistencia no puede superar ' . self::MAXIMO_DIAS_ASISTENCIA . ' días.', 422);
        }

        $fechas = [];
        $deportistas = [];
        foreach ($this->asistencia->planillaEnRango($desde, $hasta, $estado) as $registro) {
            $documento = (string) $registro['deportista_documento'];
            $fechas[$registro['fecha']] = true;
            $deportistas[$documento] ??= ['nombre' => $registro['nombre'], 'marcas' => []];
            $deportistas[$documento]['marcas'][$registro['fecha']] = (bool) $registro['asistio'];
        }
        $fechas = array_keys($fechas);
        sort($fechas);

        $x = $this->libroNuevo();
        $this->hojaPlanillaAsistencia($x, $desde, $hasta, $estado, $fechas, $deportistas);
        return $this->xlsx($x, "asistencia_{$desde}_a_{$hasta}_{$this->hoy()}.xlsx");
    }

    /**
     * Diseño de la planilla (PROVISIONAL, reemplazable por la plantilla
     * oficial IMRDS). Sin género, grupo étnico ni víctima (RN-1.6).
     *
     * @param array<int, string> $fechas
     * @param array<string, array{nombre: string, marcas: array<string, bool>}> $deportistas
     */
    private function hojaPlanillaAsistencia(GeneradorXlsx $x, string $desde, string $hasta, ?string $estado, array $fechas, array $deportistas): void
    {
        $h = $x->agregarHoja('Asistencia');
        $titulos = array_merge(['N°', 'Documento', 'Nombre'], array_map(fn (string $f): string => $this->diaCorto($f), $fechas), ['Asistió', 'Sesiones', '% asistencia']);
        $anchos = array_merge([6, 16, 34], array_fill(0, count($fechas), 9), [10, 10, 14]);
        $subtitulos = ["Rango: {$desde} a {$hasta}" . ($estado === null ? '' : " · Estado: {$estado}"), self::PLANILLA_LEYENDA];
        $fila = $this->encabezado($x, $h, self::PLANILLA_TITULO, $subtitulos, $titulos, $anchos, GeneradorXlsx::ESTILO_COLUMNA_TITULO_AJUSTADO);
        $x->altoFila($h, $fila - 1, 32);

        $columnaTotales = 3 + count($fechas);
        $asistentesPorFecha = array_fill_keys($fechas, 0);
        $numero = 1;
        foreach ($deportistas as $documento => $d) {
            $this->numero($x, $h, 0, $fila, $numero++);
            $this->texto($x, $h, 1, $fila, (string) $documento);
            $this->texto($x, $h, 2, $fila, $d['nombre']);
            foreach ($fechas as $i => $f) {
                $columna = $x->letraColumna(3 + $i);
                if (!array_key_exists($f, $d['marcas'])) {
                    $x->celda($h, $columna, $fila, '', GeneradorXlsx::ESTILO_CELDA_NEUTRA);
                } elseif ($d['marcas'][$f]) {
                    $x->celda($h, $columna, $fila, self::PLANILLA_MARCA_PRESENTE, GeneradorXlsx::ESTILO_CELDA_POSITIVA);
                    $asistentesPorFecha[$f]++;
                } else {
                    $x->celda($h, $columna, $fila, self::PLANILLA_MARCA_AUSENTE, GeneradorXlsx::ESTILO_CELDA_NEGATIVA);
                }
            }
            $sesiones = count($d['marcas']);
            $presentes = count(array_filter($d['marcas']));
            $porcentaje = AsistenciaService::calcularPorcentaje($sesiones, $presentes);
            $this->numero($x, $h, $columnaTotales, $fila, $presentes, GeneradorXlsx::ESTILO_TOTAL_DESTACADO);
            $this->numero($x, $h, $columnaTotales + 1, $fila, $sesiones);
            $x->celda($h, $x->letraColumna($columnaTotales + 2), $fila, $porcentaje['porcentaje'] ?? 'Datos incompletos', GeneradorXlsx::ESTILO_TOTAL_DESTACADO);
            $fila++;
        }

        // Fila final: asistentes por fecha.
        $x->celda($h, 'A', $fila, 'Asistentes por fecha', GeneradorXlsx::ESTILO_TOTAL_POSITIVO);
        $x->combinar($h, "A{$fila}", "C{$fila}");
        foreach ($fechas as $i => $f) {
            $this->numero($x, $h, 3 + $i, $fila, $asistentesPorFecha[$f], GeneradorXlsx::ESTILO_TOTAL_POSITIVO);
        }
        $this->numero($x, $h, $columnaTotales, $fila, array_sum($asistentesPorFecha), GeneradorXlsx::ESTILO_TOTAL_POSITIVO);
    }

    // ------------------------------------------------------------------
    // D) Planilla anual de pagos y deudores (xlsx, dos hojas)
    // ------------------------------------------------------------------

    /**
     * Planilla de pagos (?anio, por defecto el actual; ?estado opcional).
     * Hoja «Planilla»: deportista × 12 meses con la semana de pago (RN-8.1)
     * y el estado de cada mes calculado con PagoService::calcularEstado (la
     * misma regla vigente); matrícula en su columna. Hoja «Deudores»:
     * PagoService::deudores tal cual (año en curso). Ocho consultas en total.
     */
    public function pagos(array $parametros): array
    {
        $this->exigirAdministrativo();
        $hoy = new DateTimeImmutable('today', new DateTimeZone(self::ZONA_HORARIA));
        $anioActual = (int) $hoy->format('Y');
        $anio = $anioActual;
        if (array_key_exists('anio', $parametros)) {
            $valor = $parametros['anio'];
            if (!is_string($valor) || !ctype_digit($valor) || (int) $valor < self::ANIO_MINIMO || (int) $valor > $anioActual + 1) {
                throw new ExcepcionNegocio('El parámetro "anio" debe ser un año entre ' . self::ANIO_MINIMO . ' y ' . ($anioActual + 1) . '.', 400);
            }
            $anio = (int) $valor;
        }
        $estado = $this->estado($parametros);

        $deportistas = $this->deportista->listar(['estado_registro' => 'validado'] + ($estado === null ? [] : ['estado' => $estado]));
        $pagosPorDeportista = [];
        foreach ($this->pago->listar(['anio' => $anio]) as $pago) {
            $pagosPorDeportista[(string) $pago['deportista_documento']][] = $pago;
        }
        $semanas = $this->pago->semanasDelUltimoPago();
        $fechas = $this->asistencia->fechasPresentesEnRango("{$anio}-01-01", ($anio + 1) . '-01-31');
        $deudores = (new PagoService())->deudores();

        $x = $this->libroNuevo();
        $h = $x->agregarHoja('Planilla');
        $titulos = array_merge(['N°', 'Documento', 'Nombre', 'Estado', 'Matrícula'], array_values(self::MESES), ['Meses pagados']);
        $subtitulos = [
            "Año {$anio}" . ($estado === null ? '' : " · Estado: {$estado}"),
            'Cada mes muestra la semana del pago (1: días 1–7, 2: 8–14, 3: 15–21, 4: 22–fin). Verde = pagado · Rojo = vencido sin pagar («Debe» si ya es deuda, RN-8.3) · Blanco = aún no vence o no aplica.',
        ];
        $fila = $this->encabezado($x, $h, 'Planilla anual de pagos', $subtitulos, $titulos, array_merge([6, 16, 34, 10, 12], array_fill(0, 12, 11), [10]), GeneradorXlsx::ESTILO_COLUMNA_TITULO_AJUSTADO);
        foreach ($deportistas as $i => $d) {
            $documento = (string) $d['documento'];
            $estadoPagos = PagoService::calcularEstado(
                $d,
                $anio,
                $pagosPorDeportista[$documento] ?? [],
                $semanas[$documento] ?? PagoService::semanaDelMes($d['fecha_ingreso']),
                $fechas[$documento] ?? [],
                $hoy
            );
            $this->numero($x, $h, 0, $fila, $i + 1);
            $this->texto($x, $h, 1, $fila, $documento);
            $this->texto($x, $h, 2, $fila, $d['nombre']);
            $this->texto($x, $h, 3, $fila, ucfirst($d['estado']));
            [$matricula, $estiloMatricula] = match (true) {
                $estadoPagos['matricula']['pagada'] => ['Pagada', GeneradorXlsx::ESTILO_CELDA_POSITIVA],
                $estadoPagos['matricula']['pendiente'] => ['Pendiente', GeneradorXlsx::ESTILO_CELDA_NEGATIVA],
                default => ['No aplica', GeneradorXlsx::ESTILO_CELDA_NEUTRA],
            };
            $x->celda($h, 'E', $fila, $matricula, $estiloMatricula);
            $pagados = 0;
            foreach ($estadoPagos['meses'] as $mes) {
                $columna = $x->letraColumna(4 + $mes['mes']);
                if ($mes['pagado']) {
                    $pagados++;
                    $x->celda($h, $columna, $fila, $mes['semana'], GeneradorXlsx::ESTILO_CELDA_POSITIVA);
                } elseif ($mes['vencido']) {
                    $x->celda($h, $columna, $fila, $mes['en_deuda'] ? 'Debe' : '', GeneradorXlsx::ESTILO_CELDA_NEGATIVA);
                } else {
                    $x->celda($h, $columna, $fila, '', GeneradorXlsx::ESTILO_CELDA_NEUTRA);
                }
            }
            $this->numero($x, $h, 17, $fila, $pagados, GeneradorXlsx::ESTILO_TOTAL_DESTACADO);
            $fila++;
        }

        $h2 = $x->agregarHoja('Deudores');
        $fila = $this->encabezado($x, $h2, 'Deudores de mensualidad', [
            "Año en curso {$anioActual}, calculado al {$hoy->format('Y-m-d')} con la regla vigente (RN-8.3: mes vencido, sin pago y con 2 o más asistencias). La matrícula no genera deuda.",
        ], ['N°', 'Documento', 'Nombre', 'Meses adeudados', 'Cantidad'], [6, 16, 34, 50, 10]);
        foreach ($deudores as $i => $deudor) {
            $this->numero($x, $h2, 0, $fila, $i + 1);
            $this->texto($x, $h2, 1, $fila, $deudor['documento']);
            $this->texto($x, $h2, 2, $fila, $deudor['nombre']);
            $this->texto($x, $h2, 3, $fila, implode(', ', array_map(fn (int $m): string => self::MESES[$m], $deudor['meses_en_deuda'])));
            $this->numero($x, $h2, 4, $fila, count($deudor['meses_en_deuda']), GeneradorXlsx::ESTILO_CELDA_NEGATIVA);
            $fila++;
        }
        return $this->xlsx($x, "pagos_{$anio}_{$hoy->format('Y-m-d')}.xlsx");
    }

    // ------------------------------------------------------------------
    // E) Torneos y medallería (xlsx)
    // ------------------------------------------------------------------

    /**
     * Participaciones en torneos realizados (?desde&hasta opcionales) con
     * HistorialService::torneos (fecha <= hoy, mismas validaciones) y hoja
     * de medallería con EstadisticaService::medallas. Sin notas_oponente.
     */
    public function torneos(array $parametros): array
    {
        $this->exigirAdministrativo();
        $filtros = array_intersect_key($parametros, ['desde' => true, 'hasta' => true]);
        $filas = (new HistorialService())->torneos($filtros);
        $medallas = EstadisticaService::medallas($filas);
        $rango = 'Rango: ' . ($filtros['desde'] ?? 'inicio') . ' a ' . ($filtros['hasta'] ?? 'hoy') . ' · solo torneos ya realizados';

        $x = $this->libroNuevo();
        $h = $x->agregarHoja('Participaciones');
        $titulos = ['Fecha', 'Torneo', 'Tipo', 'Plataforma', 'Lugar', 'Documento', 'Deportista', 'Modalidad', 'Categoría de edad', 'Categoría de peso', 'Puesto', 'Medalla', 'Combates ganados', 'Combates perdidos'];
        $fila = $this->encabezado($x, $h, 'Torneos y participaciones', [$rango], $titulos, [12, 30, 14, 14, 22, 16, 30, 20, 12, 12, 8, 10, 10, 10], GeneradorXlsx::ESTILO_COLUMNA_TITULO_AJUSTADO);
        foreach ($filas as $t) {
            $this->fecha($x, $h, 0, $fila, $t['fecha']);
            $this->texto($x, $h, 1, $fila, $t['torneo']);
            $this->texto($x, $h, 2, $fila, ucfirst($t['tipo_torneo']));
            $this->texto($x, $h, 3, $fila, $t['plataforma']);
            $this->texto($x, $h, 4, $fila, $t['lugar']);
            $this->texto($x, $h, 5, $fila, $t['deportista_documento']);
            $this->texto($x, $h, 6, $fila, $t['nombre']);
            $this->texto($x, $h, 7, $fila, str_replace('_', ' ', ucfirst($t['modalidad'])));
            $this->texto($x, $h, 8, $fila, ucfirst($t['categoria_edad']));
            $this->texto($x, $h, 9, $fila, $t['categoria_peso']);
            $this->numero($x, $h, 10, $fila, $t['puesto']);
            $this->texto($x, $h, 11, $fila, $t['medalla'] === null ? null : ucfirst($t['medalla']));
            $this->numero($x, $h, 12, $fila, $t['combates_ganados']);
            $this->numero($x, $h, 13, $fila, $t['combates_perdidos']);
            $fila++;
        }

        $h2 = $x->agregarHoja('Medallería');
        $fila = $this->encabezado($x, $h2, 'Resumen de medallería', [$rango . ' · «ninguna» no suma'], ['Tipo de torneo', 'Oro', 'Plata', 'Bronce', 'Total'], [20, 10, 10, 10, 10]);
        foreach ($medallas['por_tipo_torneo'] as $tipo => $conteo) {
            $this->texto($x, $h2, 0, $fila, ucfirst($tipo));
            $this->numero($x, $h2, 1, $fila, $conteo['oro']);
            $this->numero($x, $h2, 2, $fila, $conteo['plata']);
            $this->numero($x, $h2, 3, $fila, $conteo['bronce']);
            $this->numero($x, $h2, 4, $fila, array_sum($conteo), GeneradorXlsx::ESTILO_TOTAL_DESTACADO);
            $fila++;
        }
        $x->celda($h2, 'A', $fila, 'Total', GeneradorXlsx::ESTILO_TOTAL_POSITIVO);
        foreach (['oro', 'plata', 'bronce', 'total'] as $i => $clave) {
            $this->numero($x, $h2, 1 + $i, $fila, $medallas['totales'][$clave], GeneradorXlsx::ESTILO_TOTAL_POSITIVO);
        }
        return $this->xlsx($x, "torneos_{$this->hoy()}.xlsx");
    }

    // ------------------------------------------------------------------
    // F) Resultados de una sesión de examen (xlsx)
    // ------------------------------------------------------------------

    /**
     * Resultados de una sesión (?id=) con EvaluacionService::consultar.
     * Se permite una sesión ABIERTA (planilla de trabajo durante el examen),
     * marcada «EN CURSO»; el resultado general es PROVISIONAL.
     */
    public function sesionExamen(array $parametros): array
    {
        $this->exigirAdministrativo();
        $sesion = (new EvaluacionService())->consultar(['id' => $parametros['id'] ?? null]);
        if ($sesion === null) {
            throw new ExcepcionNegocio('Sesión de examen no encontrada.', 404);
        }

        $abierta = $sesion['estado'] === 'abierta';
        $x = $this->libroNuevo();
        $h = $x->agregarHoja('Resultados');
        $titulos = array_merge(['N°', 'Documento', 'Nombre', 'Grado actual', 'Grado evaluado'], array_values(self::NOMBRES_MODALIDAD), ['Promedio', 'Resultado general (PROVISIONAL)', 'Cinturón entregado']);
        $subtitulos = [
            "Sesión #{$sesion['id']} · Fecha: {$sesion['fecha']} · Fecha de grado: {$sesion['fecha_grado']} · Lugar: {$sesion['lugar']}",
            $abierta ? 'Estado: EN CURSO (sesión abierta: las notas pueden cambiar)' : 'Estado: cerrada',
            'PROVISIONAL (plantilla de examen de Aldrin): aprueba con promedio de las seis notas >= 5; en rojo las notas menores de 5.',
        ];
        $fila = $this->encabezado($x, $h, 'Resultados de la sesión de examen', $subtitulos, $titulos, array_merge([6, 16, 34, 16, 16], array_fill(0, 6, 11), [10, 18, 22]), GeneradorXlsx::ESTILO_COLUMNA_TITULO_AJUSTADO);
        foreach ($sesion['participantes'] as $i => $p) {
            $notas = array_column($p['modalidades'], 'nota', 'modalidad');
            $this->numero($x, $h, 0, $fila, $i + 1);
            $this->texto($x, $h, 1, $fila, $p['deportista_documento']);
            $this->texto($x, $h, 2, $fila, $p['nombre']);
            $this->texto($x, $h, 3, $fila, $p['grado_actual'] ?? 'Ingresado');
            $this->texto($x, $h, 4, $fila, $p['grado_evaluado']);
            foreach (EvaluacionService::MODALIDADES as $j => $modalidad) {
                $nota = $notas[$modalidad] ?? null;
                $estilo = $nota === null ? GeneradorXlsx::ESTILO_CELDA_NEUTRA
                    : ($nota < self::NOTA_MINIMA_MODALIDAD ? GeneradorXlsx::ESTILO_CELDA_NEGATIVA : GeneradorXlsx::ESTILO_CELDA_POSITIVA);
                $x->celda($h, $x->letraColumna(5 + $j), $fila, $nota === null ? '' : (float) $nota, $estilo);
            }
            $general = $p['resultado_general'];
            $this->numero($x, $h, 11, $fila, $general['promedio'], GeneradorXlsx::ESTILO_TOTAL_DESTACADO);
            [$resultado, $estilo] = match ($general['aprobado']) {
                true => ['Aprobado', GeneradorXlsx::ESTILO_CELDA_POSITIVA],
                false => ['No aprobado', GeneradorXlsx::ESTILO_CELDA_NEGATIVA],
                null => ['Incompleto', GeneradorXlsx::ESTILO_CELDA_NEUTRA],
            };
            $x->celda($h, 'M', $fila, $resultado, $estilo);
            $entrega = $p['entrega'];
            $x->celda($h, 'N', $fila, match (true) {
                $entrega === null => 'Sin registro',
                (bool) $entrega['cinturon_entregado'] => 'Sí, ' . ($entrega['fecha_entrega'] ?? 'sin fecha') . ($entrega['confirmado_por'] === null ? '' : " ({$entrega['confirmado_por']})"),
                default => 'No',
            }, GeneradorXlsx::ESTILO_CELDA_NEUTRA);
            $fila++;
        }
        return $this->xlsx($x, "sesion_examen_{$sesion['id']}_{$this->hoy()}.xlsx");
    }

    // ------------------------------------------------------------------
    // Secciones de la ficha
    // ------------------------------------------------------------------

    private function seccionExamen(?array $examen): array
    {
        $titulo = 'Último examen (resultado PROVISIONAL: plantilla de examen de Aldrin)';
        if ($examen === null) {
            return ['titulo' => $titulo, 'lista' => ['Sin exámenes en sesiones cerradas.']];
        }
        $general = $examen['resultado_general'];
        $resultado = match ($general['aprobado']) {
            true => 'Aprobado',
            false => 'No aprobado',
            null => 'Incompleto (faltan notas)',
        };
        return [
            'titulo' => $titulo,
            'pares' => [
                ['Fecha de grado', $examen['fecha_grado']],
                ['Grado evaluado', $examen['grado_evaluado']],
                ['Promedio', $general['promedio'] === null ? '—' : number_format($general['promedio'], 2)],
                ['Resultado general', $resultado],
            ],
            'tabla' => [
                'columnas' => [['Modalidad', 60], ['Nota', 30], ['Observación', 90]],
                'filas' => array_map(fn (array $m): array => [
                    self::NOMBRES_MODALIDAD[$m['modalidad']],
                    $m['nota'] === null ? '—' : number_format($m['nota'], 2),
                    $m['nota'] === null ? 'Sin calificar' : ($m['nota'] < self::NOTA_MINIMA_MODALIDAD ? 'Por mejorar (nota menor de 5)' : ''),
                ], $examen['modalidades']),
                'resaltar' => array_keys(array_filter($examen['modalidades'], fn (array $m): bool => $m['nota'] !== null && $m['nota'] < self::NOTA_MINIMA_MODALIDAD)),
            ],
        ];
    }

    /**
     * «Qué necesita mejorar» no es un campo: se deduce de las notas bajas,
     * los aspectos fallidos y las recomendaciones del último examen.
     */
    private function seccionPorMejorar(?array $examen): array
    {
        $lista = [];
        foreach ($examen['modalidades'] ?? [] as $m) {
            $nombre = self::NOMBRES_MODALIDAD[$m['modalidad']];
            if ($m['nota'] !== null && $m['nota'] < self::NOTA_MINIMA_MODALIDAD) {
                $lista[] = "{$nombre}: nota " . number_format($m['nota'], 2) . ' (menor de 5).';
            }
            if ($m['aspectos_fallidos'] !== null && trim($m['aspectos_fallidos']) !== '') {
                $lista[] = "{$nombre} — aspectos fallidos: {$m['aspectos_fallidos']}";
            }
            if ($m['recomendaciones'] !== null && trim($m['recomendaciones']) !== '') {
                $lista[] = "{$nombre} — recomendaciones: {$m['recomendaciones']}";
            }
        }
        return [
            'titulo' => 'Aspectos por mejorar y recomendaciones (último examen)',
            'lista' => $lista === [] ? ['Sin notas bajas, aspectos fallidos ni recomendaciones registradas.'] : $lista,
        ];
    }

    private function seccionTorneos(array $torneos): array
    {
        $titulo = 'Últimos torneos (hasta ' . self::ULTIMOS_TORNEOS_FICHA . ' participaciones más recientes)';
        if ($torneos === []) {
            return ['titulo' => $titulo, 'lista' => ['Sin participaciones en torneos realizados.']];
        }
        return [
            'titulo' => $titulo,
            'tabla' => [
                'columnas' => [['Fecha', 22], ['Torneo', 62], ['Modalidad', 38], ['Puesto (de N del club)', 34], ['Medalla', 24]],
                'filas' => array_map(fn (array $t): array => [
                    $t['fecha'],
                    $t['torneo'],
                    str_replace('_', ' ', ucfirst($t['modalidad'])),
                    $t['puesto'] === null ? '—' : $t['puesto'] . ($t['participantes_en_modalidad'] > 0 ? " de {$t['participantes_en_modalidad']}" : ''),
                    $t['medalla'] === null ? '—' : ucfirst($t['medalla']),
                ], $torneos),
                'resaltar' => [],
            ],
        ];
    }

    private function textoAsistencia(array $porcentaje): string
    {
        $base = "{$porcentaje['presentes']} de {$porcentaje['sesiones']} sesiones desde {$porcentaje['desde']}";
        return $porcentaje['datos_incompletos']
            ? "Datos incompletos ({$base})"
            : number_format($porcentaje['porcentaje'], 1) . " % ({$base})";
    }

    // ------------------------------------------------------------------
    // Apoyo de Excel
    // ------------------------------------------------------------------

    private function libroNuevo(): GeneradorXlsx
    {
        // Se carga solo al generar un Excel (api.php no lo incluye).
        require_once __DIR__ . '/../Utils/GeneradorXlsx.php';
        return new GeneradorXlsx();
    }

    /**
     * @return array{contenido: string, nombre: string, tipo: string}
     */
    private function xlsx(GeneradorXlsx $x, string $nombre): array
    {
        return ['contenido' => $x->generar(), 'nombre' => $nombre, 'tipo' => self::TIPO_XLSX];
    }

    /**
     * Título (con el nombre del club), subtítulos, fecha de generación y
     * títulos de columna. Devuelve la primera fila de datos.
     *
     * @param array<int, string> $subtitulos
     * @param array<int, string> $titulos
     * @param array<int, float|int> $anchos
     */
    private function encabezado(GeneradorXlsx $x, int $h, string $titulo, array $subtitulos, array $titulos, array $anchos, int $estiloTitulos = GeneradorXlsx::ESTILO_COLUMNA_TITULO): int
    {
        $ultima = $x->letraColumna(max(count($titulos), 1) - 1);
        $x->celda($h, 'A', 1, self::NOMBRE_CLUB . ' — ' . $titulo, GeneradorXlsx::ESTILO_ENCABEZADO_VERDE);
        $x->combinar($h, 'A1', "{$ultima}1");
        $x->altoFila($h, 1, 24);
        $fila = 2;
        foreach (array_merge($subtitulos, ['Generado el ' . $this->hoy() . ' (hora de Bogotá)']) as $subtitulo) {
            $x->celda($h, 'A', $fila, $subtitulo, GeneradorXlsx::ESTILO_SUBTITULO);
            $x->combinar($h, "A{$fila}", "{$ultima}{$fila}");
            $fila++;
        }
        $fila++;
        foreach ($titulos as $i => $t) {
            $columna = $x->letraColumna($i);
            $x->celda($h, $columna, $fila, $t, $estiloTitulos);
            $x->anchoColumna($h, $columna, (float) ($anchos[$i] ?? 12));
        }
        return $fila + 1;
    }

    /**
     * Texto que viene de la base de datos: siempre cadena literal con
     * quotePrefix (un "=..." nunca es fórmula), sin superar el límite de
     * una celda. null queda vacío.
     */
    private function texto(GeneradorXlsx $x, int $h, int $columna, int $fila, ?string $valor): void
    {
        $valor = $valor === null ? '' : mb_substr($valor, 0, self::MAXIMO_TEXTO_CELDA);
        $x->celda($h, $x->letraColumna($columna), $fila, $valor, GeneradorXlsx::ESTILO_CELDA_TEXTO);
    }

    private function numero(GeneradorXlsx $x, int $h, int $columna, int $fila, int|float|null $valor, int $estilo = GeneradorXlsx::ESTILO_CELDA_INDICE): void
    {
        $x->celda($h, $x->letraColumna($columna), $fila, $valor ?? '', $estilo);
    }

    private function fecha(GeneradorXlsx $x, int $h, int $columna, int $fila, ?string $valor): void
    {
        $x->celda($h, $x->letraColumna($columna), $fila, $valor ?? '', GeneradorXlsx::ESTILO_CELDA_INDICE);
    }

    /** "2026-03-07" => "Sáb 07/03/2026". */
    private function diaCorto(string $fecha): string
    {
        $d = new DateTimeImmutable($fecha);
        return self::DIAS[(int) $d->format('N')] . ' ' . $d->format('d/m/Y');
    }

    // ------------------------------------------------------------------
    // Validación y sesión
    // ------------------------------------------------------------------

    private function exigirAdministrativo(): void
    {
        $usuario = Sesion::usuarioActual();
        if ($usuario === null) {
            throw new ExcepcionNegocio('No autenticado. Inicie sesión para continuar.', 401);
        }
        if ($usuario['ambito'] !== 'administrativa') {
            throw new ExcepcionNegocio('Solo el ámbito administrativo puede descargar reportes.', 403);
        }
    }

    private function estado(array $parametros): ?string
    {
        if (!array_key_exists('estado', $parametros)) {
            return null;
        }
        $estado = $parametros['estado'];
        if (!is_string($estado) || !in_array($estado, self::ESTADOS, true)) {
            throw new ExcepcionNegocio('El parámetro "estado" debe ser activo o inactivo.', 400);
        }
        return $estado;
    }

    /**
     * desde/hasta AAAA-MM-DD (400 si mal formadas o desde > hasta).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function rango(array $parametros): array
    {
        $fechas = [];
        foreach (['desde', 'hasta'] as $campo) {
            $valor = $parametros[$campo] ?? null;
            if ($valor === null) {
                $fechas[] = null;
                continue;
            }
            $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
            if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
                throw new ExcepcionNegocio("El parámetro \"{$campo}\" debe ser una fecha válida con formato AAAA-MM-DD.", 400);
            }
            $fechas[] = $valor;
        }
        if ($fechas[0] !== null && $fechas[1] !== null && $fechas[0] > $fechas[1]) {
            throw new ExcepcionNegocio('El parámetro "desde" no puede ser posterior a "hasta".', 400);
        }
        return $fechas;
    }

    private function hoy(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone(self::ZONA_HORARIA)))->format('Y-m-d');
    }
}
