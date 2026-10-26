<?php

declare(strict_types=1);

/**
 * Error esperado de la logica de negocio, con el codigo HTTP que le
 * corresponde. La lanzan los Services; la atrapa el try/catch de
 * Public/api.php y la convierte en una respuesta JSON de error.
 *
 * Codigos de uso: 400 (dato invalido), 401 (sin sesion), 403 (ambito sin
 * permiso), 404 (no encontrado), 409 (conflicto, ej. documento duplicado),
 * 413 (solicitud o archivo demasiado grande), 415 (tipo de contenido no
 * soportado), 422 (dato valido pero no procesable, ej. rango de fechas o
 * filas por encima del limite) y 429 (demasiados intentos: bloqueo de
 * inicio de sesion o limite del autorregistro).
 *
 * Ejemplo: throw new ExcepcionNegocio('La fecha de nacimiento no es valida.', 400);
 *
 * El mensaje se lee con getMessage(), metodo propio de PHP (no se renombra).
 */
class ExcepcionNegocio extends RuntimeException
{
    private int $codigoHttp;

    public function __construct(string $mensaje, int $codigoHttp = 400)
    {
        parent::__construct($mensaje);
        $this->codigoHttp = $codigoHttp;
    }

    public function obtenerCodigoHttp(): int
    {
        return $this->codigoHttp;
    }
}
