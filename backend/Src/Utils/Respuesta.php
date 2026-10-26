<?php

declare(strict_types=1);

/**
 * Respuestas JSON estandar de la API, centralizadas en un solo lugar.
 *
 * Formato de exito: {"exito": true, "datos": ...}
 * Formato de error: {"exito": false, "mensaje": "..."}
 *
 * archivo() envia una descarga (no JSON) para Reportes y Documentos.
 *
 * Todos los metodos envian la respuesta y terminan la ejecucion (exit), por
 * eso devuelven "never". Si el formato cambia, se cambia solo aqui.
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
     * Responde con error: 400, 401, 403, 404, 405, 409, 413, 415, 422, 429 o
     * 500, segun el caso.
     */
    public static function error(int $codigoHttp, string $mensaje): never
    {
        self::enviar(['exito' => false, 'mensaje' => $mensaje], $codigoHttp);
    }

    /**
     * Envia un archivo para descargar (ej. un .xlsx de Reportes o un PDF de
     * Documentos) y termina. El nombre se limpia antes de ponerlo en la
     * cabecera: solo letras, numeros, punto, guion y guion bajo, para que un
     * nombre malicioso no pueda alterar la respuesta.
     */
    public static function archivo(string $contenido, string $nombreArchivo, string $tipoMime): never
    {
        // Sin caracteres de control, comillas ni separadores de ruta, que
        // podrian romper la cabecera o sugerir otra carpeta al navegador.
        $nombre = trim((string) preg_replace('/[\x00-\x1F\x7F"\\\\\/]/u', '', $nombreArchivo));
        $nombreLimpio = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre);
        if ($nombreLimpio === null || trim($nombreLimpio, '._') === '') {
            $nombreLimpio = 'archivo';
            $nombre = 'archivo';
        }

        http_response_code(200);
        header('Content-Type: ' . $tipoMime);
        // filename: version solo ASCII para navegadores viejos; filename*:
        // el nombre real en UTF-8 (RFC 6266), asi se conservan las tildes.
        header('Content-Disposition: attachment; filename="' . $nombreLimpio . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($contenido));
        echo $contenido;
        exit;
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
        header('X-Content-Type-Options: nosniff');
        echo self::json($contenido);
        exit;
    }

    /**
     * Responde con éxito y entrega la respuesta completa al cliente SIN
     * terminar la ejecución, para hacer después un trabajo lento que no debe
     * notarse en el tiempo de respuesta (ej. enviar el correo de
     * recuperación: si tardara solo cuando la cuenta existe, revelaría qué
     * correos están registrados). El llamador termina con exit al acabar.
     */
    public static function exitoSinTerminar(mixed $datos, int $codigoHttp = 200): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        $cuerpo = self::json(['exito' => true, 'datos' => $datos]);
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($cuerpo));
        header('Connection: close');
        echo $cuerpo;
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * JSON de la respuesta. Un texto con UTF-8 inválido (ej. un parámetro de
     * la URL repetido en un mensaje) se reemplaza por U+FFFD en vez de hacer
     * fallar json_encode y dejar la respuesta vacía.
     *
     * @param array<string, mixed> $contenido
     */
    private static function json(array $contenido): string
    {
        return (string) json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
