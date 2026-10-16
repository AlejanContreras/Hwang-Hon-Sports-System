<?php

declare(strict_types=1);

/**
 * Modelo de la tabla `intento_acceso`.
 * Contador de inicios de sesión fallidos consecutivos por ámbito e
 * identificador digitado (correo o documento), con su bloqueo temporal (RN-T.16).
 * Llave primaria: ambito, identificador.
 */
class IntentoAccesoModel
{
    private string $ambito;
    private string $identificador;
    private int $intentosFallidos;
    private ?string $bloqueadoHasta;

    public function __construct(
        string $ambito = '',
        string $identificador = '',
        int $intentosFallidos = 0,
        ?string $bloqueadoHasta = null
    ) {
        $this->ambito = $ambito;
        $this->identificador = $identificador;
        $this->intentosFallidos = $intentosFallidos;
        $this->bloqueadoHasta = $bloqueadoHasta;
    }

    public function obtenerAmbito(): string
    {
        return $this->ambito;
    }

    public function establecerAmbito(string $ambito): void
    {
        $this->ambito = $ambito;
    }

    public function obtenerIdentificador(): string
    {
        return $this->identificador;
    }

    public function establecerIdentificador(string $identificador): void
    {
        $this->identificador = $identificador;
    }

    public function obtenerIntentosFallidos(): int
    {
        return $this->intentosFallidos;
    }

    public function establecerIntentosFallidos(int $intentosFallidos): void
    {
        $this->intentosFallidos = $intentosFallidos;
    }

    public function obtenerBloqueadoHasta(): ?string
    {
        return $this->bloqueadoHasta;
    }

    public function establecerBloqueadoHasta(?string $bloqueadoHasta): void
    {
        $this->bloqueadoHasta = $bloqueadoHasta;
    }

    /**
     * Convierte el modelo a un arreglo asociativo (claves = columnas de la tabla `intento_acceso`).
     */
    public function toArray(): array
    {
        return [
            'ambito' => $this->ambito,
            'identificador' => $this->identificador,
            'intentos_fallidos' => $this->intentosFallidos,
            'bloqueado_hasta' => $this->bloqueadoHasta,
        ];
    }

    /**
     * Construye el modelo a partir de un arreglo asociativo (por ejemplo, una fila de la base de datos).
     */
    public static function fromArray(array $datos): self
    {
        return new self(
            ambito: $datos['ambito'] ?? '',
            identificador: $datos['identificador'] ?? '',
            intentosFallidos: $datos['intentos_fallidos'] ?? 0,
            bloqueadoHasta: $datos['bloqueado_hasta'] ?? null,
        );
    }
}
