<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del módulo Auth (RN-T.10 a RN-T.18; Especificación
 * B.3, B.5, B.7, B.8). Módulo transversal: no tiene tabla propia de usuarios;
 * usa UsuarioAdministrativoRepository y DeportistaRepository para validar las
 * credenciales de cada ámbito, e IntentoAccesoRepository para el bloqueo.
 *
 *   - Administrativo: correo + contraseña (hash bcrypt).
 *   - Público: documento + fecha de nacimiento DEL DEPORTISTA (RN-T.16).
 *   - Bloqueo: 4 fallos consecutivos bloquean 15 minutos, por ámbito e
 *     identificador, exista o no la cuenta (no revela cuáles existen). Un
 *     fallo después de vencido el bloqueo vuelve a contar desde 1 (B.5).
 *   - Recuperación (RN-T.12): token aleatorio de un solo uso, 1 hora de
 *     vigencia; en la base solo se guarda su SHA-256.
 *
 * Todas las fechas se calculan en PHP con la hora de Bogotá, nunca con NOW()
 * del servidor de base de datos.
 */
class AuthService
{
    private const UMBRAL_INTENTOS = 4;
    private const MINUTOS_BLOQUEO = 15;
    // MINIMO_CONTRASENA (8) y el máximo de 72 bytes viven en Validador,
    // compartidos con UsuarioAdministrativoService.
    /** Vigencia del token de recuperación, en segundos (1 hora, RN-T.12). */
    private const VIGENCIA_TOKEN = 3600;
    /** Espera mínima entre dos solicitudes de recuperación, en segundos. */
    private const ESPERA_ENTRE_SOLICITUDES = 300;

    private const ZONA_HORARIA = 'America/Bogota';
    private const FORMATO_FECHA_HORA = 'Y-m-d H:i:s';
    private const MAXIMO_DOCUMENTO = 20;

    /**
     * Hash bcrypt de un texto aleatorio que nadie conoce. Si la cuenta no
     * existe, se verifica la contraseña contra él igual: así la respuesta
     * tarda lo mismo y el tiempo no revela qué correos están registrados.
     */
    private const HASH_FICTICIO = '$2y$10$5588N.pwM4UX33CyQxPgJuR1IraA/D2wLaE3Kb3XnCU7WlmpGx5TG';

    private const MENSAJE_CREDENCIALES = 'Correo o contraseña incorrectos.';
    private const MENSAJE_CREDENCIALES_PUBLICAS = 'Documento o fecha de nacimiento incorrectos.';
    private const MENSAJE_RECUPERACION = 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.';
    private const MENSAJE_ENLACE_INVALIDO = 'El enlace no es válido o ya venció.';

    private UsuarioAdministrativoRepository $usuarioAdministrativo;
    private DeportistaRepository $deportista;
    private IntentoAccesoRepository $intentoAcceso;

    public function __construct()
    {
        $this->usuarioAdministrativo = new UsuarioAdministrativoRepository();
        $this->deportista = new DeportistaRepository();
        $this->intentoAcceso = new IntentoAccesoRepository();
    }

    /**
     * Login administrativo (RN-T.10, RN-T.16). Cuenta inexistente,
     * desactivada o contraseña errada responden igual (401) y suman al
     * contador; el cuarto fallo bloquea (429).
     *
     * @return array{ambito: string, correo: string, nombre: string, es_administrador_principal: bool}
     */
    public function iniciarSesionAdministrativa(array $credenciales): array
    {
        $correo = Validador::correo($credenciales['correo'] ?? null);
        $contrasena = Validador::contrasenaRecibida($credenciales['contrasena'] ?? null, 'contrasena');

        $this->exigirSinBloqueo('administrativa', $correo);

        $cuenta = $this->usuarioAdministrativo->obtenerParaAutenticacion($correo);
        // Se verifica siempre (con el hash ficticio si la cuenta no existe) para igualar tiempos.
        $claveCorrecta = password_verify($contrasena, $cuenta['contrasena'] ?? self::HASH_FICTICIO);

        if ($cuenta === null || !$claveCorrecta || !(bool) $cuenta['activa']) {
            $this->registrarFallo('administrativa', $correo, self::MENSAJE_CREDENCIALES);
        }

        $this->intentoAcceso->eliminar('administrativa', $correo);
        Sesion::iniciar('administrativa', $cuenta['correo']);

        return [
            'ambito' => 'administrativa',
            'correo' => $cuenta['correo'],
            'nombre' => $cuenta['nombre'],
            'es_administrador_principal' => (bool) $cuenta['es_administrador_principal'],
        ];
    }

