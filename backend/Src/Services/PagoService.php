<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del módulo Pagos (RN-8.x; Contexto Base §35.11).
 *
 * Solo se guardan los pagos hechos (tabla `pago`); la deuda y los
 * vencimientos se calculan, nunca se guardan. Nunca se manejan montos
 * (RN-8.3, RN-8.5).
 *
 *   - Cada mes tiene 4 semanas: días 1–7, 8–14, 15–21 y 22–fin (RN-8.1).
 *   - Semana habitual: la del último pago de mensualidad, el de mayor (año,
 *     mes); quien nunca pagó, la semana de su fecha de ingreso. La
 *     mensualidad de un mes vence el primer día de esa semana en ese mes.
 *   - Deuda (RN-8.3): mes vencido, sin pago, con 2 o más asistencias desde
 *     el vencimiento dentro de su ciclo (hasta el vencimiento del mes
 *     siguiente, o hasta hoy).
 *   - Matrícula anual (RN-8.2): es solo un recordatorio, NO genera deuda ni
 *     cuenta para al_dia ni para deudores. Reset global en enero (se tiene
 *     todo enero); quien ingresa de marzo a noviembre la paga al ingresar;
 *     quien ingresa en diciembre no la debe ese año (CONFIRMADO). "atrasada"
 *     (sin pagar desde el 1 de febrero) es solo informativa.
 *   - Solo los deportistas activos y validados generan alertas y deuda.
 *
 * El cálculo vive en métodos públicos estáticos y puros (sin base de datos
 * ni HTTP, con "hoy" como parámetro) para reutilizarlo, por ejemplo, en el
 * Calendario. Todas las fechas son de America/Bogota.
 */
class PagoService
{
    private const ZONA_HORARIA = 'America/Bogota';
    private const TIPOS = ['mensualidad', 'matricula'];
    /** Primer día de cada semana del mes (RN-8.1). */
    private const INICIO_SEMANA = [1 => 1, 2 => 8, 3 => 15, 4 => 22];
    /** Días antes del vencimiento en que aparece el aviso «por vencer» (RN-8.3: entre 3 y 5). */
    private const MARGEN_POR_VENCER_DIAS = 5;
    /** Asistencias en el ciclo a partir de las cuales un mes vencido es deuda (RN-8.3). */
    private const MINIMO_ASISTENCIAS_DEUDA = 2;
    private const ANIO_MINIMO = 2020;
    private const CAMPOS_REGISTRAR = ['documento', 'tipo', 'anio', 'pagos'];

    private PagoRepository $pago;
    private DeportistaRepository $deportista;
    private AsistenciaRepository $asistencia;

    public function __construct()
    {
        $this->pago = new PagoRepository();
        $this->deportista = new DeportistaRepository();
        $this->asistencia = new AsistenciaRepository();
    }

    // ------------------------------------------------------------------
    // Acciones
    // ------------------------------------------------------------------

