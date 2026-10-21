<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del módulo Asistencia (RN-7.x, RN-1.11; Contexto
 * Base §35.10).
 *
 *   - Solo se registra asistencia en sábado o domingo, de hoy hacia atrás
 *     (RN-7.4). «Hoy» es la fecha de Bogotá, no la del servidor (RN-7.7).
 *   - Solo presente o ausente (RN-7.5). Tomar una fecha crea una fila por
 *     cada deportista activo, presente o ausente (RN-7.6), en una transacción.
 *   - Reactivación automática (RN-1.11): un inactivo marcado presente (al
 *     tomar o al corregir) vuelve a activo. La desactivación NO es
 *     automática: la dispara el botón «Revisar inactivos» (3 meses sin
 *     asistir, o desde el ingreso si nunca asistió).
 *   - Los registros pendientes de validación no entran en la asistencia.
 *   - Porcentaje sobre las fechas con filas, dentro de la ventana y desde el
 *     ingreso; con pocas sesiones, «datos incompletos» (RN-7.8).
 *   - Ámbito público: solo consulta su propia asistencia y su porcentaje
 *     (RN-7.9); lo ajeno responde 404.
 */
class AsistenciaService
{
    private const ZONA_HORARIA = 'America/Bogota';
    /** Días ISO-8601 permitidos: 6 = sábado, 7 = domingo (RN-7.4). */
    private const DIAS_ENTRENAMIENTO = [6, 7];
    /** Ventanas del porcentaje, en días; 'todo' = desde el ingreso. */
    private const VENTANAS = ['30' => 30, '90' => 90, 'todo' => null];
    private const VENTANA_POR_DEFECTO = '90';
    /**
     * PROPUESTA (pendiente de confirmar con Aldrin): con menos sesiones que
     * esto, el porcentaje no se muestra (datos incompletos, RN-7.8).
     */
    private const MINIMO_SESIONES = 4;
    /** Meses sin asistir para pasar a inactivo (RN-1.11). */
    private const MESES_PARA_INACTIVO = 3;

    private AsistenciaRepository $asistencia;
    private DeportistaRepository $deportista;

    public function __construct()
    {
        $this->asistencia = new AsistenciaRepository();
        $this->deportista = new DeportistaRepository();
    }

    /**
     * Toma la asistencia de una fecha: {fecha, presentes: [documentos]}. Una
     * fila por cada deportista activo; los inactivos que vengan en presentes
     * se reactivan y se marcan presentes. Documentos inexistentes o
     * pendientes de validación: 400 sin guardar nada. Fecha ya tomada: 409
     * (se corrige con «corregir»).
     */
    public function tomar(array $datos): array
    {
        $this->exigirAdministrativo('tomar asistencia');
        $fecha = $this->fechaEntrenamiento($datos['fecha'] ?? null);
        $presentes = $this->listaDocumentos($datos['presentes'] ?? null);

        if ($this->asistencia->existeFecha($fecha)) {
            throw new ExcepcionNegocio('La asistencia de esa fecha ya fue tomada: usa «corregir» para cambiar una marca.', 409);
        }

        $deportistas = $this->deportistasValidados();
        $invalidos = array_values(array_diff($presentes, array_keys($deportistas)));
        if ($invalidos !== []) {
            throw new ExcepcionNegocio('Documentos que no se pueden marcar (no existen o están pendientes de validación): ' . implode(', ', $invalidos) . '.', 400);
        }

        $reactivados = array_values(array_filter($presentes, fn (string $documento): bool => $deportistas[$documento]['estado'] === 'inactivo'));

        $registrados = 0;
        try {
            $this->asistencia->enTransaccion(function () use ($fecha, $presentes, $deportistas, $reactivados, &$registrados): void {
                foreach ($reactivados as $documento) {
                    $this->deportista->actualizarEstado($documento, 'activo');
                }
                foreach ($deportistas as $documento => $deportista) {
                    $documento = (string) $documento;
                    if ($deportista['estado'] !== 'activo' && !in_array($documento, $reactivados, true)) {
                        continue;
                    }
                    $this->asistencia->crear(new AsistenciaModel(
                        deportistaDocumento: $documento,
                        fecha: $fecha,
                        asistio: in_array($documento, $presentes, true),
                    ));
                    $registrados++;
                }
            });
        } catch (PDOException $error) {
            // Otra petición tomó la misma fecha al mismo tiempo (llave duplicada).
            if ((string) $error->getCode() === '23000') {
                throw new ExcepcionNegocio('La asistencia de esa fecha ya fue tomada: usa «corregir» para cambiar una marca.', 409);
            }
            throw $error;
        }

        return [
            'fecha' => $fecha,
            'registrados' => $registrados,
            'presentes' => count($presentes),
            'reactivados' => $reactivados,
        ];
    }

