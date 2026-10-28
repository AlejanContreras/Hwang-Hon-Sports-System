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
 *     su propio registro y solo edita peso, discapacidad/condicion de salud,
 *     telefono, direccion y correo (RN-1.4).
 *     El acudiente es un dato de contacto que gestiona el club, no una cuenta
 *     (documento, nombre, relacion, telefono y correo; solo el ambito
 *     administrativo lo registra o lo edita).
 *
 * Edad, categoria por edad y categoria por peso (RN-1.3, RN-3.8,
 * Especificacion A.1; tabla de peso en CATEGORIAS_PESO) se calculan al
 * leer y nunca se guardan. consultar agrega el record de combates (suma de
 * participacion_competencia); listar no, para no hacer una consulta por fila.
 * buscar (solo administrativo) es el autocompletado por nombre o documento.
 */
class DeportistaService
{
    /** Pública: la usa también la ficha PDF de Reportes (acudiente si es menor). */
    public const MAYORIA_EDAD = 18;
    private const ZONA_HORARIA = 'America/Bogota';
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

    /**
     * Categoria por peso. Este es el UNICO lugar de la tabla. Cada entrada:
     * [categorias por edad a las que aplica, cortes en kg por sexo]. Corte
     * INCLUSIVO: el peso hasta el corte pertenece a esa categoria ("-40");
     * por encima del ultimo corte, "+X".
     *   - Junior (12-14) y Juvenil (15-17): OFICIALES, Reglamento de
     *     Competencia Fedecolhap v3.1 2024, art. 3 y 4.
     *   - Mayores, Senior y Master: tabla de Mayores (DOCUMENTAL, Contexto
     *     Base seccion 8).
     *   - Infantil: PROPUESTA (Contexto Base 35.5), reemplazable.
     */
    private const CATEGORIAS_PESO = [
        [['Infantil'], [
            'masculino' => [20, 25, 30, 35],
            'femenino' => [20, 25, 30, 35],
        ]],
        [['Junior'], [
            'masculino' => [40, 45, 50, 55, 60],
            'femenino' => [38, 43, 48, 53, 58],
        ]],
        [['Juvenil'], [
            'masculino' => [45, 50, 55, 60, 65, 70, 75],
            'femenino' => [43, 48, 53, 58, 63, 68, 73],
        ]],
        [['Mayores', 'Sénior', 'Máster'], [
            'masculino' => [55, 60, 65, 70, 75, 80, 85],
            'femenino' => [50, 55, 60, 65, 70, 75, 80],
        ]],
    ];

    /** Maximo de resultados del autocompletado (buscar). */
    private const MAXIMO_RESULTADOS_BUSQUEDA = 20;
    private const MINIMO_TEXTO_BUSQUEDA = 2;

    private const FILTROS_ADMINISTRATIVOS = [
        'estado', 'estado_registro', 'sede_entrenamiento', 'grado_actual', 'acudiente_documento',
    ];
    /** Campos del formulario de inscripción; público para el autorregistro. */
    public const CAMPOS_CREAR = [
        'documento', 'nombre', 'fecha_nacimiento', 'lugar_nacimiento', 'sexo', 'estatura', 'peso',
        'telefono', 'direccion', 'correo', 'discapacidad', 'experiencia_previa', 'sede_entrenamiento',
        'autorizacion_datos', 'grado_actual', 'fecha_ingreso', 'estado', 'acudiente',
    ];
    private const CAMPOS_ACTUALIZAR_ADMINISTRATIVO = [
        'nombre', 'fecha_nacimiento', 'lugar_nacimiento', 'sexo', 'estatura', 'peso', 'telefono',
        'direccion', 'correo', 'discapacidad', 'experiencia_previa', 'sede_entrenamiento',
        'autorizacion_datos', 'grado_actual', 'fecha_ingreso', 'estado', 'estado_registro', 'acudiente',
    ];
    private const CAMPOS_ACTUALIZAR_PUBLICO = ['peso', 'discapacidad', 'telefono', 'direccion', 'correo'];
    private const CAMPOS_ACUDIENTE = ['documento', 'nombre', 'relacion', 'telefono', 'correo'];