    /**
     * Registra pagos (solo administrativo), todo en una transacción:
     *   - mensualidad: {documento, tipo, anio, pagos: [{mes, semana}, ...]},
     *     uno o varios meses, incluidos futuros (pago adelantado).
     *   - matricula: {documento, tipo, anio}, sin pagos ni semana (mes = 0).
     * Un mes ya pagado: 409 sin guardar nada del lote.
     *
     * @return array<int, array<string, mixed>> las filas creadas
     */
    public function registrar(array $datos): array
    {
        $this->exigirAdministrativo('registrar pagos');
        $sobrantes = array_diff(array_keys($datos), self::CAMPOS_REGISTRAR);
        if ($sobrantes !== []) {
            throw new ExcepcionNegocio('Campos no permitidos: ' . implode(', ', $sobrantes) . '.', 400);
        }

        $documento = $this->documento($datos['documento'] ?? null);
        $tipo = $datos['tipo'] ?? null;
        if (!is_string($tipo) || !in_array($tipo, self::TIPOS, true)) {
            throw new ExcepcionNegocio('El campo "tipo" debe ser mensualidad o matricula.', 400);
        }
        $anio = $this->entero($datos['anio'] ?? null, 'anio', self::ANIO_MINIMO, (int) $this->hoy()->format('Y') + 1);

        $deportista = $this->deportista->obtenerPorId($documento);
        if ($deportista === null) {
            throw new ExcepcionNegocio('Deportista no encontrado.', 404);
        }
        if ($deportista['estado_registro'] !== 'validado') {
            throw new ExcepcionNegocio('El deportista está pendiente de validación: aún no se le registran pagos.', 400);
        }

        $nuevos = $tipo === 'matricula'
            ? $this->periodosMatricula($datos)
            : $this->periodosMensualidad($datos['pagos'] ?? null);

        $yaPagados = array_column($this->pago->listar(['deportista_documento' => $documento, 'tipo' => $tipo, 'anio' => $anio]), 'mes');
        $repetidos = array_intersect(array_column($nuevos, 'mes'), array_map('intval', $yaPagados));
        if ($repetidos !== []) {
            throw new ExcepcionNegocio(
                $tipo === 'matricula'
                    ? "La matrícula de {$anio} ya está pagada."
                    : "Ya están pagados los meses " . implode(', ', $repetidos) . " de {$anio}. No se guardó ningún pago.",
                409
            );
        }

        try {
            $ids = $this->pago->enTransaccion(function () use ($nuevos, $documento, $tipo, $anio): array {
                $ids = [];
                foreach ($nuevos as $nuevo) {
                    $ids[] = $this->pago->crear(new PagoModel(
                        deportistaDocumento: $documento,
                        tipo: $tipo,
                        anio: $anio,
                        mes: $nuevo['mes'],
                        semana: $nuevo['semana'],
                    ));
                }
                return $ids;
            });
        } catch (PDOException $error) {
            // Otra petición registró el mismo mes al mismo tiempo (llave única).
            if ((string) $error->getCode() === '23000') {
                throw new ExcepcionNegocio('Uno de los meses ya fue pagado. No se guardó ningún pago.', 409);
            }
            throw $error;
        }

        return array_map(fn (int $id): array => $this->formatear($this->pago->obtenerPorId($id)), $ids);
    }

    /**
     * Anula (borra) un pago registrado por error (solo administrativo; la
     * doble confirmación es del front). El mes vuelve a quedar sin pagar.
     */
    public function anular(array $parametros): void
    {
        $this->exigirAdministrativo('anular pagos');
        $id = $this->idDe($parametros);
        if ($this->pago->obtenerPorId($id) === null) {
            throw new ExcepcionNegocio('Pago no encontrado.', 404);
        }
        $this->pago->eliminar($id);
    }

    /**
     * Estado de pagos de un deportista en un año (?documento=&anio=, por
     * defecto el actual). El público solo el suyo; uno ajeno responde 404.
     */
    public function estado(array $parametros): array
    {
        $documento = $this->documentoVisible($parametros);
        $hoy = $this->hoy();
        $anio = array_key_exists('anio', $parametros)
            ? $this->entero($parametros['anio'], 'anio', self::ANIO_MINIMO, (int) $hoy->format('Y') + 1)
            : (int) $hoy->format('Y');

        $deportista = $this->deportista->obtenerPorId($documento);
        if ($deportista === null) {
            throw new ExcepcionNegocio('Deportista no encontrado.', 404);
        }

        $pagos = $this->pago->listar(['deportista_documento' => $documento]);
        $semana = self::semanaHabitual($pagos, $deportista['fecha_ingreso']);
        $fechas = array_column(array_filter(
            $this->asistencia->listarFiltrado($documento, "{$anio}-01-01", ($anio + 1) . '-01-31'),
            fn (array $fila): bool => (bool) $fila['asistio']
        ), 'fecha');

        return self::calcularEstado($deportista, $anio, $pagos, $semana, $fechas, $hoy);
    }

