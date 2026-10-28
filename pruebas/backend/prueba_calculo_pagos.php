<?php

declare(strict_types=1);

// Las fechas (date(), 'today') se calculan con la hora de Bogotá, como el backend.
date_default_timezone_set('America/Bogota');

// Pruebas del cálculo puro de PagoService (métodos estáticos, "hoy" elegido). Sin base de datos ni HTTP.
// Uso: php prueba_calculo_pagos.php <ruta backend/Src>

require $argv[1] . '/Services/PagoService.php';

$ok = 0; $fallos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}
$tz = new DateTimeZone('America/Bogota');
$dia = fn (string $f): DateTimeImmutable => new DateTimeImmutable($f, $tz);
function mens(int $anio, array $meses, int $semana = 1): array
{
    return array_map(fn ($m) => ['tipo' => 'mensualidad', 'anio' => $anio, 'mes' => $m, 'semana' => $semana], $meses);
}
function matricula(int $anio): array
{
    return [['tipo' => 'matricula', 'anio' => $anio, 'mes' => 0, 'semana' => null]];
}
$base = ['documento' => '9900000001', 'fecha_ingreso' => '2025-06-01', 'estado' => 'activo', 'estado_registro' => 'validado'];
$estado = fn (array $dep, int $anio, array $pagos, int $semana, array $fechas, string $hoy) => PagoService::calcularEstado($dep, $anio, $pagos, $semana, $fechas, $dia($hoy));

echo "\n[semana del mes]\n";
foreach (['2026-03-01' => 1, '2026-03-07' => 1, '2026-03-08' => 2, '2026-03-14' => 2, '2026-03-15' => 3, '2026-03-21' => 3, '2026-03-22' => 4,
    '2026-03-31' => 4, '2026-04-30' => 4, '2026-02-28' => 4, '2028-02-29' => 4, '2026-02-22' => 4, '2026-02-21' => 3] as $f => $esperada) {
    comprobar("semanaDelMes({$f}) = {$esperada}", PagoService::semanaDelMes($f) === $esperada, (string) PagoService::semanaDelMes($f));
}

echo "\n[vencimiento y semana habitual]\n";
foreach ([1 => '2026-11-01', 2 => '2026-11-08', 3 => '2026-11-15', 4 => '2026-11-22'] as $s => $esperado) {
    comprobar("vencimiento de noviembre con semana habitual {$s} = {$esperado}", PagoService::vencimiento(2026, 11, $s) === $esperado);
}
$pagos = [
    ['id' => 1, 'tipo' => 'mensualidad', 'anio' => 2026, 'mes' => 3, 'semana' => 2],
    ['id' => 9, 'tipo' => 'mensualidad', 'anio' => 2026, 'mes' => 1, 'semana' => 4],
    ['id' => 5, 'tipo' => 'mensualidad', 'anio' => 2025, 'mes' => 12, 'semana' => 1],
    ['id' => 12, 'tipo' => 'matricula', 'anio' => 2027, 'mes' => 0, 'semana' => null],
];
comprobar('habitual = semana del pago de mayor (año, mes) (marzo 2026, semana 2), no el de mayor id ni la matrícula', PagoService::semanaHabitual($pagos, '2025-01-01') === 2);
$pagos[] = ['id' => 3, 'tipo' => 'mensualidad', 'anio' => 2027, 'mes' => 1, 'semana' => 3];
comprobar('un pago adelantado de un año siguiente pasa a ser el último (semana 3)', PagoService::semanaHabitual($pagos, '2025-01-01') === 3);
comprobar('nunca pagó: semana de la fecha de ingreso (17 de mayo -> 3)', PagoService::semanaHabitual(matricula(2026), '2026-05-17') === 3);

