<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del módulo Evaluaciones y ascensos (RN-5.x;
 * Contexto Base §35.8).
 *
 * El CONTENIDO del examen depende de la plantilla oficial de Aldrin, que no
 * ha llegado: todo lo que depende de ella está marcado "PROVISIONAL (plantilla
 * de Aldrin)" y aislado en constantes o métodos estáticos puros. Los campos
 * criterios_evaluados, puntajes, aspectos_fallidos, observaciones y
 * recomendaciones son texto libre que digita el staff.
 *
 *   - Sesión con fecha de inicio y fecha de grado, ligada a DOS eventos de
 *     calendario públicos (evaluacion y grado). Abierta mientras se califica
 *     por etapas; cerrada = protegida contra toda escritura (409).
 *   - Al inscribir se generan siempre las seis modalidades pendientes y la
 *     fila de entrega de cinturón.
 *   - Nota 1 a 10; completado si y solo si tiene nota; aprobado por modalidad
 *     solo informativo. Resultado general PROVISIONAL: promedio de las seis.
 *   - Elegibilidad (RN-5.2, RN-5.6): solo avisos en la lista de candidatos.
 *   - Entrega de cinturón (RN-5.7): con sesión cerrada y resultado aprobado,
 *     la confirma el administrativo o el propio deportista; en la misma
 *     transacción grado_actual pasa a ser el grado evaluado.
 *   - Ámbito público: solo sus sesiones y solo su propio participante.
 */
class EvaluacionService
{
    private const ZONA_HORARIA = 'America/Bogota';

    /**
     * Grados en orden (lista fija, sin tabla; descripción de John, RN-5.3).
     * Para agregar Negro por dan, se añaden al final. PROVISIONAL (plantilla de Aldrin).
     */
    private const GRADOS = [
        'Ingresado', 'Blanco', 'Amarillo', 'Naranja', 'Verde', 'Azul', 'Rojo',
        'Violeta', 'Marrón', 'Marrón franja negra', 'Negro',
    ];
    /** Las seis modalidades: aplican a todos y se generan siempre. PROVISIONAL (plantilla de Aldrin). */
    /** Orden canónico de las modalidades de examen; público para Historial y Estadística. */
    public const MODALIDADES = ['teorico', 'fisico', 'combate', 'rompimiento', 'formas', 'llaves'];
    /** Nota mínima para marcar aprobada una modalidad (informativo). PROVISIONAL (plantilla de Aldrin). */
    private const NOTA_MINIMA_MODALIDAD = 5;
    /** Promedio mínimo del resultado general (mitad de la escala). PROVISIONAL (plantilla de Aldrin). */
    private const PROMEDIO_MINIMO_APROBAR = 5;
    private const NOTA_MINIMA = 1;
    private const NOTA_MAXIMA = 10;
    private const VALOR_MEDIBLE_MAXIMO = 9999.99;
    /** Umbrales de inasistencia (RN-5.2): 30 % hasta 16 años, 40 % desde 17. PROVISIONAL (plantilla de Aldrin). */
    private const UMBRAL_INASISTENCIA_MENOR = 30;
    private const UMBRAL_INASISTENCIA_MAYOR = 40;
    private const EDAD_UMBRAL_MAYOR = 17;
    private const MAXIMO_PARTICIPANTES_LOTE = 50;
    private const MAXIMO_LUGAR = 150;
    private const CAMPOS_CALIFICAR = ['nota', 'valor_medible', 'criterios_evaluados', 'puntajes', 'aspectos_fallidos', 'observaciones', 'recomendaciones', 'fecha_completado'];

    private SesionExamenRepository $sesion;
    private DetalleEvaluacionRepository $detalle;
    private EntregaCinturonRepository $entrega;
    private EventoCalendarioRepository $evento;
    private DeportistaRepository $deportista;
    private AsistenciaRepository $asistencia;

    public function __construct()
    {
        $this->sesion = new SesionExamenRepository();
        $this->detalle = new DetalleEvaluacionRepository();
        $this->entrega = new EntregaCinturonRepository();
        $this->evento = new EventoCalendarioRepository();
        $this->deportista = new DeportistaRepository();
        $this->asistencia = new AsistenciaRepository();
    }

    // ------------------------------------------------------------------
    // Candidatos (elegibilidad: solo avisos)
    // ------------------------------------------------------------------

