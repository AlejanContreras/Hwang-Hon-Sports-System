<?php

declare(strict_types=1);

/**
 * Capa de lógica de negocio del módulo UsuarioAdministrativo: gestión de las
 * cuentas del equipo administrativo (RN-T.10, RN-T.11, RN-T.12; Contexto Base
 * §35.16).
 *
 * Solo gestionan cuentas quienes tienen es_administrador_principal = 1 (Aldrin
 * y la cuenta «Desarrollador»). La política solo_administrativa de api.php ya
 * exige una sesión administrativa válida y activa; el permiso de gestión
 * depende de la identidad, así que se valida aquí, leyendo la cuenta de la
 * sesión en la base de datos en cada petición (no de la sesión): cualquier
 * otra cuenta administrativa recibe 403 en todas las acciones.
 *
 *   - Las cuentas no se borran: se desactivan y se reactivan (RN-T.11).
 *   - Ningún endpoint otorga ni quita es_administrador_principal; las cuentas
 *     principales no se desactivan desde la interfaz (se manejan por base de
 *     datos o por el script de instalación).
 *   - Nadie se desactiva a sí mismo, ni cambia su propio correo, ni
 *     restablece su propia contraseña desde aquí (usa «cambiar mi contraseña»).
 *   - Nunca se devuelve contrasena ni token_recuperacion.
 */
class UsuarioAdministrativoService
{
    private const MAXIMO_NOMBRE = 150;
    private const MAXIMO_ROL = 30;
    private const CAMPOS_CREAR = ['correo', 'nombre', 'rol', 'contrasena'];
    private const CAMPOS_ACTUALIZAR = ['correo', 'nombre', 'rol'];
    /** Código de MySQL/MariaDB para llave duplicada. */
    private const ERROR_LLAVE_DUPLICADA = 1062;

    private UsuarioAdministrativoRepository $usuarioAdministrativo;
    private IntentoAccesoRepository $intentoAcceso;

    public function __construct()
    {
        $this->usuarioAdministrativo = new UsuarioAdministrativoRepository();
        $this->intentoAcceso = new IntentoAccesoRepository();
    }

    /**
     * Todas las cuentas, sin paginación, ordenadas por nombre y correo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listar(): array
    {
        $this->exigirGestor();
        return array_map(
            fn (array $fila): array => $this->formatear($fila),
            $this->usuarioAdministrativo->listar()
        );
    }

    /**
     * Una cuenta por ?correo=, o null si no existe (el Controller responde 404).
     */
    public function consultar(array $identificador): ?array
    {
        $this->exigirGestor();
        $fila = $this->usuarioAdministrativo->obtenerPorId($this->correoDe($identificador));
        return $fila === null ? null : $this->formatear($fila);
    }

    /**
     * Alta de una cuenta. Nace siempre sin rol principal, activa y con
     * creado_por_correo = quien la crea. La contraseña inicial la escribe
     * quien crea la cuenta (RN-T.18).
     */
    public function crear(array $datos): array
    {
        $gestor = $this->exigirGestor();
        $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_CREAR);

        $correo = Validador::correo($datos['correo'] ?? null);
        $modelo = new UsuarioAdministrativoModel(
            correo: $correo,
            contrasena: password_hash(Validador::contrasenaNueva($datos['contrasena'] ?? null, 'contrasena'), PASSWORD_DEFAULT),
            nombre: Validador::texto($datos['nombre'] ?? null, 'nombre', self::MAXIMO_NOMBRE, true),
            rol: Validador::texto($datos['rol'] ?? null, 'rol', self::MAXIMO_ROL, false),
            esAdministradorPrincipal: false,
            activa: true,
            creadoPorCorreo: $gestor['correo'],
            tokenRecuperacion: null,
            tokenRecuperacionExpira: null,
        );

        if ($this->usuarioAdministrativo->obtenerPorId($correo) !== null) {
            $this->rechazarCorreoRepetido($correo);
        }
        try {
            $this->usuarioAdministrativo->crear($modelo);
        } catch (PDOException $error) {
            // Otra petición creó el mismo correo entre el SELECT y el INSERT.
            if ($this->esLlaveDuplicada($error)) {
                $this->rechazarCorreoRepetido($correo);
            }
            throw $error;
        }

