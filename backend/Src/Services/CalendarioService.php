<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del módulo Calendario (RN-9.x; Contexto Base
 * §35.12).
 *
 *   - Eventos guardados (evento_calendario): una sola fecha, sin fecha fin
 *     (RN-9.2). Se crean y se actualizan; no se eliminan. Mismo nombre y misma
 *     fecha = duplicado (la collation no distingue mayúsculas ni tildes).
 *   - Visibilidad (RN-9.3): el público solo ve los eventos públicos. Una
 *     reunión administrativa es siempre administrativa y al crearla se exige
 *     confirmar que es confidencial (RN-9.9).
 *   - Vista (RN-9.8): eventos + cumpleaños + vencimientos de mensualidad,
 *     estos dos calculados (no guardados), agrupados por día y solo de
 *     deportistas activos y validados; el público solo ve su propia fila. El
 *     29/02 se muestra el 28/02 en años no bisiestos. Los vencimientos usan el
 *     cálculo de PagoService.
 *   - Importación CSV (RN-9.6): duplicados omitidos e informados; filas
 *     inválidas rechazadas con su línea; las válidas se guardan.
 */
class CalendarioService
{
    private const TIPOS = ['competencia', 'evaluacion', 'grado', 'reunion_administrativa', 'evento_extra'];
    private const VISIBILIDADES = ['administrativa', 'publica'];
    private const MAXIMO_NOMBRE = 150;
    private const MAXIMO_LUGAR = 150;
    private const MAXIMO_DIAS_VISTA = 366;
    private const TAMANO_MAXIMO_CSV = 10 * 1024 * 1024;
    private const MAXIMO_FILAS_CSV = 500;
    private const COLUMNAS_CSV = ['nombre', 'tipo', 'fecha', 'lugar', 'visibilidad', 'observaciones'];
    private const CAMPOS_CREAR = ['nombre', 'tipo', 'fecha', 'lugar', 'visibilidad', 'observaciones', 'confirmar_confidencial'];
    private const CAMPOS_ACTUALIZAR = ['nombre', 'fecha', 'lugar', 'visibilidad', 'observaciones'];

    private EventoCalendarioRepository $evento;
    private DeportistaRepository $deportista;
    private PagoRepository $pago;

    public function __construct()
    {
        $this->evento = new EventoCalendarioRepository();
        $this->deportista = new DeportistaRepository();
        $this->pago = new PagoRepository();
    }

    // ------------------------------------------------------------------
    // Eventos guardados
    // ------------------------------------------------------------------

    /**
     * Eventos con filtros opcionales (desde + hasta, tipo), por fecha y
     * nombre. El público solo ve los públicos.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listar(array $parametros): array
    {
        $usuario = $this->usuario();
        [$desde, $hasta] = $this->rangoOpcional($parametros);
        $tipo = null;
        if (array_key_exists('tipo', $parametros)) {
            $tipo = $this->opcion($parametros['tipo'], 'tipo', self::TIPOS);
        }
        return $this->eventosVisibles($usuario, $desde, $hasta, $tipo);
    }

    /**
     * Un evento por ?id=, o null si no existe o no es visible para el
     * público (el Controller responde 404).
     */
    public function consultar(array $parametros): ?array
    {
        $usuario = $this->usuario();
        $fila = $this->evento->obtenerPorId($this->idDe($parametros));
        if ($fila === null || ($usuario['ambito'] !== 'administrativa' && $fila['visibilidad'] !== 'publica')) {
            return null;
        }
        return $this->formatear($fila);
    }

    /**
     * Crea un evento (solo administrativo). Una reunión administrativa exige
     * confirmar_confidencial = true y queda siempre administrativa.
     */
    public function crear(array $datos): array
    {
        $this->exigirAdministrativo('crear eventos');
        $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_CREAR);
        $modelo = $this->modeloDesdeDatos($datos, true);