    /**
     * Deudores del año en curso (solo administrativo): activos y validados
     * con al menos un mes de mensualidad en deuda (la matrícula no cuenta),
     * por nombre y documento. Cuatro consultas en total, sin una por
     * deportista.
     *
     * @return array<int, array<string, mixed>>
     */
    public function deudores(): array
    {
        $this->exigirAdministrativo('ver la lista de deudores');
        $hoy = $this->hoy();
        $anio = (int) $hoy->format('Y');

        $pagosPorDeportista = [];
        foreach ($this->pago->listar(['anio' => $anio]) as $fila) {
            $pagosPorDeportista[(string) $fila['deportista_documento']][] = $fila;
        }
        $semanas = $this->pago->semanasDelUltimoPago();
        $fechas = $this->asistencia->fechasPresentesEnRango("{$anio}-01-01", $hoy->format('Y-m-d'));

        $deudores = [];
        foreach ($this->deportista->listar(['estado' => 'activo', 'estado_registro' => 'validado']) as $deportista) {
            $documento = (string) $deportista['documento'];
            $estado = self::calcularEstado(
                $deportista,
                $anio,
                $pagosPorDeportista[$documento] ?? [],
                $semanas[$documento] ?? self::semanaDelMes($deportista['fecha_ingreso']),
                $fechas[$documento] ?? [],
                $hoy
            );
            if ($estado['meses_en_deuda'] !== []) {
                $deudores[] = [
                    'documento' => $documento,
                    'nombre' => $deportista['nombre'],
                    'meses_en_deuda' => $estado['meses_en_deuda'],
                ];
            }
        }
        return $deudores;
    }

    /**
     * Pagos con filtros opcionales documento, anio y tipo. El público solo
     * ve los suyos (sin documento usa el de su sesión; uno ajeno: 404).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listar(array $parametros): array
    {
        $filtros = [];
        $usuario = $this->usuario();
        if ($usuario['ambito'] === 'publica' || array_key_exists('documento', $parametros)) {
            $filtros['deportista_documento'] = $this->documentoVisible($parametros);
        }
        if (array_key_exists('anio', $parametros)) {
            $filtros['anio'] = $this->entero($parametros['anio'], 'anio', self::ANIO_MINIMO, 9999);
        }
        if (array_key_exists('tipo', $parametros)) {
            if (!is_string($parametros['tipo']) || !in_array($parametros['tipo'], self::TIPOS, true)) {
                throw new ExcepcionNegocio('El parámetro "tipo" debe ser mensualidad o matricula.', 400);
            }
            $filtros['tipo'] = $parametros['tipo'];
        }
        return array_map(fn (array $fila): array => $this->formatear($fila), $this->pago->listar($filtros));
    }

    /**
     * Un pago por ?id=, o null si no existe o es ajeno al público (404).
     */
    public function consultar(array $parametros): ?array
    {
        $usuario = $this->usuario();
        $fila = $this->pago->obtenerPorId($this->idDe($parametros));
        if ($fila === null || ($usuario['ambito'] !== 'administrativa' && (string) $fila['deportista_documento'] !== $usuario['identificador'])) {
            return null;
        }
        return $this->formatear($fila);
    }

    // ------------------------------------------------------------------
    // Cálculo puro y reutilizable (sin base de datos ni HTTP)
    // ------------------------------------------------------------------

    /**
     * Semana del mes de una fecha AAAA-MM-DD: 1–7 → 1, 8–14 → 2, 15–21 → 3,
     * 22–fin → 4 (RN-8.1).
     */
    public static function semanaDelMes(string $fecha): int
    {
        $dia = (int) substr($fecha, 8, 2);
        return min(4, intdiv($dia - 1, 7) + 1);
    }

    /**
     * Vencimiento de la mensualidad de un mes: el primer día de la semana
     * habitual en ese mes (día 1, 8, 15 o 22).
     */
    public static function vencimiento(int $anio, int $mes, int $semanaHabitual): string
    {
        return sprintf('%04d-%02d-%02d', $anio, $mes, self::INICIO_SEMANA[$semanaHabitual]);
    }

