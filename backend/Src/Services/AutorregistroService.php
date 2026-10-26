<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del autorregistro (RN-T.14; Contexto Base §16,
 * §15 y §35.16). Ruta pública, SOLO para registrar: no da acceso a
 * `deportistas` ni a ninguna ruta autenticada.
 *
 *   - registrar: el mismo formulario de inscripción del administrativo,
 *     validado con las reglas de DeportistaService (modeloAlta,
 *     acudienteAlta). El servidor fija estado_registro =
 *     pendiente_validacion, validado_por_correo = null, estado = activo,
 *     grado_actual = null (el de crear) y fecha_ingreso = hoy (Bogotá); si
 *     esos campos llegan en el cuerpo se ignoran. autorizacion_datos debe
 *     ser true.
 *   - Un registro pendiente no entra por la cuenta pública (AuthService: «tu
 *     registro está en revisión») y solo el administrativo lo ve, lo valida
 *     o lo elimina (DeportistaService; no hay estado «rechazado»).
 *   - Documentos: registrar abre un permiso temporal en la sesión de PHP
 *     (Sesion::abrirRegistro), ligado a ese único documento y sin cuenta.
 *     Mientras siga vigente y el registro siga pendiente, el registrante
 *     lista, sube, reemplaza y elimina SOLO los suyos (DocumentoService).
 *     confirmar («Guardar cambios») lo cierra; también vence por tiempo.
 *
 * Anti-spam, sin CAPTCHA (PROPUESTA técnica del backend, §35.16):
 *   - Campo trampa "sitio_web" (oculto en el formulario): si llega con valor,
 *     la respuesta es idéntica a un registro exitoso, pero no se crea nada.
 *   - Límite por IP: MAXIMO_REGISTROS_POR_IP intentos válidos por ventana de
 *     VENTANA_MINUTOS (429 al superarlo), contados en `intento_acceso` sin
 *     cambiar la tabla (ver IntentoAccesoRepository::contarEnVentana).
 *   - Tope del cuerpo JSON (413) y, en documentos, el tope y los formatos de
 *     DocumentoService.
 *   - Documento ya registrado: la misma respuesta de éxito, sin crear nada
 *     ni abrir un permiso real (no permite enumerar documentos).
 */
class AutorregistroService
{
    private const ZONA_HORARIA = 'America/Bogota';
    /** PROPUESTA: vigencia del permiso de documentos desde que se registra. */
    private const VIGENCIA_PERMISO_SEGUNDOS = 7200;
    /**
     * PROPUESTA: registros por IP en la ventana. 10 y no 5: en un día de
     * inscripciones varias familias se registran desde el wifi del club, que
     * sale a internet con una sola IP.
     */
    private const MAXIMO_REGISTROS_POR_IP = 10;
    private const VENTANA_MINUTOS = 60;
    /** El formulario más grande cabe de sobra (experiencia_previa: 16.000 caracteres). */
    private const MAXIMO_CUERPO_BYTES = 128 * 1024;
    private const CAMPO_TRAMPA = 'sitio_web';
    /**
     * Prefijo del identificador en intento_acceso: con él, el identificador
     * supera los 20 caracteres que admite un documento en el login público,
     * así nunca choca con un contador de inicio de sesión.
     */
    private const PREFIJO_IP = 'autorregistro-ip:';
    /** Campos que fija el servidor: si llegan, se ignoran. */
    private const CAMPOS_FORZADOS = ['estado', 'estado_registro', 'validado_por_correo', 'validado_por', 'grado_actual', 'fecha_ingreso'];
    private const MENSAJE_RECIBIDO = 'Registro recibido. Queda en revisión hasta que el club lo valide; mientras tanto puedes cargar tus documentos.';
    private const MENSAJE_SIN_PERMISO = 'No hay un registro abierto para tus documentos: ya se confirmó o venció. Comunícate con el club.';
    private const FORMATO_FECHA_HORA = 'Y-m-d H:i:s';

    private DeportistaRepository $deportista;
    private IntentoAccesoRepository $intentoAcceso;

    public function __construct()
    {
        $this->deportista = new DeportistaRepository();
        $this->intentoAcceso = new IntentoAccesoRepository();
    }

    /**
     * Registra un deportista pendiente de validación (JSON). Siempre responde
     * lo mismo si los datos son válidos: registro nuevo, campo trampa con
     * valor o documento ya registrado.
     */
    public function registrar(): array
    {
        $datos = $this->cuerpo();

        $trampa = $datos[self::CAMPO_TRAMPA] ?? null;
        unset($datos[self::CAMPO_TRAMPA]);
        if ($trampa !== null && $trampa !== '' && !(is_string($trampa) && trim($trampa) === '')) {
            $this->contarIntento();
            return $this->respuestaRecibido(null);
        }

        foreach (self::CAMPOS_FORZADOS as $campo) {
            unset($datos[$campo]);
        }
        $permitidos = array_diff(DeportistaService::CAMPOS_CREAR, self::CAMPOS_FORZADOS);
        $sobrantes = array_diff(array_keys($datos), $permitidos);
        if ($sobrantes !== []) {
            throw new ExcepcionNegocio('Campos no reconocidos: ' . implode(', ', $sobrantes) . '.', 400);
        }

        $servicio = new DeportistaService();
        $modelo = $servicio->modeloAlta($datos, 'pendiente_validacion', null);
        if (!$modelo->obtenerAutorizacionDatos()) {
            throw new ExcepcionNegocio('Debes aceptar la autorización de tratamiento de datos para registrarte.', 400);
        }
        $acudienteNuevo = $servicio->acudienteAlta($modelo, $datos['acudiente'] ?? null, null, true);

        $this->contarIntento();
        $documento = $modelo->obtenerDocumento();
        if ($this->deportista->existePorId($documento)) {
            return $this->respuestaRecibido(null);
        }
        try {
            $servicio->guardarAlta($modelo, $acudienteNuevo);
        } catch (ExcepcionNegocio $error) {
            // Mismo documento registrado al mismo tiempo: igual que si ya existiera.
            if ($error->obtenerCodigoHttp() !== 409) {
                throw $error;
            }
            return $this->respuestaRecibido(null);
        }
        return $this->respuestaRecibido($documento);
    }