    /**
     * Lista de candidatos para una sesión (?sesion_id=) o para una fecha de
     * inicio (?fecha=, antes de crear la sesión): deportistas activos y
     * validados con grado, grado siguiente, edad a la fecha de inicio, último
     * ascenso, % de inasistencia en la ventana, umbral, apto y avisos. Nunca
     * bloquea. Consultas constantes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidatos(array $parametros): array
    {
        $this->exigirAdministrativo('ver candidatos');
        $tieneSesion = array_key_exists('sesion_id', $parametros);
        if ($tieneSesion === array_key_exists('fecha', $parametros)) {
            throw new ExcepcionNegocio('Indica "sesion_id" o "fecha" (solo uno).', 400);
        }
        $exceptoId = 0;
        if ($tieneSesion) {
            $sesion = $this->sesionExistente($this->entero($parametros['sesion_id'], 'sesion_id', 1, PHP_INT_MAX));
            $fecha = $sesion['fecha'];
            $exceptoId = (int) $sesion['id'];
        } else {
            $fecha = $this->fecha($parametros['fecha'], 'fecha');
        }

        $anterior = $this->sesion->sesionAnterior($fecha, $exceptoId);
        $vispera = (new DateTimeImmutable($fecha))->modify('-1 day')->format('Y-m-d');
        $filasAsistencia = [];
        foreach ($this->asistencia->listarFiltrado(null, $anterior['fecha_grado'] ?? null, $vispera) as $fila) {
            $filasAsistencia[(string) $fila['deportista_documento']][] = $fila;
        }
        $ultimasEntregas = $this->entrega->ultimasEntregas();

        $candidatos = [];
        foreach ($this->deportista->listar(['estado' => 'activo', 'estado_registro' => 'validado']) as $deportista) {
            $documento = (string) $deportista['documento'];
            $edad = DeportistaService::edadEnFecha($deportista['fecha_nacimiento'], $fecha);
            $inicio = $anterior === null ? $deportista['fecha_ingreso'] : max($anterior['fecha_grado'], $deportista['fecha_ingreso']);
            $enVentana = array_filter($filasAsistencia[$documento] ?? [], fn (array $f): bool => $f['fecha'] >= $inicio);
            $eleg = self::elegibilidad(count($enVentana), count(array_filter($enVentana, fn (array $f): bool => !(bool) $f['asistio'])), $edad);

            $avisos = $eleg['avisos'];
            if ($anterior !== null && $deportista['fecha_ingreso'] > $anterior['fecha']) {
                $avisos[] = "No completó el ciclo anterior: ingresó después de la sesión del {$anterior['fecha']}.";
            }
            $candidatos[] = [
                'documento' => $documento,
                'nombre' => $deportista['nombre'],
                'grado_actual' => $deportista['grado_actual'],
                'grado_siguiente' => self::gradoSiguiente($deportista['grado_actual']),
                'edad' => $edad,
                'fecha_ultimo_ascenso' => $ultimasEntregas[$documento] ?? null,
                'ventana_desde' => $inicio,
                'sesiones_en_ventana' => count($enVentana),
                'porcentaje_inasistencia' => $eleg['porcentaje'],
                'umbral' => $eleg['umbral'],
                'apto' => $eleg['apto'],
                'avisos' => $avisos,
            ];
        }
        return $candidatos;
    }

    // ------------------------------------------------------------------
    // Sesiones
    // ------------------------------------------------------------------

    /**
     * Crea una sesión (solo administrativo) con sus dos eventos de calendario
     * (evaluacion en la fecha de inicio y grado en la fecha de grado,
     * públicos) y, por participante, las seis modalidades pendientes y la fila
     * de entrega. Todo en una transacción: si algo falla, nada queda.
     */
    public function crear(array $datos): array
    {
        $this->exigirAdministrativo('crear sesiones de examen');
        $this->rechazarCamposNoPermitidos($datos, ['fecha', 'fecha_grado', 'lugar', 'participantes']);
        [$fecha, $fechaGrado] = $this->fechasSesion($datos['fecha'] ?? null, $datos['fecha_grado'] ?? null);
        $lugar = Validador::texto($datos['lugar'] ?? null, 'lugar', self::MAXIMO_LUGAR, true);
        $participantes = $this->prepararParticipantes($datos['participantes'] ?? [], null);

        $nombreExamen = "Exámenes de ascenso {$fecha}";
        $nombreGrado = "Grado {$fechaGrado}";
        if ($this->evento->existeNombreFecha($nombreExamen, $fecha) || $this->evento->existeNombreFecha($nombreGrado, $fechaGrado)) {
            throw new ExcepcionNegocio('Ya hay en el calendario un evento de exámenes o de grado con ese nombre y fecha.', 409);
        }

        $id = $this->sesion->enTransaccion(function () use ($fecha, $fechaGrado, $lugar, $participantes, $nombreExamen, $nombreGrado): int {
            $idExamen = $this->evento->crear(new EventoCalendarioModel(nombre: $nombreExamen, tipo: 'evaluacion', fecha: $fecha, lugar: $lugar, visibilidad: 'publica'));
            $idGrado = $this->evento->crear(new EventoCalendarioModel(nombre: $nombreGrado, tipo: 'grado', fecha: $fechaGrado, lugar: $lugar, visibilidad: 'publica'));
            $id = $this->sesion->crear(new SesionExamenModel(
                fecha: $fecha, fechaGrado: $fechaGrado, lugar: $lugar, estado: 'abierta', eventoExamenId: $idExamen, eventoGradoId: $idGrado,
            ));
            $this->insertarParticipantes($id, $participantes);
            return $id;
        });
        return $this->consultar(['id' => (string) $id]);
    }

    /**
     * Inscribe participantes en una sesión abierta (?sesion_id=, máximo 50),
     * todo o nada.
     */
    public function inscribir(array $parametros, array $datos): array
    {
        $this->exigirAdministrativo('inscribir participantes');
        $sesion = $this->sesionAbierta($this->entero($parametros['sesion_id'] ?? null, 'sesion_id', 1, PHP_INT_MAX));
        $this->rechazarCamposNoPermitidos($datos, ['participantes']);
        $lista = $datos['participantes'] ?? null;
        if (!is_array($lista) || $lista === []) {
            throw new ExcepcionNegocio('El campo "participantes" debe ser una lista con al menos un participante.', 400);
        }
        $participantes = $this->prepararParticipantes($lista, (int) $sesion['id']);
        $this->detalle->enTransaccion(fn () => $this->insertarParticipantes((int) $sesion['id'], $participantes));
        return $this->consultar(['id' => (string) $sesion['id']]);
    }

