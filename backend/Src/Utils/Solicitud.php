<?php

declare(strict_types=1);

/**
 * Lectura de la solicitud HTTP entrante, centralizada en un solo lugar.
 * Es la pareja de Respuesta: Solicitud maneja lo que ENTRA (metodo HTTP,
 * origen y cuerpo JSON), Respuesta maneja lo que SALE.
 *
 * Si algo no es valido, responde el error de una vez (403, 405, 415 o 400) y
 * termina la ejecucion, igual que Respuesta::error().
 */
class Solicitud
{
    /**
     * Exige que el metodo HTTP recibido sea el esperado para la accion
     * (ej. listar exige GET, crear exige POST). Si no coincide, responde 405.
     */
    public static function exigirMetodo(string $metodoEsperado, string $metodoActual): void
    {
        if ($metodoActual !== $metodoEsperado) {
            Respuesta::error(405, "Método no permitido. Se esperaba {$metodoEsperado}.");
        }
    }

    /**
     * Proteccion CSRF para todo metodo distinto de GET (la cookie de sesion
     * viaja sola: otro sitio no debe poder disparar una accion con ella):
     *   - Sec-Fetch-Site cross-site o same-site: 403 (el navegador avisa que
     *     la peticion la origino otro sitio).
     *   - Origin presente: su host:puerto debe ser el Host de la solicitud o
     *     estar en ORIGENES_PERMITIDOS (Config/seguridad.php); si no, 403.
     *   - Sin ninguna de las dos cabeceras (curl, scripts, pruebas): se acepta;
     *     los navegadores actuales siempre envian alguna en una peticion POST.
     */
    public static function exigirMismoOrigen(string $metodo): void
    {
        if ($metodo === 'GET') {
            return;
        }
        $sitio = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if (is_string($sitio) && in_array(strtolower(trim($sitio)), ['cross-site', 'same-site'], true)) {
            Respuesta::error(403, 'Solicitud rechazada: viene de otro sitio.');
        }
        $origen = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origen === null) {
            return;
        }
        $hostOrigen = self::hostPuerto((string) $origen);
        $permitidos = [strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')))];
        if (defined('ORIGENES_PERMITIDOS')) {
            foreach (explode(',', (string) ORIGENES_PERMITIDOS) as $permitido) {
                $permitido = trim($permitido);
                if ($permitido !== '') {
                    $permitidos[] = str_contains($permitido, '://') ? self::hostPuerto($permitido) : strtolower($permitido);
                }
            }
        }
        if ($hostOrigen === null || $hostOrigen === '' || !in_array($hostOrigen, $permitidos, true)) {
            Respuesta::error(403, 'Solicitud rechazada: origen no permitido.');
        }
    }

    /**
     * Lee y decodifica el cuerpo JSON de la solicitud como arreglo.
     * Cuerpo vacio: devuelve []. Con cuerpo, el Content-Type debe ser
     * application/json (415): un formulario HTML de otro sitio solo puede
     * enviar text/plain o form-urlencoded sin permiso del navegador. Las
     * acciones multipart (subida de archivos) no llaman a este metodo.
     * JSON invalido: responde 400.
     *
     * @return array<string, mixed>
     */
    public static function cuerpoJson(): array
    {
        $crudo = file_get_contents('php://input');
        if ($crudo === false || $crudo === '') {
            return [];
        }
        $tipo = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        if (strtolower(trim(explode(';', $tipo)[0])) !== 'application/json') {
            Respuesta::error(415, 'Tipo de contenido no soportado: envía el cuerpo como application/json.');
        }
        $datos = json_decode($crudo, true);
        if (!is_array($datos)) {
            Respuesta::error(400, 'Cuerpo de la solicitud inválido: se esperaba JSON.');
        }
        return $datos;
    }

    /**
     * "https://Ejemplo.com:8443/ruta" => "ejemplo.com:8443"; el puerto solo
     * si viene escrito (igual que en la cabecera Host). null si no se puede
     * leer (ej. Origin "null").
     */
    /**
     * Segmentos de la forma por ruta (URL limpia): .../Public/deportistas/consultar
     * da ['deportistas', 'consultar']. Toma la ruta de la solicitud sin la
     * consulta, relativa a la carpeta de api.php ($nombreScript, el
     * SCRIPT_NAME), y quita "api.php" si viene en la ruta
     * (.../api.php/deportistas/consultar). Una barra final se ignora; una
     * barra doble deja un segmento vacio, que api.php rechaza.
     * Devuelve [] si la ruta es la carpeta o api.php (health check) y null
     * si la ruta no esta dentro de esa carpeta.
     *
     * @return list<string>|null
     */
    public static function segmentosDeRuta(string $uri, string $nombreScript): ?array
    {
        $ruta = (string) parse_url($uri, PHP_URL_PATH);
        $base = rtrim(str_replace('\\', '/', dirname($nombreScript)), '/');
        // Sin distinguir mayusculas: en Windows Apache sirve la carpeta igual.
        if ($base !== '' && strncasecmp($ruta, $base, strlen($base)) !== 0) {
            return null;
        }
        $resto = substr($ruta, strlen($base));
        if ($resto !== '' && $resto[0] !== '/') {
            return null; // .../PublicOtra no es la carpeta de api.php
        }
        $resto = preg_replace('#^/' . preg_quote(basename($nombreScript), '#') . '(?=/|$)#i', '', $resto);
        $resto = rtrim($resto, '/');
        return $resto === '' ? [] : explode('/', substr($resto, 1));
    }

    private static function hostPuerto(string $origen): ?string
    {
        $partes = parse_url(trim($origen));
        if (!is_array($partes) || !isset($partes['host'])) {
            return null;
        }
        $host = strtolower($partes['host']);
        return isset($partes['port']) ? "{$host}:{$partes['port']}" : $host;
    }
}