    /**
     * @return array{documentos: array<int, array<string, mixed>>, vigente_hasta: string}
     */
    public function listarDocumentos(): array
    {
        [$documento, $expira] = $this->permiso();
        return ['documentos' => (new DocumentoService())->listarDeRegistro($documento), 'vigente_hasta' => $this->fechaHora($expira)];
    }

    public function subirDocumento(array $datos): array
    {
        [$documento] = $this->permiso();
        return (new DocumentoService())->subirDeRegistro($documento, $datos);
    }

    public function reemplazarDocumento(array $parametros): array
    {
        [$documento] = $this->permiso();
        return (new DocumentoService())->reemplazarDeRegistro($documento, $parametros);
    }

    public function eliminarDocumento(array $parametros): void
    {
        [$documento] = $this->permiso();
        (new DocumentoService())->eliminarDeRegistro($documento, $parametros);
    }

    /**
     * «Guardar cambios»: cierra el permiso. Desde ahí, los documentos solo los
     * cambia el ámbito administrativo.
     */
    public function confirmar(): array
    {
        if (Sesion::registroAbierto() === null) {
            throw new ExcepcionNegocio(self::MENSAJE_SIN_PERMISO, 403);
        }
        Sesion::cerrarRegistro();
        return ['mensaje' => 'Documentos guardados. Desde ahora cualquier cambio lo hace el club.'];
    }

    // ------------------------------------------------------------------
    // Apoyo
    // ------------------------------------------------------------------

    /**
     * Cuerpo JSON del registro: tope de tamaño (413), no vacío y objeto (400).
     */
    private function cuerpo(): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAXIMO_CUERPO_BYTES) {
            throw new ExcepcionNegocio('La solicitud es demasiado grande.', 413);
        }
        $crudo = (string) file_get_contents('php://input', false, null, 0, self::MAXIMO_CUERPO_BYTES + 1);
        if (strlen($crudo) > self::MAXIMO_CUERPO_BYTES) {
            throw new ExcepcionNegocio('La solicitud es demasiado grande.', 413);
        }
        if (trim($crudo) === '') {
            throw new ExcepcionNegocio('El cuerpo de la solicitud está vacío: envía el formulario en JSON.', 400);
        }
        $datos = json_decode($crudo, true);
        if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) {
            throw new ExcepcionNegocio('Cuerpo de la solicitud invalido: se esperaba un objeto JSON.', 400);
        }
        return $datos;
    }

    /**
     * Cuenta un intento válido de esta IP en la ventana; 429 al superar el
     * máximo (los intentos rechazados también cuentan).
     */
    private function contarIntento(): void
    {
        $ahora = new DateTimeImmutable('now', new DateTimeZone(self::ZONA_HORARIA));
        $this->intentoAcceso->limpiarDeVezEnCuando($ahora->modify('-7 days')->format(self::FORMATO_FECHA_HORA));
        $total = $this->intentoAcceso->contarEnVentana(
            'publica',
            self::PREFIJO_IP . ($_SERVER['REMOTE_ADDR'] ?? 'desconocida'),
            $ahora->format(self::FORMATO_FECHA_HORA),
            $ahora->modify('+' . self::VENTANA_MINUTOS . ' minutes')->format(self::FORMATO_FECHA_HORA)
        );
        if ($total > self::MAXIMO_REGISTROS_POR_IP) {
            throw new ExcepcionNegocio('Se recibieron demasiados registros desde esta conexión. Intenta de nuevo más tarde.', 429);
        }
    }

    /**
     * Abre el permiso (real con $documento; vacío con null) y arma la
     * respuesta, idéntica en los dos casos: solo el mensaje y el estado de
     * los documentos, sin datos ni ids internos.
     */
    private function respuestaRecibido(?string $documento): array
    {
        Sesion::abrirRegistro($documento, self::VIGENCIA_PERMISO_SEGUNDOS);
        return [
            'mensaje' => self::MENSAJE_RECIBIDO,
            'documentos' => [
                'carga_habilitada' => true,
                'vigente_hasta' => $this->fechaHora(time() + self::VIGENCIA_PERMISO_SEGUNDOS),
                'tipos_permitidos' => DocumentoService::TIPOS_AUTORREGISTRO,
                'cargados' => [],
            ],
        ];
    }

    /**
     * Documento del permiso vigente; 403 si no hay, si es un permiso vacío o
     * si el registro ya no está pendiente (en ese caso lo cierra).
     *
     * @return array{0: string, 1: int}
     */
    private function permiso(): array
    {
        $registro = Sesion::registroAbierto();
        if ($registro === null || $registro['documento'] === null) {
            throw new ExcepcionNegocio(self::MENSAJE_SIN_PERMISO, 403);
        }
        $fila = $this->deportista->obtenerPorId($registro['documento']);
        if ($fila === null || $fila['estado_registro'] !== 'pendiente_validacion') {
            Sesion::cerrarRegistro();
            throw new ExcepcionNegocio(self::MENSAJE_SIN_PERMISO, 403);
        }
        return [$registro['documento'], $registro['expira']];
    }

    private function fechaHora(int $marca): string
    {
        return (new DateTimeImmutable('@' . $marca))->setTimezone(new DateTimeZone(self::ZONA_HORARIA))->format(DATE_ATOM);
    }
}
