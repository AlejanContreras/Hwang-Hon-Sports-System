<?php

declare(strict_types=1);

// Las fechas (date(), 'today') se calculan con la hora de Bogotá, como el backend.
date_default_timezone_set('America/Bogota');

// Reglas puras PROVISIONALES de EvaluacionService. Uso: php prueba_reglas_evaluacion.php <ruta backend/Src>
require $argv[1] . '/Services/EvaluacionService.php';

$ok = 0; $fallos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}
echo "[gradoSiguiente]\n";
foreach ([[null, 'Blanco'], ['', 'Blanco'], ['Ingresado', 'Blanco'], ['amarillo', 'Naranja'], ['Marrón', 'Marrón franja negra'], ['marron franja negra', 'Negro'], ['Negro', null], ['Dorado', null]] as [$actual, $esperado]) {
    comprobar('gradoSiguiente(' . var_export($actual, true) . ') = ' . var_export($esperado, true), EvaluacionService::gradoSiguiente($actual) === $esperado, var_export(EvaluacionService::gradoSiguiente($actual), true));
}
comprobar('gradoCanonico sin mayúsculas ni tildes ("VIOLETA" -> Violeta, "marron" -> Marrón)', EvaluacionService::gradoCanonico('VIOLETA') === 'Violeta' && EvaluacionService::gradoCanonico('marron') === 'Marrón' && EvaluacionService::gradoCanonico('Dorado') === null);

echo "\n[resultadoGeneral]\n";
$seis = fn (array $n) => array_combine(['teorico', 'fisico', 'combate', 'rompimiento', 'formas', 'llaves'], $n);
comprobar('seis notas de 5 -> promedio 5.00, aprobado (borde)', EvaluacionService::resultadoGeneral($seis([5, 5, 5, 5, 5, 5])) === ['completo' => true, 'promedio' => 5.0, 'aprobado' => true]);
comprobar('promedio 4.99 -> no aprobado (borde)', EvaluacionService::resultadoGeneral($seis([5, 5, 5, 5, 5, 4.94])) === ['completo' => true, 'promedio' => 4.99, 'aprobado' => false], json_encode(EvaluacionService::resultadoGeneral($seis([5, 5, 5, 5, 5, 4.94]))));
comprobar('falla tres modalidades pero el promedio da 5.5 -> aprueba (no exige aprobar cada una)', EvaluacionService::resultadoGeneral($seis([10, 10, 10, 1, 1, 1]))['aprobado'] === true);
comprobar('una modalidad sin nota -> incompleto, promedio y aprobado null', EvaluacionService::resultadoGeneral(['teorico' => 8.0, 'fisico' => 8.0, 'combate' => 8.0, 'rompimiento' => 8.0, 'formas' => 8.0, 'llaves' => null]) === ['completo' => false, 'promedio' => null, 'aprobado' => null]);
comprobar('falta una modalidad (fila inexistente) -> incompleto', EvaluacionService::resultadoGeneral(['teorico' => 8.0, 'fisico' => 8.0, 'combate' => 8.0])['completo'] === false);
comprobar('promedio a 2 decimales (7.333.. -> 7.33)', EvaluacionService::resultadoGeneral($seis([7, 7, 7, 7, 8, 8]))['promedio'] === 7.33);

echo "\n[elegibilidad]\n";
comprobar('16 años, 3 de 10 ausencias (30 %) -> apto (umbral 30, borde)', EvaluacionService::elegibilidad(10, 3, 16) === ['porcentaje' => 30.0, 'umbral' => 30, 'apto' => true, 'avisos' => []]);
comprobar('16 años, 4 de 13 (30,77 %) -> no apto, con aviso', EvaluacionService::elegibilidad(13, 4, 16)['apto'] === false && count(EvaluacionService::elegibilidad(13, 4, 16)['avisos']) === 1);
comprobar('17 años, 4 de 10 (40 %) -> apto (umbral 40, borde)', EvaluacionService::elegibilidad(10, 4, 17)['apto'] === true && EvaluacionService::elegibilidad(10, 4, 17)['umbral'] === 40);
comprobar('17 años, 41 de 100 -> no apto', EvaluacionService::elegibilidad(100, 41, 17)['apto'] === false);
comprobar('borde de edad: 4 de 10 (40 %) a los 16 no es apto y a los 17 sí', EvaluacionService::elegibilidad(10, 4, 16)['apto'] === false && EvaluacionService::elegibilidad(10, 4, 17)['apto'] === true);
comprobar('5 años usa el umbral de 30', EvaluacionService::elegibilidad(10, 3, 5)['umbral'] === 30);
comprobar('sin sesiones -> porcentaje y apto null, aviso de sin datos', EvaluacionService::elegibilidad(0, 0, 20) === ['porcentaje' => null, 'umbral' => 40, 'apto' => null, 'avisos' => ['Sin datos de asistencia en la ventana.']]);
echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
exit($fallos === [] ? 0 : 1);