    /**
     * Sesiones con filtros opcionales estado, desde, hasta (fecha de inicio) y
     * deportista, por fecha descendente, con conteos. El público solo ve las
     * sesiones donde participa y sin conteos de otros.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listar(array $parametros): array
    {
        $usuario = $this->usuario();
        $esPublico = $usuario['ambito'] !== 'administrativa';
        $deportista = array_key_exists('deportista', $parametros) ? Validador::texto($parametros['deportista'], 'deportista', 20, true) : null;
        if ($esPublico) {
            if ($deportista !== null && $deportista !== $usuario['identificador']) {
                throw new ExcepcionNegocio('Deportista no encontrado.', 404);
            }
            $deportista = $usuario['identificador'];
        }
        $estado = array_key_exists('estado', $parametros) ? $this->opcion($parametros['estado'], 'estado', ['abierta', 'cerrada']) : null;
        $desde = array_key_exists('desde', $parametros) ? $this->fecha($parametros['desde'], 'desde') : null;
        $hasta = array_key_exists('hasta', $parametros) ? $this->fecha($parametros['hasta'], 'hasta') : null;

        return array_map(function (array $fila) use ($esPublico): array {
            $sesion = $this->formatearSesion($fila);
            if (!$esPublico) {
                $sesion += [
                    'num_participantes' => (int) $fila['num_participantes'],
                    'num_completados' => (int) $fila['num_completados'],
                    'total_modalidades' => (int) $fila['total_modalidades'],
                ];
            }
            return $sesion;
        }, $this->sesion->listarSesiones($estado, $desde, $hasta, $deportista));
    }

    /**
     * Sesión (?id=) con sus participantes: grado actual, grado evaluado, las
     * seis modalidades con todo su detalle, resultado general y entrega. El
     * público solo su propio participante; si no participa, null (404).
     */
    public function consultar(array $parametros): ?array
    {
        $usuario = $this->usuario();
        $fila = $this->sesion->obtenerPorId($this->entero($parametros['id'] ?? null, 'id', 1, PHP_INT_MAX));
        if ($fila === null) {
            return null;
        }
        $soloDe = $usuario['ambito'] === 'administrativa' ? null : $usuario['identificador'];
        $filas = $this->detalle->listarDeSesion((int) $fila['id'], $soloDe);
        if ($soloDe !== null && $filas === []) {
            return null;
        }
        $entregas = [];
        foreach ($this->entrega->listar(['sesion_examen_id' => (int) $fila['id']]) as $e) {
            $entregas[(string) $e['deportista_documento']] = $e;
        }
        return $this->formatearSesion($fila) + ['participantes' => $this->agruparParticipantes($filas, $entregas)];
    }

    /**
     * Cambia fecha, fecha_grado y lugar de una sesión abierta y, en la misma
     * transacción, la fecha y el lugar de sus dos eventos de calendario.
     */
    public function actualizar(array $parametros, array $datos): array
    {
        $this->exigirAdministrativo('actualizar sesiones de examen');
        $sesion = $this->sesionAbierta($this->entero($parametros['id'] ?? null, 'id', 1, PHP_INT_MAX));
        if ($datos === []) {
            throw new ExcepcionNegocio('No se recibió ningún campo para actualizar.', 400);
        }
        $this->rechazarCamposNoPermitidos($datos, ['fecha', 'fecha_grado', 'lugar']);
        [$fecha, $fechaGrado] = $this->fechasSesion($datos['fecha'] ?? $sesion['fecha'], $datos['fecha_grado'] ?? $sesion['fecha_grado']);
        $lugar = array_key_exists('lugar', $datos) ? Validador::texto($datos['lugar'], 'lugar', self::MAXIMO_LUGAR, true) : $sesion['lugar'];

        $eventos = [];
        foreach ([[$sesion['evento_examen_id'], $fecha], [$sesion['evento_grado_id'], $fechaGrado]] as [$idEvento, $fechaEvento]) {
            $filaEvento = $idEvento === null ? null : $this->evento->obtenerPorId((int) $idEvento);
            if ($filaEvento === null) {
                continue;
            }
            if ($this->evento->existeNombreFecha($filaEvento['nombre'], $fechaEvento, (int) $idEvento)) {
                throw new ExcepcionNegocio("Ya hay en el calendario otro evento «{$filaEvento['nombre']}» en {$fechaEvento}.", 409);
            }
            $modeloEvento = EventoCalendarioModel::fromArray($filaEvento);
            $modeloEvento->establecerFecha($fechaEvento);
            $modeloEvento->establecerLugar($lugar);
            $eventos[] = $modeloEvento;
        }

        $modelo = SesionExamenModel::fromArray($sesion);
        $modelo->establecerFecha($fecha);
        $modelo->establecerFechaGrado($fechaGrado);
        $modelo->establecerLugar($lugar);
        $this->sesion->enTransaccion(function () use ($modelo, $eventos): void {
            $this->sesion->actualizar($modelo);
            foreach ($eventos as $modeloEvento) {
                $this->evento->actualizar($modeloEvento);
            }
        });
        return $this->consultar(['id' => (string) $modelo->obtenerId()]);
    }

