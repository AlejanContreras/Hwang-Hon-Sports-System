<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `usuario_administrativo`.
 * Usuario con acceso al ámbito administrativo del sistema (RN-T.10/RN-T.11).
 * Llave primaria: correo.
 */
class UsuarioAdministrativoModel
{
    private string $correo;
    private string $contrasena;
    private string $nombre;
    private ?string $rol;
    private bool $esAdministradorPrincipal;
    private bool $activa;
    private ?string $creadoPorCorreo;
    private ?string $tokenRecuperacion;
    private ?string $tokenRecuperacionExpira;

    public function __construct(
        string $correo = '',
        string $contrasena = '',
        string $nombre = '',
        ?string $rol = null,
        bool $esAdministradorPrincipal = false,
        bool $activa = true,
        ?string $creadoPorCorreo = null,
        ?string $tokenRecuperacion = null,
        ?string $tokenRecuperacionExpira = null
    ) {
        $this->correo = $correo;
        $this->contrasena = $contrasena;
        $this->nombre = $nombre;
        $this->rol = $rol;
        $this->esAdministradorPrincipal = $esAdministradorPrincipal;
        $this->activa = $activa;
        $this->creadoPorCorreo = $creadoPorCorreo;
        $this->tokenRecuperacion = $tokenRecuperacion;
        $this->tokenRecuperacionExpira = $tokenRecuperacionExpira;
    }

    public function obtenerCorreo(): string
    {
        return $this->correo;
    }

    public function establecerCorreo(string $correo): void
    {
        $this->correo = $correo;
    }

    public function obtenerContrasena(): string
    {
        return $this->contrasena;
    }

    public function establecerContrasena(string $contrasena): void
    {
        $this->contrasena = $contrasena;
    }

    public function obtenerNombre(): string
    {
        return $this->nombre;
    }

    public function establecerNombre(string $nombre): void
    {
        $this->nombre = $nombre;
    }

    public function obtenerRol(): ?string
    {
        return $this->rol;
    }

    public function establecerRol(?string $rol): void
    {
        $this->rol = $rol;
    }

    public function obtenerEsAdministradorPrincipal(): bool
    {
        return $this->esAdministradorPrincipal;
    }

    public function establecerEsAdministradorPrincipal(bool $esAdministradorPrincipal): void
    {
        $this->esAdministradorPrincipal = $esAdministradorPrincipal;
    }

    public function obtenerActiva(): bool
    {
        return $this->activa;
    }

    public function establecerActiva(bool $activa): void
    {
        $this->activa = $activa;
    }

    public function obtenerCreadoPorCorreo(): ?string
    {
        return $this->creadoPorCorreo;
    }

    public function establecerCreadoPorCorreo(?string $creadoPorCorreo): void
    {
        $this->creadoPorCorreo = $creadoPorCorreo;
    }

    public function obtenerTokenRecuperacion(): ?string
    {
        return $this->tokenRecuperacion;
    }

    public function establecerTokenRecuperacion(?string $tokenRecuperacion): void
    {
        $this->tokenRecuperacion = $tokenRecuperacion;
    }

    public function obtenerTokenRecuperacionExpira(): ?string
    {
        return $this->tokenRecuperacionExpira;
    }

    public function establecerTokenRecuperacionExpira(?string $tokenRecuperacionExpira): void
    {
        $this->tokenRecuperacionExpira = $tokenRecuperacionExpira;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `usuario_administrativo`).
     */
    public function toArray(): array
    {
        return [
            'correo' => $this->correo,
            'contrasena' => $this->contrasena,
            'nombre' => $this->nombre,
            'rol' => $this->rol,
            'es_administrador_principal' => $this->esAdministradorPrincipal,
            'activa' => $this->activa,
            'creado_por_correo' => $this->creadoPorCorreo,
            'token_recuperacion' => $this->tokenRecuperacion,
            'token_recuperacion_expira' => $this->tokenRecuperacionExpira,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     * Los TINYINT(1) llegan de PDO como entero y los DECIMAL como texto: se
     * convierten explicitamente porque, con strict_types, el constructor
     * lanzaria TypeError.
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            correo: $datos['correo'] ?? '',
            contrasena: $datos['contrasena'] ?? '',
            nombre: $datos['nombre'] ?? '',
            rol: $datos['rol'] ?? null,
            esAdministradorPrincipal: (bool) ($datos['es_administrador_principal'] ?? false),
            activa: (bool) ($datos['activa'] ?? true),
            creadoPorCorreo: $datos['creado_por_correo'] ?? null,
            tokenRecuperacion: $datos['token_recuperacion'] ?? null,
            tokenRecuperacionExpira: $datos['token_recuperacion_expira'] ?? null,
        );
    }
}