echo "\n[deuda]\n";
$alDia = array_merge(mens(2026, range(1, 8)), matricula(2026));
$e = $estado($base, 2026, $alDia, 1, ['2026-09-05'], '2026-10-21');
comprobar('septiembre vencido sin pago con 1 asistencia -> no es deuda', $e['meses'][8]['vencido'] === true && $e['meses'][8]['en_deuda'] === false && $e['meses_en_deuda'] === []);
$e = $estado($base, 2026, $alDia, 1, ['2026-09-05', '2026-09-12'], '2026-10-21');
comprobar('2 asistencias en el ciclo -> deuda [9]', $e['meses_en_deuda'] === [9] && $e['al_dia'] === false, json_encode($e['meses_en_deuda']));
$e = $estado($base, 2026, $alDia, 1, ['2026-09-01', '2026-09-05'], '2026-10-21');
comprobar('borde: una asistencia justo el día del vencimiento cuenta -> deuda [9]', $e['meses_en_deuda'] === [9]);
$e = $estado($base, 2026, $alDia, 1, ['2026-08-31', '2026-09-05'], '2026-10-21');
comprobar('borde: la asistencia del día anterior al vencimiento no cuenta -> sin deuda', $e['meses_en_deuda'] === []);
$e = $estado($base, 2026, $alDia, 1, ['2026-09-30', '2026-10-01'], '2026-10-21');
comprobar('ciclo cerrado: la asistencia del vencimiento siguiente (1 oct) ya no es del ciclo de septiembre', !in_array(9, $e['meses_en_deuda'], true), json_encode($e['meses_en_deuda']));
$e = $estado($base, 2026, $alDia, 1, ['2026-10-03', '2026-10-10'], '2026-10-21');
comprobar('ciclo abierto (octubre, hasta hoy): 2 asistencias -> deuda [10]', $e['meses_en_deuda'] === [10], json_encode($e['meses_en_deuda']));
$e = $estado($base, 2026, $alDia, 1, ['2026-10-03', '2026-10-10'], '2026-10-05');
comprobar('ciclo abierto con hoy = 5 oct: la asistencia del 10 aún no cuenta -> sin deuda', $e['meses_en_deuda'] === []);
$e = $estado($base, 2026, array_merge($alDia, mens(2026, [9])), 1, ['2026-09-05', '2026-09-12'], '2026-10-21');
comprobar('mes pagado con asistencias -> no es deuda', $e['meses_en_deuda'] === [] && $e['meses'][8]['pagado'] === true && $e['meses'][8]['vencido'] === false);
$e = $estado($base, 2026, $alDia, 1, ['2026-09-05', '2026-09-12'], '2026-09-01');
comprobar('el día del vencimiento todavía no está vencido (vencimiento < hoy es estricto)', $e['meses'][8]['vencido'] === false && $e['meses_en_deuda'] === []);
foreach (['inactivo' => ['estado' => 'inactivo'], 'pendiente de validación' => ['estado_registro' => 'pendiente_validacion']] as $caso => $cambio) {
    $e = $estado(array_merge($base, $cambio), 2026, mens(2026, range(1, 8)), 1, ['2026-09-05', '2026-09-12'], '2026-10-21');
    comprobar("{$caso}: sin deuda, sin matrícula atrasada, sin aviso (genera_alertas false; vencido sigue visible)", $e['meses_en_deuda'] === [] && $e['matricula']['atrasada'] === false
        && $e['por_vencer'] === null && $e['genera_alertas'] === false && $e['meses'][8]['vencido'] === true && $e['meses'][8]['en_deuda'] === false);
}
$medioAnio = array_merge($base, ['fecha_ingreso' => '2026-06-10']);
$e = $estado($medioAnio, 2026, matricula(2026), PagoService::semanaHabitual([], '2026-06-10'), ['2026-06-13', '2026-06-20'], '2026-10-21');
comprobar('ingreso a mitad de año: los meses anteriores no cuentan; el mes de ingreso sí (vence el 8 de junio, semana 2)', $e['meses'][4]['vencido'] === false && $e['meses'][5]['vencimiento'] === '2026-06-08'
    && $e['meses'][5]['vencido'] === true && in_array(6, $e['meses_en_deuda'], true), json_encode([$e['meses'][4], $e['meses'][5]]));

echo "\n[por vencer: margen de 5 días]\n";
// Agosto de 2026 empieza en sábado; julio pagado; semana habitual 1.
$hastaJulio = array_merge(mens(2026, range(1, 7)), matricula(2026));
$e = $estado($base, 2026, $hastaJulio, 1, [], '2026-07-27');
comprobar('5 días antes (lunes 27 de julio, en el margen) -> aviso', $e['por_vencer'] === ['mes' => 8, 'anio' => 2026, 'vencimiento' => '2026-08-01', 'dias_restantes' => 5], json_encode($e['por_vencer']));
$e = $estado($base, 2026, $hastaJulio, 1, [], '2026-07-26');
comprobar('6 días antes (domingo 26) -> sin aviso', $e['por_vencer'] === null, json_encode($e['por_vencer']));
$e = $estado($base, 2026, $hastaJulio, 1, [], '2026-07-30');
comprobar('ejemplo semana 1: mes que arranca el sábado 1, el jueves 30 hay aviso (2 días)', ($e['por_vencer']['dias_restantes'] ?? null) === 2);
$e = $estado($base, 2026, $hastaJulio, 1, [], '2026-07-29');
comprobar('ejemplo semana 1: el miércoles 29 también (3 días)', ($e['por_vencer']['dias_restantes'] ?? null) === 3);
$e = $estado($base, 2026, $hastaJulio, 1, [], '2026-08-01');
comprobar('el mismo día del vencimiento -> aviso con 0 días', ($e['por_vencer']['dias_restantes'] ?? null) === 0);
$hastaOctubre = array_merge(mens(2026, range(1, 10)), matricula(2026));
$e = $estado($base, 2026, $hastaOctubre, 3, [], '2026-11-10');
comprobar('semana habitual 3 (vence el 15): el 10 (5 días) aviso', ($e['por_vencer']['vencimiento'] ?? null) === '2026-11-15' && $e['por_vencer']['dias_restantes'] === 5);
$e = $estado($base, 2026, $hastaOctubre, 3, [], '2026-11-09');
comprobar('semana habitual 3: el 9 (6 días) sin aviso', $e['por_vencer'] === null);
$e = $estado($base, 2026, $hastaOctubre, 1, [], '2026-10-27');
comprobar('cruce de mes: el 27 de octubre avisa noviembre (5 días)', $e['por_vencer'] === ['mes' => 11, 'anio' => 2026, 'vencimiento' => '2026-11-01', 'dias_restantes' => 5], json_encode($e['por_vencer']));
$e = $estado($base, 2026, array_merge(mens(2026, range(1, 12)), matricula(2026)), 1, [], '2026-12-27');
comprobar('cruce de año: todo 2026 pagado, el 27 dic avisa enero de 2027 (5 días)', $e['por_vencer'] === ['mes' => 1, 'anio' => 2027, 'vencimiento' => '2027-01-01', 'dias_restantes' => 5], json_encode($e['por_vencer']));
$e = $estado($base, 2026, array_merge(mens(2026, range(1, 12)), matricula(2026)), 1, [], '2026-12-26');
comprobar('cruce de año: el 26 dic (6 días) sin aviso', $e['por_vencer'] === null);
$e = $estado($base, 2026, array_merge(mens(2026, range(1, 8)), matricula(2026)), 1, [], '2026-07-29');
comprobar('mes siguiente ya pagado por adelantado -> sin aviso', $e['por_vencer'] === null, json_encode($e['por_vencer']));