    /**
     * Elimina una sesión abierta sin ninguna modalidad completada, con sus
     * filas y sus dos eventos de calendario.
     */
    public function eliminar(array $parametros): void
    {
        $this->exigirAdministrativo('eliminar sesiones de examen');
        $sesion = $this->sesionAbierta($this->entero($parametros['id'] ?? null, 'id', 1, PHP_INT_MAX));
        $id = (int) $sesion['id'];
        if ($this->detalle->contar(['sesion_examen_id' => $id, 'estado' => 'completado']) > 0) {
            throw new ExcepcionNegocio('La sesión tiene modalidades calificadas: no se puede eliminar (solo modificar o reabrir).', 409);
        }
        $this->sesion->enTransaccion(function () use ($id, $sesion): void {
            $this->detalle->eliminarDeSesion($id);
            $this->entrega->eliminarDeSesion($id);
            $this->sesion->eliminar($id);
            foreach ([$sesion['evento_examen_id'], $sesion['evento_grado_id']] as $idEvento) {
                if ($idEvento !== null) {
                    $this->evento->eliminar((int) $idEvento);
                }
            }
        });
    }

    /**
     * Cierra la sesión: exige al menos un participante y las seis modalidades
     * de todos completadas (409 indicando quiénes faltan).
     */
    public function cerrar(array $parametros): array
    {
        $this->exigirAdministrativo('cerrar sesiones de examen');
        $sesion = $this->sesionAbierta($this->entero($parametros['id'] ?? null, 'id', 1, PHP_INT_MAX));
        $porParticipante = $this->notasPorParticipante($this->detalle->listarDeSesion((int) $sesion['id']));
        if ($porParticipante === []) {
            throw new ExcepcionNegocio('La sesión no tiene participantes: no se puede cerrar.', 409);
        }
        $faltan = array_keys(array_filter($porParticipante, fn (array $notas): bool => !self::resultadoGeneral($notas)['completo']));
        if ($faltan !== []) {
            throw new ExcepcionNegocio('Faltan modalidades por calificar de: ' . implode(', ', $faltan) . '.', 409);
        }
        $this->cambiarEstado($sesion, 'cerrada');
        return $this->consultar(['id' => (string) $sesion['id']]);
    }

    /**
     * Reabre una sesión cerrada. Si alguien ya tiene la entrega confirmada,
     * lo advierte (no bloquea; el grado se corrige a mano en Deportistas).
     */
    public function reabrir(array $parametros): array
    {
        $this->exigirAdministrativo('reabrir sesiones de examen');
        $sesion = $this->sesionExistente($this->entero($parametros['id'] ?? null, 'id', 1, PHP_INT_MAX));
        if ($sesion['estado'] !== 'cerrada') {
            throw new ExcepcionNegocio('La sesión ya está abierta.', 409);
        }
        $this->cambiarEstado($sesion, 'abierta');
        $conEntrega = array_column(
            $this->entrega->listar(['sesion_examen_id' => (int) $sesion['id'], 'cinturon_entregado' => true]),
            'deportista_documento'
        );
        $advertencias = array_map(
            fn (mixed $doc): string => "El deportista {$doc} ya tiene la entrega de cinturón confirmada: si cambia su resultado, corrige su grado a mano en Deportistas.",
            $conEntrega
        );
        return $this->consultar(['id' => (string) $sesion['id']]) + ['advertencias' => $advertencias];
    }

    // ------------------------------------------------------------------
    // Participantes y calificación
    // ------------------------------------------------------------------

    /**
     * Cambia solo el grado_evaluado de un participante (las seis filas).
     */
    public function actualizarParticipante(array $parametros, array $datos): array
    {
        $this->exigirAdministrativo('actualizar participantes');
        $sesion = $this->sesionAbierta($this->entero($parametros['sesion_id'] ?? null, 'sesion_id', 1, PHP_INT_MAX));
        $documento = Validador::texto($parametros['deportista_documento'] ?? null, 'deportista_documento', 20, true);
        $this->exigirParticipante((int) $sesion['id'], $documento);
        $this->rechazarCamposNoPermitidos($datos, ['grado_evaluado'], 'Solo se edita grado_evaluado');
        $grado = self::gradoCanonico($datos['grado_evaluado'] ?? null);
        if ($grado === null) {
            throw new ExcepcionNegocio('El campo "grado_evaluado" debe ser uno de: ' . implode(', ', self::GRADOS) . '.', 400);
        }
        $this->detalle->actualizarGradoEvaluado((int) $sesion['id'], $documento, $grado);
        return $this->participante((int) $sesion['id'], $documento);
    }

    /**
     * Califica una modalidad (?sesion_id=&deportista_documento=&modalidad=).
     * Parcial: lo no enviado no se toca; null explícito limpia. El estado y
     * el aprobado se derivan de la nota; fecha_completado por defecto es hoy.
     */
    public function calificar(array $parametros, array $datos): array
    {
        $this->exigirAdministrativo('calificar modalidades');
        $sesion = $this->sesionAbierta($this->entero($parametros['sesion_id'] ?? null, 'sesion_id', 1, PHP_INT_MAX));
        $documento = Validador::texto($parametros['deportista_documento'] ?? null, 'deportista_documento', 20, true);
        $modalidad = $this->opcion($parametros['modalidad'] ?? null, 'modalidad', self::MODALIDADES);
        $fila = $this->detalle->obtenerPorId((int) $sesion['id'], $documento, $modalidad);
        if ($fila === null) {
            throw new ExcepcionNegocio('El deportista no tiene esa modalidad en esta sesión.', 404);
        }
        $hoy = $this->hoy();
        if ($sesion['fecha'] > $hoy) {
            throw new ExcepcionNegocio('La sesión todavía no ha empezado: no se puede calificar antes de la fecha de inicio.', 422);
        }
        if ($datos === []) {
            throw new ExcepcionNegocio('No se recibió ningún campo para actualizar.', 400);
        }
        $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_CALIFICAR);