    /**
     * Datos de la pantalla de tomar asistencia (RN-7.6): activos e inactivos,
     * cada uno con documento, nombre, discapacidad (RN-7.1) y asistio (null
     * si esa fecha aún no tiene marca para el deportista).
     */
    public function listarPantalla(array $parametros): array
    {
        $this->exigirAdministrativo('ver la pantalla de asistencia');
        $fecha = $this->fechaEntrenamiento($parametros['fecha'] ?? null);

        $marcas = [];
        foreach ($this->asistencia->listar(['fecha' => $fecha]) as $fila) {
            $marcas[(string) $fila['deportista_documento']] = (bool) $fila['asistio'];
        }

        $listas = ['activos' => [], 'inactivos' => []];
        foreach ($this->deportistasValidados() as $documento => $deportista) {
            $documento = (string) $documento;
            $listas[$deportista['estado'] === 'activo' ? 'activos' : 'inactivos'][] = [
                'documento' => $documento,
                'nombre' => $deportista['nombre'],
                'discapacidad' => $deportista['discapacidad'],
                'asistio' => $marcas[$documento] ?? null,
            ];
        }

        return ['fecha' => $fecha, 'tomada' => $marcas !== []] + $listas;
    }

    /**
     * Marcas por fecha, por rango (desde + hasta) o por deportista (se pueden
     * combinar deportista y rango). El público solo ve lo suyo: sin
     * ?documento= usa el de su sesión; uno ajeno responde 404.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listar(array $parametros): array
    {
        $usuario = $this->usuario();
        $documento = array_key_exists('documento', $parametros) ? $this->documento($parametros['documento']) : null;
        if ($usuario['ambito'] === 'publica') {
            $documento ??= $usuario['identificador'];
            if ($documento !== $usuario['identificador']) {
                throw new ExcepcionNegocio('Deportista no encontrado.', 404);
            }
        }

        [$desde, $hasta] = $this->rango($parametros);
        if ($documento === null && $desde === null) {
            throw new ExcepcionNegocio('Indica una fecha, un rango (desde y hasta) o un documento.', 400);
        }

        return array_map(
            fn (array $fila): array => $this->formatear($fila),
            $this->asistencia->listarFiltrado($documento, $desde, $hasta)
        );
    }

    /**
     * La marca de un deportista en una fecha (?documento=&fecha=), o null si
     * no existe o no es visible para la sesión (el Controller responde 404).
     */
    public function consultar(array $parametros): ?array
    {
        $usuario = $this->usuario();
        $documento = $this->documento($parametros['documento'] ?? null);
        $fecha = $this->fechaFormato($parametros['fecha'] ?? null, 'fecha');
        if ($usuario['ambito'] === 'publica' && $documento !== $usuario['identificador']) {
            return null;
        }
        $fila = $this->asistencia->obtenerPorId($documento, $fecha);
        return $fila === null ? null : $this->formatear($fila);
    }

    /**
     * Tarjeta del dashboard: presentes y total de hoy, o de la última sesión
     * tomada si hoy no hubo.
     */
    public function resumenDia(): array
    {
        $this->exigirAdministrativo('ver el resumen de asistencia');
        $hoy = $this->hoy()->format('Y-m-d');
        $fecha = $this->asistencia->ultimaFechaHasta($hoy);
        if ($fecha === null) {
            return ['fecha' => null, 'presentes' => 0, 'total' => 0, 'es_hoy' => false];
        }
        $resumen = $this->asistencia->resumenDeFecha($fecha);
        return ['fecha' => $fecha, 'presentes' => $resumen['presentes'], 'total' => $resumen['total'], 'es_hoy' => $fecha === $hoy];
    }

    /**
     * Cambia una marca existente (?documento=&fecha=, cuerpo {asistio}). Si
     * queda en presente y el deportista está inactivo, se reactiva (RN-1.11).
     */
    public function corregir(array $parametros, array $datos): array
    {
        $this->exigirAdministrativo('corregir asistencia');
        [$documento, $fecha] = $this->marcaDe($parametros);
        $fila = $this->marcaExistente($documento, $fecha);
        $asistio = $this->booleano($datos['asistio'] ?? null, 'asistio');

        $deportista = $this->deportista->obtenerPorId($documento);
        $reactivar = $asistio && $deportista !== null && $deportista['estado'] === 'inactivo';

        $modelo = AsistenciaModel::fromArray($fila);
        $modelo->establecerAsistio($asistio);
        $this->asistencia->enTransaccion(function () use ($modelo, $reactivar, $documento): void {
            $this->asistencia->actualizar($modelo);
            if ($reactivar) {
                $this->deportista->actualizarEstado($documento, 'activo');
            }
        });

        return $this->formatear($this->asistencia->obtenerPorId($documento, $fecha)) + ['reactivado' => $reactivar];
    }

