<?php

declare(strict_types=1);

/**
 * Lectura de la solicitud HTTP entrante, centralizada en un solo lugar.
 * Es la pareja de Respuesta: Solicitud maneja lo que ENTRA (metodo HTTP y
 * cuerpo JSON), Respuesta maneja lo que SALE.
 *
 * Si algo no es valido, responde el error de una vez (405 o 400) y termina
 * la ejecucion, igual que Respuesta::error().
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
            Respuesta::error(405, "Metodo no permitido. Se esperaba {$metodoEsperado}.");
        }
    }

    /**
     * Lee y decodifica el cuerpo JSON de la solicitud como arreglo.
     * Cuerpo vacio: devuelve []. JSON invalido: responde 400.
     *
     * @return array<string, mixed>
     */
    public static function cuerpoJson(): array
    {
        $crudo = file_get_contents('php://input');
        if ($crudo === false || $crudo === '') {
            return [];
        }
        $datos = json_decode($crudo, true);
        if (!is_array($datos)) {
            Respuesta::error(400, 'Cuerpo de la solicitud invalido: se esperaba JSON.');
        }
        return $datos;
    }
}