echo "\n[matrícula: solo recordatorio]\n";
$antiguo = array_merge($base, ['fecha_ingreso' => '2025-01-01']);
$todoPagado = mens(2026, range(1, 12));
$e = $estado($antiguo, 2026, array_merge($todoPagado, matricula(2026)), 1, [], '2026-02-15');
comprobar('pagada -> {pagada: true, pendiente: false, atrasada: false}', $e['matricula'] === ['pagada' => true, 'pendiente' => false, 'atrasada' => false]);
$e = $estado($antiguo, 2026, $todoPagado, 1, [], '2026-01-31');
comprobar('31 de enero sin pagar -> pendiente, no atrasada; al_dia sigue true', $e['matricula'] === ['pagada' => false, 'pendiente' => true, 'atrasada' => false] && $e['al_dia'] === true);
$e = $estado($antiguo, 2026, $todoPagado, 1, [], '2026-02-01');
comprobar('1 de febrero sin pagar -> atrasada (informativa): al_dia sigue true, sin meses en deuda', $e['matricula'] === ['pagada' => false, 'pendiente' => true, 'atrasada' => true] && $e['al_dia'] === true && $e['meses_en_deuda'] === []);
$e = $estado(array_merge($base, ['fecha_ingreso' => '2026-06-10']), 2026, [], 2, [], '2026-06-15');
comprobar('ingreso el 10 de junio: la debe al ingresar (pendiente; atrasada porque ya pasó el 1 de febrero)', $e['matricula'] === ['pagada' => false, 'pendiente' => true, 'atrasada' => true]);
$diciembre = array_merge($base, ['fecha_ingreso' => '2026-12-03']);
$e = $estado($diciembre, 2026, [], 1, [], '2026-12-15');
comprobar('ingreso en diciembre (PROPUESTA): la matrícula del año no cuenta (pendiente false, atrasada false)', $e['matricula'] === ['pagada' => false, 'pendiente' => false, 'atrasada' => false], json_encode($e['matricula']));
comprobar('ingreso en diciembre: la mensualidad de diciembre sí cuenta y ya está vencida', $e['meses'][11]['vencimiento'] === '2026-12-01' && $e['meses'][11]['vencido'] === true && $e['meses'][10]['vencido'] === false);
$e = $estado($diciembre, 2027, [], 1, [], '2027-01-05');
comprobar('ingreso en diciembre: en el reset de enero siguiente sí se le pide (pendiente)', $e['matricula'] === ['pagada' => false, 'pendiente' => true, 'atrasada' => false]);
$e = $estado($antiguo, 2027, matricula(2026), 1, [], '2027-01-05');
comprobar('reset de enero: la de 2026 pagada no cuenta para 2027 (pendiente, no atrasada)', $e['matricula'] === ['pagada' => false, 'pendiente' => true, 'atrasada' => false]);
$e = $estado($antiguo, 2027, matricula(2026), 1, [], '2027-02-01');
comprobar('reset: el 1 de febrero de 2027 queda atrasada', $e['matricula']['atrasada'] === true);
$e = $estado(array_merge($base, ['fecha_ingreso' => '2027-03-01']), 2026, [], 1, [], '2027-04-01');
comprobar('quien ingresó después de ese año no la debe', $e['matricula'] === ['pagada' => false, 'pendiente' => false, 'atrasada' => false]);
$e = $estado(array_merge($antiguo, ['estado' => 'inactivo']), 2026, [], 1, [], '2026-03-01');
comprobar('inactivo: sin recordatorio de matrícula (no genera alertas)', $e['matricula'] === ['pagada' => false, 'pendiente' => false, 'atrasada' => false]);
comprobar('matriculaCuenta (método único de la regla): diciembre no, noviembre sí, año siguiente sí', PagoService::matriculaCuenta('2026-12-01', 2026) === false
    && PagoService::matriculaCuenta('2026-11-30', 2026) === true && PagoService::matriculaCuenta('2026-12-01', 2027) === true);

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
