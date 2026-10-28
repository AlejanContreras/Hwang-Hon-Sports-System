<?php

declare(strict_types=1);

// Las fechas (date(), 'today') se calculan con la hora de Bogotá, como el backend.
date_default_timezone_set('America/Bogota');

// Bordes de CompetenciaService::recomendacionPeso (PROPUESTA), método estático puro. Uso: php prueba_recomendacion_peso.php <ruta backend/Src>
require $argv[1] . '/Services/DeportistaService.php';
require $argv[1] . '/Services/CompetenciaService.php';

$ok = 0; $fallos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}
$r = fn (?string $cat, string $sexo, float $peso) => CompetenciaService::recomendacionPeso($cat, $sexo, $peso);
comprobar('72,5 kg (M, Mayores): exceso 2,5 sobre -70 (en el margen) -> recortar', str_contains((string) $r('Mayores', 'masculino', 72.5), 'recortar para competir en -70'), (string) $r('Mayores', 'masculino', 72.5));
comprobar('72,51 kg: exceso 2,51 (fuera del margen) -> mantener el peso', str_contains((string) $r('Mayores', 'masculino', 72.51), 'mantener el peso'), (string) $r('Mayores', 'masculino', 72.51));
comprobar('70,01 kg: exceso mínimo -> recortar', str_contains((string) $r('Mayores', 'masculino', 70.01), 'recortar'));
comprobar('70 kg exacto: está en -70, la inferior es -65 por 5 kg -> mantener', str_contains((string) $r('Mayores', 'masculino', 70.0), '-65') && str_contains((string) $r('Mayores', 'masculino', 70.0), 'mantener'), (string) $r('Mayores', 'masculino', 70.0));
comprobar('55 kg (categoría más liviana, no hay inferior) -> null', $r('Mayores', 'masculino', 55.0) === null);
comprobar('87 kg (abierta +85): exceso 2 sobre -85 -> recortar', str_contains((string) $r('Mayores', 'masculino', 87.0), '-85'));
comprobar('junior femenino 40 kg (tabla oficial): -38 por 2 kg -> recortar', str_contains((string) $r('Junior', 'femenino', 40.0), 'recortar para competir en -38'));
comprobar('sin peso o sin categoría -> null', $r('Mayores', 'masculino', 0.0) === null && $r(null, 'masculino', 70.0) === null);
comprobar('texto con coma decimal en español ("2,5 kg")', str_contains((string) $r('Mayores', 'masculino', 72.5), '2,5 kg'));
echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
exit($fallos === [] ? 0 : 1);