    /**
     * experiencia_previa es TEXT (65.535 bytes). Un caracter utf8mb4 ocupa
     * hasta 4 bytes: con 16.000 caracteres nunca se pasa del limite.
     */
    private const MAXIMO_EXPERIENCIA_PREVIA = 16000;

    private DeportistaRepository $deportista;
    private AcudienteTutorRepository $acudiente;
    private ParticipacionCompetenciaRepository $participacion;

    public function __construct()
    {
        $this->deportista = new DeportistaRepository();
        $this->acudiente = new AcudienteTutorRepository();
        $this->participacion = new ParticipacionCompetenciaRepository();
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
        $record = $this->participacion->recordDeCombates($documento);
        $ficha['combates_ganados'] = $record['combates_ganados'];
        $ficha['combates_perdidos'] = $record['combates_perdidos'];
        $ficha['acudiente'] = $fila['acudiente_documento'] === null
            ? null
            : $this->acudiente->obtenerPorId($fila['acudiente_documento']);

        return $ficha;
    }

    /**
     * Autocompletado (solo administrativo): documento que EMPIEZA por el texto
     * o nombre que lo CONTIENE (la collation de la tabla ignora mayusculas y
     * tildes). El texto es literal: los comodines de LIKE se escapan en el
     * Repository. Incluye inactivos y pendientes de validacion (RN-3.9).
     * Maximo 20 resultados, ordenados por nombre y documento, solo con datos
     * de identificacion (sin salud ni contacto).
     *
     * @return array<int, array{documento: string, nombre: string, estado: string, estado_registro: string, grado_actual: ?string}>
     */
    public function buscar(array $parametros): array
    {
        $this->exigirAdministrativo('buscar deportistas');

        $texto = $parametros['texto'] ?? null;
        if (!is_string($texto) || mb_strlen(trim($texto)) < self::MINIMO_TEXTO_BUSQUEDA) {
            throw new ExcepcionNegocio('Escribe al menos 2 caracteres para buscar.', 400);
        }

        return $this->deportista->buscar(trim($texto), self::MAXIMO_RESULTADOS_BUSQUEDA);
    }

    /**
     * Registra un deportista (solo ambito administrativo; el autorregistro
     * RN-T.14 vive en AutorregistroService y reutiliza modeloAlta,
     * acudienteAlta y guardarAlta). Queda validado y activo por defecto.
     * Si es menor de edad exige acudiente: reutiliza uno existente sin
     * modificarlo, o lo crea en la misma transaccion.
     */
    public function crear(array $datos): array
    {
        $this->exigirAdministrativo('registrar deportistas');
        $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_CREAR, 400, 'Campos no reconocidos');

        $modelo = $this->modeloAlta($datos, 'validado', null);
        $documento = $modelo->obtenerDocumento();
        if ($this->deportista->existePorId($documento)) {
            throw new ExcepcionNegocio("Ya existe un deportista con el documento {$documento}.", 409);
        }
        $acudienteNuevo = $this->acudienteAlta($modelo, $datos['acudiente'] ?? null);
        $this->guardarAlta($modelo, $acudienteNuevo);

