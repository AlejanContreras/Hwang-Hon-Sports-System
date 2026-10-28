<?php

declare(strict_types=1);

// Bateria HTTP del paso 6 (Pagos), fuera del repositorio. "Hoy" es la fecha real de Bogotá.
// Uso: php prueba_http_pagos.php <url base>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recien cargados.

$base = rtrim($argv[1], '/');
date_default_timezone_set('America/Bogota');
$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$bd->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$anio = (int) date('Y');
$hoy = date('Y-m-d');

$ok = 0; $fallos = []; $crudos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}
/** @return array{0: int, 1: ?array} */
function peticion(string $metodo, string $url, ?array $cuerpo = null, ?string $cookie = null): array
{
    global $crudos;
    $cabeceras = "Content-Type: application/json\r\n" . ($cookie ? "Cookie: {$cookie}\r\n" : '');
    $contexto = stream_context_create(['http' => ['method' => $metodo, 'header' => $cabeceras, 'ignore_errors' => true,
        'content' => $cuerpo === null ? '' : json_encode($cuerpo)]]);
    $texto = (string) file_get_contents($url, false, $contexto);
    $crudos[] = $texto;
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $m);
    return [(int) $m[1], json_decode($texto, true)];
}
function sesion(string $accion, array $cuerpo): string
{
    global $base;
    $c = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'ignore_errors' => true, 'content' => json_encode($cuerpo)]]);
    file_get_contents("{$base}/api.php?recurso=auth&accion={$accion}", false, $c);
    foreach ($http_response_header as $h) { if (preg_match('/^Set-Cookie: (HWANGHON_SESION=[^;]*)/i', $h, $m)) { return $m[1]; } }
    return '';
}
function p(string $accion, array $query = []): string
{
    global $base;
    return "{$base}/api.php?recurso=pagos&accion={$accion}" . ($query ? '&' . http_build_query($query) : '');
}
function cuantos(string $documento, ?string $tipo = null): int
{
    global $bd;
    return (int) $bd->query("SELECT COUNT(*) FROM pago WHERE deportista_documento = '{$documento}'" . ($tipo ? " AND tipo = '{$tipo}'" : ''))->fetchColumn();
}

$ins = $bd->prepare("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro) VALUES (?, ?, '2000-01-01', 'masculino', 1.70, 70, 1, ?, ?, ?)");
foreach ([
    ['9300000001', 'Pagador Uno', "{$anio}-01-03", 'activo', 'validado'],
    ['9300000002', 'Pendiente Pagos', "{$anio}-01-03", 'activo', 'pendiente_validacion'],
    ['9300000003', 'Inactivo Con Asistencias', "{$anio}-01-05", 'inactivo', 'validado'],
    ['9300000004', 'Deudor Septiembre', "{$anio}-01-05", 'activo', 'validado'],
    ['9300000005', 'Matricula Atrasada', ($anio - 1) . '-01-01', 'activo', 'validado'],
] as $d) { $ins->execute($d); }

