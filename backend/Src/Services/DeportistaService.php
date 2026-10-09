<?php

declare(strict_types=1);

/**
 * Capa de logica de negocio del modulo Deportista (RN-1.x).
 *
 * Reglas de acceso segun el ambito de la sesion (RN-T.2, RN-T.3, RN-1.4):
 *   - Administrativo: ve y edita todo; es el unico que registra y elimina.
 *   - Publico: UNA cuenta por deportista. La sesion se inicia con el documento
 *     y la fecha de nacimiento DEL DEPORTISTA (RN-T.16), la use el propio
 *     deportista, su acudiente o un familiar, sin distincion por edad. Solo ve
 *     su propio registro y solo edita peso y discapacidad/condicion de salud.
 *     El acudiente es un dato de contacto que gestiona el club, no una cuenta.
 *
 * Edad y categoria por edad (RN-1.3, RN-3.8, Especificacion A.1) se calculan
 * al consultar y nunca se guardan. La categoria por peso queda PENDIENTE
 * (faltan los valores oficiales de Mayores).
 */
class DeportistaService
{
    private const MAYORIA_EDAD = 18;
    private const SEXOS = ['masculino', 'femenino'];
    private const ESTADOS = ['activo', 'inactivo'];
    private const ESTADOS_REGISTRO = ['pendiente_validacion', 'validado'];

    /**
     * Categorias por edad (RN-3.8): [edad minima, edad maxima o null, nombre].
     * Menores de 8 anos no tienen categoria.
     */
    private const CATEGORIAS_EDAD = [
        [8, 11, 'Infantil'],
        [12, 14, 'Junior'],
        [15, 17, 'Juvenil'],
        [18, 35, 'Mayores'],
        [36, 45, 'Sénior'],
        [46, null, 'Máster'],
    ];

    private const FILTROS_ADMINISTRATIVOS = [
        'estado', 'estado_registro', 'sede_entrenamiento', 'grado_actual', 'acudiente_documento',
    ];
    private const CAMPOS_CREAR = [
        'documento', 'nombre', 'fecha_nacimiento', 'sexo', 'estatura', 'peso', 'discapacidad',
        'sede_entrenamiento', 'autorizacion_datos', 'grado_actual', 'fecha_ingreso', 'estado', 'acudiente',
    ];
    private const CAMPOS_ACTUALIZAR_ADMINISTRATIVO = [
        'nombre', 'fecha_nacimiento', 'sexo', 'estatura', 'peso', 'discapacidad', 'sede_entrenamiento',
        'autorizacion_datos', 'grado_actual', 'fecha_ingreso', 'estado', 'estado_registro', 'acudiente',
    ];
    private const CAMPOS_ACTUALIZAR_PUBLICO = ['peso', 'discapacidad'];
    private const CAMPOS_ACUDIENTE = ['documento', 'nombre', 'relacion'];

    private DeportistaRepository $deportista;
    private AcudienteTutorRepository $acudiente;

    public function __construct()
    {
        $this->deportista = new DeportistaRepository();
        $this->acudiente = new AcudienteTutorRepository();
    }

    /**
     * Administrativo: lista con filtros permitidos (ej. estado_registro=
     * pendiente_validacion para la lista de registros por validar, RN-T.14).
     * Administrativo: devuelve todas las filas que cumplen el filtro (sin
     * limite fijo). Publico: ignora filtros y devuelve solo su propio registro
     * (RN-T.4).
     */
    public function listar(array $filtros = []): array
    {
        $usuario = $this->usuario();

        if ($usuario['ambito'] === 'publica') {
            $fila = $this->deportista->obtenerPorId($usuario['identificador']);
            return $fila === null ? [] : [$this->formatear($fila, false)];
        }

        $this->rechazarCamposNoPermitidos($filtros, self::FILTROS_ADMINISTRATIVOS, 400, 'Filtros no permitidos');
        foreach ($filtros as $columna => $valor) {
            if (!is_string($valor)) {
                throw new ExcepcionNegocio("El filtro \"{$columna}\" debe ser un solo valor de texto.", 400);
            }
        }

        return array_map(
            fn (array $fila): array => $this->formatear($fila, true),
            $this->deportista->listar($filtros, null)
        );
    }