    /**
     * Semana habitual: la del pago de mensualidad de mayor (año, mes); si
     * nunca pagó, la de su fecha de ingreso.
     *
     * @param array<int, array<string, mixed>> $pagos filas de pago del deportista (cualquier año y tipo)
     */
    public static function semanaHabitual(array $pagos, string $fechaIngreso): int
    {
        $ultimo = null;
        foreach ($pagos as $pago) {
            if ($pago['tipo'] !== 'mensualidad') {
                continue;
            }
            $periodo = (int) $pago['anio'] * 100 + (int) $pago['mes'];
            if ($ultimo === null || $periodo > $ultimo[0]) {
                $ultimo = [$periodo, (int) $pago['semana']];
            }
        }
        return $ultimo[1] ?? self::semanaDelMes($fechaIngreso);
    }

    /**
     * Estado de pagos de un deportista en un año, calculado con "hoy".
     *
     * @param array<string, mixed> $deportista fila de deportista (documento, fecha_ingreso, estado, estado_registro)
     * @param array<int, array<string, mixed>> $pagos pagos del deportista (al menos los de $anio y $anio + 1)
     * @param array<int, string> $fechasPresentes fechas con asistio = 1 (al menos de $anio hasta enero siguiente)
     */
    public static function calcularEstado(array $deportista, int $anio, array $pagos, int $semanaHabitual, array $fechasPresentes, DateTimeImmutable $hoy): array
    {
        $hoyTexto = $hoy->format('Y-m-d');
        $generaAlertas = $deportista['estado'] === 'activo' && $deportista['estado_registro'] === 'validado';

        $mensualidades = [];
        $matriculas = [];
        foreach ($pagos as $pago) {
            if ($pago['tipo'] === 'mensualidad') {
                $mensualidades[(int) $pago['anio']][(int) $pago['mes']] = (int) $pago['semana'];
            } else {
                $matriculas[(int) $pago['anio']] = true;
            }
        }

        $meses = [];
        $enDeuda = [];
        for ($mes = 1; $mes <= 12; $mes++) {
            $vencimiento = self::vencimiento($anio, $mes, $semanaHabitual);
            $pagado = isset($mensualidades[$anio][$mes]);
            $vencido = !$pagado && self::esEvaluable($deportista['fecha_ingreso'], $anio, $mes) && $vencimiento < $hoyTexto;

            $deuda = false;
            if ($vencido && $generaAlertas) {
                [$anioSiguiente, $mesSiguiente] = $mes === 12 ? [$anio + 1, 1] : [$anio, $mes + 1];
                $finCiclo = self::vencimiento($anioSiguiente, $mesSiguiente, $semanaHabitual);
                $asistencias = count(array_filter(
                    $fechasPresentes,
                    fn (string $fecha): bool => $fecha >= $vencimiento && $fecha < $finCiclo && $fecha <= $hoyTexto
                ));
                $deuda = $asistencias >= self::MINIMO_ASISTENCIAS_DEUDA;
            }
            if ($deuda) {
                $enDeuda[] = $mes;
            }

            $meses[] = [
                'mes' => $mes,
                'pagado' => $pagado,
                'semana' => $mensualidades[$anio][$mes] ?? null,
                'vencimiento' => $vencimiento,
                'vencido' => $vencido,
                'en_deuda' => $deuda,
            ];
        }

        // Matrícula: recordatorio, no deuda. pendiente y atrasada son avisos,
        // así que solo salen para quien genera alertas (activo y validado).
        $matriculaPagada = isset($matriculas[$anio]);
        $matriculaPendiente = $generaAlertas && !$matriculaPagada && self::matriculaCuenta($deportista['fecha_ingreso'], $anio);
        $matriculaAtrasada = $matriculaPendiente && $hoyTexto >= "{$anio}-02-01";

        return [
            'documento' => (string) $deportista['documento'],
            'anio' => $anio,
            'semana_habitual' => $semanaHabitual,
            'matricula' => ['pagada' => $matriculaPagada, 'pendiente' => $matriculaPendiente, 'atrasada' => $matriculaAtrasada],
            'meses' => $meses,
            'meses_en_deuda' => $enDeuda,
            'por_vencer' => $generaAlertas ? self::porVencer($deportista['fecha_ingreso'], $anio, $mensualidades, $semanaHabitual, $hoy) : null,
            'al_dia' => $enDeuda === [],
            'genera_alertas' => $generaAlertas,
        ];
    }

