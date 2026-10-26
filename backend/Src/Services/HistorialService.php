<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del módulo Historial: pestaña «Registros» de la
 * pantalla «Estadísticas e historial» (Contexto Base §35.1 y §35.4). Módulo
 * de agregación de solo lectura: no tiene tabla propia.
 *
 *   - torneos: una fila por participación, solo torneos ya realizados
 *     (fecha <= hoy de Bogotá); el ámbito público solo ve eventos públicos.
 *   - ascensos: una fila por deportista y sesión CERRADA, con el resultado
 *     general, la entrega de cinturón y las seis modalidades en orden.
 *   - permanencia: fecha de ingreso y meses de mensualidad pagados.
 *   - El administrativo ve a cualquier deportista (404 si no existe) y puede
 *     listar sin filtrar; el ámbito público solo ve lo propio y cualquier
 *     otro documento responde 404 (no revela si existe).
 *   - notas_oponente nunca sale de este módulo. Sin paginación; tablas en
 *     orden descendente.
 */
class HistorialService
{
    private const ZONA_HORARIA = 'America/Bogota';
    private const ESTADOS = ['activo', 'inactivo'];

    private DeportistaRepository $deportista;
    private PagoRepository $pago;
    private DetalleEvaluacionRepository $detalleEvaluacion;
    private ParticipacionCompetenciaRepository $participacionCompetencia;

    public function __construct()
    {
        $this->deportista = new DeportistaRepository();
        $this->pago = new PagoRepository();
        $this->detalleEvaluacion = new DetalleEvaluacionRepository();
        $this->participacionCompetencia = new ParticipacionCompetenciaRepository();
    }

    /**
     * Registro de torneos: filtros deportista (solo administrativo), desde y
     * hasta sobre la fecha del torneo. Un "hasta" futuro se recorta a hoy.
     */
    public function torneos(array $parametros): array
    {
        $publico = $this->usuario()['ambito'] === 'publica';
        $documento = $this->documentoObjetivo($parametros);
        [$desde, $hasta] = $this->rango($parametros);
        $hoy = $this->hoy();
        $hasta = $hasta === null ? $hoy : min($hasta, $hoy);

        $filas = $this->participacionCompetencia->listarHistorial($documento, $desde, $hasta, $publico);
        return array_map(fn (array $fila): array => [
            'evento_id' => (int) $fila['evento_id'],
            'torneo' => $fila['torneo'],
            'fecha' => $fila['fecha'],
            'lugar' => $fila['lugar'],
            'tipo_torneo' => $fila['tipo_torneo'],
            'plataforma' => $fila['plataforma'],
            'deportista_documento' => (string) $fila['deportista_documento'],
            'nombre' => $fila['nombre'],
            'modalidad' => $fila['modalidad'],
            'categoria_edad' => $fila['categoria_edad'],
            'categoria_peso' => $fila['categoria_peso'],
            'puesto' => self::enteroONulo($fila['puesto']),
            'resultado' => $fila['resultado'],
            'medalla' => $fila['medalla'],
            'combates_ganados' => self::enteroONulo($fila['combates_ganados']),
            'combates_perdidos' => self::enteroONulo($fila['combates_perdidos']),
            'participantes_en_modalidad' => (int) $fila['participantes_en_modalidad'],
        ], $filas);
    }

    /**
     * Registro de ascensos: filtros deportista (solo administrativo), desde y
     * hasta sobre fecha_grado. Solo sesiones cerradas. Las filas de detalle
     * (una por modalidad) se agrupan aquí por (sesión, deportista), en el
     * orden descendente que ya trae la consulta.
     */
    public function ascensos(array $parametros): array
    {
        $documento = $this->documentoObjetivo($parametros);
        [$desde, $hasta] = $this->rango($parametros);

        $grupos = [];
        foreach ($this->detalleEvaluacion->listarDeSesionesCerradas($documento, $desde, $hasta) as $fila) {
            $llave = $fila['sesion_id'] . '|' . $fila['deportista_documento'];
            $grupos[$llave] ??= ['cabecera' => $fila, 'modalidades' => []];
            $grupos[$llave]['modalidades'][$fila['modalidad']] = $fila;
        }

        $resultado = [];
        foreach ($grupos as $grupo) {
            $cabecera = $grupo['cabecera'];
            $modalidades = [];
            $notas = [];
            // Solo las seis modalidades oficiales, en su orden; una que no
            // tenga fila sale con sus valores en null.
            foreach (EvaluacionService::MODALIDADES as $modalidad) {
                $fila = $grupo['modalidades'][$modalidad] ?? null;
                $notas[$modalidad] = $fila === null ? null : self::decimalONulo($fila['nota']);
                $modalidades[] = [
                    'modalidad' => $modalidad,
                    'nota' => $notas[$modalidad],
                    'aprobado' => $fila === null || $fila['aprobado'] === null ? null : (bool) $fila['aprobado'],
                    'valor_medible' => $fila === null ? null : self::decimalONulo($fila['valor_medible']),
                    'aspectos_fallidos' => $fila['aspectos_fallidos'] ?? null,
                    'observaciones' => $fila['observaciones'] ?? null,
                    'recomendaciones' => $fila['recomendaciones'] ?? null,
                ];
            }
            $general = EvaluacionService::resultadoGeneral($notas);
            $resultado[] = [
                'sesion_id' => (int) $cabecera['sesion_id'],
                'fecha' => $cabecera['fecha'],
                'fecha_grado' => $cabecera['fecha_grado'],
                'lugar' => $cabecera['lugar'],
                'deportista_documento' => (string) $cabecera['deportista_documento'],
                'nombre' => $cabecera['nombre'],
                'grado_evaluado' => $cabecera['grado_evaluado'],
                'resultado_general' => ['promedio' => $general['promedio'], 'aprobado' => $general['aprobado']],
                'entrega' => (int) $cabecera['tiene_entrega'] === 1 ? [
                    'cinturon_entregado' => (bool) $cabecera['cinturon_entregado'],
                    'fecha_entrega' => $cabecera['fecha_entrega'],
                    'confirmado_por' => $cabecera['confirmado_por'],
                ] : null,
                'modalidades' => $modalidades,
            ];
        }
        return $resultado;
    }