    /**
     * Devuelve la ficha del deportista con los datos de su acudiente, o null si
     * no existe o no es visible para la sesion (el ambito publico no puede
     * deducir si un documento ajeno existe).
     */
    public function consultar(array $identificador): ?array
    {
        $usuario = $this->usuario();
        $documento = $this->documentoDe($identificador);

        $fila = $this->deportista->obtenerPorId($documento);
        if ($fila === null || !$this->puedeVer($usuario, $fila)) {
            return null;
        }

        $ficha = $this->formatear($fila, $usuario['ambito'] === 'administrativa');
        $ficha['acudiente'] = $fila['acudiente_documento'] === null
            ? null
            : $this->acudiente->obtenerPorId($fila['acudiente_documento']);

        return $ficha;
    }

    /**
     * Registra un deportista (solo ambito administrativo; el autorregistro
     * RN-T.14 va en su propio turno). Queda validado y activo por defecto.
     * Si es menor de edad exige acudiente: reutiliza uno existente sin
     * modificarlo, o lo crea en la misma transaccion.
     */
    public function crear(array $datos): array
    {
        $this->exigirAdministrativo('registrar deportistas');
        $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_CREAR, 400, 'Campos no reconocidos');

        $documento = $this->texto($datos['documento'] ?? null, 'documento', 20, true);
        $fechaNacimiento = $this->fecha($datos['fecha_nacimiento'] ?? null, 'fecha_nacimiento', true);
        $fechaIngreso = $this->fecha($datos['fecha_ingreso'] ?? null, 'fecha_ingreso', false)
            ?? (new DateTimeImmutable('today'))->format('Y-m-d');
        $this->validarFechaIngreso($fechaIngreso, $fechaNacimiento);

        $modelo = new DeportistaModel(
            documento: $documento,
            nombre: $this->texto($datos['nombre'] ?? null, 'nombre', 150, true),
            fechaNacimiento: $fechaNacimiento,
            sexo: $this->opcion($datos['sexo'] ?? null, 'sexo', self::SEXOS, true),
            estatura: $this->decimal($datos['estatura'] ?? null, 'estatura', 99.99),
            peso: $this->decimal($datos['peso'] ?? null, 'peso', 999.99),
            discapacidad: $this->texto($datos['discapacidad'] ?? null, 'discapacidad', 255, false),
            estadoAnimico: null,
            sedeEntrenamiento: $this->texto($datos['sede_entrenamiento'] ?? null, 'sede_entrenamiento', 100, false),
            autorizacionDatos: $this->booleano($datos['autorizacion_datos'] ?? false, 'autorizacion_datos'),
            gradoActual: $this->texto($datos['grado_actual'] ?? null, 'grado_actual', 30, false),
            fechaIngreso: $fechaIngreso,
            estado: $this->opcion($datos['estado'] ?? null, 'estado', self::ESTADOS, false) ?? 'activo',
            estadoRegistro: 'validado',
            acudienteDocumento: null,
            validadoPorCorreo: null,
        );

        if ($this->deportista->obtenerPorId($documento) !== null) {
            throw new ExcepcionNegocio("Ya existe un deportista con el documento {$documento}.", 409);
        }

        $acudienteNuevo = null;
        $datosAcudiente = $datos['acudiente'] ?? null;
        if ($datosAcudiente === null && $this->calcularEdad($fechaNacimiento) < self::MAYORIA_EDAD) {
            throw new ExcepcionNegocio('El deportista es menor de edad: se requiere un acudiente.', 400);
        }
        if ($datosAcudiente !== null) {
            [$documentoAcudiente, $acudienteNuevo] = $this->resolverAcudiente($datosAcudiente, $documento);
            $modelo->establecerAcudienteDocumento($documentoAcudiente);
        }

        $this->guardarSinDuplicados(function () use ($modelo, $acudienteNuevo): void {
            $this->deportista->enTransaccion(function () use ($modelo, $acudienteNuevo): void {
                if ($acudienteNuevo !== null) {
                    $this->acudiente->crear($acudienteNuevo);
                }
                $this->deportista->crear($modelo);
            });
        });

