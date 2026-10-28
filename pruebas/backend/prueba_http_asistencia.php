<?php

declare(strict_types=1);

// Bateria HTTP del paso 5 (Asistencia), fuera del repositorio.
// Uso: php prueba_http_asistencia.php <url base>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recien cargados.

$base = rtrim($argv[1], '/');
date_default_timezone_set('America/Bogota');
$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$bd->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");

$ok = 0; $fallos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}
/** @return array{0: int, 1: ?array} */
function peticion(string $metodo, string $url, ?array $cuerpo = null, ?string $cookie = null): array
{
    $cabeceras = "Content-Type: application/json\r\n" . ($cookie ? "Cookie: {$cookie}\r\n" : '');
    $contexto = stream_context_create(['http' => ['method' => $metodo, 'header' => $cabeceras, 'ignore_errors' => true,
        'content' => $cuerpo === null ? '' : json_encode($cuerpo)]]);
    $texto = (string) file_get_contents($url, false, $contexto);
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
function a(string $accion, array $query = []): string
{
    global $base;
    return "{$base}/api.php?recurso=asistencia&accion={$accion}" . ($query ? '&' . http_build_query($query) : '');
}
function estado(string $documento): string
{
    global $bd;
    return (string) $bd->query("SELECT estado FROM deportista WHERE documento = '{$documento}'")->fetchColumn();
}
function marcas(string $fecha): array
{
    global $bd;
    $s = $bd->prepare('SELECT deportista_documento, asistio FROM asistencia WHERE fecha = ? ORDER BY deportista_documento');
    $s->execute([$fecha]);
    $r = [];
    foreach ($s->fetchAll() as $f) { $r[$f['deportista_documento']] = (int) $f['asistio']; }
    return $r;
}

// Fechas relativas al "hoy" de Bogotá.
$hoy = new DateTimeImmutable('today');
$sabado = fn (int $k): string => ($hoy->format('N') == 6 ? $hoy : $hoy->modify('last saturday'))->modify("-{$k} weeks")->format('Y-m-d');
$S1 = $sabado(1); $D1 = (new DateTimeImmutable($S1))->modify('+1 day')->format('Y-m-d');
$S2 = $sabado(2); $S3 = $sabado(3);
$proximoSabado = $hoy->modify('next saturday')->format('Y-m-d');
$diasSemana = [];
$lunes = (new DateTimeImmutable($S1))->modify('-5 days');
for ($i = 0; $i < 5; $i++) { $diasSemana[$lunes->modify("+{$i} days")->format('l')] = $lunes->modify("+{$i} days")->format('Y-m-d'); }
$restarMeses = function (DateTimeImmutable $f, int $m): DateTimeImmutable {
    $p = $f->modify('first day of this month')->modify("-{$m} months");
    return $p->setDate((int) $p->format('Y'), (int) $p->format('n'), min((int) $f->format('j'), (int) $p->format('t')));
};
$limite = $restarMeses($hoy, 3);
$limiteTxt = $limite->format('Y-m-d');
$limiteMas1 = $limite->modify('+1 day')->format('Y-m-d');

// Deportistas de prueba (ficticios).
$ins = $bd->prepare("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, discapacidad, autorizacion_datos, fecha_ingreso, estado, estado_registro) VALUES (?, ?, '2000-01-01', 'masculino', 1.70, 70, ?, 1, ?, ?, ?)");
$reciente = $hoy->modify('-20 days')->format('Y-m-d');
foreach ([
    ['9200000001', 'Ana Asistencia', 'Asma leve', $reciente, 'activo', 'validado'],
    ['9200000002', 'Bruno Asistencia', null, $reciente, 'activo', 'validado'],
    ['9200000003', 'Carla Asistencia', null, $reciente, 'inactivo', 'validado'],
    ['9200000004', 'Pendiente Asistencia', null, '2020-01-01', 'activo', 'pendiente_validacion'],
] as $d) { $ins->execute([$d[0], $d[1], $d[2], $d[3], $d[4], $d[5]]); }

$admin = sesion('iniciarSesionAdministrativa', ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']);
$publico = sesion('iniciarSesionPublica', ['documento' => '1000000101', 'fecha_nacimiento' => '2014-03-12']);
comprobar('sesiones administrativa y pública creadas', $admin !== '' && $publico !== '');

echo "\n[Fechas]\n";
[$c] = peticion('GET', a('listarPantalla', ['fecha' => $S1]), null, $admin);
comprobar("sábado pasado ({$S1}) válido", $c === 200, (string) $c);
[$c] = peticion('GET', a('listarPantalla', ['fecha' => $D1]), null, $admin);
comprobar("domingo pasado ({$D1}) válido", $c === 200, (string) $c);
foreach ($diasSemana as $dia => $fecha) {
    [$c, $j] = peticion('GET', a('listarPantalla', ['fecha' => $fecha]), null, $admin);
    comprobar("{$dia} {$fecha} -> 400 «solo sábado o domingo»", $c === 400 && $j['mensaje'] === 'La asistencia solo se registra en sábado o domingo.', $c . json_encode($j));
}
[$c, $j] = peticion('POST', a('tomar'), ['fecha' => $proximoSabado, 'presentes' => []], $admin);
comprobar("sábado futuro ({$proximoSabado}) -> 400 «no puede ser futura»", $c === 400 && $j['mensaje'] === 'La fecha no puede ser futura.', $c . json_encode($j));
foreach (['17/10/2026', '2026-02-30', 'hoy', ''] as $mala) {
    [$c] = peticion('POST', a('tomar'), ['fecha' => $mala, 'presentes' => []], $admin);
    comprobar("formato inválido «{$mala}» -> 400", $c === 400, (string) $c);
}

echo "\n[tomar]\n";
[$c, $j] = peticion('POST', a('tomar'), ['fecha' => $S1, 'presentes' => ['9200000001', '9200000003']], $admin);
$m = marcas($S1);
comprobar('tomar -> 201, una fila por activo (incluido el inactivo marcado, reactivado)', $c === 201 && $j['datos']['registrados'] === 4
    && $j['datos']['reactivados'] === ['9200000003'], $c . json_encode($j));
comprobar('filas: presentes en 1 (Ana, Carla), ausentes en 0 (Bruno, 1000000101); sin inactivos no marcados ni pendientes',
    $m === ['1000000101' => 0, '9200000001' => 1, '9200000002' => 0, '9200000003' => 1], json_encode($m));
comprobar('Carla (inactiva marcada presente) quedó activa (RN-1.11)', estado('9200000003') === 'activo');
comprobar('1000000103 (inactivo no marcado) sigue inactivo y sin fila', estado('1000000103') === 'inactivo' && !isset($m['1000000103']));
[$c] = peticion('POST', a('tomar'), ['fecha' => $S1, 'presentes' => []], $admin);
comprobar('la misma fecha otra vez -> 409', $c === 409, (string) $c);
[$c, $j] = peticion('POST', a('tomar'), ['fecha' => $D1, 'presentes' => ['1000000103', '0000000000']], $admin);
comprobar('documento inexistente -> 400 sin guardar nada ni reactivar', $c === 400 && marcas($D1) === [] && estado('1000000103') === 'inactivo', $c . json_encode($j));
[$c] = peticion('POST', a('tomar'), ['fecha' => $D1, 'presentes' => ['9200000004']], $admin);
comprobar('pendiente de validación en presentes -> 400', $c === 400 && marcas($D1) === [], (string) $c);
[$c] = peticion('POST', a('tomar'), ['fecha' => $D1, 'presentes' => '9200000001'], $admin);
comprobar('presentes que no es lista -> 400', $c === 400, (string) $c);
// Rollback real: falla forzada en la 2.a inserción, después de reactivar a 1000000103.
$bd->exec("CREATE TRIGGER prueba_falla_asistencia BEFORE INSERT ON asistencia FOR EACH ROW BEGIN IF NEW.deportista_documento = '9200000002' AND NEW.fecha = '{$S2}' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla forzada'; END IF; END");
[$c] = peticion('POST', a('tomar'), ['fecha' => $S2, 'presentes' => ['1000000103']], $admin);
comprobar('falla de la BD a mitad de tomar -> 500 y rollback total (sin filas, sin reactivar)', $c === 500 && marcas($S2) === [] && estado('1000000103') === 'inactivo', (string) $c);
$bd->exec('DROP TRIGGER prueba_falla_asistencia');
[$c] = peticion('POST', a('tomar'), ['fecha' => $D1, 'presentes' => []], $publico);
comprobar('público no puede tomar -> 403', $c === 403, (string) $c);
[$c] = peticion('POST', a('tomar'), ['fecha' => $D1, 'presentes' => []]);
comprobar('anónimo -> 401', $c === 401, (string) $c);

echo "\n[listarPantalla]\n";
[$c, $j] = peticion('GET', a('listarPantalla', ['fecha' => $S1]), null, $admin);
$activos = $j['datos']['activos'] ?? [];
comprobar('fecha tomada: dos listas, activos ordenados por nombre', $c === 200 && $j['datos']['tomada'] === true
    && array_column($activos, 'nombre') === ['Ana Asistencia', 'Bruno Asistencia', 'Carla Asistencia', 'Samuel Reyes Ortiz'], json_encode(array_column($activos, 'nombre')));
comprobar('cada elemento trae exactamente documento, nombre, discapacidad y asistio', array_filter(array_merge($activos, $j['datos']['inactivos']), fn ($e) => array_keys($e) !== ['documento', 'nombre', 'discapacidad', 'asistio']) === []);
comprobar('asistio refleja la marca (Ana true, Bruno false) y la discapacidad sale (RN-7.1)', $activos[0]['asistio'] === true && $activos[0]['discapacidad'] === 'Asma leve' && $activos[1]['asistio'] === false);
comprobar('inactivos aparte (1000000103) con asistio null; pendientes fuera', array_column($j['datos']['inactivos'], 'documento') === ['1000000103'] && $j['datos']['inactivos'][0]['asistio'] === null
    && !in_array('9200000004', array_column($activos, 'documento'), true) && !in_array('1000000102', array_column($activos, 'documento'), true));
[$c, $j] = peticion('GET', a('listarPantalla', ['fecha' => $D1]), null, $admin);
comprobar('fecha sin tomar: tomada false y asistio null en todos', $c === 200 && $j['datos']['tomada'] === false && array_unique(array_column($j['datos']['activos'], 'asistio')) === [null]);
[$c] = peticion('GET', a('listarPantalla', ['fecha' => $S1]), null, $publico);
comprobar('público -> 403', $c === 403, (string) $c);

echo "\n[listar y consultar]\n";
peticion('POST', a('tomar'), ['fecha' => $S3, 'presentes' => ['1000000101']], $admin);
[$c, $j] = peticion('GET', a('listar', ['fecha' => $S1]), null, $admin);
comprobar('por fecha -> 4 filas con deportista_documento, fecha y asistio (bool)', $c === 200 && count($j['datos']) === 4 && array_keys($j['datos'][0]) === ['deportista_documento', 'fecha', 'asistio'] && is_bool($j['datos'][0]['asistio']));
[$c, $j] = peticion('GET', a('listar', ['desde' => $S3, 'hasta' => $D1]), null, $admin);
comprobar('por rango -> filas de S3 y S1, en orden de fecha', $c === 200 && array_values(array_unique(array_column($j['datos'], 'fecha'))) === [$S3, $S1], json_encode(array_unique(array_column($j['datos'], 'fecha'))));
[$c, $j] = peticion('GET', a('listar', ['documento' => '1000000101']), null, $admin);
comprobar('por deportista -> sus filas (2 de la semilla + S3 + S1)', $c === 200 && count($j['datos']) === 4 && array_unique(array_column($j['datos'], 'deportista_documento')) === ['1000000101'], (string) count($j['datos'] ?? []));
[$c, $j] = peticion('GET', a('listar', ['documento' => '1000000101', 'desde' => $S3, 'hasta' => $S1]), null, $admin);
comprobar('deportista + rango combinados', $c === 200 && count($j['datos']) === 2);
[$c] = peticion('GET', a('listar'), null, $admin);
comprobar('administrativo sin filtros -> 400', $c === 400, (string) $c);
[$c] = peticion('GET', a('listar', ['desde' => $S3]), null, $admin);
comprobar('desde sin hasta -> 400', $c === 400, (string) $c);
[$c] = peticion('GET', a('listar', ['desde' => $S1, 'hasta' => $S3]), null, $admin);
comprobar('desde posterior a hasta -> 400', $c === 400, (string) $c);
[$c, $j] = peticion('GET', a('listar'), null, $publico);
comprobar('público sin documento -> solo lo suyo', $c === 200 && count($j['datos']) === 4 && array_unique(array_column($j['datos'], 'deportista_documento')) === ['1000000101']);
[$c] = peticion('GET', a('listar', ['documento' => '9200000001']), null, $publico);
comprobar('público con documento ajeno -> 404', $c === 404, (string) $c);
[$c, $j] = peticion('GET', a('consultar', ['documento' => '1000000101', 'fecha' => $S1]), null, $publico);
comprobar('público consulta su marca -> 200 (ausente)', $c === 200 && $j['datos']['asistio'] === false, $c . json_encode($j));
[$c] = peticion('GET', a('consultar', ['documento' => '9200000001', 'fecha' => $S1]), null, $publico);
comprobar('público consulta marca ajena -> 404', $c === 404, (string) $c);
[$c, $j] = peticion('GET', a('consultar', ['documento' => '9200000001', 'fecha' => $S1]), null, $admin);
comprobar('administrativo consulta cualquier marca -> 200', $c === 200 && $j['datos']['asistio'] === true);
[$c] = peticion('GET', a('consultar', ['documento' => '9200000001', 'fecha' => $D1]), null, $admin);
comprobar('marca inexistente -> 404', $c === 404, (string) $c);

echo "\n[resumenDia]\n";
[$c, $j] = peticion('GET', a('resumenDia'), null, $admin);
$ultima = $bd->query('SELECT MAX(fecha) FROM asistencia')->fetchColumn();
$esHoyReal = $ultima === $hoy->format('Y-m-d');
comprobar('sin sesión hoy -> última sesión tomada, es_hoy false, presentes y total', $c === 200 && $j['datos'] === ['fecha' => $S1, 'presentes' => 2, 'total' => 4, 'es_hoy' => false], json_encode($j['datos']));
$hoyTxt = $hoy->format('Y-m-d');
$bd->exec("INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES ('9200000001', '{$hoyTxt}', 1), ('9200000002', '{$hoyTxt}', 0), ('1000000101', '{$hoyTxt}', 1)");
[$c, $j] = peticion('GET', a('resumenDia'), null, $admin);
comprobar('con sesión hoy (filas insertadas en la BD) -> fecha de hoy y es_hoy true', $c === 200 && $j['datos'] === ['fecha' => $hoyTxt, 'presentes' => 2, 'total' => 3, 'es_hoy' => true], json_encode($j['datos']));
$bd->exec("DELETE FROM asistencia WHERE fecha = '{$hoyTxt}'");
[$c] = peticion('GET', a('resumenDia'), null, $publico);
comprobar('público -> 403', $c === 403, (string) $c);

echo "\n[corregir, eliminarMarca, eliminarFecha]\n";
[$c, $j] = peticion('PUT', a('corregir', ['documento' => '9200000002', 'fecha' => $S1]), ['asistio' => true], $admin);
comprobar('corregir ausente -> presente', $c === 200 && $j['datos']['asistio'] === true && $j['datos']['reactivado'] === false && marcas($S1)['9200000002'] === 1, $c . json_encode($j));
peticion('PUT', a('corregir', ['documento' => '9200000002', 'fecha' => $S1]), ['asistio' => false], $admin);
$bd->exec("UPDATE deportista SET estado = 'inactivo' WHERE documento = '9200000002'");
[$c, $j] = peticion('PUT', a('corregir', ['documento' => '9200000002', 'fecha' => $S1]), ['asistio' => true], $admin);
comprobar('corregir a presente a un inactivo -> lo reactiva', $c === 200 && $j['datos']['reactivado'] === true && estado('9200000002') === 'activo', $c . json_encode($j));
[$c] = peticion('PUT', a('corregir', ['documento' => '9200000002', 'fecha' => $D1]), ['asistio' => true], $admin);
comprobar('corregir marca inexistente -> 404', $c === 404, (string) $c);
[$c] = peticion('PUT', a('corregir', ['documento' => '9200000002', 'fecha' => $S1]), ['asistio' => 'si'], $admin);
comprobar('asistio que no es booleano -> 400', $c === 400, (string) $c);
[$c] = peticion('PUT', a('corregir', ['documento' => '9200000002', 'fecha' => $S1]), ['asistio' => true], $publico);
comprobar('público no corrige -> 403', $c === 403, (string) $c);
[$c] = peticion('DELETE', a('eliminarMarca', ['documento' => '9200000002', 'fecha' => $S1]), null, $admin);
comprobar('eliminarMarca -> 200 y la fila desaparece', $c === 200 && !isset(marcas($S1)['9200000002']), (string) $c);
[$c] = peticion('DELETE', a('eliminarMarca', ['documento' => '9200000002', 'fecha' => $S1]), null, $admin);
comprobar('eliminarMarca otra vez -> 404', $c === 404, (string) $c);
[$c] = peticion('POST', a('tomar'), ['fecha' => $D1, 'presentes' => []], $admin);
comprobar('tomar con presentes vacío (nadie vino) -> 201, todos ausentes', $c === 201 && array_sum(marcas($D1)) === 0 && count(marcas($D1)) > 0, (string) $c);
[$c, $j] = peticion('DELETE', a('eliminarFecha', ['fecha' => $D1]), null, $admin);
comprobar('eliminarFecha -> 200 con el número de filas borradas', $c === 200 && $j['datos']['eliminadas'] > 0 && marcas($D1) === [], $c . json_encode($j));
[$c] = peticion('DELETE', a('eliminarFecha', ['fecha' => $D1]), null, $admin);
comprobar('eliminarFecha sin asistencia -> 404', $c === 404, (string) $c);
[$c] = peticion('DELETE', a('eliminarFecha', ['fecha' => $S1]), null, $publico);
comprobar('público no elimina -> 403', $c === 403, (string) $c);

echo "\n[porcentaje]\n";
// E: ingreso hace 16 semanas; filas en sábados k = 0,1,2 (ventana 30), 5,6,7 (ventana 90), 14,15 (solo "todo") y 20 (antes del ingreso).
$ins->execute(['9200000010', 'Elena Porcentaje', null, $sabado(16), 'activo', 'validado']);
$filas = [0 => 1, 1 => 0, 2 => 1, 5 => 1, 6 => 1, 7 => 0, 14 => 1, 15 => 0, 20 => 1];
$insA = $bd->prepare("INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES ('9200000010', ?, ?)");
foreach ($filas as $k => $asistio) { $insA->execute([$sabado($k), $asistio]); }
[$c, $j] = peticion('GET', a('porcentaje', ['documento' => '9200000010', 'ventana' => '30']), null, $admin);
comprobar('ventana 30: 3 sesiones -> datos_incompletos true y porcentaje null', $c === 200 && $j['datos']['sesiones'] === 3 && $j['datos']['presentes'] === 2
    && $j['datos']['porcentaje'] === null && $j['datos']['datos_incompletos'] === true, json_encode($j['datos']));
[$c, $j] = peticion('GET', a('porcentaje', ['documento' => '9200000010', 'ventana' => '90']), null, $admin);
comprobar('ventana 90: 6 sesiones, 4 presentes -> 66.7 %', $c === 200 && $j['datos']['sesiones'] === 6 && $j['datos']['porcentaje'] == 66.7 && $j['datos']['datos_incompletos'] === false, json_encode($j['datos']));
[$c, $j2] = peticion('GET', a('porcentaje', ['documento' => '9200000010']), null, $admin);
comprobar('sin ventana -> 90 por defecto', $c === 200 && $j2['datos']['ventana'] === '90' && $j2['datos']['sesiones'] === 6);
[$c, $j] = peticion('GET', a('porcentaje', ['documento' => '9200000010', 'ventana' => 'todo']), null, $admin);
comprobar('ventana todo: desde el ingreso (8 sesiones, excluye la anterior al ingreso) -> 62.5 %', $c === 200 && $j['datos']['sesiones'] === 8 && $j['datos']['presentes'] === 5
    && $j['datos']['porcentaje'] == 62.5 && $j['datos']['desde'] === $sabado(16), json_encode($j['datos']));
$ins->execute(['9200000011', 'Felipe Ingreso Reciente', null, $sabado(1), 'activo', 'validado']);
$insB = $bd->prepare("INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES ('9200000011', ?, 1)");
$insB->execute([$sabado(0)]); $insB->execute([$sabado(2)]);
[$c, $j] = peticion('GET', a('porcentaje', ['documento' => '9200000011', 'ventana' => '90']), null, $admin);
comprobar('ingreso dentro de la ventana: cuenta desde el ingreso (1 sesión, no 2)', $c === 200 && $j['datos']['sesiones'] === 1 && $j['datos']['desde'] === $sabado(1), json_encode($j['datos']));
[$c] = peticion('GET', a('porcentaje', ['documento' => '9200000010', 'ventana' => '60']), null, $admin);
comprobar('ventana no permitida -> 400', $c === 400, (string) $c);
[$c] = peticion('GET', a('porcentaje'), null, $admin);
comprobar('administrativo sin documento -> 400', $c === 400, (string) $c);
[$c] = peticion('GET', a('porcentaje', ['documento' => '0000000000']), null, $admin);
comprobar('deportista inexistente -> 404', $c === 404, (string) $c);
[$c, $j] = peticion('GET', a('porcentaje'), null, $publico);
comprobar('público: el suyo sin documento -> 200', $c === 200 && $j['datos']['documento'] === '1000000101', $c . json_encode($j));
[$c] = peticion('GET', a('porcentaje', ['documento' => '9200000010']), null, $publico);
comprobar('público: ajeno -> 404', $c === 404, (string) $c);

echo "\n[revisarInactivos]\n";
foreach ([
    ['9200000020', 'Gabriel Borde Exacto', '2020-01-01', [[$limiteTxt, 1]]],
    ['9200000021', 'Hugo Un Dia Antes', '2020-01-01', [[$limiteMas1, 1]]],
    ['9200000022', 'Irene Nunca Asistio', $limiteTxt, []],
    ['9200000023', 'Julia Nunca Asistio Reciente', $limiteMas1, []],
    ['9200000024', 'Kevin Solo Ausencias Recientes', '2020-01-01', [['2020-02-01', 1], [$hoy->modify('-7 days')->format('Y-m-d'), 0]]],
] as [$doc, $nombre, $ingreso, $asist]) {
    $ins->execute([$doc, $nombre, null, $ingreso, 'activo', 'validado']);
    foreach ($asist as [$f, $v]) { $bd->prepare('INSERT INTO asistencia VALUES (?, ?, ?)')->execute([$doc, $f, $v]); }
}
$bd->exec("UPDATE deportista SET fecha_ingreso = '2020-01-01' WHERE documento = '9200000004'");
[$c, $j] = peticion('POST', a('revisarInactivos'), null, $admin);
$desactivados = $j['datos']['desactivados'] ?? [];
sort($desactivados);
comprobar("revisarInactivos -> fecha límite = hoy - 3 meses ({$limiteTxt})", $c === 200 && $j['datos']['fecha_limite'] === $limiteTxt, json_encode($j['datos'] ?? null));
comprobar('desactiva: borde exacto (Gabriel), nunca asistió desde el ingreso (Irene), solo ausencias recientes (Kevin)',
    $desactivados === ['9200000020', '9200000022', '9200000024'], json_encode($desactivados));
comprobar('un día antes del borde (Hugo, Julia) siguen activos; quienes asistieron hace poco (Ana, 1000000101 en S3) también', estado('9200000021') === 'activo' && estado('9200000023') === 'activo' && estado('9200000001') === 'activo' && estado('1000000101') === 'activo');
comprobar('el pendiente de validación no se toca', estado('9200000004') === 'activo');
comprobar('la BD refleja los inactivos', estado('9200000020') === 'inactivo' && estado('9200000022') === 'inactivo');
[$c, $j] = peticion('POST', a('revisarInactivos'), null, $admin);
comprobar('idempotente: la segunda vez no desactiva a nadie', $c === 200 && $j['datos']['desactivados'] === [], json_encode($j['datos'] ?? null));
[$c] = peticion('POST', a('revisarInactivos'), null, $publico);
comprobar('público -> 403', $c === 403, (string) $c);
[$c] = peticion('GET', a('revisarInactivos'), null, $admin);
comprobar('con GET -> 405', $c === 405, (string) $c);

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