        return $this->consultar(['documento' => $documento]);
    }

    /**
     * Valida los campos del formulario de inscripción con las reglas de
     * crear (sin el acudiente y sin tocar la base de datos) y arma el
     * modelo. La reutilizan el autorregistro (pendiente_validacion) y la
     * carga masiva (validado por quien la carga).
     */
    public function modeloAlta(array $datos, string $estadoRegistro, ?string $validadoPorCorreo): DeportistaModel
    {
        $documento = Validador::texto($datos['documento'] ?? null, 'documento', 20, true);
        $fechaNacimiento = $this->fecha($datos['fecha_nacimiento'] ?? null, 'fecha_nacimiento', true);
        $fechaIngreso = $this->fecha($datos['fecha_ingreso'] ?? null, 'fecha_ingreso', false)
            ?? self::hoy();
        $this->validarFechaIngreso($fechaIngreso, $fechaNacimiento);

        $modelo = new DeportistaModel(
            documento: $documento,
            nombre: Validador::texto($datos['nombre'] ?? null, 'nombre', 150, true),
            fechaNacimiento: $fechaNacimiento,
            lugarNacimiento: Validador::texto($datos['lugar_nacimiento'] ?? null, 'lugar_nacimiento', 100, false),
            sexo: $this->opcion($datos['sexo'] ?? null, 'sexo', self::SEXOS, true),
            estatura: $this->decimal($datos['estatura'] ?? null, 'estatura', 99.99),
            peso: $this->decimal($datos['peso'] ?? null, 'peso', 999.99),
            telefono: $this->telefono($datos['telefono'] ?? null),
            direccion: Validador::texto($datos['direccion'] ?? null, 'direccion', 150, false),
            correo: Validador::correoOpcional($datos['correo'] ?? null),
            discapacidad: Validador::texto($datos['discapacidad'] ?? null, 'discapacidad', 255, false),
            experienciaPrevia: Validador::texto($datos['experiencia_previa'] ?? null, 'experiencia_previa', self::MAXIMO_EXPERIENCIA_PREVIA, false),
            estadoAnimico: null,
            sedeEntrenamiento: Validador::texto($datos['sede_entrenamiento'] ?? null, 'sede_entrenamiento', 100, false),
            autorizacionDatos: $this->booleano($datos['autorizacion_datos'] ?? false, 'autorizacion_datos'),
            gradoActual: Validador::texto($datos['grado_actual'] ?? null, 'grado_actual', 30, false),
            fechaIngreso: $fechaIngreso,
            estado: $this->opcion($datos['estado'] ?? null, 'estado', self::ESTADOS, false) ?? 'activo',
            estadoRegistro: $estadoRegistro,
            acudienteDocumento: null,
            validadoPorCorreo: $validadoPorCorreo,
        );

        return $modelo;
    }

    /**
     * Acudiente del alta: obligatorio si el deportista es menor de edad;
     * uno existente se reutiliza sin modificarlo, uno nuevo se devuelve para
     * crearlo con guardarAlta. Asigna su documento al modelo.
     *
     * @param array<string, mixed>|null $acudientesExistentes documento => fila, precargados en bloque
     *        (carga masiva); null = consultar la base de datos.
     * @param bool $exigirDatosCompletos valida nombre y relación aunque el acudiente ya exista
     *        (autorregistro: la respuesta no debe revelar si un acudiente existe).
     */
    public function acudienteAlta(DeportistaModel $modelo, mixed $datosAcudiente, ?array $acudientesExistentes = null, bool $exigirDatosCompletos = false): ?AcudienteTutorModel
    {
        if ($datosAcudiente === null && $this->calcularEdad($modelo->obtenerFechaNacimiento()) < self::MAYORIA_EDAD) {
            throw new ExcepcionNegocio('El deportista es menor de edad: se requiere un acudiente.', 400);
        }
        if ($datosAcudiente === null) {
            return null;
        }
        [$documentoAcudiente, $acudienteNuevo] = $this->resolverAcudiente($datosAcudiente, $modelo->obtenerDocumento(), $acudientesExistentes, $exigirDatosCompletos);
        $modelo->establecerAcudienteDocumento($documentoAcudiente);
        return $acudienteNuevo;
    }

    /**
     * Guarda un alta ya validada (y su acudiente nuevo) en una transacción.
     * Una llave duplicada creada al mismo tiempo responde 409.
     */
    public function guardarAlta(DeportistaModel $modelo, ?AcudienteTutorModel $acudienteNuevo): void
    {
        $this->guardarSinDuplicados(function () use ($modelo, $acudienteNuevo): void {
            $this->deportista->enTransaccion(function () use ($modelo, $acudienteNuevo): void {
                if ($acudienteNuevo !== null) {
                    $this->acudiente->crear($acudienteNuevo);
                }
                $this->deportista->crear($modelo);
            });
        });
    }

    /**
     * Administrativo: edita cualquier campo menos el documento. Con "acudiente"
     * puede editar nombre, relacion, telefono y correo del acudiente actual o reasignar otro
     * (ver aplicarAcudiente). Al pasar de pendiente_validacion a validado
     * registra quien valido (RN-T.14). No se permite volver de validado a
     * pendiente (Especificacion B.4).
     * Publico: solo peso, discapacidad, telefono, direccion y correo, y solo
     * sobre su propio registro (RN-1.4).
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
            throw new ExcepcionNegocio('No se recibió ningún campo para actualizar.', 400);
        }

        $esAdministrativo = $usuario['ambito'] === 'administrativa';
        if ($esAdministrativo) {
            $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_ACTUALIZAR_ADMINISTRATIVO, 400, 'Campos no reconocidos');
        } else {
            $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_ACTUALIZAR_PUBLICO, 403, 'Campos que no puede editar');
        }

        $cambios = $this->prepararCambios($fila, $datos, $esAdministrativo ? $usuario['identificador'] : null);
        $this->guardarCambios($cambios);

        return $this->consultar(['documento' => $documento]);
    }

    /**
     * Aplica sobre la fila actual los campos de $datos con las reglas de
     * actualizar, sin guardar. $correoAdministrativo null = ámbito público
     * (solo los campos de RN-1.4; el llamador ya rechazó los demás). La
     * reutiliza la carga masiva con acudientes precargados en bloque.
     *
     * @param array<string, mixed>|null $acudientes documento => fila; null = consultar la base de datos
     * @return array{modelo: DeportistaModel, acudiente_nuevo: ?AcudienteTutorModel, acudiente_editado: ?AcudienteTutorModel, acudiente_anterior: ?string}
     */
    public function prepararCambios(array $fila, array $datos, ?string $correoAdministrativo, ?array $acudientes = null): array
    {
        $modelo = DeportistaModel::fromArray($fila);

        if (array_key_exists('peso', $datos)) {
            $modelo->establecerPeso($this->decimal($datos['peso'], 'peso', 999.99));
        }
        if (array_key_exists('discapacidad', $datos)) {
            $modelo->establecerDiscapacidad(Validador::texto($datos['discapacidad'], 'discapacidad', 255, false));
        }
        if (array_key_exists('telefono', $datos)) {
            $modelo->establecerTelefono($this->telefono($datos['telefono']));
        }
        if (array_key_exists('direccion', $datos)) {
            $modelo->establecerDireccion(Validador::texto($datos['direccion'], 'direccion', 150, false));
        }
        if (array_key_exists('correo', $datos)) {
            $modelo->establecerCorreo(Validador::correoOpcional($datos['correo']));
        }

        $acudienteNuevo = null;
        $acudienteEditado = null;
        if ($correoAdministrativo !== null) {
            $this->aplicarCamposAdministrativos($modelo, $datos, $fila, $correoAdministrativo);
            if (array_key_exists('acudiente', $datos)) {
                [$acudienteNuevo, $acudienteEditado] = $this->aplicarAcudiente($modelo, $datos['acudiente'], $acudientes);
            }
        }

        if ($modelo->obtenerAcudienteDocumento() === null
            && $this->calcularEdad($modelo->obtenerFechaNacimiento()) < self::MAYORIA_EDAD) {
            throw new ExcepcionNegocio('El deportista es menor de edad y no tiene acudiente asignado.', 400);
        }

        return [
            'modelo' => $modelo,
            'acudiente_nuevo' => $acudienteNuevo,
            'acudiente_editado' => $acudienteEditado,
            'acudiente_anterior' => $fila['acudiente_documento'],
        ];
    }

    /**
     * Guarda en una transacción lo que armó prepararCambios; si el acudiente
     * cambió, borra el anterior si quedó sin deportistas.
     *
     * @param array{modelo: DeportistaModel, acudiente_nuevo: ?AcudienteTutorModel, acudiente_editado: ?AcudienteTutorModel, acudiente_anterior: ?string} $cambios
     */
    public function guardarCambios(array $cambios): void
    {
        ['modelo' => $modelo, 'acudiente_nuevo' => $acudienteNuevo, 'acudiente_editado' => $acudienteEditado, 'acudiente_anterior' => $acudienteAnterior] = $cambios;
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
    }

    /**
     * Elimina un registro (solo ambito administrativo y solo si esta en
     * pendiente_validacion: Especificacion B.4, no existe estado "rechazado").
     * Un deportista validado no se elimina: se pasa a inactivo con actualizar.
     * En la misma transaccion borra sus documentos (los que subio en el
     * autorregistro) y su acudiente si queda sin deportistas; los archivos se
     * borran del disco despues del commit. Si tiene pagos, asistencia,
     * torneos o examenes, responde 409 sin borrar nada.
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
                'Solo se eliminan registros pendientes de validación. Para un deportista validado, cambie su estado a inactivo.',
                409
            );
        }

        $documentoAcudiente = $fila['acudiente_documento'];
        $documentos = new DocumentoRepository();
        $archivos = array_column($documentos->listar(['deportista_documento' => $documento]), 'archivo');
        try {
            // Una transacción: sus documentos (p. ej. los del autorregistro),
            // el deportista y su acudiente si queda sin deportistas.
            $this->deportista->enTransaccion(function () use ($documentos, $documento, $documentoAcudiente): void {
                $documentos->eliminarDeDeportista($documento);
                $this->deportista->eliminar($documento);
                $this->eliminarAcudienteSiQuedaHuerfano($documentoAcudiente);
            });
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                // Otros módulos lo referencian (pagos, asistencia, torneos o
                // exámenes): esos datos no se borran en silencio.
                throw new ExcepcionNegocio('El registro ya tiene datos en otros módulos (pagos, asistencia, torneos o exámenes): no se puede eliminar.', 409);
            }
            throw $error;
        }
        // Los archivos, solo después del commit: si algo falló antes, siguen
        // en disco junto con sus filas (nunca filas sin archivo).
        (new DocumentoService())->borrarArchivosDeDocumentos($archivos);
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
            $modelo->establecerNombre(Validador::texto($datos['nombre'], 'nombre', 150, true));
        }
        if (array_key_exists('fecha_nacimiento', $datos)) {
            $modelo->establecerFechaNacimiento($this->fecha($datos['fecha_nacimiento'], 'fecha_nacimiento', true));
        }
        if (array_key_exists('lugar_nacimiento', $datos)) {
            $modelo->establecerLugarNacimiento(Validador::texto($datos['lugar_nacimiento'], 'lugar_nacimiento', 100, false));
        }
        if (array_key_exists('experiencia_previa', $datos)) {
            $modelo->establecerExperienciaPrevia(
                Validador::texto($datos['experiencia_previa'], 'experiencia_previa', self::MAXIMO_EXPERIENCIA_PREVIA, false)
            );
        }
        if (array_key_exists('sexo', $datos)) {
            $modelo->establecerSexo($this->opcion($datos['sexo'], 'sexo', self::SEXOS, true));
        }
        if (array_key_exists('estatura', $datos)) {
            $modelo->establecerEstatura($this->decimal($datos['estatura'], 'estatura', 99.99));
        }
        if (array_key_exists('sede_entrenamiento', $datos)) {
            $modelo->establecerSedeEntrenamiento(Validador::texto($datos['sede_entrenamiento'], 'sede_entrenamiento', 100, false));
        }
        if (array_key_exists('autorizacion_datos', $datos)) {
            $modelo->establecerAutorizacionDatos($this->booleano($datos['autorizacion_datos'], 'autorizacion_datos'));
        }
        if (array_key_exists('grado_actual', $datos)) {
            $modelo->establecerGradoActual(Validador::texto($datos['grado_actual'], 'grado_actual', 30, false));
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
                throw new ExcepcionNegocio('Un registro validado no puede volver a pendiente de validación.', 409);
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
     *   - {nombre?, relacion?, telefono?, correo?} sin documento, o con el
     *     documento del acudiente actual: edita los datos del acudiente actual
     *     (telefono y correo admiten null para borrarlos).
     *   - {documento distinto, ...}: reasigna. Si ese acudiente ya existe se
     *     reutiliza sin sobrescribirlo; si no existe, se crea (nombre y relacion
     *     obligatorios; telefono y correo opcionales).
     * Devuelve [acudiente a crear o null, acudiente a editar o null].
     *
     * @return array{0: ?AcudienteTutorModel, 1: ?AcudienteTutorModel}
     */
    private function aplicarAcudiente(DeportistaModel $modelo, mixed $datosAcudiente, ?array $acudientes = null): array
    {
        if ($datosAcudiente === null) {
            $modelo->establecerAcudienteDocumento(null);
            return [null, null];
        }
        if (!is_array($datosAcudiente) || $datosAcudiente === []) {
            throw new ExcepcionNegocio('El campo "acudiente" debe ser null o un objeto con documento, nombre, relación, teléfono y/o correo.', 400);
        }
        $this->rechazarCamposNoPermitidos($datosAcudiente, self::CAMPOS_ACUDIENTE, 400, 'Campos de acudiente no reconocidos');

        $actual = $modelo->obtenerAcudienteDocumento();
        $documentoPedido = array_key_exists('documento', $datosAcudiente)
            ? Validador::texto($datosAcudiente['documento'], 'acudiente.documento', 20, true)
            : null;

        if ($documentoPedido !== null && $documentoPedido !== $actual) {
            [$documentoAcudiente, $acudienteNuevo] = $this->resolverAcudiente($datosAcudiente, $modelo->obtenerDocumento(), $acudientes);
            $modelo->establecerAcudienteDocumento($documentoAcudiente);
            return [$acudienteNuevo, null];
        }

        if ($actual === null) {
            throw new ExcepcionNegocio('El deportista no tiene acudiente asignado: envíe "acudiente.documento" para asignarle uno.', 400);
        }
        $filaActual = $acudientes === null ? $this->acudiente->obtenerPorId($actual) : ($acudientes[$actual] ?? null);
        $acudiente = AcudienteTutorModel::fromArray($filaActual ?? []);
        if (array_key_exists('nombre', $datosAcudiente)) {
            $acudiente->establecerNombre(Validador::texto($datosAcudiente['nombre'], 'acudiente.nombre', 150, true));
        }
        if (array_key_exists('relacion', $datosAcudiente)) {
            $acudiente->establecerRelacion(Validador::texto($datosAcudiente['relacion'], 'acudiente.relacion', 50, true));
        }
        if (array_key_exists('telefono', $datosAcudiente)) {
            $acudiente->establecerTelefono($this->telefono($datosAcudiente['telefono'], 'acudiente.telefono'));
        }
        if (array_key_exists('correo', $datosAcudiente)) {
            $acudiente->establecerCorreo(Validador::correoOpcional($datosAcudiente['correo'], 'acudiente.correo'));
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
     * Un acudiente existente se reutiliza tal cual: los demas datos que
     * traiga la peticion (nombre, relacion, telefono, correo) se ignoran.
     * Con $exigirDatosCompletos se validan siempre, exista o no (asi la
     * respuesta no revela si un acudiente existe). $existentes: documento =>
     * fila precargados en bloque; null = consultar la base de datos.
     *
     * @return array{0: string, 1: ?AcudienteTutorModel}
     */
    private function resolverAcudiente(mixed $datosAcudiente, string $documentoDeportista, ?array $existentes = null, bool $exigirDatosCompletos = false): array
    {
        if (!is_array($datosAcudiente)) {
            throw new ExcepcionNegocio('El campo "acudiente" debe ser un objeto con documento, nombre, relación y, opcionalmente, teléfono y correo.', 400);
        }
        $this->rechazarCamposNoPermitidos($datosAcudiente, self::CAMPOS_ACUDIENTE, 400, 'Campos de acudiente no reconocidos');

        $documento = Validador::texto($datosAcudiente['documento'] ?? null, 'acudiente.documento', 20, true);
        if ($documento === $documentoDeportista) {
            throw new ExcepcionNegocio('El deportista no puede ser su propio acudiente.', 400);
        }

        $nuevo = fn (): AcudienteTutorModel => new AcudienteTutorModel(
            documento: $documento,
            nombre: Validador::texto($datosAcudiente['nombre'] ?? null, 'acudiente.nombre', 150, true),
            relacion: Validador::texto($datosAcudiente['relacion'] ?? null, 'acudiente.relacion', 50, true),
            telefono: $this->telefono($datosAcudiente['telefono'] ?? null, 'acudiente.telefono'),
            correo: Validador::correoOpcional($datosAcudiente['correo'] ?? null, 'acudiente.correo'),
        );
        $modelo = $exigirDatosCompletos ? $nuevo() : null;

        $existe = $existentes === null ? $this->acudiente->existePorId($documento) : isset($existentes[$documento]);
        if ($existe) {
            return [$documento, null];
        }

        return [$documento, $modelo ?? $nuevo()];
    }

    // ------------------------------------------------------------------
    // Calculos (RN-1.3, RN-3.8)
    // ------------------------------------------------------------------

    /**
     * Fecha de hoy en Bogotá (AAAA-MM-DD), sin depender de la zona por
     * defecto del servidor.
     */
    private static function hoy(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone(self::ZONA_HORARIA)))->format('Y-m-d');
    }

    private function calcularEdad(string $fechaNacimiento): int
    {
        return self::edadEnFecha($fechaNacimiento, self::hoy());
    }

    /**
     * Edad en años cumplidos a una fecha de referencia (hoy, o la fecha de
     * un torneo en Competencias, RN-3.8). Público y estático para que otros
     * módulos lo reutilicen sin duplicar la regla.
     */
    public static function edadEnFecha(string $fechaNacimiento, string $fechaReferencia): int
    {
        return (new DateTimeImmutable($fechaNacimiento))->diff(new DateTimeImmutable($fechaReferencia))->y;
    }

    /**
     * Categoría por edad (RN-3.8) con su nombre para mostrar (ej. "Sénior"),
     * o null si es menor de 8 años.
     */
    public static function categoriaPorEdad(int $edad): ?string
    {
        foreach (self::CATEGORIAS_EDAD as [$minima, $maxima, $nombre]) {
            if ($edad >= $minima && ($maxima === null || $edad <= $maxima)) {
                return $nombre;
            }
        }
        return null;
    }

    /**
     * Categoria por peso segun CATEGORIAS_PESO, a partir de la categoria por
     * edad ya calculada (misma edad). null si no hay categoria por edad
     * (menor de 8) o si falta el peso.
     */
    public static function categoriaPorPeso(?string $categoriaEdad, string $sexo, float $peso): ?string
    {
        $cortes = self::cortesDePeso($categoriaEdad, $sexo);
        if ($cortes === null || $peso <= 0) {
            return null;
        }
        foreach ($cortes as $corte) {
            if ($peso <= $corte) {
                return '-' . $corte;
            }
        }
        return '+' . end($cortes);
    }

    /**
     * Cortes de peso (kg) de CATEGORIAS_PESO para una categoría por edad
     * (nombre para mostrar) y un sexo, o null si no hay tabla.
     *
     * @return array<int, int>|null
     */
    public static function cortesDePeso(?string $categoriaEdad, string $sexo): ?array
    {
        if ($categoriaEdad === null) {
            return null;
        }
        foreach (self::CATEGORIAS_PESO as [$categoriasEdad, $cortesPorSexo]) {
            if (in_array($categoriaEdad, $categoriasEdad, true)) {
                return $cortesPorSexo[$sexo] ?? null;
            }
        }
        return null;
    }

    /**
     * Forma de un deportista en las respuestas: tipos correctos, sin
     * estado_animico (reservado, no visible: RN-2.4) y con edad, categoria
     * por edad y categoria por peso.
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
        $deportista['categoria_edad'] = self::categoriaPorEdad($edad);
        $deportista['categoria_peso'] = self::categoriaPorPeso(
            $deportista['categoria_edad'],
            $deportista['sexo'],
            $deportista['peso']
        );

        return $deportista;
    }

    // ------------------------------------------------------------------
    // Validacion de datos de entrada
    // ------------------------------------------------------------------

    private function documentoDe(array $identificador): string
    {
        return Validador::texto($identificador['documento'] ?? null, 'documento', 20, true);
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

    /**
     * Telefono opcional (del deportista o del acudiente; mismo largo de
     * columna, VARCHAR 20): digitos y espacios, con un + inicial opcional
     * (ej. "+57 300 000 0000"). No se exige una longitud minima ni un
     * formato de pais: el club registra lo que trae el formulario.
     */
    private function telefono(mixed $valor, string $campo = 'telefono'): ?string
    {
        $telefono = Validador::texto($valor, $campo, 20, false);
        if ($telefono !== null && preg_match('/^\+?[0-9][0-9 ]*$/', $telefono) !== 1) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" solo admite dígitos, espacios y un + inicial.", 400);
        }
        return $telefono;
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
        $fecha = Validador::esFechaIso($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser una fecha válida con formato AAAA-MM-DD.", 400);
        }
        if ($valor > self::hoy()) {
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
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser un número.", 400);
        }
        $numero = round((float) $valor, 2);
        if ($numero <= 0 || $numero > $maximo) {
            throw new ExcepcionNegocio("El campo \"{$campo}\" debe ser mayor que 0 y máximo {$maximo}.", 400);
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
                throw new ExcepcionNegocio('La operación entra en conflicto con datos existentes.', 409);
            }
            throw $error;
        }
    }
}
