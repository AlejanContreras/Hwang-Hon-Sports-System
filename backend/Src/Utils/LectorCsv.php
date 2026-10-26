<?php

declare(strict_types=1);

/**
 * Lector de CSV compartido (importación del Calendario y carga masiva de
 * Deportistas). Solo interpreta el texto: las columnas esperadas, los
 * límites de filas y la validación de cada valor son de cada Service.
 *
 *   - Quita el BOM de UTF-8.
 *   - Separador: punto y coma o coma, el que más aparezca en la primera
 *     línea (Excel en español guarda con punto y coma).
 *   - Comillas dobles como delimitador de celda, sin carácter de escape
 *     (una comilla dentro de una celda se escribe doble: "").
 *   - Las líneas en blanco se saltan; el número de línea de cada fila es el
 *     de su primera línea física (la 1 es el encabezado).
 *
 * Dos opciones, apagadas por defecto (así lee el Calendario desde el paso 7):
 *   - aceptarWindows1252: un archivo que no es UTF-8 válido se interpreta
 *     como Windows-1252 (el «CSV» por defecto de Excel en español) y se
 *     convierte; apagada, responde 400 pidiendo «CSV UTF-8».
 *   - celdasMultilinea: una celda entre comillas puede contener saltos de
 *     línea; apagada, cada línea física es una fila.
 */
final class LectorCsv
{
    /**
     * @return array{encabezado: array<int, ?string>, filas: array<int, array<int, string>>, separador: string, codificacion: string}
     */
    public static function leer(string $contenido, bool $aceptarWindows1252 = false, bool $celdasMultilinea = false): array
    {
        if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
            $contenido = substr($contenido, 3);
        }
        $codificacion = 'UTF-8';
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            if (!$aceptarWindows1252) {
                throw new ExcepcionNegocio('El archivo debe estar guardado en UTF-8 (en Excel: «CSV UTF-8»).', 400);
            }
            $contenido = (string) mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
            $codificacion = 'Windows-1252';
        }

        $primeraLinea = preg_split('/\r\n|\n|\r/', $contenido, 2)[0] ?? '';
        $separador = substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',') ? ';' : ',';

        [$encabezado, $filas] = $celdasMultilinea
            ? self::registrosMultilinea($contenido, $separador)
            : self::registrosPorLinea($contenido, $separador);

        return ['encabezado' => $encabezado, 'filas' => $filas, 'separador' => $separador, 'codificacion' => $codificacion];
    }

    /**
     * Una fila por línea física (comportamiento original del Calendario).
     *
     * @return array{0: array<int, ?string>, 1: array<int, array<int, string>>}
     */
    private static function registrosPorLinea(string $contenido, string $separador): array
    {
        $lineas = preg_split('/\r\n|\n|\r/', $contenido);
        $encabezado = str_getcsv(array_shift($lineas) ?? '', $separador, '"', '');
        $filas = [];
        foreach ($lineas as $indice => $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $filas[$indice + 2] = str_getcsv($linea, $separador, '"', ''); // la línea 1 es el encabezado
        }
        return [$encabezado, $filas];
    }

    /**
     * Filas con celdas entre comillas que pueden ocupar varias líneas. Se
     * leen con fgetcsv desde memoria (nada se escribe en disco). También se
     * saltan las filas con todas las celdas vacías (Excel guarda las filas en
     * blanco como ";;;;").
     *
     * @return array{0: array<int, ?string>, 1: array<int, array<int, string>>}
     */
    private static function registrosMultilinea(string $contenido, string $separador): array
    {
        $contenido = str_replace(["\r\n", "\r"], "\n", $contenido);
        $flujo = fopen('php://memory', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        $encabezado = [null];
        $filas = [];
        $linea = 1;
        $posicion = 0;
        $primero = true;
        while (($registro = fgetcsv($flujo, null, $separador, '"', '')) !== false) {
            $nuevaPosicion = (int) ftell($flujo);
            $lineaInicio = $linea;
            $linea += substr_count($contenido, "\n", $posicion, $nuevaPosicion - $posicion);
            $posicion = $nuevaPosicion;
            if ($primero) {
                $encabezado = $registro;
                $primero = false;
                continue;
            }
            $valores = array_map(fn (?string $valor): string => $valor ?? '', $registro);
            if (implode('', array_map('trim', $valores)) === '') {
                continue;
            }
            $filas[$lineaInicio] = $valores;
        }
        fclose($flujo);
        return [$encabezado, $filas];
    }
}