    /**
     * Un mes cuenta solo si el deportista ya había ingresado: desde el mes de
     * ingreso (incluido, porque se paga al ingresar).
     */
    public static function esEvaluable(string $fechaIngreso, int $anio, int $mes): bool
    {
        return $anio * 100 + $mes >= (int) substr($fechaIngreso, 0, 4) * 100 + (int) substr($fechaIngreso, 5, 2);
    }

    /**
     * Mensualidades sin pagar cuyo vencimiento cae entre $desde y $hasta
     * (incluidos), con la semana habitual dada. Solo meses evaluables (desde
     * el ingreso). La usa el Calendario para mostrar vencimientos.
     *
     * @param array<int, array<string, mixed>> $pagos pagos del deportista que cubran los años del rango
     * @return array<int, array{anio: int, mes: int, vencimiento: string}>
     */
    public static function vencimientosPendientes(string $fechaIngreso, int $semanaHabitual, array $pagos, string $desde, string $hasta): array
    {
        $pagados = [];
        foreach ($pagos as $pago) {
            if ($pago['tipo'] === 'mensualidad') {
                $pagados[(int) $pago['anio'] * 100 + (int) $pago['mes']] = true;
            }
        }

        $resultado = [];
        $mes = new DateTimeImmutable(substr($desde, 0, 7) . '-01');
        $ultimo = substr($hasta, 0, 7) . '-01';
        while ($mes->format('Y-m-d') <= $ultimo) {
            [$anio, $numero] = [(int) $mes->format('Y'), (int) $mes->format('n')];
            $vencimiento = self::vencimiento($anio, $numero, $semanaHabitual);
            if ($vencimiento >= $desde && $vencimiento <= $hasta
                && !isset($pagados[$anio * 100 + $numero])
                && self::esEvaluable($fechaIngreso, $anio, $numero)) {
                $resultado[] = ['anio' => $anio, 'mes' => $numero, 'vencimiento' => $vencimiento];
            }
            $mes = $mes->modify('+1 month');
        }
        return $resultado;
    }

    /**
     * ¿Debe el deportista la matrícula de $anio? Único lugar de esta regla,
     * para cambiarla fácil:
     *   - No, si ingresó después de ese año.
     *   - No, si ingresó en diciembre de ese año (CONFIRMADO por John
     *     (21/10/2026)): paga la mensualidad y la matrícula se le pide en el
     *     reset de enero.
     *   - Sí en los demás casos (reset global de enero; quien ingresa de
     *     marzo a noviembre la paga al ingresar).
     */
    public static function matriculaCuenta(string $fechaIngreso, int $anio): bool
    {
        $anioIngreso = (int) substr($fechaIngreso, 0, 4);
        if ($anioIngreso > $anio) {
            return false;
        }
        return !($anioIngreso === $anio && (int) substr($fechaIngreso, 5, 2) === 12);
    }