    /**
     * Borra una marca tomada por error. No revierte una reactivación.
     */
    public function eliminarMarca(array $parametros): void
    {
        $this->exigirAdministrativo('eliminar marcas de asistencia');
        [$documento, $fecha] = $this->marcaDe($parametros);
        $this->marcaExistente($documento, $fecha);
        $this->asistencia->eliminar($documento, $fecha);
    }

    /**
     * Borra la fecha completa tomada por error. No revierte reactivaciones.
     */
    public function eliminarFecha(array $parametros): array
    {
        $this->exigirAdministrativo('eliminar una fecha de asistencia');
        $fecha = $this->fechaEntrenamiento($parametros['fecha'] ?? null);
        $eliminadas = $this->asistencia->eliminarFecha($fecha);
        if ($eliminadas === 0) {
            throw new ExcepcionNegocio('Esa fecha no tiene asistencia tomada.', 404);
        }
        return ['fecha' => $fecha, 'eliminadas' => $eliminadas];
    }

    /**
     * Porcentaje de asistencia de un deportista (RN-7.8) en la ventana (30,
     * 90 o todo; por defecto 90 días), contado desde su ingreso. Con menos de
     * MINIMO_SESIONES sesiones: porcentaje null y datos_incompletos.
     */
    public function porcentaje(array $parametros): array
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

        $ventana = $parametros['ventana'] ?? self::VENTANA_POR_DEFECTO;
        if (!is_string($ventana) || !array_key_exists($ventana, self::VENTANAS)) {
            throw new ExcepcionNegocio('El parámetro "ventana" debe ser 30, 90 o todo.', 400);
        }

        $deportista = $this->deportista->obtenerPorId($documento);
        if ($deportista === null) {
            throw new ExcepcionNegocio('Deportista no encontrado.', 404);
        }

        $desde = $deportista['fecha_ingreso'];
        $dias = self::VENTANAS[$ventana];
        if ($dias !== null) {
            $inicioVentana = $this->hoy()->modify("-{$dias} days")->format('Y-m-d');
            $desde = max($desde, $inicioVentana);
        }

        $conteo = $this->asistencia->conteoDesde($documento, $desde);
        $incompletos = $conteo['sesiones'] < self::MINIMO_SESIONES;