        $modelo = DetalleEvaluacionModel::fromArray($fila);
        if (array_key_exists('nota', $datos)) {
            $modelo->establecerNota($this->decimal($datos['nota'], 'nota', self::NOTA_MINIMA, self::NOTA_MAXIMA));
        }
        if (array_key_exists('valor_medible', $datos)) {
            $modelo->establecerValorMedible($this->decimal($datos['valor_medible'], 'valor_medible', 0, self::VALOR_MEDIBLE_MAXIMO));
        }
        foreach (['criterios_evaluados' => 'establecerCriteriosEvaluados', 'puntajes' => 'establecerPuntajes', 'aspectos_fallidos' => 'establecerAspectosFallidos',
            'observaciones' => 'establecerObservaciones', 'recomendaciones' => 'establecerRecomendaciones'] as $campo => $metodo) {
            if (array_key_exists($campo, $datos)) {
                $modelo->{$metodo}($this->textoLibre($datos[$campo], $campo));
            }
        }

        // Regla 4: completado si y solo si hay nota.
        if ($modelo->obtenerNota() === null) {
            $modelo->establecerEstado('pendiente');
            $modelo->establecerAprobado(null);
            $modelo->establecerFechaCompletado(null);
        } else {
            $modelo->establecerEstado('completado');
            $modelo->establecerAprobado($modelo->obtenerNota() >= self::NOTA_MINIMA_MODALIDAD);
            if (array_key_exists('fecha_completado', $datos) && $datos['fecha_completado'] !== null) {
                $fechaCompletado = $this->fecha($datos['fecha_completado'], 'fecha_completado');
                if ($fechaCompletado > $hoy || $fechaCompletado < $sesion['fecha']) {
                    throw new ExcepcionNegocio('"fecha_completado" no puede ser futura ni anterior a la fecha de inicio de la sesión.', 400);
                }
                $modelo->establecerFechaCompletado($fechaCompletado);
            } elseif ($modelo->obtenerFechaCompletado() === null || array_key_exists('fecha_completado', $datos)) {
                $modelo->establecerFechaCompletado($hoy);
            }
        }