    /**
     * Login público (RN-T.16): documento y fecha de nacimiento del deportista.
     * Con datos correctos, un registro pendiente de validación recibe 403 sin
     * sesión y sin contar como fallo (RN-T.14); un deportista inactivo entra
     * con normalidad (RN-1.11).
     *
     * @return array{ambito: string, documento: string, nombre: string}
     */
    public function iniciarSesionPublica(array $credenciales): array
    {
        $documento = $this->documento($credenciales['documento'] ?? null);
        $fechaNacimiento = $this->fechaNacimiento($credenciales['fecha_nacimiento'] ?? null);

        $this->exigirSinBloqueo('publica', $documento);

        $deportista = $this->deportista->obtenerPorId($documento);
        if ($deportista === null || $deportista['fecha_nacimiento'] !== $fechaNacimiento) {
            $this->registrarFallo('publica', $documento, self::MENSAJE_CREDENCIALES_PUBLICAS);
        }

        $this->intentoAcceso->eliminar('publica', $documento);
        if ($deportista['estado_registro'] === 'pendiente_validacion') {
            throw new ExcepcionNegocio('Tu registro está en revisión.', 403);
        }

        Sesion::iniciar('publica', $deportista['documento']);

        return [
            'ambito' => 'publica',
            'documento' => $deportista['documento'],
            'nombre' => $deportista['nombre'],
        ];
    }

    /**
     * Cierra la sesión. Idempotente: sin sesión no hace nada ni da error.
     */
    public function cerrarSesion(): void
    {
        Sesion::cerrar();
    }

    /**
     * Datos de la sesión actual. El Controller ya exigió una sesión válida
     * (AuthMiddleware); es_administrador_principal solo sale en el ámbito
     * administrativo.
     *
     * @return array<string, mixed>
     */
    public function sesionActual(): array
    {
        $usuario = $this->usuario();

        if ($usuario['ambito'] === 'administrativa') {
            $cuenta = $this->usuarioAdministrativo->obtenerPorId($usuario['identificador']);
            return [
                'ambito' => 'administrativa',
                'identificador' => $usuario['identificador'],
                'nombre' => $cuenta['nombre'] ?? '',
                'es_administrador_principal' => (bool) ($cuenta['es_administrador_principal'] ?? false),
            ];
        }

        $deportista = $this->deportista->obtenerPorId($usuario['identificador']);
        if ($deportista === null) {
            Sesion::cerrar();
            throw new ExcepcionNegocio('Tu sesión ya no es válida.', 401);
        }

        return [
            'ambito' => 'publica',
            'identificador' => $usuario['identificador'],
            'nombre' => $deportista['nombre'],
        ];
    }

    /**
     * «Cambiar mi contraseña» (RN-T.18): solo cuentas administrativas (el
     * ámbito público no tiene contraseña). Pide la actual; al guardar la
     * nueva invalida cualquier token de recuperación pendiente.
     */
    public function cambiarContrasena(array $datos): void
    {
        $usuario = $this->usuario();
        if ($usuario['ambito'] !== 'administrativa') {
            throw new ExcepcionNegocio('Solo las cuentas administrativas tienen contraseña.', 403);
        }

        $actual = Validador::contrasenaRecibida($datos['contrasena_actual'] ?? null, 'contrasena_actual');
        $nueva = Validador::contrasenaNueva($datos['contrasena_nueva'] ?? null);

        $cuenta = $this->usuarioAdministrativo->obtenerParaAutenticacion($usuario['identificador']);
        if ($cuenta === null || !password_verify($actual, $cuenta['contrasena'])) {
            throw new ExcepcionNegocio('La contraseña actual no es correcta.', 400);
        }

        $modelo = UsuarioAdministrativoModel::fromArray($cuenta);
        $modelo->establecerContrasena(password_hash($nueva, PASSWORD_DEFAULT));
        $modelo->establecerTokenRecuperacion(null);
        $modelo->establecerTokenRecuperacionExpira(null);
        $this->usuarioAdministrativo->actualizarCredenciales($modelo);
    }