    /**
     * Permanencia: filtros deportista (solo administrativo) y estado
     * (activo/inactivo). meses_pagados cuenta los pares (año, mes) con
     * mensualidad, aunque haya meses sin pagar en medio; la matrícula no
     * cuenta. primer_mes_pagado y ultimo_mes_pagado en "AAAA-MM" o null.
     */
    public function permanencia(array $parametros): array
    {
        $documento = $this->documentoObjetivo($parametros);
        $estado = null;
        if (array_key_exists('estado', $parametros)) {
            $estado = $parametros['estado'];
            if (!is_string($estado) || !in_array($estado, self::ESTADOS, true)) {
                throw new ExcepcionNegocio('El parámetro "estado" debe ser activo o inactivo.', 400);
            }
        }

        return array_map(fn (array $fila): array => [
            'documento' => (string) $fila['documento'],
            'nombre' => $fila['nombre'],
            'estado' => $fila['estado'],
            'fecha_ingreso' => $fila['fecha_ingreso'],
            'meses_pagados' => (int) $fila['meses_pagados'],
            'primer_mes_pagado' => self::periodo($fila['primero']),
            'ultimo_mes_pagado' => self::periodo($fila['ultimo']),
        ], $this->pago->permanencia($documento, $estado));
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

    /**
     * Deportista sobre el que se consulta. Público: siempre el de la sesión;
     * si pide otro, 404. Administrativo: el del parámetro "deportista" (404
     * si no existe) o null para listar a todos.
     */
    private function documentoObjetivo(array $parametros): ?string
    {
        $usuario = $this->usuario();
        $documento = array_key_exists('deportista', $parametros)
            ? Validador::texto($parametros['deportista'], 'deportista', 20, true)
            : null;

        if ($usuario['ambito'] === 'publica') {
            if ($documento !== null && $documento !== $usuario['identificador']) {
                throw new ExcepcionNegocio('Deportista no encontrado.', 404);
            }
            return $usuario['identificador'];
        }
        if ($documento !== null && !$this->deportista->existePorId($documento)) {
            throw new ExcepcionNegocio('Deportista no encontrado.', 404);
        }
        return $documento;
    }

    /**
     * Filtros desde/hasta (AAAA-MM-DD, opcionales). 400 si están mal formados
     * o si desde es posterior a hasta.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function rango(array $parametros): array
    {
        $desde = array_key_exists('desde', $parametros) ? $this->fecha($parametros['desde'], 'desde') : null;
        $hasta = array_key_exists('hasta', $parametros) ? $this->fecha($parametros['hasta'], 'hasta') : null;
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            throw new ExcepcionNegocio('El parámetro "desde" no puede ser posterior a "hasta".', 400);
        }
        return [$desde, $hasta];
    }

    private function fecha(mixed $valor, string $campo): string
    {
        $fecha = Validador::esFechaIso($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            throw new ExcepcionNegocio("El parámetro \"{$campo}\" debe ser una fecha válida con formato AAAA-MM-DD.", 400);
        }
        return $valor;
    }

    private function hoy(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone(self::ZONA_HORARIA)))->format('Y-m-d');
    }

    private static function enteroONulo(mixed $valor): ?int
    {
        return $valor === null ? null : (int) $valor;
    }

    private static function decimalONulo(mixed $valor): ?float
    {
        return $valor === null ? null : (float) $valor;
    }

    /**
     * AAAA*100+MM (como lo agrega la consulta) => "AAAA-MM"; null si no hay.
     */
    private static function periodo(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $valor = (int) $valor;
        return sprintf('%04d-%02d', intdiv($valor, 100), $valor % 100);
    }
}