$admin = sesion('iniciarSesionAdministrativa', ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']);
$publico = sesion('iniciarSesionPublica', ['documento' => '1000000101', 'fecha_nacimiento' => '2014-03-12']);
comprobar('sesiones administrativa y pública creadas', $admin !== '' && $publico !== '');

echo "\n[registrar]\n";
$meses = fn (array $ms, int $s) => array_map(fn ($m) => ['mes' => $m, 'semana' => $s], $ms);
[$c, $j] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'mensualidad', 'anio' => $anio, 'pagos' => array_merge($meses(range(1, 8), 1), $meses([9], 2))], $admin);
comprobar('varios meses en un lote -> 201 con las 9 filas creadas', $c === 201 && count($j['datos']) === 9 && array_keys($j['datos'][0]) === ['id', 'deportista_documento', 'tipo', 'anio', 'mes', 'semana'], $c . json_encode($j));
comprobar('las filas vienen con enteros (PagoModel::fromArray con filas reales)', is_int($j['datos'][0]['id']) && is_int($j['datos'][0]['anio']) && is_int($j['datos'][8]['mes']) && $j['datos'][8]['semana'] === 2);
[$c, $j] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'mensualidad', 'anio' => $anio, 'pagos' => $meses([11, 12], 1)], $admin);
comprobar('pago adelantado (noviembre y diciembre) -> 201', $c === 201 && count($j['datos']) === 2, (string) $c);
$idDiciembre = $j['datos'][1]['id'] ?? 0;
$antes = cuantos('9300000001');
[$c, $j] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'mensualidad', 'anio' => $anio, 'pagos' => $meses([10, 11], 1)], $admin);
comprobar('lote con un mes ya pagado (11) -> 409 y no guarda nada (ni octubre)', $c === 409 && cuantos('9300000001') === $antes && str_contains($j['mensaje'], '11'), $c . json_encode($j));
[$c] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'mensualidad', 'anio' => $anio, 'pagos' => [['mes' => 10, 'semana' => 1], ['mes' => 10, 'semana' => 2]]], $admin);
comprobar('meses repetidos en el mismo lote -> 400', $c === 400 && cuantos('9300000001') === $antes, (string) $c);
foreach ([
    'mes 13' => [['mes' => 13, 'semana' => 1]], 'mes 0' => [['mes' => 0, 'semana' => 1]], 'semana 0' => [['mes' => 10, 'semana' => 0]],
    'semana 5' => [['mes' => 10, 'semana' => 5]], 'semana faltante' => [['mes' => 10]], 'pagos vacío' => [], 'campo extra en el pago' => [['mes' => 10, 'semana' => 1, 'monto' => 1000]],
] as $caso => $lote) {
    [$c] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'mensualidad', 'anio' => $anio, 'pagos' => $lote], $admin);
    comprobar("{$caso} -> 400", $c === 400, (string) $c);
}
foreach (['año 2019' => 2019, 'año actual + 2' => $anio + 2, 'año como texto' => 'dos mil'] as $caso => $a) {
    [$c] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'mensualidad', 'anio' => $a, 'pagos' => $meses([1], 1)], $admin);
    comprobar("{$caso} -> 400", $c === 400, (string) $c);
}
[$c] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'mensualidad', 'anio' => $anio + 1, 'pagos' => $meses([1], 1)], $admin);
comprobar('año actual + 1 (adelanto de enero) -> 201', $c === 201, (string) $c);
[$c, $j] = peticion('POST', p('registrar'), ['documento' => '9300000002', 'tipo' => 'mensualidad', 'anio' => $anio, 'pagos' => $meses([1], 1)], $admin);
comprobar('deportista pendiente de validación -> 400', $c === 400 && cuantos('9300000002') === 0, $c . json_encode($j));
[$c] = peticion('POST', p('registrar'), ['documento' => '0000000000', 'tipo' => 'mensualidad', 'anio' => $anio, 'pagos' => $meses([1], 1)], $admin);
comprobar('deportista inexistente -> 404', $c === 404, (string) $c);
[$c] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'donacion', 'anio' => $anio], $admin);
comprobar('tipo inválido -> 400', $c === 400, (string) $c);
[$c] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'matricula', 'anio' => $anio, 'monto' => 50000], $admin);
comprobar('campo no permitido (monto) -> 400', $c === 400, (string) $c);
[$c, $j] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'matricula', 'anio' => $anio], $admin);
comprobar('matrícula -> 201 con mes 0 y sin semana', $c === 201 && $j['datos'][0]['mes'] === 0 && $j['datos'][0]['semana'] === null && $j['datos'][0]['tipo'] === 'matricula', $c . json_encode($j));
[$c] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'matricula', 'anio' => $anio], $admin);
comprobar('matrícula del mismo año otra vez -> 409', $c === 409 && cuantos('9300000001', 'matricula') === 1, (string) $c);
[$c] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'matricula', 'anio' => $anio, 'pagos' => $meses([1], 1)], $admin);
comprobar('matrícula con pagos/semana -> 400', $c === 400, (string) $c);
[$c] = peticion('POST', p('registrar'), ['documento' => '1000000101', 'tipo' => 'matricula', 'anio' => $anio + 1], $publico);
comprobar('público no registra -> 403', $c === 403, (string) $c);
[$c] = peticion('POST', p('registrar'), ['documento' => '1000000101', 'tipo' => 'matricula', 'anio' => $anio + 1]);
comprobar('anónimo -> 401', $c === 401, (string) $c);