        return $this->formatear($this->usuarioAdministrativo->obtenerPorId($correo));
    }

    /**
     * Edita nombre y rol; con "correo" cambia el correo con el procedimiento
     * de la base de datos. El propio correo no se cambia desde aquí (la
     * sesión lo usa como identificador): lo cambia la otra cuenta principal.
     * No toca contraseña, activa ni es_administrador_principal.
     *
     * El cambio de correo y el de nombre/rol no van en una misma transacción
     * (el procedimiento abre la suya): primero va el correo, que es lo que
     * puede fallar por duplicado; nombre y rol ya llegan validados.
     */
    public function actualizar(array $identificador, array $datos): array
    {
        $gestor = $this->exigirGestor();
        $correo = $this->correoDe($identificador);
        $fila = $this->cuentaExistente($correo);

        if ($datos === []) {
            throw new ExcepcionNegocio('No se recibió ningún campo para actualizar.', 400);
        }
        $this->rechazarCamposNoPermitidos($datos, self::CAMPOS_ACTUALIZAR);

        $modelo = UsuarioAdministrativoModel::fromArray($fila);
        if (array_key_exists('nombre', $datos)) {
            $modelo->establecerNombre(Validador::texto($datos['nombre'], 'nombre', self::MAXIMO_NOMBRE, true));
        }
        if (array_key_exists('rol', $datos)) {
            $modelo->establecerRol(Validador::texto($datos['rol'], 'rol', self::MAXIMO_ROL, false));
        }

        $correoNuevo = array_key_exists('correo', $datos) ? Validador::correo($datos['correo']) : $correo;
        if ($correoNuevo !== $correo) {
            if ($correo === $gestor['correo']) {
                throw new ExcepcionNegocio(
                    'No puedes cambiar tu propio correo: la sesión lo usa para identificarte. Pide a la otra cuenta principal que lo cambie.',
                    409
                );
            }
            if ($this->usuarioAdministrativo->obtenerPorId($correoNuevo) !== null) {
                $this->rechazarCorreoRepetido($correoNuevo);
            }
            try {
                $this->usuarioAdministrativo->cambiarCorreo($correo, $correoNuevo);
            } catch (PDOException $error) {
                if ($this->esLlaveDuplicada($error)) {
                    $this->rechazarCorreoRepetido($correoNuevo);
                }
                throw $error;
            }
            $modelo->establecerCorreo($correoNuevo);
        }

        $this->usuarioAdministrativo->actualizar($modelo);
        return $this->formatear($this->usuarioAdministrativo->obtenerPorId($correoNuevo));
    }

    /**
     * Desactiva una cuenta (no se borra, RN-T.11). Idempotente. No se puede
     * desactivar la propia cuenta ni una principal. La sesión abierta de la
     * cuenta deja de valer en su siguiente petición (AuthMiddleware).
     */
    public function desactivar(array $identificador): array
    {
        $gestor = $this->exigirGestor();
        $correo = $this->correoDe($identificador);
        $fila = $this->cuentaExistente($correo);

        if ($correo === $gestor['correo']) {
            throw new ExcepcionNegocio('No puedes desactivar tu propia cuenta.', 409);
        }
        if ((bool) $fila['es_administrador_principal']) {
            throw new ExcepcionNegocio('Una cuenta principal no se puede desactivar desde la aplicación.', 409);
        }

        return $this->cambiarActiva($fila, false);
    }

    /**
     * Reactiva una cuenta desactivada. Idempotente.
     */
    public function reactivar(array $identificador): array
    {
        $this->exigirGestor();
        $fila = $this->cuentaExistente($this->correoDe($identificador));
        return $this->cambiarActiva($fila, true);
    }

    /**
     * Quien gestiona escribe la contraseña nueva de OTRA cuenta (RN-T.12),
     * con la validación de RN-T.18. Invalida el token de recuperación
     * pendiente y reinicia el contador de intentos de acceso de esa cuenta.
     * Las sesiones que esa cuenta tenga abiertas caen en su siguiente
     * petición: la huella de la contraseña ya no coincide (AuthMiddleware).
     */
    public function restablecerContrasena(array $identificador, array $datos): void
    {
        $gestor = $this->exigirGestor();
        $correo = $this->correoDe($identificador);
        $fila = $this->cuentaExistente($correo);

        if ($correo === $gestor['correo']) {
            throw new ExcepcionNegocio('Para tu propia cuenta usa «cambiar mi contraseña».', 409);
        }
        $nueva = Validador::contrasenaNueva($datos['contrasena_nueva'] ?? null);

        $credenciales = UsuarioAdministrativoModel::fromArray($fila);
        $credenciales->establecerContrasena(password_hash($nueva, PASSWORD_DEFAULT));
        $credenciales->establecerTokenRecuperacion(null);
        $credenciales->establecerTokenRecuperacionExpira(null);
        $this->usuarioAdministrativo->actualizarCredenciales($credenciales);
        $this->intentoAcceso->eliminar('administrativa', $correo);
    }

    // ------------------------------------------------------------------
    // Permisos y apoyo
    // ------------------------------------------------------------------

    /**
     * Exige que la cuenta de la sesión gestione cuentas (RN-T.11). Lee
     * es_administrador_principal y activa de la base de datos, no de la
     * sesión. Devuelve la fila de quien gestiona.
     *
     * @return array<string, mixed>
     */
    private function exigirGestor(): array
    {
        $usuario = Sesion::usuarioActual();
        if ($usuario === null) {
            throw new ExcepcionNegocio('No autenticado. Inicie sesión para continuar.', 401);
        }
        $cuenta = $usuario['ambito'] === 'administrativa'
            ? $this->usuarioAdministrativo->obtenerPorId($usuario['identificador'])
            : null;
        if ($cuenta === null || !(bool) $cuenta['activa'] || !(bool) $cuenta['es_administrador_principal']) {
            throw new ExcepcionNegocio('Solo las cuentas principales pueden gestionar las cuentas administrativas.', 403);
        }
        return $cuenta;
    }

    private function correoDe(array $identificador): string
    {
        return Validador::correo($identificador['correo'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function cuentaExistente(string $correo): array
    {
        $fila = $this->usuarioAdministrativo->obtenerPorId($correo);
        if ($fila === null) {
            throw new ExcepcionNegocio('Cuenta no encontrada.', 404);
        }
        return $fila;
    }

    /**
     * @param array<string, mixed> $fila
     */
    private function cambiarActiva(array $fila, bool $activa): array
    {
        $modelo = UsuarioAdministrativoModel::fromArray($fila);
        if ($modelo->obtenerActiva() !== $activa) {
            $modelo->establecerActiva($activa);
            $this->usuarioAdministrativo->actualizar($modelo);
        }
        return $this->formatear($this->usuarioAdministrativo->obtenerPorId($modelo->obtenerCorreo()));
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

    private function esLlaveDuplicada(PDOException $error): bool
    {
        return (int) ($error->errorInfo[1] ?? 0) === self::ERROR_LLAVE_DUPLICADA;
    }

    private function rechazarCorreoRepetido(string $correo): never
    {
        throw new ExcepcionNegocio("Ya existe una cuenta con el correo {$correo}.", 409);
    }

    /**
     * Forma de una cuenta en las respuestas: solo estos seis campos, con los
     * TINYINT como booleanos. Nunca contrasena ni token_recuperacion.
     *
     * @param array<string, mixed> $fila
     */
    private function formatear(array $fila): array
    {
        return [
            'correo' => $fila['correo'],
            'nombre' => $fila['nombre'],
            'rol' => $fila['rol'],
            'es_administrador_principal' => (bool) $fila['es_administrador_principal'],
            'activa' => (bool) $fila['activa'],
            'creado_por_correo' => $fila['creado_por_correo'],
        ];
    }
}