    /**
     * Recuperación de contraseña, paso 1 (RN-T.12). La respuesta es siempre
     * la misma, exista o no la cuenta. Si la cuenta existe, está activa y no
     * pidió otro enlace en los últimos 5 minutos, genera un token nuevo
     * (reemplaza al anterior) y envía el enlace. Un fallo del correo solo
     * queda en el log.
     */
    public function solicitarRecuperacionContrasena(array $datos): string
    {
        $correo = Validador::correo($datos['correo'] ?? null);

        $cuenta = $this->usuarioAdministrativo->obtenerParaAutenticacion($correo);
        if ($cuenta === null || !(bool) $cuenta['activa'] || $this->solicitudReciente($cuenta['token_recuperacion_expira'])) {
            return self::MENSAJE_RECUPERACION;
        }

        $token = bin2hex(random_bytes(32));
        $modelo = UsuarioAdministrativoModel::fromArray($cuenta);
        $modelo->establecerTokenRecuperacion(hash('sha256', $token));
        $modelo->establecerTokenRecuperacionExpira(
            $this->ahora()->modify('+' . self::VIGENCIA_TOKEN . ' seconds')->format(self::FORMATO_FECHA_HORA)
        );
        $this->usuarioAdministrativo->actualizarCredenciales($modelo);

        // Armar el cuerpo también lee la configuración del correo: si falla
        // (ej. falta Config/correo.php), solo se registra. Un 500 aquí
        // revelaría que el correo existe (RN-T.12).
        try {
            $enviado = Correo::enviar($cuenta['correo'], 'Restablecer tu contraseña', $this->cuerpoCorreoRecuperacion($token));
        } catch (Throwable $error) {
            error_log('AuthService: no se pudo preparar el correo de recuperación: ' . $error->getMessage());
            $enviado = false;
        }
        if (!$enviado) {
            error_log('AuthService: falló el envío del enlace de recuperación a una cuenta administrativa.');
        }

        return self::MENSAJE_RECUPERACION;
    }

    /**
     * Recuperación de contraseña, paso 2 (RN-T.12). El token debe existir,
     * estar vigente y la cuenta activa. La contraseña nueva y el borrado del
     * token van en un solo UPDATE condicionado al token, así no se puede usar
     * dos veces. Reinicia el contador de intentos del correo; no inicia sesión.
     */
    public function restablecerContrasena(array $datos): void
    {
        $nueva = Validador::contrasenaNueva($datos['contrasena_nueva'] ?? null);
        $token = $datos['token'] ?? null;
        if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            throw new ExcepcionNegocio(self::MENSAJE_ENLACE_INVALIDO, 400);
        }

        $hashToken = hash('sha256', $token);
        $ahora = $this->ahora()->format(self::FORMATO_FECHA_HORA);
        $cuenta = $this->usuarioAdministrativo->obtenerPorTokenRecuperacion($hashToken);
        if ($cuenta === null
            || !(bool) $cuenta['activa']
            || $cuenta['token_recuperacion_expira'] === null
            || $cuenta['token_recuperacion_expira'] <= $ahora) {
            throw new ExcepcionNegocio(self::MENSAJE_ENLACE_INVALIDO, 400);
        }

        if (!$this->usuarioAdministrativo->restablecerContrasenaConToken($hashToken, password_hash($nueva, PASSWORD_DEFAULT), $ahora)) {
            // Otra petición usó el mismo token un instante antes.
            throw new ExcepcionNegocio(self::MENSAJE_ENLACE_INVALIDO, 400);
        }

