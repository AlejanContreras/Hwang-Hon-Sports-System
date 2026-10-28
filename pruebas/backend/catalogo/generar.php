<?php

declare(strict_types=1);

// Genera catalogo_api.md: reemplaza {{ej:clave}} y {{err:clave}} de la plantilla por las capturas REALES.
// Un {{ej:…}} con sangría (dentro de una lista) se sangra completo, para no romper la numeración.
// Uso: php generar.php <plantilla.md> <capturas.json> <salida.md>
[$_, $plantilla, $capturasJson, $salida] = $argv;
$capturas = json_decode(file_get_contents($capturasJson), true);
$usadas = [];

/** Listas de más de 3 elementos: se muestran 2 y un marcador con cuántos se omitieron. */
function recortar(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    if (array_is_list($v)) {
        $r = array_map('recortar', count($v) > 3 ? array_slice($v, 0, 2) : $v);
        if (count($v) > 3) {
            $r[] = '… (' . (count($v) - 2) . ' elementos más, omitidos en este ejemplo)';
        }
        return $r;
    }
    return array_map('recortar', $v);
}
function bloque(mixed $v): string
{
    return "```json\n" . json_encode(recortar($v), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```";
}
function captura(string $clave): ?array
{
    global $capturas, $usadas;
    if (!isset($capturas[$clave])) {
        fwrite(STDERR, "FALTA captura {$clave}\n");
        return null;
    }
    $usadas[$clave] = true;
    return $capturas[$clave];
}
function ejemplo(array $c): string
{
    $salida = "Ejemplo real (llamada como **{$c['quien']}**): `{$c['metodo']} {$c['ruta']}`\n\n";
    if ($c['cuerpo'] !== null) {
        $salida .= is_array($c['cuerpo']) && isset($c['cuerpo']['multipart'])
            ? "Cuerpo `multipart/form-data`:\n\n" . bloque($c['cuerpo']['multipart']) . "\n\n"
            : "Cuerpo:\n\n" . bloque($c['cuerpo']) . "\n\n";
    }
    if ($c['respuesta'] !== null) {
        return $salida . "Respuesta `{$c['codigo']}`:\n\n" . bloque($c['respuesta']);
    }
    $salida .= "Respuesta `{$c['codigo']}` (archivo; cabeceras):\n\n```http\n";
    foreach ($c['cabeceras'] as $k => $v) {
        $salida .= ucwords($k, '-') . ": {$v}\n";
    }
    return $salida . '```';
}

$texto = file_get_contents($plantilla);
$texto = preg_replace_callback('/^([ \t]*)\{\{ej:([^}]+)\}\}[ \t]*$/m', function (array $m): string {
    $c = captura($m[2]);
    if ($c === null) {
        return "{$m[1]}**(falta captura {$m[2]})**";
    }
    return implode("\n", array_map(fn (string $l): string => $l === '' ? '' : $m[1] . $l, explode("\n", ejemplo($c))));
}, $texto);
$texto = preg_replace_callback('/\{\{err:([^}]+)\}\}/', function (array $m): string {
    $c = captura($m[1]);
    return $c === null ? "**(falta captura {$m[1]})**" : "`{$c['codigo']}` «" . ($c['respuesta']['mensaje'] ?? '') . '»';
}, $texto);
if (preg_match('/\{\{(ej|err):/', $texto)) {
    fwrite(STDERR, "QUEDAN marcadores sin reemplazar (un {{ej}} debe ir solo en su línea)\n");
}

file_put_contents($salida, $texto);
$noUsadas = array_diff(array_keys($capturas), array_keys($usadas));
fwrite(STDERR, count($usadas) . ' capturas usadas; sin usar: ' . implode(', ', $noUsadas) . "\n");