        $this->detalle->actualizar($modelo);
        return $this->participante((int) $sesion['id'], $documento);
    }

    /**
     * Quita a un participante de una sesión abierta (detalle y entrega en una
     * transacción), solo si no tiene modalidades completadas.
     */
    public function quitarParticipante(array $parametros): void
    {
        $this->exigirAdministrativo('quitar participantes');
        $sesion = $this->sesionAbierta($this->entero($parametros['sesion_id'] ?? null, 'sesion_id', 1, PHP_INT_MAX));
        $documento = Validador::texto($parametros['deportista_documento'] ?? null, 'deportista_documento', 20, true);
        $this->exigirParticipante((int) $sesion['id'], $documento);
        if ($this->detalle->contar(['sesion_examen_id' => (int) $sesion['id'], 'deportista_documento' => $documento, 'estado' => 'completado']) > 0) {
            throw new ExcepcionNegocio('El participante ya tiene modalidades calificadas: no se puede quitar.', 409);
        }
        $this->detalle->enTransaccion(function () use ($sesion, $documento): void {
            $this->detalle->eliminarParticipante((int) $sesion['id'], $documento);
            $this->entrega->eliminar((int) $sesion['id'], $documento);
        });
    }

    // ------------------------------------------------------------------
    // Entrega de cinturón (RN-5.7)
    // ------------------------------------------------------------------

    /**
     * Confirma la entrega física del cinturón (administrativo o el propio
     * deportista). Exige sesión cerrada y resultado aprobado. En UNA
     * transacción, con la fila bloqueada (FOR UPDATE): marca la entrega y
     * actualiza deportista.grado_actual al grado evaluado. La fecha es la de
     * grado, salvo que el administrativo envíe otra.
     */
    public function confirmarEntregaCinturon(array $parametros, array $datos): array
    {
        $usuario = $this->usuario();
        $sesionId = $this->entero($parametros['sesion_id'] ?? null, 'sesion_id', 1, PHP_INT_MAX);
        $documento = Validador::texto($parametros['deportista_documento'] ?? null, 'deportista_documento', 20, true);
        $esAdministrativo = $usuario['ambito'] === 'administrativa';
        if (!$esAdministrativo && $documento !== $usuario['identificador']) {
            throw new ExcepcionNegocio('Solo puedes confirmar la entrega de tu propio cinturón.', 403);
        }
        $sesion = $this->sesionExistente($sesionId);
        $filas = $this->detalle->listarDeSesion($sesionId, $documento);
        if ($filas === []) {
            throw new ExcepcionNegocio('El deportista no participa en esta sesión.', 404);
        }

        $fechaEntrega = $sesion['fecha_grado'];
        if ($esAdministrativo && array_key_exists('fecha_entrega', $datos) && $datos['fecha_entrega'] !== null) {
            $fechaEntrega = $this->fecha($datos['fecha_entrega'], 'fecha_entrega');
        }
        if ($fechaEntrega > $this->hoy()) {
            throw new ExcepcionNegocio('La fecha de entrega no puede ser futura.', 422);
        }

        try {
            $this->entrega->enTransaccion(function () use ($sesion, $sesionId, $documento, $fechaEntrega, $esAdministrativo): void {
                if ($this->sesion->obtenerPorId($sesionId)['estado'] !== 'cerrada') {
                    throw new ExcepcionNegocio('La entrega se confirma cuando la sesión está cerrada.', 409);
                }
                $notas = $this->notasPorParticipante($this->detalle->listarDeSesion($sesionId, $documento))[$documento] ?? [];
                if (self::resultadoGeneral($notas)['aprobado'] !== true) {
                    throw new ExcepcionNegocio('El deportista no aprobó el examen: no hay cinturón que entregar.', 409);
                }
                $fila = $this->entrega->obtenerParaActualizar($sesionId, $documento);
                if ($fila === null) {
                    $this->entrega->crear(new EntregaCinturonModel(sesionExamenId: $sesionId, deportistaDocumento: $documento));
                    $fila = $this->entrega->obtenerParaActualizar($sesionId, $documento);
                }
                if ((bool) $fila['cinturon_entregado']) {
                    throw new ExcepcionNegocio('La entrega de este cinturón ya fue confirmada.', 409);
                }
                $modelo = EntregaCinturonModel::fromArray($fila);
                $modelo->establecerCinturonEntregado(true);
                $modelo->establecerFechaEntrega($fechaEntrega);
                $modelo->establecerConfirmadoPor($esAdministrativo ? 'administrativo' : 'deportista');
                $this->entrega->actualizar($modelo);
                $grado = $this->detalle->listarDeSesion($sesionId, $documento)[0]['grado_evaluado'];
                $this->deportista->actualizarGradoActual($documento, $grado);
            });
        } catch (PDOException $error) {
            // Otra confirmación creó la fila de entrega al mismo tiempo.
            if ((string) $error->getCode() === '23000') {
                throw new ExcepcionNegocio('La entrega de este cinturón ya fue confirmada.', 409);
            }
            throw $error;
        }

        $entrega = $this->entrega->obtenerPorId($sesionId, $documento);
        return $this->formatearEntrega($entrega) + [
            'sesion_id' => $sesionId,
            'deportista_documento' => $documento,
            'grado_actual' => $this->deportista->obtenerPorId($documento)['grado_actual'],
        ];
    }

    // ------------------------------------------------------------------
    // Reglas puras: PROVISIONAL (plantilla de Aldrin)
    // ------------------------------------------------------------------

    /**
     * Nombre canónico de un grado de la lista, sin distinguir mayúsculas ni
     * tildes ("amarillo" -> "Amarillo"); null si no está en la lista.
     */
    public static function gradoCanonico(mixed $grado): ?string
    {
        if (!is_string($grado) || trim($grado) === '') {
            return null;
        }
        $buscado = self::normalizar($grado);
        foreach (self::GRADOS as $canonico) {
            if (self::normalizar($canonico) === $buscado) {
                return $canonico;
            }
        }
        return null;
    }

    /**
     * Grado siguiente: null o "Ingresado" -> "Blanco"; último grado -> null;
     * grado fuera de la lista -> null (sin sugerencia, no error).
     */
    public static function gradoSiguiente(?string $gradoActual): ?string
    {
        if ($gradoActual === null || trim($gradoActual) === '') {
            return self::GRADOS[1];
        }
        $canonico = self::gradoCanonico($gradoActual);
        if ($canonico === null) {
            return null;
        }
        $posicion = array_search($canonico, self::GRADOS, true);
        return self::GRADOS[$posicion + 1] ?? null;
    }

    /**
     * Resultado general PROVISIONAL (plantilla de Aldrin): exige las seis
     * modalidades con nota; promedio de las seis a 2 decimales; aprueba con
     * promedio >= 5. No exige aprobar cada modalidad.
     *
     * @param array<string, ?float> $notas modalidad => nota (o null)
     * @return array{completo: bool, promedio: ?float, aprobado: ?bool}
     */
    public static function resultadoGeneral(array $notas): array
    {
        $suma = 0.0;
        foreach (self::MODALIDADES as $modalidad) {
            if (!isset($notas[$modalidad])) {
                return ['completo' => false, 'promedio' => null, 'aprobado' => null];
            }
            $suma += $notas[$modalidad];
        }
        $promedio = round($suma / count(self::MODALIDADES), 2);
        return ['completo' => true, 'promedio' => $promedio, 'aprobado' => $promedio >= self::PROMEDIO_MINIMO_APROBAR];
    }

    /**
     * Elegibilidad por inasistencia (RN-5.2), solo aviso. PROVISIONAL
     * (plantilla de Aldrin): umbral 30 % hasta 16 años y 40 % desde 17; apto si
     * el porcentaje no supera el umbral (la comparación usa los conteos, sin
     * redondeo). Sin fechas registradas: porcentaje y apto null.
     *
     * @return array{porcentaje: ?float, umbral: int, apto: ?bool, avisos: array<int, string>}
     */
    public static function elegibilidad(int $sesiones, int $ausencias, int $edad): array
    {
        $umbral = $edad >= self::EDAD_UMBRAL_MAYOR ? self::UMBRAL_INASISTENCIA_MAYOR : self::UMBRAL_INASISTENCIA_MENOR;
        if ($sesiones === 0) {
            return ['porcentaje' => null, 'umbral' => $umbral, 'apto' => null, 'avisos' => ['Sin datos de asistencia en la ventana.']];
        }
        $porcentaje = round($ausencias * 100 / $sesiones, 2);
        $apto = $ausencias * 100 <= $umbral * $sesiones;
        $avisos = $apto ? [] : ["Inasistencia de {$porcentaje} % supera el umbral de {$umbral} %."];
        return ['porcentaje' => $porcentaje, 'umbral' => $umbral, 'apto' => $apto, 'avisos' => $avisos];
    }

    private static function normalizar(string $texto): string
    {
        return strtr(mb_strtolower(trim($texto)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    }

    // ------------------------------------------------------------------
    // Apoyo
    // ------------------------------------------------------------------

    /**
     * Valida una lista de participantes [{deportista_documento,
     * grado_evaluado?}]: deportista existente (404), grado de la lista (400) o
     * sugerido (obligatorio si no hay sugerencia), sin repetidos en el lote
     * ni ya inscritos (409).
     *
     * @return array<int, array{documento: string, grado: string}>
     */
    private function prepararParticipantes(mixed $lista, ?int $sesionId): array
    {
        if (!is_array($lista) || !array_is_list($lista)) {
            throw new ExcepcionNegocio('El campo "participantes" debe ser una lista.', 400);
        }
        if (count($lista) > self::MAXIMO_PARTICIPANTES_LOTE) {
            throw new ExcepcionNegocio('Máximo 50 participantes por operación.', 400);
        }
        $resultado = [];
        foreach ($lista as $participante) {
            if (!is_array($participante)) {
                throw new ExcepcionNegocio('Cada participante debe ser un objeto.', 400);
            }
            $this->rechazarCamposNoPermitidos($participante, ['deportista_documento', 'grado_evaluado']);
            $documento = Validador::texto($participante['deportista_documento'] ?? null, 'deportista_documento', 20, true);
            $deportista = $this->deportista->obtenerPorId($documento);
            if ($deportista === null) {
                throw new ExcepcionNegocio("Deportista {$documento} no encontrado.", 404);
            }
            if (isset($resultado[$documento]) || ($sesionId !== null && $this->detalle->contar(['sesion_examen_id' => $sesionId, 'deportista_documento' => $documento]) > 0)) {
                throw new ExcepcionNegocio("El deportista {$documento} ya está inscrito en la sesión.", 409);
            }
            if (array_key_exists('grado_evaluado', $participante)) {
                $grado = self::gradoCanonico($participante['grado_evaluado']);
                if ($grado === null) {
                    throw new ExcepcionNegocio('El campo "grado_evaluado" debe ser uno de: ' . implode(', ', self::GRADOS) . '.', 400);
                }
            } else {
                $grado = self::gradoSiguiente($deportista['grado_actual']);
                if ($grado === null) {
                    throw new ExcepcionNegocio("No hay grado siguiente sugerido para {$documento}: envía grado_evaluado.", 400);
                }
            }
            $resultado[$documento] = ['documento' => $documento, 'grado' => $grado];
        }
        return array_values($resultado);
    }

    /**
     * Por participante: las seis filas de detalle pendientes y la fila de
     * entrega (sin entregar). Se llama dentro de una transacción.
     *
     * @param array<int, array{documento: string, grado: string}> $participantes
     */
    private function insertarParticipantes(int $sesionId, array $participantes): void
    {
        foreach ($participantes as $participante) {
            foreach (self::MODALIDADES as $modalidad) {
                $this->detalle->crear(new DetalleEvaluacionModel(
                    sesionExamenId: $sesionId,
                    deportistaDocumento: $participante['documento'],
                    modalidad: $modalidad,
                    gradoEvaluado: $participante['grado'],
                    estado: 'pendiente',
                ));
            }
            $this->entrega->crear(new EntregaCinturonModel(sesionExamenId: $sesionId, deportistaDocumento: $participante['documento']));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $filas
     * @return array<string, array<string, ?float>> documento => [modalidad => nota]
     */
    private function notasPorParticipante(array $filas): array
    {
        $resultado = [];
        foreach ($filas as $fila) {
            $resultado[(string) $fila['deportista_documento']][$fila['modalidad']] = $fila['nota'] === null ? null : (float) $fila['nota'];
        }
        return $resultado;
    }

    /**
     * @param array<int, array<string, mixed>> $filas
     * @param array<string, array<string, mixed>> $entregas
     * @return array<int, array<string, mixed>>
     */
    private function agruparParticipantes(array $filas, array $entregas): array
    {
        $porDocumento = [];
        foreach ($filas as $fila) {
            $porDocumento[(string) $fila['deportista_documento']][] = $fila;
        }
        $participantes = [];
        foreach ($porDocumento as $documento => $propias) {
            $documento = (string) $documento;
            usort($propias, fn (array $a, array $b): int => $this->ordenModalidad($a['modalidad']) <=> $this->ordenModalidad($b['modalidad']));
            $participantes[] = [
                'deportista_documento' => $documento,
                'nombre' => $propias[0]['nombre'],
                'grado_actual' => $propias[0]['grado_actual'],
                'grado_evaluado' => $propias[0]['grado_evaluado'],
                'modalidades' => array_map(fn (array $f): array => $this->formatearModalidad($f), $propias),
                'resultado_general' => self::resultadoGeneral($this->notasPorParticipante($propias)[$documento]),
                'entrega' => isset($entregas[$documento]) ? $this->formatearEntrega($entregas[$documento]) : null,
            ];
        }
        return $participantes;
    }

    private function participante(int $sesionId, string $documento): array
    {
        $entrega = $this->entrega->obtenerPorId($sesionId, $documento);
        return $this->agruparParticipantes(
            $this->detalle->listarDeSesion($sesionId, $documento),
            $entrega === null ? [] : [$documento => $entrega]
        )[0];
    }

    private function ordenModalidad(string $modalidad): int
    {
        $posicion = array_search($modalidad, self::MODALIDADES, true);
        return $posicion === false ? 99 : $posicion;
    }

    private function formatearSesion(array $fila): array
    {
        return SesionExamenModel::fromArray($fila)->toArray();
    }

    private function formatearModalidad(array $fila): array
    {
        $datos = DetalleEvaluacionModel::fromArray($fila)->toArray();
        unset($datos['sesion_examen_id'], $datos['deportista_documento'], $datos['grado_evaluado']);
        return $datos;
    }

    private function formatearEntrega(array $fila): array
    {
        $datos = EntregaCinturonModel::fromArray($fila)->toArray();
        return [
            'cinturon_entregado' => $datos['cinturon_entregado'],
            'fecha_entrega' => $datos['fecha_entrega'],
            'confirmado_por' => $datos['confirmado_por'],
        ];
    }

    private function cambiarEstado(array $sesion, string $estado): void
    {
        $modelo = SesionExamenModel::fromArray($sesion);
        $modelo->establecerEstado($estado);
        $this->sesion->actualizar($modelo);
    }

    /**
     * @return array<string, mixed>
     */
    private function sesionExistente(int $id): array
    {
        $sesion = $this->sesion->obtenerPorId($id);
        if ($sesion === null) {
            throw new ExcepcionNegocio('Sesión de examen no encontrada.', 404);
        }
        return $sesion;
    }

    /**
     * Sesión existente y abierta: una sesión cerrada está protegida contra
     * cualquier escritura (409).
     *
     * @return array<string, mixed>
     */
    private function sesionAbierta(int $id): array
    {
        $sesion = $this->sesionExistente($id);
        if ($sesion['estado'] !== 'abierta') {
            throw new ExcepcionNegocio('La sesión está cerrada: reábrela para modificarla.', 409);
        }
        return $sesion;
    }

    private function exigirParticipante(int $sesionId, string $documento): void
    {
        if ($this->detalle->contar(['sesion_examen_id' => $sesionId, 'deportista_documento' => $documento]) === 0) {
            throw new ExcepcionNegocio('El deportista no participa en esta sesión.', 404);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function fechasSesion(mixed $fecha, mixed $fechaGrado): array
    {
        $fecha = $this->fecha($fecha, 'fecha');
        $fechaGrado = $this->fecha($fechaGrado, 'fecha_grado');
        if ($fechaGrado < $fecha) {
            throw new ExcepcionNegocio('La fecha de grado no puede ser anterior a la fecha de inicio de los exámenes.', 422);
        }
        return [$fecha, $fechaGrado];
    }

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

    private function hoy(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone(self::ZONA_HORARIA)))->format('Y-m-d');
    }

    private function fecha(mixed $valor, string $campo): string
    {
        $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser una fecha válida con formato AAAA-MM-DD.", 400);
        }
        return $valor;
    }

    /**
     * Número con máximo 2 decimales dentro de [min, max], o null.
     */
    private function decimal(mixed $valor, string $campo, float $minimo, float $maximo): ?float
    {
        if ($valor === null) {
            return null;
        }
        if (!is_int($valor) && !is_float($valor)) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser un número.", 400);
        }
        $numero = (float) $valor;
        if ($numero < $minimo || $numero > $maximo || abs(round($numero, 2) - $numero) > 1e-9) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe estar entre {$minimo} y {$maximo}, con máximo 2 decimales.", 400);
        }
        return round($numero, 2);
    }

    private function textoLibre(mixed $valor, string $campo): ?string
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }
        if (!is_string($valor)) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser texto.", 400);
        }
        return trim($valor);
    }

    /**
     * @param array<int, string> $opciones
     */
    private function opcion(mixed $valor, string $campo, array $opciones): string
    {
        if (!is_string($valor) || !in_array($valor, $opciones, true)) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser uno de: " . implode(', ', $opciones) . '.', 400);
        }
        return $valor;
    }

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

    /**
     * @param array<int, string> $permitidos
     */
    private function rechazarCamposNoPermitidos(array $datos, array $permitidos, string $mensaje = 'Campos no permitidos'): void
    {
        $sobrantes = array_diff(array_keys($datos), $permitidos);
        if ($sobrantes !== []) {
            throw new ExcepcionNegocio($mensaje . ' (' . implode(', ', $sobrantes) . ').', 400);
        }
    }
}