        $this->intentoAcceso->eliminar('administrativa', $cuenta['correo']);
    }

    // ------------------------------------------------------------------
    // Bloqueo por intentos fallidos (RN-T.16, B.5)
    // ------------------------------------------------------------------

    /**
     * 429 si el identificador tiene un bloqueo vigente. No evalúa
     * credenciales: durante el bloqueo, ni la contraseña correcta entra.
     */
    private function exigirSinBloqueo(string $ambito, string $identificador): void
    {
        $intento = $this->intentoAcceso->obtenerPorId($ambito, $identificador);
        $bloqueadoHasta = $intento['bloqueado_hasta'] ?? null;
        if ($bloqueadoHasta !== null && $bloqueadoHasta > $this->ahora()->format(self::FORMATO_FECHA_HORA)) {
            $this->rechazarPorBloqueo($bloqueadoHasta);
        }
    }

    /**
     * Suma el fallo (sentencia atómica en el Repository) y, si llegó al
     * umbral, fija el bloqueo de 15 minutos y responde 429; si no, responde
     * 401 con el mensaje genérico.
     */
    private function registrarFallo(string $ambito, string $identificador, string $mensaje): never
    {
        $ahora = $this->ahora();
        $this->intentoAcceso->registrarFallo($ambito, $identificador, $ahora->format(self::FORMATO_FECHA_HORA));

        $intento = IntentoAccesoModel::fromArray($this->intentoAcceso->obtenerPorId($ambito, $identificador) ?? []);
        if ($intento->obtenerIntentosFallidos() >= self::UMBRAL_INTENTOS) {
            $bloqueadoHasta = $ahora->modify('+' . self::MINUTOS_BLOQUEO . ' minutes')->format(self::FORMATO_FECHA_HORA);
            $intento->establecerBloqueadoHasta($bloqueadoHasta);
            $this->intentoAcceso->actualizar($intento);
            $this->rechazarPorBloqueo($bloqueadoHasta);
        }

        throw new ExcepcionNegocio($mensaje, 401);
    }

    private function rechazarPorBloqueo(string $bloqueadoHasta): never
    {
        $segundos = (new DateTimeImmutable($bloqueadoHasta, new DateTimeZone(self::ZONA_HORARIA)))->getTimestamp()
            - $this->ahora()->getTimestamp();
        $minutos = max(1, (int) ceil($segundos / 60));
        throw new ExcepcionNegocio("Demasiados intentos fallidos. Intenta de nuevo en {$minutos} minutos.", 429);
    }

    // ------------------------------------------------------------------
    // Recuperación
    // ------------------------------------------------------------------

    /**
     * La fecha de la última solicitud se deriva del vencimiento guardado
     * (vencimiento - 1 hora); no hay otra columna para ella.
     */
    private function solicitudReciente(?string $tokenExpira): bool
    {
        if ($tokenExpira === null) {
            return false;
        }
        $ultimaSolicitud = (new DateTimeImmutable($tokenExpira, new DateTimeZone(self::ZONA_HORARIA)))->getTimestamp()
            - self::VIGENCIA_TOKEN;
        return $this->ahora()->getTimestamp() - $ultimaSolicitud < self::ESPERA_ENTRE_SOLICITUDES;
    }

    private function cuerpoCorreoRecuperacion(string $token): string
    {
        $url = (string) (Correo::configuracion()['url_restablecimiento'] ?? '');
        $enlace = $url . (str_contains($url, '?') ? '&' : '?') . 'token=' . $token;

        return "Para restablecer tu contraseña de HwangHon Sports System, abre este enlace:\n\n"
            . $enlace . "\n\n"
            . "El enlace vence en 1 hora.\n";
    }

    // ------------------------------------------------------------------
    // Sesión, fechas y validación de datos de entrada
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

    private function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::ZONA_HORARIA));
    }

    private function documento(mixed $valor): string
    {
        if ((!is_string($valor) && !is_int($valor)) || trim((string) $valor) === '') {
            throw new ExcepcionNegocio('El campo "documento" es obligatorio.', 400);
        }
        $documento = trim((string) $valor);
        if (mb_strlen($documento) > self::MAXIMO_DOCUMENTO) {
            throw new ExcepcionNegocio('El campo "documento" admite máximo 20 caracteres.', 400);
        }
        return $documento;
    }

    /**
     * Fecha con formato AAAA-MM-DD y real (ej. 2014-02-30 no vale).
     */
    private function fechaNacimiento(mixed $valor): string
    {
        $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            throw new ExcepcionNegocio('El campo "fecha_nacimiento" debe ser una fecha válida con formato AAAA-MM-DD.', 400);
        }
        return $valor;
    }
}