echo "\n[anular y volver a registrar: cambia la semana habitual]\n";
[$c, $j] = peticion('GET', p('estado', ['documento' => '9300000001']), null, $admin);
comprobar('habitual = semana del pago de mayor (año, mes): enero del año siguiente, semana 1', $c === 200 && $j['datos']['semana_habitual'] === 1, json_encode($j['datos']['semana_habitual'] ?? null));
$idEneroSiguiente = (int) $bd->query("SELECT id FROM pago WHERE deportista_documento = '9300000001' AND anio = " . ($anio + 1))->fetchColumn();
[$c, $j] = peticion('DELETE', p('anular', ['id' => $idEneroSiguiente]), null, $admin);
comprobar('anular -> 200 {eliminado: true} y la fila desaparece', $c === 200 && $j['datos'] === ['eliminado' => true] && cuantos('9300000001') === $antes + 1, $c . json_encode($j));
[$c] = peticion('DELETE', p('anular', ['id' => $idDiciembre]), null, $admin);
[$c2, $j] = peticion('POST', p('registrar'), ['documento' => '9300000001', 'tipo' => 'mensualidad', 'anio' => $anio, 'pagos' => $meses([12], 3)], $admin);
[$c3, $j3] = peticion('GET', p('estado', ['documento' => '9300000001']), null, $admin);
comprobar('anular diciembre (semana 1) y registrarlo en semana 3 -> la semana habitual pasa a 3', $c === 200 && $c2 === 201 && $j3['datos']['semana_habitual'] === 3
    && $j3['datos']['meses'][9]['vencimiento'] === sprintf('%d-10-15', $anio), json_encode([$j3['datos']['semana_habitual'] ?? null, $j3['datos']['meses'][9]['vencimiento'] ?? null]));
[$c] = peticion('DELETE', p('anular', ['id' => $idDiciembre]), null, $admin);
comprobar('anular un pago inexistente -> 404', $c === 404, (string) $c);
[$c] = peticion('DELETE', p('anular', ['id' => 'abc']), null, $admin);
comprobar('id no numérico -> 400', $c === 400, (string) $c);
[$c] = peticion('DELETE', p('anular', ['id' => $idEneroSiguiente]), null, $publico);
comprobar('público no anula -> 403', $c === 403, (string) $c);

echo "\n[estado y deudores]\n";
// Deudor: todo pagado hasta agosto + matrícula; septiembre sin pagar con 2 asistencias en su ciclo; octubre con 1.
$insP = $bd->prepare("INSERT INTO pago (deportista_documento, tipo, anio, mes, semana) VALUES (?, ?, ?, ?, ?)");
foreach (['9300000004', '9300000003'] as $doc) {
    $insP->execute([$doc, 'matricula', $anio, 0, null]);
    for ($m = 1; $m <= 8; $m++) { $insP->execute([$doc, 'mensualidad', $anio, $m, 1]); }
    foreach (["{$anio}-09-05" => 1, "{$anio}-09-12" => 1, "{$anio}-10-03" => 1] as $f => $v) { $bd->prepare('INSERT INTO asistencia VALUES (?, ?, ?)')->execute([$doc, $f, $v]); }
}
[$c, $j] = peticion('GET', p('estado', ['documento' => '9300000004']), null, $admin);
$e = $j['datos'] ?? [];
comprobar('estado: forma exacta de la respuesta', $c === 200 && array_keys($e) === ['documento', 'anio', 'semana_habitual', 'matricula', 'meses', 'meses_en_deuda', 'por_vencer', 'al_dia', 'genera_alertas'], json_encode(array_keys($e)));
comprobar('los 12 meses con mes, pagado, semana, vencimiento, vencido y en_deuda', count($e['meses']) === 12 && array_filter($e['meses'], fn ($m) => array_keys($m) !== ['mes', 'pagado', 'semana', 'vencimiento', 'vencido', 'en_deuda']) === []);
comprobar('matrícula con pagada, pendiente y atrasada', $e['matricula'] === ['pagada' => true, 'pendiente' => false, 'atrasada' => false], json_encode($e['matricula']));
comprobar('septiembre en deuda (2 asistencias); octubre vencido con 1 asistencia no es deuda', $e['meses_en_deuda'] === [9] && $e['meses'][9]['vencido'] === true && $e['meses'][9]['en_deuda'] === false && $e['al_dia'] === false, json_encode($e['meses_en_deuda']));
comprobar('sin montos en ninguna respuesta', array_filter($crudos, fn ($r) => preg_match('/"(monto|valor|precio|total_pagado)"\s*:/', $r) === 1) === []);
[$c, $j] = peticion('GET', p('estado', ['documento' => '9300000003']), null, $admin);
comprobar('inactivo en la misma situación: sin deuda ni alertas', $c === 200 && $j['datos']['meses_en_deuda'] === [] && $j['datos']['genera_alertas'] === false && $j['datos']['al_dia'] === true);
[$c, $j] = peticion('GET', p('estado', ['documento' => '9300000005']), null, $admin);
comprobar('ingresó el año pasado, sin matrícula de este año -> pendiente y atrasada (hoy ' . $hoy . '), pero al_dia true (la matrícula no es deuda)', $c === 200
    && $j['datos']['matricula'] === ['pagada' => false, 'pendiente' => true, 'atrasada' => true] && $j['datos']['al_dia'] === true && $j['datos']['meses_en_deuda'] === [], json_encode([$j['datos']['matricula'] ?? null, $j['datos']['al_dia'] ?? null]));