        return $this->consultar(['documento' => $documento]);
    }

    /**
     * Administrativo: edita cualquier campo menos el documento. Con "acudiente"
     * puede editar nombre/relacion del acudiente actual o reasignar otro
     * (ver aplicarAcudiente). Al pasar de pendiente_validacion a validado
     * registra quien valido (RN-T.14). No se permite volver de validado a
     * pendiente (Especificacion B.4).
     * Publico: solo peso y discapacidad, y solo sobre su propio registro (RN-1.4).
     */
    public function actualizar(array $identificador, array $datos): array
    {
        $usuario = $this->usuario();
        $documento = $this->documentoDe($identificador);

        $fila = $this->deportista->obtenerPorId($documento);
        if ($fila === null || !$this->puedeVer($usuario, $fila)) {
            throw new ExcepcionNegocio('Deportista no encontrado.', 404);
        }
        if ($datos === []) {
            throw new ExcepcionNegocio('No se recibio ningun campo para actualizar.', 400);
        }

        $esAdministrativo = $usuario['ambito'] === 'administrativa';
        if ($esAdministrativo) {
            $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_ACTUALIZAR_ADMINISTRATIVO, 400, 'Campos no reconocidos');
        } else {
            $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_ACTUALIZAR_PUBLICO, 403, 'Campos que no puede editar');
        }

        $modelo = DeportistaModel::fromArray($fila);

        if (array_key_exists('peso', $datos)) {
            $modelo->establecerPeso($this->decimal($datos['peso'], 'peso', 999.99));
        }
        if (array_key_exists('discapacidad', $datos)) {
            $modelo->establecerDiscapacidad($this->texto($datos['discapacidad'], 'discapacidad', 255, false));
        }

        $acudienteNuevo = null;
        $acudienteEditado = null;
        if ($esAdministrativo) {
            $this->aplicarCamposAdministrativos($modelo, $datos, $fila, $usuario['identificador']);
            if (array_key_exists('acudiente', $datos)) {
                [$acudienteNuevo, $acudienteEditado] = $this->aplicarAcudiente($modelo, $datos['acudiente']);
            }
        }
        $acudienteAnterior = $fila['acudiente_documento'];

        if ($modelo->obtenerAcudienteDocumento() === null
            && $this->calcularEdad($modelo->obtenerFechaNacimiento()) < self::MAYORIA_EDAD) {
            throw new ExcepcionNegocio('El deportista es menor de edad y no tiene acudiente asignado.', 400);
        }

        $this->guardarSinDuplicados(function () use ($modelo, $acudienteNuevo, $acudienteEditado, $acudienteAnterior): void {
            $this->deportista->enTransaccion(function () use ($modelo, $acudienteNuevo, $acudienteEditado, $acudienteAnterior): void {
                if ($acudienteNuevo !== null) {
                    $this->acudiente->crear($acudienteNuevo);
                }
                if ($acudienteEditado !== null) {
                    $this->acudiente->actualizar($acudienteEditado);
                }
                $this->deportista->actualizar($modelo);
                if ($acudienteAnterior !== $modelo->obtenerAcudienteDocumento()) {
                    $this->eliminarAcudienteSiQuedaHuerfano($acudienteAnterior);
                }
            });
        });

        return $this->consultar(['documento' => $documento]);
    }

    /**
     * Elimina un registro (solo ambito administrativo y solo si esta en
     * pendiente_validacion: Especificacion B.4, no existe estado "rechazado").
     * Un deportista validado no se elimina: se pasa a inactivo con actualizar.
     * Si su acudiente queda sin deportistas asociados, se borra en la misma
     * transaccion.
     */
    public function eliminar(array $identificador): void
    {
        $this->exigirAdministrativo('eliminar registros');
        $documento = $this->documentoDe($identificador);

        $fila = $this->deportista->obtenerPorId($documento);
        if ($fila === null) {
            throw new ExcepcionNegocio('Deportista no encontrado.', 404);
        }
        if ($fila['estado_registro'] !== 'pendiente_validacion') {
            throw new ExcepcionNegocio(
                'Solo se eliminan registros pendientes de validacion. Para un deportista validado, cambie su estado a inactivo.',
                409
            );
        }

        $documentoAcudiente = $fila['acudiente_documento'];
        $this->guardarSinDuplicados(function () use ($documento, $documentoAcudiente): void {
            $this->deportista->enTransaccion(function () use ($documento, $documentoAcudiente): void {
                $this->deportista->eliminar($documento);
                $this->eliminarAcudienteSiQuedaHuerfano($documentoAcudiente);
            });
        });
    }

    // ------------------------------------------------------------------
    // Reglas de acceso
    // ------------------------------------------------------------------

    /**
     * @return array{ambito: string, identificador: string}
     */
    private function usuario(): array
    {
        $usuario = Sesion::usuarioActual();
        if ($usuario === null) {
            throw new ExcepcionNegocio('No autenticado. Inicie sesion para continuar.', 401);
        }
        return $usuario;
    }

    private function exigirAdministrativo(string $accion): void
    {
        if ($this->usuario()['ambito'] !== 'administrativa') {
            throw new ExcepcionNegocio("Solo el ambito administrativo puede {$accion}.", 403);
        }
    }

    /**
     * Administrativo: ve todo. Publico (RN-T.3): una cuenta por deportista, solo
     * ve su propio registro (documento de la sesion == documento del
     * deportista), sin importar la edad.
     *
     * @param array{ambito: string, identificador: string} $usuario
     */
    private function puedeVer(array $usuario, array $fila): bool
    {
        return $usuario['ambito'] === 'administrativa'
            || $usuario['identificador'] === $fila['documento'];
    }

    // ------------------------------------------------------------------
    // Actualizacion administrativa y acudiente
    // ------------------------------------------------------------------

    private function aplicarCamposAdministrativos(DeportistaModel $modelo, array $datos, array $fila, string $correoAdministrativo): void
    {
        if (array_key_exists('nombre', $datos)) {
            $modelo->establecerNombre($this->texto($datos['nombre'], 'nombre', 150, true));
        }
        if (array_key_exists('fecha_nacimiento', $datos)) {
            $modelo->establecerFechaNacimiento($this->fecha($datos['fecha_nacimiento'], 'fecha_nacimiento', true));
        }
        if (array_key_exists('sexo', $datos)) {
            $modelo->establecerSexo($this->opcion($datos['sexo'], 'sexo', self::SEXOS, true));
        }
        if (array_key_exists('estatura', $datos)) {
            $modelo->establecerEstatura($this->decimal($datos['estatura'], 'estatura', 99.99));
        }
        if (array_key_exists('sede_entrenamiento', $datos)) {
            $modelo->establecerSedeEntrenamiento($this->texto($datos['sede_entrenamiento'], 'sede_entrenamiento', 100, false));
        }
        if (array_key_exists('autorizacion_datos', $datos)) {
            $modelo->establecerAutorizacionDatos($this->booleano($datos['autorizacion_datos'], 'autorizacion_datos'));
        }
        if (array_key_exists('grado_actual', $datos)) {
            $modelo->establecerGradoActual($this->texto($datos['grado_actual'], 'grado_actual', 30, false));
        }
        if (array_key_exists('fecha_ingreso', $datos)) {
            $modelo->establecerFechaIngreso($this->fecha($datos['fecha_ingreso'], 'fecha_ingreso', true));
        }
        if (array_key_exists('estado', $datos)) {
            $modelo->establecerEstado($this->opcion($datos['estado'], 'estado', self::ESTADOS, true));
        }
        if (array_key_exists('estado_registro', $datos)) {
            $nuevo = $this->opcion($datos['estado_registro'], 'estado_registro', self::ESTADOS_REGISTRO, true);
            $actual = $fila['estado_registro'];
            if ($actual === 'validado' && $nuevo === 'pendiente_validacion') {
                throw new ExcepcionNegocio('Un registro validado no puede volver a pendiente de validacion.', 409);
            }
            if ($actual === 'pendiente_validacion' && $nuevo === 'validado') {
                $modelo->establecerEstadoRegistro('validado');
                $modelo->establecerValidadoPorCorreo($correoAdministrativo);
            }
        }

        $this->validarFechaIngreso($modelo->obtenerFechaIngreso(), $modelo->obtenerFechaNacimiento());
    }

    /**
     * Aplica el campo "acudiente" de actualizar (solo administrativo):
     *   - null: quita el acudiente (solo valido si el deportista es mayor de edad;
     *     se valida despues).
     *   - {nombre?, relacion?} sin documento, o con el documento del acudiente
     *     actual: edita los datos del acudiente actual.
     *   - {documento distinto, ...}: reasigna. Si ese acudiente ya existe se
     *     reutiliza sin sobrescribirlo; si no existe, se crea (nombre y relacion
     *     obligatorios).
     * Devuelve [acudiente a crear o null, acudiente a editar o null].
     *
     * @return array{0: ?AcudienteTutorModel, 1: ?AcudienteTutorModel}
     */
    private function aplicarAcudiente(DeportistaModel $modelo, mixed $datosAcudiente): array
    {
        if ($datosAcudiente === null) {
            $modelo->establecerAcudienteDocumento(null);
            return [null, null];
        }
        if (!is_array($datosAcudiente) || $datosAcudiente === []) {
            throw new ExcepcionNegocio('El campo "acudiente" debe ser null o un objeto con documento, nombre y/o relacion.', 400);
        }
        $this->rechazarCamposNoPermitidos($datosAcudiente, self::CAMPOS_ACUDIENTE, 400, 'Campos de acudiente no reconocidos');

        $actual = $modelo->obtenerAcudienteDocumento();
        $documentoPedido = array_key_exists('documento', $datosAcudiente)
            ? $this->texto($datosAcudiente['documento'], 'acudiente.documento', 20, true)
            : null;

        if ($documentoPedido !== null && $documentoPedido !== $actual) {
            [$documentoAcudiente, $acudienteNuevo] = $this->resolverAcudiente($datosAcudiente, $modelo->obtenerDocumento());
            $modelo->establecerAcudienteDocumento($documentoAcudiente);
            return [$acudienteNuevo, null];
        }

        if ($actual === null) {
            throw new ExcepcionNegocio('El deportista no tiene acudiente asignado: envie "acudiente.documento" para asignarle uno.', 400);
        }
        $acudiente = AcudienteTutorModel::fromArray($this->acudiente->obtenerPorId($actual) ?? []);
        if (array_key_exists('nombre', $datosAcudiente)) {
            $acudiente->establecerNombre($this->texto($datosAcudiente['nombre'], 'acudiente.nombre', 150, true));
        }
        if (array_key_exists('relacion', $datosAcudiente)) {
            $acudiente->establecerRelacion($this->texto($datosAcudiente['relacion'], 'acudiente.relacion', 50, true));
        }
        return [null, $acudiente];
    }

    /**
     * Borra el acudiente si ya no tiene ningun deportista asociado. Se llama
     * dentro de la transaccion de eliminar o de reasignar acudiente.
     */
    private function eliminarAcudienteSiQuedaHuerfano(?string $documentoAcudiente): void
    {
        if ($documentoAcudiente !== null
            && $this->deportista->listar(['acudiente_documento' => $documentoAcudiente], 1) === []) {
            $this->acudiente->eliminar($documentoAcudiente);
        }
    }

    /**
     * Devuelve [documento del acudiente, modelo a crear o null si ya existe].
     *
     * @return array{0: string, 1: ?AcudienteTutorModel}
     */
    private function resolverAcudiente(mixed $datosAcudiente, string $documentoDeportista): array
    {
        if (!is_array($datosAcudiente)) {
            throw new ExcepcionNegocio('El campo "acudiente" debe ser un objeto con documento, nombre y relacion.', 400);
        }
        $this->rechazarCamposNoPermitidos($datosAcudiente, self::CAMPOS_ACUDIENTE, 400, 'Campos de acudiente no reconocidos');

        $documento = $this->texto($datosAcudiente['documento'] ?? null, 'acudiente.documento', 20, true);
        if ($documento === $documentoDeportista) {
            throw new ExcepcionNegocio('El deportista no puede ser su propio acudiente.', 400);
        }

        if ($this->acudiente->obtenerPorId($documento) !== null) {
            return [$documento, null];
        }

        return [$documento, new AcudienteTutorModel(
            documento: $documento,
            nombre: $this->texto($datosAcudiente['nombre'] ?? null, 'acudiente.nombre', 150, true),
            relacion: $this->texto($datosAcudiente['relacion'] ?? null, 'acudiente.relacion', 50, true),
        )];
    }

    // ------------------------------------------------------------------
    // Calculos (RN-1.3, RN-3.8)
    // ------------------------------------------------------------------

    private function calcularEdad(string $fechaNacimiento): int
    {
        return (new DateTimeImmutable($fechaNacimiento))->diff(new DateTimeImmutable('today'))->y;
    }

    private function categoriaPorEdad(int $edad): ?string
    {
        foreach (self::CATEGORIAS_EDAD as [$minima, $maxima, $nombre]) {
            if ($edad >= $minima && ($maxima === null || $edad <= $maxima)) {
                return $nombre;
            }
        }
        return null;
    }

    /**
     * Forma de un deportista en las respuestas: tipos correctos, sin
     * estado_animico (reservado, no visible: RN-2.4) y con edad y categoria.
     * validado_por_correo (correo del administrativo que valido) solo sale en
     * el ambito administrativo: la cuenta publica la puede usar cualquier
     * familiar.
     */
    private function formatear(array $fila, bool $esAdministrativo): array
    {
        $deportista = DeportistaModel::fromArray($fila)->toArray();
        unset($deportista['estado_animico']);
        if (!$esAdministrativo) {
            unset($deportista['validado_por_correo']);
        }

        $edad = $this->calcularEdad($deportista['fecha_nacimiento']);
        $deportista['edad'] = $edad;
        $deportista['categoria_edad'] = $this->categoriaPorEdad($edad);

        return $deportista;
    }

    // ------------------------------------------------------------------
    // Validacion de datos de entrada
    // ------------------------------------------------------------------

    private function documentoDe(array $identificador): string
    {
        return $this->texto($identificador['documento'] ?? null, 'documento', 20, true);
    }

    /**
     * @param array<int, string> $permitidos
     */
    private function rechazarCamposNoPermitidos(array $datos, array $permitidos, int $codigoHttp, string $motivo): void
    {
        $sobrantes = array_diff(array_keys($datos), $permitidos);
        if ($sobrantes !== []) {
            throw new ExcepcionNegocio($motivo . ': ' . implode(', ', $sobrantes) . '.', $codigoHttp);
        }
    }

    private function texto(mixed $valor, string $campo, int $maximo, bool $obligatorio): ?string
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            if ($obligatorio) {
                throw new ExcepcionNegocio("El campo \"{$campo}\" es obligatorio.", 400);
            }
            return null;
        }
        if (!is_string($valor) && !is_int($valor)) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser texto.", 400);
        }
        $texto = trim((string) $valor);
        if (mb_strlen($texto) > $maximo) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" admite maximo {$maximo} caracteres.", 400);
        }
        return $texto;
    }

    /**
     * Fecha con formato AAAA-MM-DD, real y no futura.
     */
    private function fecha(mixed $valor, string $campo, bool $obligatorio): ?string
    {
        if ($valor === null || $valor === '') {
            if ($obligatorio) {
                throw new ExcepcionNegocio("El campo \"{$campo}\" es obligatorio.", 400);
            }
            return null;
        }
        $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser una fecha valida con formato AAAA-MM-DD.", 400);
        }
        if ($fecha > new DateTimeImmutable('today')) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" no puede ser una fecha futura.", 400);
        }
        return $valor;
    }

    private function validarFechaIngreso(string $fechaIngreso, string $fechaNacimiento): void
    {
        if ($fechaIngreso < $fechaNacimiento) {
            throw new ExcepcionNegocio('La fecha de ingreso no puede ser anterior a la fecha de nacimiento.', 400);
        }
    }

    /**
     * Numero positivo con maximo 2 decimales que quepa en su columna DECIMAL.
     */
    private function decimal(mixed $valor, string $campo, float $maximo): float
    {
        if ($valor === null || $valor === '') {
            throw new ExcepcionNegocio("El campo \"{$campo}\" es obligatorio.", 400);
        }
        if (!is_int($valor) && !is_float($valor) && !(is_string($valor) && is_numeric($valor))) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser un numero.", 400);
        }
        $numero = round((float) $valor, 2);
        if ($numero <= 0 || $numero > $maximo) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser mayor que 0 y maximo {$maximo}.", 400);
        }
        return $numero;
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

    /**
     * @param array<int, string> $opciones
     */
    private function opcion(mixed $valor, string $campo, array $opciones, bool $obligatorio): ?string
    {
        if ($valor === null || $valor === '') {
            if ($obligatorio) {
                throw new ExcepcionNegocio("El campo \"{$campo}\" es obligatorio.", 400);
            }
            return null;
        }
        if (!is_string($valor) || !in_array($valor, $opciones, true)) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser uno de: " . implode(', ', $opciones) . '.', 400);
        }
        return $valor;
    }

    /**
     * Ejecuta una escritura y convierte una violacion de integridad de la base
     * de datos (SQLSTATE 23000: llave duplicada creada al mismo tiempo, o
     * registros de otros modulos que dependen del deportista) en un 409.
     */
    private function guardarSinDuplicados(callable $escritura): void
    {
        try {
            $escritura();
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                throw new ExcepcionNegocio('La operacion entra en conflicto con datos existentes.', 409);
            }
            throw $error;
        }
    }
}