    /**
     * Aviso «por vencer»: el siguiente mes sin pago (del año y, para el cruce
     * de diciembre a enero, el enero siguiente) cuyo vencimiento está entre
     * hoy y hoy + margen. null si no hay.
     *
     * @param array<int, array<int, int>> $mensualidades [anio][mes] => semana pagada
     */
    private static function porVencer(string $fechaIngreso, int $anio, array $mensualidades, int $semanaHabitual, DateTimeImmutable $hoy): ?array
    {
        $hoyTexto = $hoy->format('Y-m-d');
        $candidatos = array_map(fn (int $mes): array => [$anio, $mes], range(1, 12));
        $candidatos[] = [$anio + 1, 1];

        foreach ($candidatos as [$anioMes, $mes]) {
            if (isset($mensualidades[$anioMes][$mes]) || !self::esEvaluable($fechaIngreso, $anioMes, $mes)) {
                continue;
            }
            $vencimiento = self::vencimiento($anioMes, $mes, $semanaHabitual);
            if ($vencimiento < $hoyTexto) {
                continue;
            }
            $dias = (int) $hoy->diff(new DateTimeImmutable($vencimiento, $hoy->getTimezone()))->days;
            if ($dias > self::MARGEN_POR_VENCER_DIAS) {
                return null;
            }
            return ['mes' => $mes, 'anio' => $anioMes, 'vencimiento' => $vencimiento, 'dias_restantes' => $dias];
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Permisos y validación de datos de entrada
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

    private function exigirAdministrativo(string $accion): void
    {
        if ($this->usuario()['ambito'] !== 'administrativa') {
            throw new ExcepcionNegocio("Solo el ámbito administrativo puede {$accion}.", 403);
        }
    }

    /**
     * Documento a consultar: el público solo el suyo (sin ?documento= usa el
     * de la sesión; uno ajeno, 404); el administrativo debe indicarlo.
     */
    private function documentoVisible(array $parametros): string
    {
        $usuario = $this->usuario();
        $documento = array_key_exists('documento', $parametros) ? $this->documento($parametros['documento']) : null;
        if ($usuario['ambito'] === 'publica') {
            $documento ??= $usuario['identificador'];
            if ($documento !== $usuario['identificador']) {
                throw new ExcepcionNegocio('Deportista no encontrado.', 404);
            }
        } elseif ($documento === null) {
            throw new ExcepcionNegocio('El parámetro "documento" es obligatorio.', 400);
        }
        return $documento;
    }

    /**
     * @return array<int, array{mes: int, semana: int}>
     */
    private function periodosMensualidad(mixed $pagos): array
    {
        if (!is_array($pagos) || $pagos === [] || !array_is_list($pagos)) {
            throw new ExcepcionNegocio('El campo "pagos" debe ser una lista de {mes, semana} con al menos un mes.', 400);
        }
        $periodos = [];
        foreach ($pagos as $pago) {
            if (!is_array($pago) || array_diff(array_keys($pago), ['mes', 'semana']) !== []) {
                throw new ExcepcionNegocio('Cada pago debe tener solo "mes" y "semana".', 400);
            }
            $periodos[] = [
                'mes' => $this->entero($pago['mes'] ?? null, 'mes', 1, 12),
                'semana' => $this->entero($pago['semana'] ?? null, 'semana', 1, 4),
            ];
        }
        $meses = array_column($periodos, 'mes');
        if (count($meses) !== count(array_unique($meses))) {
            throw new ExcepcionNegocio('Hay meses repetidos en el mismo registro.', 400);
        }
        return $periodos;
    }

    /**
     * La matrícula es una sola por año: mes 0 y sin semana.
     *
     * @return array<int, array{mes: int, semana: null}>
     */
    private function periodosMatricula(array $datos): array
    {
        if (($datos['pagos'] ?? null) !== null) {
            throw new ExcepcionNegocio('La matrícula no lleva "pagos" ni semana.', 400);
        }
        return [['mes' => 0, 'semana' => null]];
    }

    /**
     * Entero (o texto de dígitos, como en la URL) dentro de [min, max].
     */
    private function entero(mixed $valor, string $campo, int $minimo, int $maximo): int
    {
        if (is_string($valor) && ctype_digit($valor)) {
            $valor = (int) $valor;
        }
        if (!is_int($valor) || $valor < $minimo || $valor > $maximo) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser un número entero entre {$minimo} y {$maximo}.", 400);
        }
        return $valor;
    }

    private function idDe(array $parametros): int
    {
        return $this->entero($parametros['id'] ?? null, 'id', 1, PHP_INT_MAX);
    }

    private function documento(mixed $valor): string
    {
        return Validador::texto($valor, 'documento', 20, true);
    }

    private function hoy(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone(self::ZONA_HORARIA));
    }

    private function formatear(array $fila): array
    {
        return PagoModel::fromArray($fila)->toArray();
    }
}