[$c, $j] = peticion('GET', p('estado', ['documento' => '9300000004', 'anio' => (string) ($anio - 1)]), null, $admin);
comprobar('estado de un año anterior al ingreso: nada vencido ni en deuda', $c === 200 && $j['datos']['anio'] === $anio - 1 && $j['datos']['meses_en_deuda'] === [] && array_sum(array_column($j['datos']['meses'], 'vencido')) === 0);
[$c] = peticion('GET', p('estado', ['documento' => '9300000004', 'anio' => '1999']), null, $admin);
comprobar('anio fuera de rango -> 400', $c === 400, (string) $c);
[$c] = peticion('GET', p('estado'), null, $admin);
comprobar('administrativo sin documento -> 400', $c === 400, (string) $c);
[$c, $j] = peticion('GET', p('deudores'), null, $admin);
comprobar('deudores: solo mensualidad en deuda (el de matrícula atrasada, el inactivo, el pendiente y los al día quedan fuera); sin matricula_atrasada', $c === 200 && $j['datos'] === [
    ['documento' => '9300000004', 'nombre' => 'Deudor Septiembre', 'meses_en_deuda' => [9]],
], json_encode($j['datos'] ?? null));
[$c, $j] = peticion('GET', p('estado'), null, $publico);
comprobar('público: su estado sin documento -> 200', $c === 200 && $j['datos']['documento'] === '1000000101', $c . json_encode($j));
[$c] = peticion('GET', p('estado', ['documento' => '9300000004']), null, $publico);
comprobar('público: estado ajeno -> 404', $c === 404, (string) $c);
[$c] = peticion('GET', p('deudores'), null, $publico);
comprobar('público: deudores -> 403', $c === 403, (string) $c);
[$c] = peticion('GET', p('estado', ['documento' => '1000000101']));
comprobar('sin sesión -> 401', $c === 401, (string) $c);

echo "\n[listar, consultar y actualizar]\n";
[$c, $j] = peticion('GET', p('listar'), null, $admin);
$total = (int) $bd->query('SELECT COUNT(*) FROM pago')->fetchColumn();
comprobar('listar sin filtros (admin) -> todos', $c === 200 && count($j['datos']) === $total, count($j['datos'] ?? []) . " de {$total}");
[$c, $j] = peticion('GET', p('listar', ['documento' => '9300000001', 'anio' => $anio, 'tipo' => 'mensualidad']), null, $admin);
comprobar('listar con documento + anio + tipo', $c === 200 && count($j['datos']) === 11 && array_unique(array_column($j['datos'], 'tipo')) === ['mensualidad'], (string) count($j['datos'] ?? []));
[$c, $j] = peticion('GET', p('listar', ['tipo' => 'matricula']), null, $admin);
comprobar('listar por tipo matrícula', $c === 200 && array_unique(array_column($j['datos'], 'tipo')) === ['matricula']);
[$c] = peticion('GET', p('listar', ['tipo' => 'donacion']), null, $admin);
comprobar('tipo inválido -> 400', $c === 400, (string) $c);
[$c, $j] = peticion('GET', p('listar'), null, $publico);
comprobar('público: listar solo los suyos', $c === 200 && $j['datos'] !== [] && array_unique(array_column($j['datos'], 'deportista_documento')) === ['1000000101']);
[$c] = peticion('GET', p('listar', ['documento' => '9300000001']), null, $publico);
comprobar('público: listar de otro -> 404', $c === 404, (string) $c);
$idPropio = (int) $bd->query("SELECT id FROM pago WHERE deportista_documento = '1000000101' LIMIT 1")->fetchColumn();
$idAjeno = (int) $bd->query("SELECT id FROM pago WHERE deportista_documento = '9300000001' LIMIT 1")->fetchColumn();
[$c, $j] = peticion('GET', p('consultar', ['id' => $idPropio]), null, $publico);
comprobar('público: consultar un pago propio -> 200', $c === 200 && $j['datos']['id'] === $idPropio);
[$c] = peticion('GET', p('consultar', ['id' => $idAjeno]), null, $publico);
comprobar('público: consultar un pago ajeno -> 404', $c === 404, (string) $c);
[$c] = peticion('GET', p('consultar', ['id' => $idAjeno]), null, $admin);
comprobar('administrativo: consultar cualquiera -> 200', $c === 200, (string) $c);
[$c, $j] = peticion('PUT', p('actualizar', ['id' => $idAjeno]), ['semana' => 2], $admin);
comprobar('actualizar no existe -> 404 «Acción no reconocida»', $c === 404 && str_contains($j['mensaje'], 'actualizar'), $c . json_encode($j));

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