        return [
            'documento' => $documento,
            'ventana' => $ventana,
            'desde' => $desde,
            'sesiones' => $conteo['sesiones'],
            'presentes' => $conteo['presentes'],
            'porcentaje' => $incompletos ? null : round($conteo['presentes'] * 100 / $conteo['sesiones'], 1),
            'datos_incompletos' => $incompletos,
        ];
    }

    /**
     * Botón «Revisar inactivos» (RN-1.11): pasa a inactivo a cada deportista
     * activo y validado cuya fecha de referencia —la última asistencia con
     * asistio = 1, o el ingreso si es posterior o si nunca asistió— fue hace
     * 3 meses o más. Idempotente.
     */
    public function revisarInactivos(): array
    {
        $this->exigirAdministrativo('revisar inactivos');
        $limite = $this->restarMeses($this->hoy(), self::MESES_PARA_INACTIVO)->format('Y-m-d');
        $ultimas = $this->asistencia->ultimasAsistencias();

        $desactivados = [];
        foreach ($this->deportistasValidados() as $documento => $deportista) {
            $documento = (string) $documento;
            if ($deportista['estado'] !== 'activo') {
                continue;
            }
            $referencia = max($deportista['fecha_ingreso'], $ultimas[$documento] ?? '0000-00-00');
            if ($referencia <= $limite) {
                $desactivados[] = $documento;
            }
        }

        $this->deportista->enTransaccion(function () use ($desactivados): void {
            foreach ($desactivados as $documento) {
                $this->deportista->actualizarEstado($documento, 'inactivo');
            }
        });

        return ['fecha_limite' => $limite, 'desactivados' => $desactivados];
    }

    // ------------------------------------------------------------------
    // Permisos y apoyo
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
     * Deportistas validados (los pendientes no entran en asistencia),
     * ordenados por nombre y documento.
     *
     * Ojo: PHP convierte en entero una llave que sea texto numérico (ej.
     * "1000000101"); quien recorra el arreglo debe volver a convertirla a texto.
     *
     * @return array<string, array<string, mixed>> documento => fila
     */
    private function deportistasValidados(): array
    {
        $resultado = [];
        foreach ($this->deportista->listar(['estado_registro' => 'validado']) as $fila) {
            $resultado[(string) $fila['documento']] = $fila;
        }
        return $resultado;
    }

    /**
     * @return array<string, mixed>
     */
    private function marcaExistente(string $documento, string $fecha): array
    {
        $fila = $this->asistencia->obtenerPorId($documento, $fecha);
        if ($fila === null) {
            throw new ExcepcionNegocio('No hay marca de asistencia para ese deportista en esa fecha.', 404);
        }
        return $fila;
    }

    private function formatear(array $fila): array
    {
        return [
            'deportista_documento' => (string) $fila['deportista_documento'],
            'fecha' => $fila['fecha'],
            'asistio' => (bool) $fila['asistio'],
        ];
    }

    private function hoy(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone(self::ZONA_HORARIA));
    }

    /**
     * Resta meses sin el desborde de PHP (31 de mayo - 3 meses daría 3 de
     * marzo): si el día no existe en el mes destino, usa el último día.
     */
    private function restarMeses(DateTimeImmutable $fecha, int $meses): DateTimeImmutable
    {
        $primerDia = $fecha->modify('first day of this month')->modify("-{$meses} months");
        $dia = min((int) $fecha->format('j'), (int) $primerDia->format('t'));
        return $primerDia->setDate((int) $primerDia->format('Y'), (int) $primerDia->format('n'), $dia);
    }

    // ------------------------------------------------------------------
    // Validación de datos de entrada
    // ------------------------------------------------------------------

    /**
     * Fecha AAAA-MM-DD real (sin validar el día de la semana).
     */
    private function fechaFormato(mixed $valor, string $campo): string
    {
        $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new DateTimeZone(self::ZONA_HORARIA)) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser una fecha válida con formato AAAA-MM-DD.", 400);
        }
        return $valor;
    }

    /**
     * Fecha de entrenamiento (RN-7.4, RN-7.7): formato válido, sábado o
     * domingo, y no futura según la fecha de Bogotá.
     */
    private function fechaEntrenamiento(mixed $valor): string
    {
        $texto = $this->fechaFormato($valor, 'fecha');
        $fecha = new DateTimeImmutable($texto, new DateTimeZone(self::ZONA_HORARIA));
        if ($fecha > $this->hoy()) {
            throw new ExcepcionNegocio('La fecha no puede ser futura.', 400);
        }
        if (!in_array((int) $fecha->format('N'), self::DIAS_ENTRENAMIENTO, true)) {
            throw new ExcepcionNegocio('La asistencia solo se registra en sábado o domingo.', 400);
        }
        return $texto;
    }

    /**
     * desde y hasta, o fecha (= desde y hasta iguales), o ninguno.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function rango(array $parametros): array
    {
        if (array_key_exists('fecha', $parametros)) {
            $fecha = $this->fechaFormato($parametros['fecha'], 'fecha');
            return [$fecha, $fecha];
        }
        $tieneDesde = array_key_exists('desde', $parametros);
        if ($tieneDesde !== array_key_exists('hasta', $parametros)) {
            throw new ExcepcionNegocio('Para un rango envía "desde" y "hasta".', 400);
        }
        if (!$tieneDesde) {
            return [null, null];
        }
        $desde = $this->fechaFormato($parametros['desde'], 'desde');
        $hasta = $this->fechaFormato($parametros['hasta'], 'hasta');
        if ($desde > $hasta) {
            throw new ExcepcionNegocio('"desde" no puede ser posterior a "hasta".', 400);
        }
        return [$desde, $hasta];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function marcaDe(array $parametros): array
    {
        return [$this->documento($parametros['documento'] ?? null), $this->fechaEntrenamiento($parametros['fecha'] ?? null)];
    }

    private function documento(mixed $valor): string
    {
        return Validador::texto($valor, 'documento', 20, true);
    }

    /**
     * Lista de documentos sin repetidos (puede venir vacía: nadie asistió).
     *
     * @return array<int, string>
     */
    private function listaDocumentos(mixed $valor): array
    {
        if ($valor === null) {
            return [];
        }
        if (!is_array($valor) || !array_is_list($valor)) {
            throw new ExcepcionNegocio('El campo "presentes" debe ser una lista de documentos.', 400);
        }
        return array_values(array_unique(array_map(
            fn (mixed $documento): string => Validador::texto($documento, 'presentes', 20, true),
            $valor
        )));
    }

    private function booleano(mixed $valor, string $campo): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        if ($valor === 0 || $valor === 1) {
            return $valor === 1;
        }
        throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser verdadero o falso.", 400);
    }
}
