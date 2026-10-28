<?php

declare(strict_types=1);

// Bateria HTTP del paso 10 (Estadisticas e historial), fuera del repositorio. Fechas relativas a hoy (Bogota).
// Uso: php prueba_http_estadisticas.php <url base> <ruta backend/Src>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recien cargados. Solo datos ficticios.

$base = rtrim($argv[1], '/');
require $argv[2] . '/Services/DeportistaService.php';
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
function peticion(string $metodo, string $url, ?string $cookie = null): array
{
    global $crudos;
    $cabeceras = "Content-Type: application/json\r\n" . ($cookie ? "Cookie: {$cookie}\r\n" : '');
    $contexto = stream_context_create(['http' => ['method' => $metodo, 'header' => $cabeceras, 'ignore_errors' => true, 'content' => '']]);
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
function h(string $accion, array $query = []): string
{
    global $base;
    return "{$base}/api.php?recurso=historial&accion={$accion}" . ($query ? '&' . http_build_query($query) : '');
}
function es(string $accion, array $query = []): string
{
    global $base;
    return "{$base}/api.php?recurso=estadisticas&accion={$accion}" . ($query ? '&' . http_build_query($query) : '');
}
function comSelect(): int
{
    global $bd;
    return (int) $bd->query("SHOW GLOBAL STATUS LIKE 'Com_select'")->fetch()['Value'];
}
/** Consultas SELECT que hace una peticion (delta de Com_select; SHOW GLOBAL STATUS cuenta como Com_show_status). */
function consultasDe(string $url, string $cookie): int
{
    $antes = comSelect();
    peticion('GET', $url, $cookie);
    return comSelect() - $antes;
}
function clave(array $filas, string ...$campos): array
{
    return array_map(fn ($f) => implode('|', array_map(fn ($c) => (string) $f[$c], $campos)), $filas);
}

// ------------------------------------------------------------------
// Datos ficticios
// ------------------------------------------------------------------
$A = '9800000001'; $B = '9800000002'; $C = '9800000003'; $D = '9800000004'; $P = '9800000005';
$nacA = $hoy->modify('-20 years -30 days')->format('Y-m-d');
$insD = $bd->prepare("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro, grado_actual) VALUES (?, ?, ?, 'masculino', 1.70, 60, 1, ?, ?, ?, ?)");
foreach ([
    [$A, 'Atleta Historial Uno', $nacA, '2024-01-01', 'activo', 'validado', 'Naranja'],
    [$B, 'Atleta Historial Dos', '2000-05-05', '2024-01-01', 'activo', 'validado', 'Amarillo'],
    [$C, 'Atleta Sin Datos', '1990-01-01', '2024-01-01', 'inactivo', 'validado', null],
    [$D, 'Atleta Permanencia', '1995-01-01', '2026-01-15', 'activo', 'validado', null],
    [$P, 'Atleta Pendiente', '1995-01-01', '2026-01-15', 'activo', 'pendiente_validacion', null],
] as $x) { $insD->execute($x); }

$insE = $bd->prepare('INSERT INTO evento_calendario (nombre, tipo, fecha, lugar, visibilidad) VALUES (?, ?, ?, ?, ?)');
$insC = $bd->prepare('INSERT INTO competencia (evento_id, plataforma, tipo_torneo) VALUES (?, ?, ?)');
$torneo = function (string $nombre, string $fecha, string $visibilidad, string $tipo) use ($bd, $insE, $insC): int {
    $insE->execute([$nombre, 'competencia', $fecha, 'Coliseo ficticio', $visibilidad]);
    $id = (int) $bd->lastInsertId();
    $insC->execute([$id, 'Fitofan', $tipo]);
    return $id;
};
$T1 = $torneo('Torneo Nacional Ficticio', $d('-200 days'), 'publica', 'nacional');
$T2 = $torneo('Torneo Internacional Reservado', $d('-100 days'), 'administrativa', 'internacional');
$T3 = $torneo('Torneo Departamental Ficticio', $d('-50 days'), 'publica', 'departamental');
$T4 = $torneo('Torneo Futuro Ficticio', $d('+30 days'), 'publica', 'nacional');
$insE->execute(['Evento competencia sin torneo', 'competencia', $d('-20 days'), 'Coliseo ficticio', 'publica']);
$sinCompetencia = (int) $bd->lastInsertId();

$insP = $bd->prepare('INSERT INTO participacion_competencia (deportista_documento, competencia_evento_id, modalidad, categoria_edad, categoria_peso, puesto, resultado, medalla, combates_ganados, combates_perdidos, notas_oponente) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [$A, $T1, 'combate_individual', 'mayores', '-68 kg', 1, 'Campeón', 'oro', 3, 0, 'SECRETO-OPONENTE zurdo'],
    [$A, $T1, 'formas', 'mayores', null, 3, null, 'bronce', null, null, null],
    [$B, $T1, 'combate_individual', 'mayores', '-68 kg', 2, null, 'plata', 2, 1, 'SECRETO-OPONENTE alto'],
    [$A, $T2, 'combate_equipos', 'mayores', null, 2, null, 'plata', 1, 2, null],
    [$A, $T2, 'formas', 'mayores', null, 1, null, 'oro', null, null, null],
    [$B, $T2, 'formas', 'mayores', null, null, null, null, null, null, null],
    [$A, $T3, 'salto_alto', 'mayores', null, 4, null, 'ninguna', null, null, null],
    [$B, $T3, 'salto_alto', 'mayores', null, 1, null, 'oro', null, null, null],
    [$A, $T4, 'combate_individual', 'mayores', null, null, null, null, null, null, null],
] as $x) { $insP->execute($x); }

