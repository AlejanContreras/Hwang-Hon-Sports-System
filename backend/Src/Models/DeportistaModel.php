<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `deportista`.
 * Deportista inscrito en el club, con sus datos personales, deportivos y de estado (RN-1.x).
 * Llave primaria: documento.
 */
class DeportistaModel
{
    private string $documento;
    private string $nombre;
    private string $fechaNacimiento;
    private float $estatura;
    private float $peso;
    private ?string $discapacidad;
    private ?string $estadoAnimico;
    private ?string $sedeEntrenamiento;
    private bool $autorizacionDatos;
    private ?string $gradoActual;
    private string $fechaIngreso;
    private string $estado;
    private string $estadoRegistro;
    private ?string $acudienteDocumento;
    private ?string $validadoPorCorreo;

    public function __construct(
        string $documento = '',
        string $nombre = '',
        string $fechaNacimiento = '',
        float $estatura = 0.0,
        float $peso = 0.0,
        ?string $discapacidad = null,
        ?string $estadoAnimico = null,
        ?string $sedeEntrenamiento = null,
        bool $autorizacionDatos = false,
        ?string $gradoActual = null,
        string $fechaIngreso = '',
        string $estado = '',
        string $estadoRegistro = '',
        ?string $acudienteDocumento = null,
        ?string $validadoPorCorreo = null
    ) {
        $this->documento = $documento;
        $this->nombre = $nombre;
        $this->fechaNacimiento = $fechaNacimiento;
        $this->estatura = $estatura;
        $this->peso = $peso;
        $this->discapacidad = $discapacidad;
        $this->estadoAnimico = $estadoAnimico;
        $this->sedeEntrenamiento = $sedeEntrenamiento;
        $this->autorizacionDatos = $autorizacionDatos;
        $this->gradoActual = $gradoActual;
        $this->fechaIngreso = $fechaIngreso;
        $this->estado = $estado;
        $this->estadoRegistro = $estadoRegistro;
        $this->acudienteDocumento = $acudienteDocumento;
        $this->validadoPorCorreo = $validadoPorCorreo;
    }

    public function obtenerDocumento(): string
    {
        return $this->documento;
    }

    public function establecerDocumento(string $documento): void
    {
        $this->documento = $documento;
    }

    public function obtenerNombre(): string
    {
        return $this->nombre;
    }

    public function establecerNombre(string $nombre): void
    {
        $this->nombre = $nombre;
    }

    public function obtenerFechaNacimiento(): string
    {
        return $this->fechaNacimiento;
    }

    public function establecerFechaNacimiento(string $fechaNacimiento): void
    {
        $this->fechaNacimiento = $fechaNacimiento;
    }

    public function obtenerEstatura(): float
    {
        return $this->estatura;
    }

    public function establecerEstatura(float $estatura): void
    {
        $this->estatura = $estatura;
    }

    public function obtenerPeso(): float
    {
        return $this->peso;
    }

    public function establecerPeso(float $peso): void
    {
        $this->peso = $peso;
    }

    public function obtenerDiscapacidad(): ?string
    {
        return $this->discapacidad;
    }

    public function establecerDiscapacidad(?string $discapacidad): void
    {
        $this->discapacidad = $discapacidad;
    }

    public function obtenerEstadoAnimico(): ?string
    {
        return $this->estadoAnimico;
    }

    public function establecerEstadoAnimico(?string $estadoAnimico): void
    {
        $this->estadoAnimico = $estadoAnimico;
    }

    public function obtenerSedeEntrenamiento(): ?string
    {
        return $this->sedeEntrenamiento;
    }

    public function establecerSedeEntrenamiento(?string $sedeEntrenamiento): void
    {
        $this->sedeEntrenamiento = $sedeEntrenamiento;
    }

    public function obtenerAutorizacionDatos(): bool
    {
        return $this->autorizacionDatos;
    }

    public function establecerAutorizacionDatos(bool $autorizacionDatos): void
    {
        $this->autorizacionDatos = $autorizacionDatos;
    }

    public function obtenerGradoActual(): ?string
    {
        return $this->gradoActual;
    }

    public function establecerGradoActual(?string $gradoActual): void
    {
        $this->gradoActual = $gradoActual;
    }

    public function obtenerFechaIngreso(): string
    {
        return $this->fechaIngreso;
    }

    public function establecerFechaIngreso(string $fechaIngreso): void
    {
        $this->fechaIngreso = $fechaIngreso;
    }

    public function obtenerEstado(): string
    {
        return $this->estado;
    }

    public function establecerEstado(string $estado): void
    {
        $this->estado = $estado;
    }

    public function obtenerEstadoRegistro(): string
    {
        return $this->estadoRegistro;
    }

    public function establecerEstadoRegistro(string $estadoRegistro): void
    {
        $this->estadoRegistro = $estadoRegistro;
    }

    public function obtenerAcudienteDocumento(): ?string
    {
        return $this->acudienteDocumento;
    }

    public function establecerAcudienteDocumento(?string $acudienteDocumento): void
    {
        $this->acudienteDocumento = $acudienteDocumento;
    }

    public function obtenerValidadoPorCorreo(): ?string
    {
        return $this->validadoPorCorreo;
    }

    public function establecerValidadoPorCorreo(?string $validadoPorCorreo): void
    {
        $this->validadoPorCorreo = $validadoPorCorreo;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `deportista`).
     */
    public function toArray(): array
    {
        return [
            'documento' => $this->documento,
            'nombre' => $this->nombre,
            'fecha_nacimiento' => $this->fechaNacimiento,
            'estatura' => $this->estatura,
            'peso' => $this->peso,
            'discapacidad' => $this->discapacidad,
            'estado_animico' => $this->estadoAnimico,
            'sede_entrenamiento' => $this->sedeEntrenamiento,
            'autorizacion_datos' => $this->autorizacionDatos,
            'grado_actual' => $this->gradoActual,
            'fecha_ingreso' => $this->fechaIngreso,
            'estado' => $this->estado,
            'estado_registro' => $this->estadoRegistro,
            'acudiente_documento' => $this->acudienteDocumento,
            'validado_por_correo' => $this->validadoPorCorreo,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            documento: $datos['documento'] ?? '',
            nombre: $datos['nombre'] ?? '',
            fechaNacimiento: $datos['fecha_nacimiento'] ?? '',
            estatura: $datos['estatura'] ?? 0.0,
            peso: $datos['peso'] ?? 0.0,
            discapacidad: $datos['discapacidad'] ?? null,
            estadoAnimico: $datos['estado_animico'] ?? null,
            sedeEntrenamiento: $datos['sede_entrenamiento'] ?? null,
            autorizacionDatos: $datos['autorizacion_datos'] ?? false,
            gradoActual: $datos['grado_actual'] ?? null,
            fechaIngreso: $datos['fecha_ingreso'] ?? '',
            estado: $datos['estado'] ?? '',
            estadoRegistro: $datos['estado_registro'] ?? '',
            acudienteDocumento: $datos['acudiente_documento'] ?? null,
            validadoPorCorreo: $datos['validado_por_correo'] ?? null,
        );
    }
}
