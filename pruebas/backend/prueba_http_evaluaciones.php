<?php

declare(strict_types=1);

// Bateria HTTP del paso 9 (Evaluaciones y ascensos), fuera del repositorio. Fechas relativas a hoy (Bogotá).
// Uso: php prueba_http_evaluaciones.php <url base>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recien cargados.

$base = rtrim($argv[1], '/');
date_default_timezone_set('America/Bogota');
$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$bd->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$hoy = new DateTimeImmutable('today');
$d = fn (string $mod): string => $hoy->modify($mod)->format('Y-m-d');

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
    $crudos[] = [$cookie, $texto];
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
function e(string $accion, array $query = []): string
{
    global $base;
    return "{$base}/api.php?recurso=evaluaciones&accion={$accion}" . ($query ? '&' . http_build_query($query) : '');
}
function cuenta(string $sql): int
{
    global $bd;
    return (int) $bd->query($sql)->fetchColumn();
}
function comSelect(): int
{
    global $bd;
    return (int) $bd->query("SHOW GLOBAL STATUS LIKE 'Com_select'")->fetch()['Value'];
}
function participante(array $sesion, string $doc): ?array
{
    foreach ($sesion['participantes'] ?? [] as $p) { if ($p['deportista_documento'] === $doc) { return $p; } }
    return null;
}

// Deportistas de prueba (activos y validados salvo que se diga).
$F = $d('+0 days');           // fecha de inicio para planear candidatos
$insD = $bd->prepare("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro, grado_actual) VALUES (?, ?, ?, 'masculino', 1.70, 60, 1, ?, 'activo', 'validado', ?)");
$edad = fn (int $anios): string => (new DateTimeImmutable($F))->modify("-{$anios} years -30 days")->format('Y-m-d');
foreach ([
    ['9700000001', 'Candidato Uno Dieciseis', $edad(16), '2024-01-01', 'Verde'],
    ['9700000002', 'Candidato Dos Dieciseis', $edad(16), '2024-01-01', 'Verde'],
    ['9700000003', 'Candidato Tres Diecisiete', $edad(17), '2024-01-01', 'Azul'],
    ['9700000004', 'Candidato Cuatro Diecisiete', $edad(17), '2024-01-01', 'Azul'],
    ['9700000005', 'Candidato Sin Datos', $edad(20), '2024-01-01', 'Blanco'],
    ['9700000006', 'Candidato Ingreso Reciente', $edad(20), $d('-80 days'), null],
    ['9700000007', 'Candidato Concurrencia', $edad(25), '2024-01-01', 'Naranja'],
    ['9700000008', 'Grado Raro', $edad(25), '2024-01-01', 'Dorado'],
] as $x) { $insD->execute($x); }