$insS = $bd->prepare('INSERT INTO sesion_examen (fecha, fecha_grado, lugar, estado) VALUES (?, ?, ?, ?)');
$sesionNueva = function (string $fecha, string $grado, string $estado) use ($bd, $insS): int {
    $insS->execute([$fecha, $grado, 'Sede ficticia', $estado]);
    return (int) $bd->lastInsertId();
};
$S1 = $sesionNueva($d('-150 days'), $d('-145 days'), 'cerrada');
$S2 = $sesionNueva($d('-40 days'), $d('-30 days'), 'cerrada');
$S3 = $sesionNueva($d('-10 days'), $d('-5 days'), 'abierta');
$insDet = $bd->prepare("INSERT INTO detalle_evaluacion (sesion_examen_id, deportista_documento, modalidad, grado_evaluado, estado, nota, valor_medible, aspectos_fallidos, observaciones, recomendaciones, aprobado) VALUES (?, ?, ?, ?, 'completado', ?, ?, ?, ?, ?, ?)");
$detalle = function (int $s, string $doc, string $grado, array $notas, array $extra = []) use ($insDet): void {
    foreach ($notas as $mod => $nota) {
        $e = $extra[$mod] ?? [];
        $insDet->execute([$s, $doc, $mod, $grado, $nota, $e['valor_medible'] ?? null, $e['aspectos_fallidos'] ?? null, $e['observaciones'] ?? null, $e['recomendaciones'] ?? null, $nota === null ? null : (int) ($nota >= 5)]);
    }
};
$detalle($S1, $A, 'Amarillo', ['teorico' => 8, 'fisico' => 7, 'combate' => 9, 'rompimiento' => 6, 'formas' => 8, 'llaves' => 7],
    ['fisico' => ['valor_medible' => 42.5, 'observaciones' => 'Buena resistencia'], 'rompimiento' => ['aspectos_fallidos' => 'Postura', 'recomendaciones' => 'Practicar base']]);
$detalle($S1, $B, 'Naranja', ['teorico' => 4, 'fisico' => 4, 'combate' => 4, 'rompimiento' => 4, 'formas' => 4, 'llaves' => 4]);
$detalle($S2, $A, 'Naranja', ['teorico' => 9, 'fisico' => 8, 'combate' => 9, 'rompimiento' => 7, 'formas' => 9, 'otra_prueba' => 10], ['fisico' => ['valor_medible' => 45]]);
$detalle($S3, $A, 'Verde', ['teorico' => 10]);
$bd->prepare('INSERT INTO entrega_cinturon (sesion_examen_id, deportista_documento, cinturon_entregado, fecha_entrega, confirmado_por) VALUES (?, ?, ?, ?, ?)')
    ->execute([$S1, $A, 1, $d('-145 days'), 'administrativo']);
$bd->prepare('INSERT INTO entrega_cinturon (sesion_examen_id, deportista_documento, cinturon_entregado, fecha_entrega, confirmado_por) VALUES (?, ?, 0, NULL, NULL)')->execute([$S2, $A]);

$insPago = $bd->prepare('INSERT INTO pago (deportista_documento, tipo, anio, mes, semana) VALUES (?, ?, ?, ?, ?)');
foreach ([[$A, 'mensualidad', 2025, 12, 1], [$A, 'mensualidad', 2026, 1, 1], [$A, 'matricula', 2026, 0, null],
    [$D, 'matricula', 2026, 0, null], [$D, 'mensualidad', 2026, 2, 1], [$D, 'mensualidad', 2026, 3, 1], [$D, 'mensualidad', 2026, 4, 2], [$D, 'mensualidad', 2026, 5, 1],
    [$P, 'mensualidad', 2026, 2, 1]] as $x) { $insPago->execute($x); }

$insA = $bd->prepare('INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES (?, ?, ?)');
foreach ([['-7 days', 1], ['-14 days', 1], ['-21 days', 0], ['-28 days', 1], ['-35 days', 1]] as [$m, $v]) { $insA->execute([$A, $d($m), $v]); }

