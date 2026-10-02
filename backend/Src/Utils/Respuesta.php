<?php

declare(strict_types=1);

/**
 * Respuestas JSON estandar de la API, centralizadas en un solo lugar.
 *
 * Formato de exito: {"exito": true, "datos": ...}
 * Formato de error: {"exito": false, "mensaje": "..."}
 *
 * Ambos metodos envian la respuesta y terminan la ejecucion (exit), por eso
 * devuelven "never". Si el formato cambia, se cambia solo aqui.
 */
class Respuesta
{
    /**
     * Responde con exito. Codigo HTTP 200 por defecto (201 al crear).
     */
    public static function exito(mixed $datos, int $codigoHttp = 200): never
    {
        self::enviar(['exito' => true, 'datos' => $datos], $codigoHttp);
    }

    /**
     * Responde con error: 400, 401, 403, 404, 405, 409 o 500, segun el caso.
     */
    public static function error(int $codigoHttp, string $mensaje): never
    {
        self::enviar(['exito' => false, 'mensaje' => $mensaje], $codigoHttp);
    }

    /**
     * Escribe el codigo HTTP, la cabecera JSON y el contenido, y termina.
     *
     * @param array<string, mixed> $contenido
     */
    private static function enviar(array $contenido, int $codigoHttp): never
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($contenido, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