        if ($this->evento->existeNombreFecha($modelo->obtenerNombre(), $modelo->obtenerFecha())) {
            throw new ExcepcionNegocio('Ya existe un evento con ese nombre en esa fecha.', 409);
        }
        return $this->formatear($this->evento->obtenerPorId($this->evento->crear($modelo)));
    }

    /**
     * Actualiza nombre, fecha, lugar, visibilidad y observaciones (solo
     * administrativo). El tipo no se cambia. Una reunión administrativa no
     * puede volverse pública.
     */
    public function actualizar(array $parametros, array $datos): array
    {
        $this->exigirAdministrativo('actualizar eventos');
        $fila = $this->evento->obtenerPorId($this->idDe($parametros));
        if ($fila === null) {
            throw new ExcepcionNegocio('Evento no encontrado.', 404);
        }
        if (array_key_exists('tipo', $datos)) {
            throw new ExcepcionNegocio('El tipo de un evento no se cambia.', 400);
        }
        if ($datos === []) {
            throw new ExcepcionNegocio('No se recibió ningún campo para actualizar.', 400);
        }
        $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_ACTUALIZAR);

        $modelo = EventoCalendarioModel::fromArray($fila);
        if (array_key_exists('nombre', $datos)) {
            $modelo->establecerNombre(Validador::texto($datos['nombre'], 'nombre', self::MAXIMO_NOMBRE, true));
        }
        if (array_key_exists('fecha', $datos)) {
            $modelo->establecerFecha($this->fecha($datos['fecha']));
        }
        if (array_key_exists('lugar', $datos)) {
            $modelo->establecerLugar(Validador::texto($datos['lugar'], 'lugar', self::MAXIMO_LUGAR, true));
        }
        if (array_key_exists('visibilidad', $datos)) {
            $visibilidad = $this->opcion($datos['visibilidad'], 'visibilidad', self::VISIBILIDADES);
            if ($modelo->obtenerTipo() === 'reunion_administrativa' && $visibilidad !== 'administrativa') {
                throw new ExcepcionNegocio('Una reunión administrativa siempre es de visibilidad administrativa (confidencial).', 400);
            }
            $modelo->establecerVisibilidad($visibilidad);
        }
        if (array_key_exists('observaciones', $datos)) {
            $modelo->establecerObservaciones($this->observaciones($datos['observaciones']));
        }

        // RN-9.2: fecha, lugar y visibilidad de un evento de otro módulo
        // (sesión de examen o torneo) se editan en ese módulo, que los
        // mantiene en sintonía con sus propios datos. Solo se consulta si uno
        // de esos campos realmente cambia.
        if ($modelo->obtenerFecha() !== $fila['fecha'] || $modelo->obtenerLugar() !== $fila['lugar']
            || $modelo->obtenerVisibilidad() !== $fila['visibilidad']) {
            $dueno = $this->evento->moduloDueno($modelo->obtenerId());
            if ($dueno !== null) {
                $modulo = $dueno === 'evaluacion'
                    ? 'una sesión de examen: edítalos en Evaluaciones (actualizar la sesión)'
                    : 'un torneo: edítalos en Competencias (actualizar el torneo)';
                throw new ExcepcionNegocio("La fecha, el lugar y la visibilidad de este evento pertenecen a {$modulo}.", 409);
            }
        }

        if ($this->evento->existeNombreFecha($modelo->obtenerNombre(), $modelo->obtenerFecha(), $modelo->obtenerId())) {
            throw new ExcepcionNegocio('Ya existe un evento con ese nombre en esa fecha.', 409);
        }
        $this->evento->actualizar($modelo);
        return $this->formatear($this->evento->obtenerPorId($modelo->obtenerId()));
    }

    // ------------------------------------------------------------------
    // Vista del calendario
    // ------------------------------------------------------------------

    /**
     * Vista de un rango (?desde=&hasta=, máximo 366 días): eventos visibles,
     * cumpleaños y vencimientos de mensualidad sin pagar, agrupados por día.
     * Pocas consultas: eventos, deportistas, pagos por año del rango y semana
     * del último pago; nunca una por deportista.
     */
    public function vista(array $parametros): array
    {
        $usuario = $this->usuario();
        [$desde, $hasta] = $this->rangoOpcional($parametros);
        if ($desde === null) {
            throw new ExcepcionNegocio('Indica el rango con "desde" y "hasta".', 400);
        }
        $dias = (int) (new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->days + 1;
        if ($dias > self::MAXIMO_DIAS_VISTA) {
            throw new ExcepcionNegocio('El rango no puede superar 366 días.', 400);
        }

        $deportistas = $this->deportista->listar(['estado' => 'activo', 'estado_registro' => 'validado']);
        if ($usuario['ambito'] !== 'administrativa') {
            $deportistas = array_values(array_filter(
                $deportistas,
                fn (array $fila): bool => (string) $fila['documento'] === $usuario['identificador']
            ));
        }

        return [
            'eventos' => $this->eventosVisibles($usuario, $desde, $hasta, null),
            'cumpleanos' => $this->cumpleanos($deportistas, $desde, $hasta),
            'vencimientos' => $this->vencimientos($deportistas, $desde, $hasta),
        ];
    }

    // ------------------------------------------------------------------
    // Importación CSV
    // ------------------------------------------------------------------

    /**
     * Importa eventos desde un CSV (solo administrativo): encabezado
     * obligatorio con nombre, tipo, fecha, lugar, visibilidad y observaciones;
     * UTF-8 con o sin BOM; separador coma o punto y coma; fecha AAAA-MM-DD o
     * DD/MM/AAAA; máximo 500 filas. Cada fila sigue las reglas de crear (una
     * reunión administrativa queda administrativa). Los duplicados (contra la
     * base o dentro del archivo) se omiten; las filas inválidas se informan
     * con su línea; las válidas se guardan en una transacción.
     */
    public function importar(): array
    {
        $this->exigirAdministrativo('importar eventos');
        ArchivoSubido::exigirCuerpoDentroDelLimite();
        $archivo = ArchivoSubido::leer('archivo', ['csv'], self::TAMANO_MAXIMO_CSV);

        // Lector compartido (Utils/LectorCsv), en su modo por defecto: solo
        // UTF-8 y una fila por línea física.
        $csv = LectorCsv::leer((string) file_get_contents($archivo['rutaTemporal']));
        $columnas = array_map(fn (string $c): string => mb_strtolower(trim($c)), $csv['encabezado']);
        if (count($columnas) !== count(self::COLUMNAS_CSV) || array_diff(self::COLUMNAS_CSV, $columnas) !== []) {
            throw new ExcepcionNegocio('El encabezado debe tener las columnas: ' . implode(', ', self::COLUMNAS_CSV) . '.', 400);
        }

        $filas = $csv['filas'];
        if (count($filas) > self::MAXIMO_FILAS_CSV) {
            throw new ExcepcionNegocio('El archivo supera el máximo de 500 filas.', 400);
        }

        $validos = [];
        $errores = [];
        foreach ($filas as $numeroLinea => $valores) {
            if (count($valores) !== count($columnas)) {
                $errores[] = ['linea' => $numeroLinea, 'motivo' => 'La fila no tiene ' . count($columnas) . ' columnas.'];
                continue;
            }
            $datos = array_combine($columnas, array_map('trim', $valores));
            $datos['fecha'] = $this->fechaCsv($datos['fecha']);
            if ($datos['observaciones'] === '') {
                $datos['observaciones'] = null;
            }
            try {
                $validos[$numeroLinea] = $this->modeloDesdeDatos($datos, false);
            } catch (ExcepcionNegocio $error) {
                $errores[] = ['linea' => $numeroLinea, 'motivo' => $error->getMessage()];
            }
        }

        // Dentro de la transacción, cada fila ya insertada cuenta para las
        // siguientes: así también se omiten los duplicados del mismo archivo.
        $omitidos = [];
        $creados = $this->evento->enTransaccion(function () use ($validos, &$omitidos): int {
            $creados = 0;
            foreach ($validos as $numeroLinea => $modelo) {
                if ($this->evento->existeNombreFecha($modelo->obtenerNombre(), $modelo->obtenerFecha())) {
                    $omitidos[] = ['linea' => $numeroLinea, 'nombre' => $modelo->obtenerNombre(), 'fecha' => $modelo->obtenerFecha()];
                    continue;
                }
                $this->evento->crear($modelo);
                $creados++;
            }
            return $creados;
        });

        return ['creados' => $creados, 'omitidos' => $omitidos, 'errores' => $errores];
    }

    // ------------------------------------------------------------------
    // Cálculos de la vista
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eventosVisibles(array $usuario, ?string $desde, ?string $hasta, ?string $tipo): array
    {
        $visibilidad = $usuario['ambito'] === 'administrativa' ? null : 'publica';
        return array_map(
            fn (array $fila): array => $this->formatear($fila),
            $this->evento->listarFiltrado($desde, $hasta, $tipo, $visibilidad)
        );
    }

    /**
     * Cumpleaños dentro del rango, agrupados por día. Quien nació el 29/02
     * aparece el 28/02 en años no bisiestos.
     *
     * @param array<int, array<string, mixed>> $deportistas
     * @return array<int, array<string, mixed>>
     */
    private function cumpleanos(array $deportistas, string $desde, string $hasta): array
    {
        $porDia = [];
        foreach ($deportistas as $deportista) {
            $mesDia = substr($deportista['fecha_nacimiento'], 5);
            for ($anio = (int) substr($desde, 0, 4); $anio <= (int) substr($hasta, 0, 4); $anio++) {
                $diaDelAnio = ($mesDia === '02-29' && !checkdate(2, 29, $anio)) ? '02-28' : $mesDia;
                $fecha = "{$anio}-{$diaDelAnio}";
                if ($fecha >= $desde && $fecha <= $hasta) {
                    $porDia[$fecha][] = ['documento' => (string) $deportista['documento'], 'nombre' => $deportista['nombre']];
                }
            }
        }
        return $this->agruparPorDia($porDia);
    }

    /**
     * Vencimientos de mensualidades sin pagar dentro del rango, agrupados por
     * día, con el cálculo de PagoService (semana habitual, vencimiento y
     * meses evaluables).
     *
     * @param array<int, array<string, mixed>> $deportistas
     * @return array<int, array<string, mixed>>
     */
    private function vencimientos(array $deportistas, string $desde, string $hasta): array
    {
        if ($deportistas === []) {
            return [];
        }
        $pagosPorDeportista = [];
        for ($anio = (int) substr($desde, 0, 4); $anio <= (int) substr($hasta, 0, 4); $anio++) {
            foreach ($this->pago->listar(['anio' => $anio, 'tipo' => 'mensualidad']) as $fila) {
                $pagosPorDeportista[(string) $fila['deportista_documento']][] = $fila;
            }
        }
        $semanas = $this->pago->semanasDelUltimoPago();

        $porDia = [];
        foreach ($deportistas as $deportista) {
            $documento = (string) $deportista['documento'];
            $semana = $semanas[$documento] ?? PagoService::semanaDelMes($deportista['fecha_ingreso']);
            $pendientes = PagoService::vencimientosPendientes($deportista['fecha_ingreso'], $semana, $pagosPorDeportista[$documento] ?? [], $desde, $hasta);
            foreach ($pendientes as $pendiente) {
                $porDia[$pendiente['vencimiento']][] = ['documento' => $documento, 'nombre' => $deportista['nombre']];
            }
        }
        return $this->agruparPorDia($porDia);
    }

    /**
     * @param array<string, array<int, array{documento: string, nombre: string}>> $porDia
     * @return array<int, array{fecha: string, cantidad: int, deportistas: array<int, array{documento: string, nombre: string}>}>
     */
    private function agruparPorDia(array $porDia): array
    {
        ksort($porDia);
        $resultado = [];
        foreach ($porDia as $fecha => $deportistas) {
            $resultado[] = ['fecha' => (string) $fecha, 'cantidad' => count($deportistas), 'deportistas' => $deportistas];
        }
        return $resultado;
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
     * Valida los datos de un evento nuevo con las mismas reglas de crear y
     * devuelve el modelo, sin guardarlo. La usa Competencias para la
     * creación excepcional de un torneo (evento + competencia en una
     * transacción). El duplicado nombre + fecha lo revisa quien guarda.
     */
    public function validarEventoNuevo(array $datos): EventoCalendarioModel
    {
        return $this->modeloDesdeDatos($datos, true);
    }

    /**
     * Valida los datos de un evento nuevo (crear o una fila del CSV) y arma
     * el modelo. Una reunión administrativa queda siempre administrativa; al
     * crearla a mano exige además confirmar_confidencial = true (RN-9.9).
     */
    private function modeloDesdeDatos(array $datos, bool $exigirConfirmacion): EventoCalendarioModel
    {
        $tipo = $this->opcion($datos['tipo'] ?? null, 'tipo', self::TIPOS);
        if ($tipo === 'reunion_administrativa') {
            if ($exigirConfirmacion && ($datos['confirmar_confidencial'] ?? null) !== true) {
                throw new ExcepcionNegocio(
                    'Una reunión administrativa es confidencial (solo visible en el ámbito administrativo): confírmalo enviando "confirmar_confidencial": true.',
                    400
                );
            }
            $visibilidad = 'administrativa';
        } else {
            $visibilidad = $this->opcion($datos['visibilidad'] ?? null, 'visibilidad', self::VISIBILIDADES);
        }

        return new EventoCalendarioModel(
            nombre: Validador::texto($datos['nombre'] ?? null, 'nombre', self::MAXIMO_NOMBRE, true),
            tipo: $tipo,
            fecha: $this->fecha($datos['fecha'] ?? null),
            lugar: Validador::texto($datos['lugar'] ?? null, 'lugar', self::MAXIMO_LUGAR, true),
            visibilidad: $visibilidad,
            observaciones: $this->observaciones($datos['observaciones'] ?? null),
        );
    }

    /**
     * desde y hasta juntos (o ninguno), válidos y en orden.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function rangoOpcional(array $parametros): array
    {
        $tieneDesde = array_key_exists('desde', $parametros);
        if ($tieneDesde !== array_key_exists('hasta', $parametros)) {
            throw new ExcepcionNegocio('Para un rango envía "desde" y "hasta".', 400);
        }
        if (!$tieneDesde) {
            return [null, null];
        }
        $desde = $this->fecha($parametros['desde'], 'desde');
        $hasta = $this->fecha($parametros['hasta'], 'hasta');
        if ($desde > $hasta) {
            throw new ExcepcionNegocio('"desde" no puede ser posterior a "hasta".', 400);
        }
        return [$desde, $hasta];
    }

    private function fecha(mixed $valor, string $campo = 'fecha'): string
    {
        $fecha = Validador::esFechaIso($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser una fecha válida con formato AAAA-MM-DD.", 400);
        }
        return $valor;
    }

    /**
     * En el CSV la fecha puede venir como DD/MM/AAAA (Excel en español); se
     * pasa a AAAA-MM-DD. Si no tiene esa forma se deja igual y la valida fecha().
     */
    private function fechaCsv(string $valor): string
    {
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $valor, $partes) === 1) {
            return sprintf('%04d-%02d-%02d', $partes[3], $partes[2], $partes[1]);
        }
        return $valor;
    }

    private function observaciones(mixed $valor): ?string
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }
        if (!is_string($valor)) {
            throw new ExcepcionNegocio('El campo "observaciones" debe ser texto.', 400);
        }
        // Columna TEXT: tope de bytes (si no, un texto enorme era un 500).
        return Validador::textoLargo($valor, 'observaciones');
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

    private function idDe(array $parametros): int
    {
        $id = $parametros['id'] ?? null;
        if (!is_string($id) || !ctype_digit($id) || (int) $id <= 0) {
            throw new ExcepcionNegocio('El parámetro "id" debe ser un número entero positivo.', 400);
        }
        return (int) $id;
    }

    /**
     * @param array<int, string> $permitidos
     */
    private function rechazarCamposNoPermitidos(array $datos, array $permitidos): void
    {
        $sobrantes = array_diff(array_keys($datos), $permitidos);
        if ($sobrantes !== []) {
            throw new ExcepcionNegocio('Campos no permitidos: ' . implode(', ', $sobrantes) . '.', 400);
        }
    }

    private function formatear(array $fila): array
    {
        return EventoCalendarioModel::fromArray($fila)->toArray();
    }
}