$admin = sesion('iniciarSesionAdministrativa', ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']);
$pubA = sesion('iniciarSesionPublica', ['documento' => $A, 'fecha_nacimiento' => $nacA]);
$pubB = sesion('iniciarSesionPublica', ['documento' => $B, 'fecha_nacimiento' => '2000-05-05']);
comprobar('sesiones administrativa y públicas creadas', $admin !== '' && $pubA !== '' && $pubB !== '');

// ------------------------------------------------------------------
echo "\n[permisos y ámbito]\n";
foreach ([h('torneos'), h('ascensos'), h('permanencia'), es('deportista', ['deportista' => $A]), es('torneo', ['id' => $T1])] as $url) {
    [$c] = peticion('GET', $url);
    comprobar('anónimo -> 401: ' . substr($url, strlen($base)), $c === 401, (string) $c);
}
foreach (['torneos' => h('torneos', ['deportista' => $B]), 'ascensos' => h('ascensos', ['deportista' => $B]), 'permanencia' => h('permanencia', ['deportista' => $B]),
    'estadisticas deportista' => es('deportista', ['deportista' => $B]), 'torneos (documento inexistente)' => h('torneos', ['deportista' => '9899999999'])] as $n => $url) {
    [$c, $j] = peticion('GET', $url, $pubA);
    comprobar("público pide otro documento en {$n} -> 404 «Deportista no encontrado»", $c === 404 && ($j['mensaje'] ?? $j['error'] ?? '') === 'Deportista no encontrado.', $c . json_encode($j));
}
foreach (['torneos' => h('torneos', ['deportista' => '9899999999']), 'ascensos' => h('ascensos', ['deportista' => '9899999999']),
    'permanencia' => h('permanencia', ['deportista' => '9899999999']), 'estadisticas deportista' => es('deportista', ['deportista' => '9899999999'])] as $n => $url) {
    [$c] = peticion('GET', $url, $admin);
    comprobar("administrativo, documento inexistente en {$n} -> 404", $c === 404, (string) $c);
}
[$c] = peticion('GET', es('torneo', ['id' => $T1]), $pubA);
comprobar('público: resumen de un torneo -> 403', $c === 403, (string) $c);
[$c] = peticion('GET', es('torneo', ['id' => 'abc']), $pubA);
comprobar('público: resumen de torneo con id inválido también 403 (no valida antes de negar)', $c === 403, (string) $c);
[$c] = peticion('GET', es('deportista'), $admin);
comprobar('administrativo: gráficas sin ?deportista= -> 400', $c === 400, (string) $c);
[$c, $j] = peticion('GET', es('deportista'), $pubA);
comprobar('público: gráficas sin ?deportista= -> las suyas', $c === 200 && $j['datos']['perfil']['documento'] === $A, (string) $c);
[$c] = peticion('POST', h('torneos'), $admin);
comprobar('POST a historial torneos -> 405', $c === 405, (string) $c);
[$c] = peticion('GET', h('consultar'), $admin);
comprobar('acción vieja "consultar" ya no existe -> 404', $c === 404, (string) $c);
[$c] = peticion('GET', es('comparar'), $admin);
comprobar('acción no reconocida en estadisticas -> 404', $c === 404, (string) $c);

// ------------------------------------------------------------------
echo "\n[historial: torneos]\n";
[$c, $j] = peticion('GET', h('torneos', ['deportista' => $A]), $admin);
$filas = $j['datos'] ?? [];
comprobar('admin, deportista A: 5 participaciones realizadas (sin el torneo futuro)', $c === 200 && count($filas) === 5, $c . ' ' . count($filas));
comprobar('orden descendente por fecha; dentro del torneo por modalidad', clave($filas, 'evento_id', 'modalidad') === ["{$T3}|salto_alto", "{$T2}|combate_equipos", "{$T2}|formas", "{$T1}|combate_individual", "{$T1}|formas"], json_encode(clave($filas, 'evento_id', 'modalidad')));
$esperadas = ['evento_id', 'torneo', 'fecha', 'lugar', 'tipo_torneo', 'plataforma', 'deportista_documento', 'nombre', 'modalidad', 'categoria_edad', 'categoria_peso', 'puesto', 'resultado', 'medalla', 'combates_ganados', 'combates_perdidos', 'participantes_en_modalidad'];
comprobar('columnas exactas de la fila (sin notas_oponente)', array_keys($filas[0] ?? []) === $esperadas, json_encode(array_keys($filas[0] ?? [])));
$f = $filas[3] ?? [];
comprobar('fila de oro en T1: tipos (int) y valores', $f['torneo'] === 'Torneo Nacional Ficticio' && $f['tipo_torneo'] === 'nacional' && $f['puesto'] === 1 && $f['medalla'] === 'oro'
    && $f['combates_ganados'] === 3 && $f['combates_perdidos'] === 0 && $f['categoria_peso'] === '-68 kg' && $f['participantes_en_modalidad'] === 2 && $f['plataforma'] === 'Fitofan', json_encode($f));
comprobar('fila sin resultados: puesto/combates null', array_key_exists('combates_ganados', $filas[4] ?? []) && $filas[4]['combates_ganados'] === null && $filas[4]['puesto'] === 3, json_encode($filas[4] ?? null));
[$c, $j] = peticion('GET', h('torneos'), $admin);
comprobar('admin sin filtro: todas las participaciones realizadas del club (8)', $c === 200 && count($j['datos']) === 8 + count(array_filter($j['datos'], fn ($x) => !str_starts_with($x['deportista_documento'], '98'))), (string) count($j['datos']));
comprobar('admin sin filtro: el torneo futuro nunca aparece', !in_array($T4, array_column($j['datos'], 'evento_id'), true));
[$c, $j] = peticion('GET', h('torneos'), $pubA);
comprobar('público: solo lo suyo y solo torneos públicos (3 filas, sin T2)', $c === 200 && clave($j['datos'], 'evento_id', 'modalidad') === ["{$T3}|salto_alto", "{$T1}|combate_individual", "{$T1}|formas"]
    && array_unique(array_column($j['datos'], 'deportista_documento')) === [$A], json_encode(clave($j['datos'] ?? [], 'evento_id', 'modalidad')));
[$c, $j2] = peticion('GET', h('torneos', ['deportista' => $A]), $pubA);
comprobar('público con su propio documento = sin filtro', $c === 200 && $j2['datos'] === $j['datos']);
[$c, $j] = peticion('GET', h('torneos', ['deportista' => $A, 'desde' => $d('-120 days')]), $admin);
comprobar('desde filtra por fecha del torneo (T3 y T2)', $c === 200 && array_values(array_unique(array_column($j['datos'], 'evento_id'))) === [$T3, $T2], json_encode(array_column($j['datos'] ?? [], 'evento_id')));
[$c, $j] = peticion('GET', h('torneos', ['deportista' => $A, 'hasta' => $d('-100 days')]), $admin);
comprobar('hasta inclusivo (T2 y T1)', $c === 200 && array_values(array_unique(array_column($j['datos'], 'evento_id'))) === [$T2, $T1], json_encode(array_column($j['datos'] ?? [], 'evento_id')));
[$c, $j] = peticion('GET', h('torneos', ['deportista' => $A, 'hasta' => $d('+60 days')]), $admin);
comprobar('hasta futuro se recorta a hoy: el torneo futuro no sale', $c === 200 && count($j['datos']) === 5);
[$c, $j] = peticion('GET', h('torneos', ['deportista' => $A, 'desde' => $d('+1 days')]), $admin);
comprobar('desde futuro -> lista vacía, no error', $c === 200 && $j['datos'] === [], (string) $c);
foreach (['desde mal formada' => ['desde' => '2026-02-30'], 'hasta mal formada' => ['hasta' => '21/10/2026'], 'desde > hasta' => ['desde' => $d('-10 days'), 'hasta' => $d('-20 days')], 'desde como arreglo' => ['desde' => ['x']]] as $n => $q) {
    [$c] = peticion('GET', h('torneos', $q), $admin);
    comprobar("{$n} -> 400", $c === 400, (string) $c);
}

// ------------------------------------------------------------------
echo "\n[historial: ascensos]\n";
[$c, $j] = peticion('GET', h('ascensos'), $admin);
$filas = array_values(array_filter($j['datos'] ?? [], fn ($x) => str_starts_with($x['deportista_documento'], '98')));
comprobar('admin sin filtro: solo sesiones cerradas (S2-A, S1-B, S1-A); ni S3 abierta ni la sesión abierta de la semilla', $c === 200 && clave($filas, 'sesion_id', 'deportista_documento') === ["{$S2}|{$A}", "{$S1}|{$B}", "{$S1}|{$A}"]
    && !in_array($S3, array_column($j['datos'], 'sesion_id'), true) && !in_array(1, array_column($j['datos'], 'sesion_id'), true), json_encode(clave($j['datos'] ?? [], 'sesion_id', 'deportista_documento')));
comprobar('columnas exactas de la fila', array_keys($filas[0] ?? []) === ['sesion_id', 'fecha', 'fecha_grado', 'lugar', 'deportista_documento', 'nombre', 'grado_evaluado', 'resultado_general', 'entrega', 'modalidades'], json_encode(array_keys($filas[0] ?? [])));
$a1 = $filas[2] ?? [];
comprobar('S1-A: seis notas -> promedio 7.5 aprobado', $a1['resultado_general'] === ['promedio' => 7.5, 'aprobado' => true] && $a1['grado_evaluado'] === 'Amarillo' && $a1['fecha_grado'] === $d('-145 days'), json_encode($a1['resultado_general'] ?? null));
comprobar('S1-A: seis modalidades en orden de EvaluacionService', array_column($a1['modalidades'] ?? [], 'modalidad') === ['teorico', 'fisico', 'combate', 'rompimiento', 'formas', 'llaves']);
comprobar('S1-A: columnas de modalidad y valores (valor_medible, aspectos, observaciones, recomendaciones)', array_keys($a1['modalidades'][1]) === ['modalidad', 'nota', 'aprobado', 'valor_medible', 'aspectos_fallidos', 'observaciones', 'recomendaciones']
    && $a1['modalidades'][1]['valor_medible'] == 42.5 && $a1['modalidades'][1]['observaciones'] === 'Buena resistencia' && $a1['modalidades'][3]['aspectos_fallidos'] === 'Postura'
    && $a1['modalidades'][3]['recomendaciones'] === 'Practicar base' && $a1['modalidades'][0]['nota'] == 8 && $a1['modalidades'][0]['aprobado'] === true, json_encode($a1['modalidades'] ?? null));
comprobar('S1-A: entrega confirmada', $a1['entrega'] === ['cinturon_entregado' => true, 'fecha_entrega' => $d('-145 days'), 'confirmado_por' => 'administrativo'], json_encode($a1['entrega'] ?? null));
$b1 = $filas[1] ?? [];
comprobar('S1-B: promedio 4 -> no aprobado; sin fila de entrega -> entrega null; modalidades no aprobadas', $b1['resultado_general'] == ['promedio' => 4, 'aprobado' => false] && $b1['entrega'] === null
    && array_unique(array_column($b1['modalidades'], 'aprobado')) === [false], json_encode($b1));
$a2 = $filas[0] ?? [];
comprobar('S2-A: falta llaves -> promedio y aprobado null; llaves con todo null', $a2['resultado_general'] === ['promedio' => null, 'aprobado' => null]
    && $a2['modalidades'][5] === ['modalidad' => 'llaves', 'nota' => null, 'aprobado' => null, 'valor_medible' => null, 'aspectos_fallidos' => null, 'observaciones' => null, 'recomendaciones' => null], json_encode($a2));
comprobar('S2-A: la modalidad fuera de la lista oficial (otra_prueba) no aparece', !in_array('otra_prueba', array_column($a2['modalidades'], 'modalidad'), true) && count($a2['modalidades']) === 6);
comprobar('S2-A: entrega registrada pero no entregada', $a2['entrega'] === ['cinturon_entregado' => false, 'fecha_entrega' => null, 'confirmado_por' => null], json_encode($a2['entrega'] ?? null));
[$c, $j] = peticion('GET', h('ascensos', ['deportista' => $A, 'desde' => $d('-100 days')]), $admin);
comprobar('desde filtra por fecha_grado (solo S2)', $c === 200 && array_column($j['datos'], 'sesion_id') === [$S2], json_encode(array_column($j['datos'] ?? [], 'sesion_id')));
[$c, $j] = peticion('GET', h('ascensos', ['deportista' => $A, 'hasta' => $d('-145 days')]), $admin);
comprobar('hasta inclusivo sobre fecha_grado (solo S1)', $c === 200 && array_column($j['datos'], 'sesion_id') === [$S1], json_encode(array_column($j['datos'] ?? [], 'sesion_id')));
[$c, $j] = peticion('GET', h('ascensos'), $pubB);
comprobar('público B: solo su fila', $c === 200 && clave($j['datos'], 'sesion_id', 'deportista_documento') === ["{$S1}|{$B}"], json_encode(clave($j['datos'] ?? [], 'sesion_id', 'deportista_documento')));
[$c] = peticion('GET', h('ascensos', ['desde' => '2026-13-01']), $pubB);
comprobar('fecha inválida también en ascensos -> 400', $c === 400, (string) $c);
$bd->exec("UPDATE sesion_examen SET estado = 'abierta' WHERE id = {$S2}");
[$c, $j] = peticion('GET', h('ascensos', ['deportista' => $A]), $admin);
comprobar('al reabrir S2 deja de salir en ascensos', $c === 200 && array_column($j['datos'], 'sesion_id') === [$S1]);
$bd->exec("UPDATE sesion_examen SET estado = 'cerrada' WHERE id = {$S2}");

// ------------------------------------------------------------------
echo "\n[historial: permanencia]\n";
[$c, $j] = peticion('GET', h('permanencia', ['deportista' => $D]), $admin);
comprobar('ejemplo de John: feb–may pagados, jun–ago sin pagar -> 4 meses acumulados', $c === 200 && $j['datos'] === [['documento' => $D, 'nombre' => 'Atleta Permanencia', 'estado' => 'activo', 'fecha_ingreso' => '2026-01-15', 'meses_pagados' => 4, 'primer_mes_pagado' => '2026-02', 'ultimo_mes_pagado' => '2026-05']], json_encode($j));
$insPago->execute([$D, 'mensualidad', 2026, 9, 1]);
[$c, $j] = peticion('GET', h('permanencia', ['deportista' => $D]), $admin);
comprobar('ejemplo de John: retoma en septiembre -> vuelve a sumar (5), último 2026-09; la matrícula no cuenta', $c === 200 && $j['datos'][0]['meses_pagados'] === 5 && $j['datos'][0]['ultimo_mes_pagado'] === '2026-09' && $j['datos'][0]['primer_mes_pagado'] === '2026-02', json_encode($j));
[$c, $j] = peticion('GET', h('permanencia', ['deportista' => $A]), $admin);
comprobar('cruce de año: dic-2025 y ene-2026 -> 2, primero 2025-12, último 2026-01', $c === 200 && $j['datos'][0]['meses_pagados'] === 2 && $j['datos'][0]['primer_mes_pagado'] === '2025-12' && $j['datos'][0]['ultimo_mes_pagado'] === '2026-01', json_encode($j));
[$c, $j] = peticion('GET', h('permanencia', ['deportista' => $C]), $admin);
comprobar('sin pagos -> 0 y nulls', $c === 200 && $j['datos'][0]['meses_pagados'] === 0 && $j['datos'][0]['primer_mes_pagado'] === null && $j['datos'][0]['ultimo_mes_pagado'] === null, json_encode($j));
[$c, $j] = peticion('GET', h('permanencia'), $admin);
$docs = array_column($j['datos'] ?? [], 'documento');
comprobar('admin sin filtro: todos los validados, sin el pendiente de validación', $c === 200 && in_array($A, $docs, true) && in_array($C, $docs, true) && !in_array($P, $docs, true) && !in_array('1000000102', $docs, true), json_encode($docs));
$meses = array_column($j['datos'] ?? [], 'meses_pagados');
$ordenado = $meses; rsort($ordenado);
comprobar('orden por meses_pagados descendente (D primero con 5)', $meses === $ordenado && $docs[0] === $D, json_encode($meses));
[$c, $j] = peticion('GET', h('permanencia', ['deportista' => $P]), $admin);
comprobar('admin con documento del pendiente: lo muestra', $c === 200 && count($j['datos']) === 1 && $j['datos'][0]['meses_pagados'] === 1);
[$c, $j] = peticion('GET', h('permanencia', ['estado' => 'inactivo']), $admin);
comprobar('estado=inactivo: solo inactivos (C y el de la semilla)', $c === 200 && array_unique(array_column($j['datos'], 'estado')) === ['inactivo'] && in_array($C, array_column($j['datos'], 'documento'), true) && in_array('1000000103', array_column($j['datos'], 'documento'), true), json_encode($j));
[$c] = peticion('GET', h('permanencia', ['estado' => 'retirado']), $admin);
comprobar('estado inválido -> 400', $c === 400, (string) $c);
[$c, $j] = peticion('GET', h('permanencia'), $pubA);
comprobar('público: solo su fila', $c === 200 && array_column($j['datos'], 'documento') === [$A], json_encode($j));
[$c, $j] = peticion('GET', h('permanencia', ['estado' => 'inactivo']), $pubA);
comprobar('público activo con estado=inactivo -> lista vacía', $c === 200 && $j['datos'] === []);

// ------------------------------------------------------------------
echo "\n[estadísticas: deportista]\n";
[$c, $j] = peticion('GET', es('deportista', ['deportista' => $A]), $admin);
$g = $j['datos'] ?? [];
comprobar('claves de la respuesta', $c === 200 && array_keys($g) === ['perfil', 'medallas', 'record', 'posiciones', 'pruebas_fisicas', 'examenes', 'asistencia'], $c . json_encode(array_keys($g)));
comprobar('perfil: edad y categoría con DeportistaService', $g['perfil'] === ['documento' => $A, 'nombre' => 'Atleta Historial Uno', 'grado_actual' => 'Naranja', 'edad' => 20,
    'categoria_edad' => DeportistaService::categoriaPorEdad(20), 'fecha_ingreso' => '2024-01-01', 'estado' => 'activo'], json_encode($g['perfil']));
comprobar('medallas por tipo (admin): nacional oro+bronce, internacional oro+plata, departamental 0 («ninguna» no suma)', $g['medallas']['por_tipo_torneo'] === [
    'nacional' => ['oro' => 1, 'plata' => 0, 'bronce' => 1], 'internacional' => ['oro' => 1, 'plata' => 1, 'bronce' => 0], 'departamental' => ['oro' => 0, 'plata' => 0, 'bronce' => 0]], json_encode($g['medallas']));
comprobar('medallas totales', $g['medallas']['totales'] === ['oro' => 2, 'plata' => 1, 'bronce' => 1, 'total' => 4], json_encode($g['medallas']['totales']));
comprobar('récord acumulado 4-2, 66.67 %', $g['record'] === ['ganados' => 4, 'perdidos' => 2, 'total' => 6, 'porcentaje_victorias' => 66.67], json_encode($g['record']));
comprobar('posiciones: serie ascendente, solo con puesto (5), sin el torneo futuro', clave($g['posiciones'], 'evento_id', 'modalidad', 'puesto', 'participantes_en_modalidad') === [
    "{$T1}|combate_individual|1|2", "{$T1}|formas|3|1", "{$T2}|combate_equipos|2|1", "{$T2}|formas|1|2", "{$T3}|salto_alto|4|2"], json_encode(clave($g['posiciones'], 'evento_id', 'modalidad', 'puesto', 'participantes_en_modalidad')));
comprobar('posiciones: columnas exactas', array_keys($g['posiciones'][0]) === ['evento_id', 'torneo', 'fecha', 'modalidad', 'puesto', 'medalla', 'participantes_en_modalidad']);
comprobar('pruebas físicas ascendentes (S1 42.5, S2 45), solo sesiones cerradas', $g['pruebas_fisicas'] == [
    ['sesion_id' => $S1, 'fecha_grado' => $d('-145 days'), 'grado_evaluado' => 'Amarillo', 'valor_medible' => 42.5, 'nota' => 7],
    ['sesion_id' => $S2, 'fecha_grado' => $d('-30 days'), 'grado_evaluado' => 'Naranja', 'valor_medible' => 45, 'nota' => 8]], json_encode($g['pruebas_fisicas']));
comprobar('exámenes ascendentes con promedio, aprobado y notas por modalidad', count($g['examenes']) === 2 && $g['examenes'][0]['sesion_id'] === $S1 && $g['examenes'][0]['promedio'] == 7.5 && $g['examenes'][0]['aprobado'] === true
    && array_keys($g['examenes'][0]['notas']) === ['teorico', 'fisico', 'combate', 'rompimiento', 'formas', 'llaves'] && $g['examenes'][1]['promedio'] === null && $g['examenes'][1]['notas']['llaves'] === null, json_encode($g['examenes']));
[$c2, $jA] = peticion('GET', "{$base}/api.php?recurso=asistencia&accion=porcentaje&documento={$A}", $admin);
comprobar('asistencia = AsistenciaService::porcentaje tal cual (4 de 5 = 80 %)', $g['asistencia'] === $jA['datos'] && $g['asistencia']['porcentaje'] == 80 && $g['asistencia']['ventana'] === '90', json_encode($g['asistencia']));
[$c, $j] = peticion('GET', es('deportista', ['deportista' => $A, 'ventana' => 'todo']), $admin);
comprobar('ventana se pasa a asistencia (todo)', $c === 200 && $j['datos']['asistencia']['ventana'] === 'todo' && $j['datos']['asistencia']['desde'] === '2024-01-01');
[$c] = peticion('GET', es('deportista', ['deportista' => $A, 'ventana' => '7']), $admin);
comprobar('ventana inválida -> 400 (la valida AsistenciaService)', $c === 400, (string) $c);
[$c, $j] = peticion('GET', es('deportista'), $pubA);
$g = $j['datos'] ?? [];
comprobar('público: medallas sin el torneo administrativo (solo nacional oro+bronce)', $c === 200 && $g['medallas']['totales'] === ['oro' => 1, 'plata' => 0, 'bronce' => 1, 'total' => 2], json_encode($g['medallas'] ?? null));
comprobar('público: posiciones sin el torneo administrativo (3)', count($g['posiciones']) === 3 && !in_array($T2, array_column($g['posiciones'], 'evento_id'), true));
[$c, $j] = peticion('GET', es('deportista', ['deportista' => $C]), $admin);
$g = $j['datos'] ?? [];
comprobar('sin datos: medallas en cero, récord 0 con porcentaje null', $c === 200 && $g['medallas']['totales'] === ['oro' => 0, 'plata' => 0, 'bronce' => 0, 'total' => 0]
    && $g['medallas']['por_tipo_torneo']['internacional'] === ['oro' => 0, 'plata' => 0, 'bronce' => 0] && $g['record'] === ['ganados' => 0, 'perdidos' => 0, 'total' => 0, 'porcentaje_victorias' => null], json_encode($g));
comprobar('sin datos: series vacías, asistencia con datos incompletos, categoría por edad', $g['posiciones'] === [] && $g['pruebas_fisicas'] === [] && $g['examenes'] === []
    && $g['asistencia']['datos_incompletos'] === true && $g['asistencia']['porcentaje'] === null && $g['perfil']['estado'] === 'inactivo' && $g['perfil']['grado_actual'] === null);
[$c, $j] = peticion('GET', es('deportista', ['deportista' => $B]), $admin);
comprobar('B: torneo con puesto null fuera de posiciones; récord 2-1 = 66.67', $c === 200 && count($j['datos']['posiciones']) === 2 && $j['datos']['record']['porcentaje_victorias'] === 66.67 && $j['datos']['pruebas_fisicas'] === []);

// ------------------------------------------------------------------
echo "\n[estadísticas: torneo]\n";
[$c, $j] = peticion('GET', es('torneo', ['id' => $T1]), $admin);
$t = $j['datos'] ?? [];
comprobar('T1: datos del evento y de la competencia', $c === 200 && $t['evento_id'] === $T1 && $t['nombre'] === 'Torneo Nacional Ficticio' && $t['tipo_torneo'] === 'nacional' && $t['plataforma'] === 'Fitofan' && $t['visibilidad'] === 'publica' && $t['num_participantes'] === 2, $c . json_encode($t));
comprobar('T1: por modalidad', $t['por_modalidad'] === [
    ['modalidad' => 'combate_individual', 'participantes' => 2, 'oro' => 1, 'plata' => 1, 'bronce' => 0, 'combates_ganados' => 5, 'combates_perdidos' => 1],
    ['modalidad' => 'formas', 'participantes' => 1, 'oro' => 0, 'plata' => 0, 'bronce' => 1, 'combates_ganados' => 0, 'combates_perdidos' => 0]], json_encode($t['por_modalidad']));
comprobar('T1: medallas_total', $t['medallas_total'] === ['oro' => 1, 'plata' => 1, 'bronce' => 1, 'total' => 3]);
comprobar('T1: participantes por modalidad y puesto', clave($t['participantes'], 'modalidad', 'documento', 'puesto') === ["combate_individual|{$A}|1", "combate_individual|{$B}|2", "formas|{$A}|3"]);
comprobar('T1: columnas de participante exactas (sin notas_oponente)', array_keys($t['participantes'][0]) === ['documento', 'nombre', 'modalidad', 'categoria_edad', 'categoria_peso', 'puesto', 'resultado', 'medalla', 'combates_ganados', 'combates_perdidos']);
[$c, $j] = peticion('GET', es('torneo', ['id' => $T2]), $admin);
comprobar('T2 (administrativo): puesto null al final de su modalidad', $c === 200 && clave($j['datos']['participantes'], 'modalidad', 'documento') === ["combate_equipos|{$A}", "formas|{$A}", "formas|{$B}"] && $j['datos']['participantes'][2]['puesto'] === null);
[$c, $j] = peticion('GET', es('torneo', ['id' => $T3]), $admin);
comprobar('T3: «ninguna» no suma medalla', $c === 200 && $j['datos']['medallas_total'] === ['oro' => 1, 'plata' => 0, 'bronce' => 0, 'total' => 1] && clave($j['datos']['participantes'], 'documento') === [$B, $A]);
foreach (['inexistente' => 999999, 'evento de tipo competencia sin fila en competencia' => $sinCompetencia, 'torneo futuro' => $T4] as $n => $id) {
    [$c] = peticion('GET', es('torneo', ['id' => $id]), $admin);
    comprobar("torneo {$n} -> 404", $c === 404, (string) $c);
}
foreach (['abc', '0', '-1', ''] as $id) {
    [$c] = peticion('GET', es('torneo', ['id' => $id]), $admin);
    comprobar("id inválido '{$id}' -> 400", $c === 400, (string) $c);
}
[$c] = peticion('GET', es('torneo'), $admin);
comprobar('sin id -> 400', $c === 400, (string) $c);

// ------------------------------------------------------------------
echo "\n[consultas constantes]\n";
$urls = ['historial torneos' => h('torneos'), 'historial ascensos' => h('ascensos'), 'permanencia' => h('permanencia'),
    'estadísticas deportista' => es('deportista', ['deportista' => $A]), 'estadísticas torneo' => es('torneo', ['id' => $T1])];
$antes = [];
foreach ($urls as $n => $u) { $antes[$n] = consultasDe($u, $admin); }
// Crece todo: 10 deportistas más con participación, examen cerrado y pagos.
for ($i = 10; $i < 20; $i++) {
    $doc = "98000000{$i}";
    $insD->execute([$doc, "Atleta Carga {$i}", '1999-01-01', '2024-01-01', 'activo', 'validado', null]);
    $insP->execute([$doc, $T1, 'combate_individual', 'mayores', null, $i, null, 'ninguna', 1, 1, null]);
    $insP->execute([$doc, $T2, 'formas', 'mayores', null, null, null, null, null, null, null]);
    $detalle($S1, $doc, 'Blanco', ['teorico' => 6, 'fisico' => 6, 'combate' => 6, 'rompimiento' => 6, 'formas' => 6, 'llaves' => 6], ['fisico' => ['valor_medible' => 30]]);
    foreach ([1, 2, 3] as $mes) { $insPago->execute([$doc, 'mensualidad', 2026, $mes, 1]); }
}
foreach ([[$A, $T3, 'formas'], [$A, $T3, 'combate_individual']] as [$doc, $ev, $mod]) { $insP->execute([$doc, $ev, $mod, 'mayores', null, 2, null, 'plata', 1, 0, null]); }
$S4 = $sesionNueva($d('-20 days'), $d('-15 days'), 'cerrada');
$detalle($S4, $A, 'Verde', ['teorico' => 9, 'fisico' => 9, 'combate' => 9, 'rompimiento' => 9, 'formas' => 9, 'llaves' => 9], ['fisico' => ['valor_medible' => 50]]);
foreach ($urls as $n => $u) {
    $despues = consultasDe($u, $admin);
    comprobar("{$n}: mismas consultas con más datos ({$antes[$n]} -> {$despues})", $antes[$n] === $despues && $antes[$n] > 0 && $antes[$n] <= 10);
}
[$c, $j] = peticion('GET', es('torneo', ['id' => $T1]), $admin);
comprobar('T1 con carga: 12 participantes; puestos 1, 2, 10..19 en combate y luego formas 3', $c === 200 && $j['datos']['num_participantes'] === 12 && array_column($j['datos']['participantes'], 'puesto') === array_merge([1, 2], range(10, 19), [3]), json_encode(array_column($j['datos']['participantes'] ?? [], 'puesto')));

// ------------------------------------------------------------------
echo "\n[fugas]\n";
$todo = implode("\n", array_column($crudos, 1));
comprobar('ninguna respuesta del módulo trae notas_oponente ni su contenido', !str_contains($todo, 'notas_oponente') && !str_contains($todo, 'SECRETO-OPONENTE'));
$publicas = implode("\n", array_column(array_filter($crudos, fn ($r) => in_array($r[0], [$pubA, $pubB], true)), 1));
comprobar('respuestas públicas sin el torneo administrativo', $publicas !== '' && !str_contains($publicas, 'Torneo Internacional Reservado'));
$deA = implode("\n", array_column(array_filter($crudos, fn ($r) => $r[0] === $pubA), 1));
comprobar('respuestas a A nunca traen el documento de B', !str_contains($deA, $B) && !str_contains($deA, 'Atleta Historial Dos'));
comprobar('ninguna respuesta trae contrasena ni token', !str_contains($todo, 'contrasena') && !str_contains($todo, 'token_recuperacion'));

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