$admin = sesion('iniciarSesionAdministrativa', ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']);
$publico101 = sesion('iniciarSesionPublica', ['documento' => '1000000101', 'fecha_nacimiento' => '2014-03-12']);
$publicoC1 = sesion('iniciarSesionPublica', ['documento' => '9700000001', 'fecha_nacimiento' => $edad(16)]);
comprobar('sesiones administrativa y públicas creadas', $admin !== '' && $publico101 !== '' && $publicoC1 !== '');

echo "\n[sesión anterior y candidatos]\n";
[$c, $j] = peticion('POST', e('crear'), ['fecha' => $d('-140 days'), 'fecha_grado' => $d('-134 days'), 'lugar' => 'Sede anterior'], $admin);
$idAnterior = $j['datos']['id'] ?? 0;
comprobar('crear sesión anterior sin participantes -> 201', $c === 201 && $j['datos']['participantes'] === [], $c . json_encode($j));
$gradoAnterior = $d('-134 days');
$bd->prepare("INSERT INTO entrega_cinturon (sesion_examen_id, deportista_documento, cinturon_entregado, fecha_entrega, confirmado_por) VALUES (?, '9700000001', 1, ?, 'administrativo')")->execute([$idAnterior, $gradoAnterior]);
// Asistencia: fechas semanales dentro de la ventana [grado anterior, víspera de F].
$fechas = [];
for ($i = 1; $i <= 15; $i++) { $fechas[] = (new DateTimeImmutable($gradoAnterior))->modify('+' . ($i * 7) . ' days')->format('Y-m-d'); }
$insA = $bd->prepare('INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES (?, ?, ?)');
$cargar = function (string $doc, int $total, int $ausencias) use ($insA, $fechas): void {
    for ($i = 0; $i < $total; $i++) { $insA->execute([$doc, $fechas[$i], $i < $ausencias ? 0 : 1]); }
};
$cargar('9700000001', 10, 3);  // 30 %   16 años -> apto (borde)
$cargar('9700000002', 13, 4);  // 30,77 % 16 años -> no apto
$cargar('9700000003', 10, 4);  // 40 %   17 años -> apto (borde)
$cargar('9700000004', 10, 5);  // 50 %   17 años -> no apto
$insA->execute(['9700000001', $d('-136 days'), 0]);   // antes de la ventana: no cuenta
$insA->execute(['9700000001', $F, 0]);                 // el mismo día de inicio: no cuenta (hasta la víspera)
$insA->execute(['9700000006', $d('-90 days'), 0]);     // antes de su ingreso: no cuenta
$insA->execute(['9700000006', $d('-70 days'), 1]);
$insA->execute(['9700000006', $d('-63 days'), 1]);
[$c, $j] = peticion('GET', e('candidatos', ['fecha' => $F]), null, $admin);
$cand = array_column($j['datos'] ?? [], null, 'documento');
comprobar('candidatos (?fecha=): solo activos y validados, con los campos esperados', $c === 200 && isset($cand['9700000001'], $cand['1000000101']) && !isset($cand['1000000103']) && !isset($cand['1000000102'])
    && array_keys($cand['9700000001']) === ['documento', 'nombre', 'grado_actual', 'grado_siguiente', 'edad', 'fecha_ultimo_ascenso', 'ventana_desde', 'sesiones_en_ventana', 'porcentaje_inasistencia', 'umbral', 'apto', 'avisos'], $c . json_encode(array_keys($cand['9700000001'] ?? [])));
comprobar('ventana desde el grado de la sesión anterior hasta la víspera (10 fechas; excluye la previa y la del día de inicio)', $cand['9700000001']['ventana_desde'] === $gradoAnterior && $cand['9700000001']['sesiones_en_ventana'] === 10, json_encode($cand['9700000001']));
comprobar('16 años, 30 % -> apto (borde del umbral 30)', $cand['9700000001']['edad'] === 16 && $cand['9700000001']['porcentaje_inasistencia'] == 30 && $cand['9700000001']['umbral'] === 30 && $cand['9700000001']['apto'] === true && $cand['9700000001']['avisos'] === []);
comprobar('16 años, 30,77 % -> no apto con aviso (no bloquea)', $cand['9700000002']['apto'] === false && $cand['9700000002']['porcentaje_inasistencia'] == 30.77 && count($cand['9700000002']['avisos']) === 1);
comprobar('17 años, 40 % -> apto (borde del umbral 40)', $cand['9700000003']['edad'] === 17 && $cand['9700000003']['umbral'] === 40 && $cand['9700000003']['apto'] === true);
comprobar('17 años, 50 % -> no apto', $cand['9700000004']['apto'] === false);
comprobar('sin datos de asistencia -> porcentaje y apto null, con aviso', $cand['9700000005']['porcentaje_inasistencia'] === null && $cand['9700000005']['apto'] === null && $cand['9700000005']['avisos'] === ['Sin datos de asistencia en la ventana.']);
comprobar('ingreso posterior a la sesión anterior: ventana desde el ingreso y aviso RN-5.6', $cand['9700000006']['ventana_desde'] === $d('-80 days') && $cand['9700000006']['sesiones_en_ventana'] === 2
    && (bool) array_filter($cand['9700000006']['avisos'], fn ($a) => str_contains($a, 'ciclo anterior')), json_encode($cand['9700000006']));
comprobar('grado siguiente sugerido (Verde -> Azul; sin grado -> Blanco; fuera de la lista -> null)', $cand['9700000001']['grado_siguiente'] === 'Azul' && $cand['9700000006']['grado_siguiente'] === 'Blanco' && $cand['9700000008']['grado_siguiente'] === null);
comprobar('fecha del último ascenso (entrega confirmada más reciente)', $cand['9700000001']['fecha_ultimo_ascenso'] === $gradoAnterior && $cand['9700000002']['fecha_ultimo_ascenso'] === null);
foreach (['sin parámetros' => [], 'los dos' => ['fecha' => $F, 'sesion_id' => $idAnterior]] as $caso => $q) {
    [$c] = peticion('GET', e('candidatos', $q), null, $admin);
    comprobar("candidatos con {$caso} -> 400", $c === 400, (string) $c);
}
$antes = comSelect(); peticion('GET', e('candidatos', ['fecha' => $F]), null, $admin); $pocos = comSelect() - $antes;
for ($i = 0; $i < 20; $i++) { $insD->execute([sprintf('98%08d', $i), "Relleno {$i}", $edad(20), '2024-01-01', 'Blanco']); $insA->execute([sprintf('98%08d', $i), $fechas[0], 1]); }
$antes = comSelect(); peticion('GET', e('candidatos', ['fecha' => $F]), null, $admin); $muchos = comSelect() - $antes;
comprobar("candidatos con consultas constantes: {$pocos} SELECT antes y {$muchos} con 20 deportistas más", $pocos === $muchos && $pocos <= 6, "{$pocos} vs {$muchos}");
$bd->exec("DELETE FROM asistencia WHERE deportista_documento LIKE '98%'");
$bd->exec("DELETE FROM deportista WHERE documento LIKE '98%'");

echo "\n[crear]\n";
$fecha1 = $d('-11 days'); $grado1 = $d('-3 days');
[$c, $j] = peticion('POST', e('crear'), ['fecha' => $fecha1, 'fecha_grado' => $grado1, 'lugar' => 'Sede Ficticia', 'participantes' => [
    ['deportista_documento' => '9700000001'], ['deportista_documento' => '9700000003', 'grado_evaluado' => 'rojo'],
    ['deportista_documento' => '1000000101'], ['deportista_documento' => '9700000007'], ['deportista_documento' => '9700000005'],
]], $admin);
$s1 = $j['datos'] ?? [];
$id1 = $s1['id'] ?? 0;
comprobar('crear con 5 participantes -> 201', $c === 201 && count($s1['participantes']) === 5, $c . json_encode($j));
$ev = $bd->query("SELECT e.* FROM evento_calendario e JOIN sesion_examen s ON e.id IN (s.evento_examen_id, s.evento_grado_id) WHERE s.id = {$id1} ORDER BY e.fecha")->fetchAll();
comprobar('dos eventos creados y vinculados: evaluacion en la fecha de inicio y grado en la fecha de grado, públicos, mismo lugar',
    count($ev) === 2 && $ev[0]['tipo'] === 'evaluacion' && $ev[0]['fecha'] === $fecha1 && $ev[0]['nombre'] === "Exámenes de ascenso {$fecha1}"
    && $ev[1]['tipo'] === 'grado' && $ev[1]['fecha'] === $grado1 && array_unique(array_column($ev, 'visibilidad')) === ['publica'] && array_unique(array_column($ev, 'lugar')) === ['Sede Ficticia'], json_encode($ev));
comprobar('seis filas de detalle pendientes por participante (30) y una fila de entrega sin entregar por participante (5)',
    cuenta("SELECT COUNT(*) FROM detalle_evaluacion WHERE sesion_examen_id = {$id1} AND estado = 'pendiente' AND nota IS NULL") === 30
    && cuenta("SELECT COUNT(*) FROM entrega_cinturon WHERE sesion_examen_id = {$id1} AND cinturon_entregado = 0") === 5);
comprobar('grado sugerido (Verde -> Azul), explícito normalizado (rojo -> Rojo, salto permitido) y sin grado -> Blanco',
    participante($s1, '9700000001')['grado_evaluado'] === 'Azul' && participante($s1, '9700000003')['grado_evaluado'] === 'Rojo' && participante($s1, '1000000101')['grado_evaluado'] === 'Blanco');
comprobar('las seis modalidades en orden fijo, con todo su detalle', array_column(participante($s1, '9700000001')['modalidades'], 'modalidad') === ['teorico', 'fisico', 'combate', 'rompimiento', 'formas', 'llaves']
    && array_keys(participante($s1, '9700000001')['modalidades'][0]) === ['modalidad', 'estado', 'fecha_completado', 'nota', 'criterios_evaluados', 'puntajes', 'valor_medible', 'aspectos_fallidos', 'observaciones', 'recomendaciones', 'aprobado']);
$eventos = cuenta('SELECT COUNT(*) FROM evento_calendario'); $sesiones = cuenta('SELECT COUNT(*) FROM sesion_examen');
[$c] = peticion('POST', e('crear'), ['fecha' => $d('+30 days'), 'fecha_grado' => $d('+20 days'), 'lugar' => 'X'], $admin);
comprobar('fecha_grado anterior a la fecha de inicio -> 422', $c === 422, (string) $c);
[$c] = peticion('POST', e('crear'), ['fecha' => $d('+30 days'), 'fecha_grado' => $d('+37 days'), 'lugar' => 'X', 'participantes' => [['deportista_documento' => '9700000002'], ['deportista_documento' => '0000000000']]], $admin);
comprobar('deportista inexistente -> 404 sin crear nada', $c === 404 && cuenta('SELECT COUNT(*) FROM evento_calendario') === $eventos && cuenta('SELECT COUNT(*) FROM sesion_examen') === $sesiones, (string) $c);
[$c] = peticion('POST', e('crear'), ['fecha' => $d('+30 days'), 'fecha_grado' => $d('+37 days'), 'lugar' => 'X', 'participantes' => [['deportista_documento' => '9700000002'], ['deportista_documento' => '9700000002']]], $admin);
comprobar('duplicado dentro del lote -> 409', $c === 409, (string) $c);
[$c] = peticion('POST', e('crear'), ['fecha' => $d('+30 days'), 'fecha_grado' => $d('+37 days'), 'lugar' => 'X', 'participantes' => [['deportista_documento' => '9700000008']]], $admin);
comprobar('grado actual fuera de la lista y sin grado_evaluado -> 400 (obligatorio)', $c === 400, (string) $c);
[$c] = peticion('POST', e('crear'), ['fecha' => $d('+30 days'), 'fecha_grado' => $d('+37 days'), 'lugar' => 'X', 'participantes' => [['deportista_documento' => '9700000002', 'grado_evaluado' => 'Dorado']]], $admin);
comprobar('grado_evaluado fuera de la lista -> 400', $c === 400, (string) $c);
$bd->exec("CREATE TRIGGER prueba_falla_detalle BEFORE INSERT ON detalle_evaluacion FOR EACH ROW BEGIN IF NEW.deportista_documento = '9700000004' AND NEW.modalidad = 'formas' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla forzada'; END IF; END");
[$c] = peticion('POST', e('crear'), ['fecha' => $d('+30 days'), 'fecha_grado' => $d('+37 days'), 'lugar' => 'X', 'participantes' => [['deportista_documento' => '9700000002'], ['deportista_documento' => '9700000004']]], $admin);
comprobar('falla de la BD a mitad (5.a fila del 2.o participante) -> 500 y rollback total: ni eventos, ni sesión, ni filas',
    $c === 500 && cuenta('SELECT COUNT(*) FROM evento_calendario') === $eventos && cuenta('SELECT COUNT(*) FROM sesion_examen') === $sesiones
    && cuenta("SELECT COUNT(*) FROM detalle_evaluacion WHERE deportista_documento = '9700000002'") === 0, (string) $c);
$bd->exec('DROP TRIGGER prueba_falla_detalle');
[$c, $j] = peticion('POST', e('crear'), ['fecha' => $d('+53 days'), 'fecha_grado' => $d('+60 days'), 'lugar' => 'Sede Futura', 'participantes' => []], $admin);
$id2 = $j['datos']['id'] ?? 0;
comprobar('sesión futura con participantes vacío -> 201', $c === 201 && $j['datos']['participantes'] === [], (string) $c);
[$c] = peticion('POST', e('crear'), ['fecha' => $d('+53 days'), 'fecha_grado' => $d('+61 days'), 'lugar' => 'Otra'], $admin);
comprobar('mismo nombre + fecha de evento en Calendario -> 409', $c === 409, (string) $c);

echo "\n[inscribir]\n";
[$c, $j] = peticion('POST', e('inscribir', ['sesion_id' => $id2]), ['participantes' => [['deportista_documento' => '9700000002'], ['deportista_documento' => '9700000004', 'grado_evaluado' => 'Violeta']]], $admin);
comprobar('inscribir 2 -> 201 con seis filas cada uno', $c === 201 && count($j['datos']['participantes']) === 2 && cuenta("SELECT COUNT(*) FROM detalle_evaluacion WHERE sesion_examen_id = {$id2}") === 12, $c . json_encode($j));
[$c] = peticion('POST', e('inscribir', ['sesion_id' => $id2]), ['participantes' => [['deportista_documento' => '9700000002']]], $admin);
comprobar('ya inscrito -> 409', $c === 409, (string) $c);
[$c] = peticion('POST', e('inscribir', ['sesion_id' => $id2]), ['participantes' => [['deportista_documento' => '9700000006'], ['deportista_documento' => '0000000000']]], $admin);
comprobar('lote con inexistente -> 404 y nadie queda inscrito (todo o nada)', $c === 404 && cuenta("SELECT COUNT(*) FROM detalle_evaluacion WHERE deportista_documento = '9700000006'") === 0, (string) $c);
[$c] = peticion('POST', e('inscribir', ['sesion_id' => $id2]), ['participantes' => array_fill(0, 51, ['deportista_documento' => '9700000005'])], $admin);
comprobar('51 participantes -> 400', $c === 400, (string) $c);

echo "\n[listar y consultar]\n";
[$c, $j] = peticion('GET', e('listar'), null, $admin);
$porId = array_column($j['datos'], null, 'id');
comprobar('admin: listar con num_participantes, num_completados y total_modalidades, por fecha desc', $c === 200 && $porId[$id1]['num_participantes'] === 5 && $porId[$id1]['total_modalidades'] === 30 && $porId[$id1]['num_completados'] === 0
    && array_column($j['datos'], 'fecha') === (function ($f) { rsort($f); return $f; })(array_column($j['datos'], 'fecha')));
[$c, $j] = peticion('GET', e('listar', ['estado' => 'abierta', 'deportista' => '9700000002']), null, $admin);
comprobar('filtros estado + deportista', $c === 200 && array_column($j['datos'], 'id') === [$id2], json_encode(array_column($j['datos'], 'id')));
[$c, $j] = peticion('GET', e('listar', ['desde' => $d('-20 days'), 'hasta' => $d('+0 days')]), null, $admin);
comprobar('filtro desde + hasta (fecha de inicio)', $c === 200 && array_column($j['datos'], 'id') === [$id1], json_encode(array_column($j['datos'], 'id')));
[$c, $j] = peticion('GET', e('listar'), null, $publico101);
comprobar('público: solo sus sesiones (la de la semilla y la nueva) y sin conteos', $c === 200 && array_column($j['datos'], 'id') === [$id1, 1] && !array_key_exists('num_participantes', $j['datos'][0]), json_encode($j['datos']));
[$c, $j] = peticion('GET', e('consultar', ['id' => $id1]), null, $publico101);
comprobar('público: consultar su sesión -> solo su participante con todo su detalle', $c === 200 && count($j['datos']['participantes']) === 1 && $j['datos']['participantes'][0]['deportista_documento'] === '1000000101'
    && array_key_exists('resultado_general', $j['datos']['participantes'][0]) && array_key_exists('entrega', $j['datos']['participantes'][0]));
[$c] = peticion('GET', e('consultar', ['id' => $id2]), null, $publico101);
comprobar('público: sesión donde no participa -> 404', $c === 404, (string) $c);
[$c] = peticion('GET', e('listar', ['deportista' => '9700000002']), null, $publico101);
comprobar('público: listar por otro deportista -> 404', $c === 404, (string) $c);

echo "\n[actualizar y actualizarParticipante]\n";
[$c, $j] = peticion('PUT', e('actualizar', ['id' => $id2]), ['lugar' => 'Coliseo Nuevo', 'fecha_grado' => $d('+62 days')], $admin);
$evGrado = $bd->query("SELECT e.fecha, e.lugar FROM evento_calendario e JOIN sesion_examen s ON e.id = s.evento_grado_id WHERE s.id = {$id2}")->fetch();
$evExamen = $bd->query("SELECT e.lugar FROM evento_calendario e JOIN sesion_examen s ON e.id = s.evento_examen_id WHERE s.id = {$id2}")->fetch();
comprobar('actualizar lugar y fecha_grado -> 200 y los dos eventos sincronizados', $c === 200 && $j['datos']['lugar'] === 'Coliseo Nuevo' && $evGrado['fecha'] === $d('+62 days') && $evGrado['lugar'] === 'Coliseo Nuevo' && $evExamen['lugar'] === 'Coliseo Nuevo', $c . json_encode($evGrado));
[$c] = peticion('PUT', e('actualizar', ['id' => $id2]), ['fecha_grado' => $d('+50 days')], $admin);
comprobar('actualizar con fecha_grado anterior a la fecha -> 422', $c === 422, (string) $c);
[$c, $j] = peticion('PUT', e('actualizarParticipante', ['sesion_id' => $id2, 'deportista_documento' => '9700000004']), ['grado_evaluado' => 'marron'], $admin);
comprobar('actualizarParticipante -> grado normalizado en las seis filas', $c === 200 && $j['datos']['grado_evaluado'] === 'Marrón'
    && cuenta("SELECT COUNT(*) FROM detalle_evaluacion WHERE sesion_examen_id = {$id2} AND deportista_documento = '9700000004' AND grado_evaluado = 'Marrón'") === 6, $c . json_encode($j));
[$c] = peticion('PUT', e('actualizarParticipante', ['sesion_id' => $id2, 'deportista_documento' => '9700000004']), ['grado_evaluado' => 'Dorado'], $admin);
comprobar('actualizarParticipante con grado fuera de la lista -> 400', $c === 400, (string) $c);

echo "\n[calificar]\n";
$cal = fn (string $doc, string $mod, array $cuerpo, ?int $sesion = null) => peticion('PUT', e('calificar', ['sesion_id' => $sesion ?? $id1, 'deportista_documento' => $doc, 'modalidad' => $mod]), $cuerpo, $admin);
[$c, $j] = $cal('9700000001', 'teorico', ['nota' => 1]);
$m = $j['datos']['modalidades'][0] ?? [];
comprobar('nota 1 (borde) -> completado, aprobado false, fecha_completado = hoy', $c === 200 && $m['estado'] === 'completado' && $m['nota'] == 1 && $m['aprobado'] === false && $m['fecha_completado'] === $d('+0 days'), $c . json_encode($m));
[$c, $j] = $cal('9700000001', 'teorico', ['nota' => 10]);
comprobar('nota 10 (borde) -> aprobado true (editable mientras está abierta)', $c === 200 && $j['datos']['modalidades'][0]['nota'] == 10 && $j['datos']['modalidades'][0]['aprobado'] === true);
foreach (['0.99' => 0.99, '10.01' => 10.01, '7.555 (3 decimales)' => 7.555, 'texto' => 'diez'] as $caso => $nota) {
    [$c] = $cal('9700000001', 'teorico', ['nota' => $nota]);
    comprobar("nota {$caso} -> 400", $c === 400, (string) $c);
}
[$c, $j] = $cal('9700000001', 'fisico', ['nota' => 5]);
[$c2, $j2] = $cal('9700000001', 'combate', ['nota' => 4.99]);
comprobar('aprobado derivado: 5 -> true, 4.99 -> false (umbral 5)', $j['datos']['modalidades'][1]['aprobado'] === true && $j2['datos']['modalidades'][2]['aprobado'] === false);
[$c, $j] = $cal('9700000001', 'combate', ['nota' => null]);
$m = $j['datos']['modalidades'][2];
comprobar('nota null limpia: pendiente, aprobado null y fecha_completado null', $c === 200 && $m['estado'] === 'pendiente' && $m['nota'] === null && $m['aprobado'] === null && $m['fecha_completado'] === null, json_encode($m));
[$c, $j] = $cal('9700000001', 'fisico', ['observaciones' => 'Buena flexibilidad', 'valor_medible' => 9999.99, 'puntajes' => 'Flexiones 30']);
$m = $j['datos']['modalidades'][1];
comprobar('actualización parcial: textos y valor_medible sin tocar la nota', $c === 200 && $m['nota'] == 5 && $m['observaciones'] === 'Buena flexibilidad' && $m['valor_medible'] == 9999.99, json_encode($m));
[$c] = $cal('9700000001', 'fisico', ['valor_medible' => 10000]);
comprobar('valor_medible 10000 -> 400', $c === 400, (string) $c);
[$c, $j] = $cal('9700000001', 'rompimiento', ['nota' => 6, 'fecha_completado' => $d('-9 days')]);
comprobar('fecha_completado explícita dentro del rango -> se respeta', $c === 200 && $j['datos']['modalidades'][3]['fecha_completado'] === $d('-9 days'));
[$c] = $cal('9700000001', 'rompimiento', ['fecha_completado' => $d('+1 day')]);
comprobar('fecha_completado futura -> 400', $c === 400, (string) $c);
[$c] = $cal('9700000001', 'rompimiento', ['fecha_completado' => $d('-12 days')]);
comprobar('fecha_completado anterior al inicio de la sesión -> 400', $c === 400, (string) $c);
[$c, $j] = $cal('9700000002', 'teorico', ['nota' => 8], $id2);
comprobar('calificar en sesión con fecha de inicio futura -> 422', $c === 422, $c . json_encode($j));
[$c] = $cal('9700000001', 'kata', ['nota' => 8]);
comprobar('modalidad inválida -> 400', $c === 400, (string) $c);
[$c] = $cal('9700000006', 'teorico', ['nota' => 8]);
comprobar('deportista que no participa -> 404', $c === 404, (string) $c);

echo "\n[cerrar y resultado general]\n";
[$c, $j] = peticion('PUT', e('cerrar', ['id' => $id1]), null, $admin);
comprobar('cerrar con modalidades pendientes -> 409 indicando quiénes faltan', $c === 409 && str_contains($j['mensaje'], '9700000001') && str_contains($j['mensaje'], '1000000101'), $c . json_encode($j));
[$c, $j] = peticion('POST', e('crear'), ['fecha' => $d('+80 days'), 'fecha_grado' => $d('+81 days'), 'lugar' => 'Vacía'], $admin);
$idVacia = $j['datos']['id'];
[$c] = peticion('PUT', e('cerrar', ['id' => $idVacia]), null, $admin);
comprobar('cerrar sin participantes -> 409', $c === 409, (string) $c);
$notas = ['9700000001' => [5, 5, 5, 5, 5, 5], '9700000003' => [5, 5, 5, 5, 5, 4.94], '1000000101' => [10, 10, 10, 1, 1, 1], '9700000007' => [8, 8, 8, 8, 8, 8], '9700000005' => [8, 8, 8, 8, 8, 8]];
foreach ($notas as $doc => $lista) {
    foreach (['teorico', 'fisico', 'combate', 'rompimiento', 'formas', 'llaves'] as $i => $mod) { $cal((string) $doc, $mod, ['nota' => $lista[$i]]); }
}
[$c, $j] = peticion('GET', e('consultar', ['id' => $id1]), null, $admin);
comprobar('resultado general: seis notas de 5 -> 5.00 aprobado (borde)', participante($j['datos'], '9700000001')['resultado_general'] == ['completo' => true, 'promedio' => 5.0, 'aprobado' => true], json_encode(participante($j['datos'], '9700000001')['resultado_general']));
comprobar('resultado general: promedio 4.99 -> no aprobado (borde)', participante($j['datos'], '9700000003')['resultado_general'] === ['completo' => true, 'promedio' => 4.99, 'aprobado' => false]);
comprobar('resultado general: falla tres modalidades pero promedio 5.5 -> aprueba', participante($j['datos'], '1000000101')['resultado_general']['aprobado'] === true);
[$c, $j] = peticion('GET', e('consultar', ['id' => $id2]), null, $admin);
comprobar('resultado general incompleto: promedio y aprobado null', participante($j['datos'], '9700000002')['resultado_general'] === ['completo' => false, 'promedio' => null, 'aprobado' => null]);
[$c, $j] = peticion('PUT', e('confirmarEntregaCinturon', ['sesion_id' => $id1, 'deportista_documento' => '9700000001']), null, $admin);
comprobar('confirmar entrega con la sesión abierta -> 409', $c === 409 && str_contains($j['mensaje'], 'cerrada'), $c . json_encode($j));
[$c, $j] = peticion('PUT', e('cerrar', ['id' => $id1]), null, $admin);
comprobar('cerrar con todo completo -> 200 cerrada', $c === 200 && $j['datos']['estado'] === 'cerrada', $c . json_encode($j));

echo "\n[sesión cerrada protegida]\n";
foreach ([
    'calificar' => ['PUT', e('calificar', ['sesion_id' => $id1, 'deportista_documento' => '9700000001', 'modalidad' => 'teorico']), ['nota' => 9]],
    'actualizar' => ['PUT', e('actualizar', ['id' => $id1]), ['lugar' => 'X']],
    'inscribir' => ['POST', e('inscribir', ['sesion_id' => $id1]), ['participantes' => [['deportista_documento' => '9700000005']]]],
    'actualizarParticipante' => ['PUT', e('actualizarParticipante', ['sesion_id' => $id1, 'deportista_documento' => '9700000001']), ['grado_evaluado' => 'Rojo']],
    'quitarParticipante' => ['DELETE', e('quitarParticipante', ['sesion_id' => $id1, 'deportista_documento' => '9700000001']), null],
    'eliminar' => ['DELETE', e('eliminar', ['id' => $id1]), null],
    'cerrar otra vez' => ['PUT', e('cerrar', ['id' => $id1]), null],
] as $accion => [$m, $u, $cu]) {
    [$c] = peticion($m, $u, $cu, $admin);
    comprobar("cerrada: {$accion} -> 409", $c === 409, (string) $c);
}
comprobar('la nota no cambió tras el intento sobre la sesión cerrada', cuenta("SELECT COUNT(*) FROM detalle_evaluacion WHERE sesion_examen_id = {$id1} AND deportista_documento = '9700000001' AND modalidad = 'teorico' AND nota = 5") === 1);

echo "\n[confirmar entrega de cinturón]\n";
[$c, $j] = peticion('PUT', e('confirmarEntregaCinturon', ['sesion_id' => $id1, 'deportista_documento' => '9700000003']), null, $admin);
comprobar('no aprobado -> 409', $c === 409 && str_contains($j['mensaje'], 'no aprobó'), $c . json_encode($j));
[$c] = peticion('PUT', e('confirmarEntregaCinturon', ['sesion_id' => $id1, 'deportista_documento' => '1000000101']), null, $publicoC1);
comprobar('público sobre otro deportista -> 403', $c === 403, (string) $c);
[$c, $j] = peticion('PUT', e('confirmarEntregaCinturon', ['sesion_id' => $id1, 'deportista_documento' => '9700000001']), ['fecha_entrega' => $d('-1 day')], $publicoC1);
comprobar('público confirma la suya -> 200, confirmado_por deportista, fecha = fecha de grado (ignora la enviada), grado_actual actualizado',
    $c === 200 && $j['datos']['cinturon_entregado'] === true && $j['datos']['confirmado_por'] === 'deportista' && $j['datos']['fecha_entrega'] === $grado1 && $j['datos']['grado_actual'] === 'Azul', $c . json_encode($j));
comprobar('BD: grado_actual = grado evaluado y entrega marcada (misma operación)', $bd->query("SELECT grado_actual FROM deportista WHERE documento = '9700000001'")->fetchColumn() === 'Azul'
    && cuenta("SELECT COUNT(*) FROM entrega_cinturon WHERE sesion_examen_id = {$id1} AND deportista_documento = '9700000001' AND cinturon_entregado = 1 AND confirmado_por = 'deportista'") === 1);
[$c] = peticion('PUT', e('confirmarEntregaCinturon', ['sesion_id' => $id1, 'deportista_documento' => '9700000001']), null, $admin);
comprobar('ya entregado -> 409', $c === 409, (string) $c);
[$c] = peticion('PUT', e('confirmarEntregaCinturon', ['sesion_id' => $id1, 'deportista_documento' => '1000000101']), ['fecha_entrega' => $d('+2 days')], $admin);
comprobar('fecha de entrega futura -> 422', $c === 422, (string) $c);
[$c, $j] = peticion('PUT', e('confirmarEntregaCinturon', ['sesion_id' => $id1, 'deportista_documento' => '1000000101']), ['fecha_entrega' => $d('-1 day')], $admin);
comprobar('administrativo con fecha_entrega propia -> se respeta, confirmado_por administrativo, grado Blanco', $c === 200 && $j['datos']['fecha_entrega'] === $d('-1 day') && $j['datos']['confirmado_por'] === 'administrativo' && $j['datos']['grado_actual'] === 'Blanco', $c . json_encode($j));
// Concurrencia: dos confirmaciones a la vez para el mismo deportista.
$url = e('confirmarEntregaCinturon', ['sesion_id' => $id1, 'deportista_documento' => '9700000007']);
$cmd = sprintf('curl -s -o NUL -w "%%{http_code}\n" -X PUT -H "Cookie: %s" "%s"', $admin, $url);
$procesos = [];
foreach ([0, 1] as $i) { $procesos[$i] = proc_open($cmd, [1 => ['pipe', 'w']], $tub); $salidas[$i] = $tub[1]; }
$codigos = [];
foreach ([0, 1] as $i) { $codigos[] = trim(stream_get_contents($salidas[$i])); proc_close($procesos[$i]); }
sort($codigos);
comprobar('concurrencia: dos confirmaciones simultáneas -> una 200 y una 409, una sola entrega', $codigos === ['200', '409']
    && cuenta("SELECT COUNT(*) FROM entrega_cinturon WHERE sesion_examen_id = {$id1} AND deportista_documento = '9700000007' AND cinturon_entregado = 1") === 1, json_encode($codigos));
// FOR UPDATE de verdad: otra conexión bloquea la fila; la petición espera y, al liberarse, ve la entrega hecha.
$bd->exec("UPDATE detalle_evaluacion SET nota = 9, aprobado = 1 WHERE sesion_examen_id = {$id1} AND deportista_documento = '9700000003'");
$otra = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$otra->beginTransaction();
$otra->query("SELECT * FROM entrega_cinturon WHERE sesion_examen_id = {$id1} AND deportista_documento = '9700000003' FOR UPDATE")->fetchAll();
$urlBloqueo = e('confirmarEntregaCinturon', ['sesion_id' => $id1, 'deportista_documento' => '9700000003']);
$procBloqueo = proc_open(sprintf('curl -s -o NUL -w "%%{http_code}" -X PUT -H "Cookie: %s" "%s"', $admin, $urlBloqueo), [1 => ['pipe', 'w']], $tubBloqueo);
usleep(1500000);
$esperando = proc_get_status($procBloqueo)['running'];
$otra->exec("UPDATE entrega_cinturon SET cinturon_entregado = 1, fecha_entrega = '{$grado1}', confirmado_por = 'administrativo' WHERE sesion_examen_id = {$id1} AND deportista_documento = '9700000003'");
$otra->commit();
$salidaBloqueo = stream_get_contents($tubBloqueo[1]); proc_close($procBloqueo);
comprobar('FOR UPDATE: con la fila bloqueada por otra transacción la petición espera; al liberarse ve la entrega y responde 409 sin tocar el grado',
    $esperando === true && str_ends_with(trim($salidaBloqueo), '409') && $bd->query("SELECT grado_actual FROM deportista WHERE documento = '9700000003'")->fetchColumn() === 'Azul', json_encode([$esperando, $salidaBloqueo]));
// Fila de entrega faltante (como la sesión de la semilla): se crea al confirmar.
$bd->exec("DELETE FROM entrega_cinturon WHERE sesion_examen_id = {$id1} AND deportista_documento = '9700000005'");
[$c, $j] = peticion('PUT', e('confirmarEntregaCinturon', ['sesion_id' => $id1, 'deportista_documento' => '9700000005']), null, $admin);
comprobar('sin fila de entrega previa (datos viejos): se crea y se confirma', $c === 200 && $j['datos']['grado_actual'] === 'Amarillo', $c . json_encode($j));

echo "\n[reabrir, quitar y eliminar]\n";
[$c, $j] = peticion('PUT', e('reabrir', ['id' => $id1]), null, $admin);
$adv = implode(' ', $j['datos']['advertencias'] ?? []);
comprobar('reabrir -> 200 abierta, con advertencias de quienes ya tienen la entrega confirmada', $c === 200 && $j['datos']['estado'] === 'abierta' && count($j['datos']['advertencias']) === 5 && str_contains($adv, '9700000001') && str_contains($adv, '9700000007'), $c . json_encode($j['datos']['advertencias'] ?? null));
[$c] = peticion('PUT', e('reabrir', ['id' => $id1]), null, $admin);
comprobar('reabrir una sesión abierta -> 409', $c === 409, (string) $c);
[$c] = peticion('DELETE', e('quitarParticipante', ['sesion_id' => $id1, 'deportista_documento' => '9700000001']), null, $admin);
comprobar('quitar participante con modalidades completadas -> 409', $c === 409, (string) $c);
[$c] = peticion('DELETE', e('quitarParticipante', ['sesion_id' => $id2, 'deportista_documento' => '9700000004']), null, $admin);
comprobar('quitar participante sin completadas -> 200 (detalle y entrega borrados)', $c === 200 && cuenta("SELECT COUNT(*) FROM detalle_evaluacion WHERE sesion_examen_id = {$id2} AND deportista_documento = '9700000004'") === 0
    && cuenta("SELECT COUNT(*) FROM entrega_cinturon WHERE sesion_examen_id = {$id2} AND deportista_documento = '9700000004'") === 0, (string) $c);
[$c] = peticion('DELETE', e('eliminar', ['id' => $id1]), null, $admin);
comprobar('eliminar sesión con modalidades completadas -> 409', $c === 409, (string) $c);
$eventos2 = $bd->query("SELECT evento_examen_id, evento_grado_id FROM sesion_examen WHERE id = {$id2}")->fetch();
[$c] = peticion('DELETE', e('eliminar', ['id' => $id2]), null, $admin);
comprobar('eliminar sesión abierta sin completadas -> 200: sesión, filas y sus dos eventos borrados', $c === 200 && cuenta("SELECT COUNT(*) FROM sesion_examen WHERE id = {$id2}") === 0
    && cuenta("SELECT COUNT(*) FROM detalle_evaluacion WHERE sesion_examen_id = {$id2}") === 0
    && cuenta("SELECT COUNT(*) FROM evento_calendario WHERE id IN ({$eventos2['evento_examen_id']}, {$eventos2['evento_grado_id']})") === 0, (string) $c);

echo "\n[permisos y fugas]\n";
foreach (['candidatos' => ['GET', e('candidatos', ['fecha' => $F]), null], 'crear' => ['POST', e('crear'), ['fecha' => $F, 'fecha_grado' => $F, 'lugar' => 'X']],
    'calificar' => ['PUT', e('calificar', ['sesion_id' => $id1, 'deportista_documento' => '1000000101', 'modalidad' => 'teorico']), ['nota' => 10]],
    'cerrar' => ['PUT', e('cerrar', ['id' => $id1]), null], 'eliminar' => ['DELETE', e('eliminar', ['id' => $idVacia]), null]] as $accion => [$m, $u, $cu]) {
    [$c] = peticion($m, $u, $cu, $publico101);
    comprobar("público: {$accion} -> 403", $c === 403, (string) $c);
}
[$c] = peticion('GET', e('listar'));
comprobar('anónimo -> 401', $c === 401, (string) $c);
$fugas = array_filter($crudos, fn ($r) => in_array($r[0], [$publico101, $publicoC1], true) && preg_match('/97000000(0[2-8])|"Candidato (Dos|Tres|Cuatro)/', $r[1]) === 1);
comprobar('ninguna respuesta pública trae datos de otros participantes', $fugas === [], json_encode(array_values($fugas)));

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
