<?php

declare(strict_types=1);

// Bateria HTTP del paso 7 (Calendario + extras), fuera del repositorio.
// Uso: php prueba_http_calendario.php <url base> <url servidor con post_max_size=1M> <ruta backend>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recien cargados.

$base = rtrim($argv[1], '/');
$baseChica = rtrim($argv[2], '/');
$backend = rtrim($argv[3], '/');
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
function peticion(string $metodo, string $url, ?array $json = null, ?string $cookie = null, ?array $archivo = null, array $campos = []): array
{
    $cabeceras = $cookie ? "Cookie: {$cookie}\r\n" : '';
    $cuerpo = '';
    if ($archivo !== null) {
        $limite = 'limite' . bin2hex(random_bytes(8));
        foreach ($campos as $n => $v) { $cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"{$n}\"\r\n\r\n{$v}\r\n"; }
        $cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"archivo\"; filename=\"{$archivo[0]}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$archivo[1]}\r\n--{$limite}--\r\n";
        $cabeceras .= "Content-Type: multipart/form-data; boundary={$limite}\r\n";
    } elseif ($json !== null) {
        $cuerpo = json_encode($json);
        $cabeceras .= "Content-Type: application/json\r\n";
    }
    $contexto = stream_context_create(['http' => ['method' => $metodo, 'header' => $cabeceras, 'content' => $cuerpo, 'ignore_errors' => true]]);
    $texto = (string) file_get_contents($url, false, $contexto);
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $m);
    return [(int) $m[1], json_decode($texto, true)];
}
function sesion(string $base, string $accion, array $cuerpo): string
{
    $c = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'ignore_errors' => true, 'content' => json_encode($cuerpo)]]);
    file_get_contents("{$base}/api.php?recurso=auth&accion={$accion}", false, $c);
    foreach ($http_response_header as $h) { if (preg_match('/^Set-Cookie: (HWANGHON_SESION=[^;]*)/i', $h, $m)) { return $m[1]; } }
    return '';
}
function c(string $accion, array $query = []): string
{
    global $base;
    return "{$base}/api.php?recurso=calendario&accion={$accion}" . ($query ? '&' . http_build_query($query) : '');
}
function dia(array $lista, string $fecha): ?array
{
    foreach ($lista as $e) { if ($e['fecha'] === $fecha) { return $e; } }
    return null;
}
function documentos(?array $entrada): array
{
    $d = array_column($entrada['deportistas'] ?? [], 'documento');
    sort($d);
    return $d;
}
function comSelect(): int
{
    global $bd;
    return (int) $bd->query("SHOW GLOBAL STATUS LIKE 'Com_select'")->fetch()['Value'];
}

// Deportistas de prueba.
$ins = $bd->prepare("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro) VALUES (?, ?, ?, 'masculino', 1.70, 70, 1, ?, ?, ?)");
foreach ([
    ['9400000001', 'Bisiesto Prueba', '2004-02-29', '2026-01-01', 'activo', 'validado'],
    ['9400000002', 'Febrero Veintiocho', '2001-02-28', '2026-01-01', 'activo', 'validado'],
    ['9400000003', 'Marzo Diez A', '2000-03-10', '2026-01-01', 'activo', 'validado'],
    ['9400000004', 'Marzo Diez B', '1995-03-10', '2026-01-01', 'activo', 'validado'],
    ['9400000005', 'Marzo Diez Inactivo', '1990-03-10', '2026-01-01', 'inactivo', 'validado'],
    ['9400000006', 'Marzo Diez Pendiente', '1990-03-10', '2026-01-01', 'activo', 'pendiente_validacion'],
    ['9400000010', 'Vence Semana Tres A', '1990-06-01', '2026-01-15', 'activo', 'validado'],
    ['9400000011', 'Vence Semana Tres B', '1990-06-02', '2026-01-15', 'activo', 'validado'],
    ['9400000012', 'Nunca Pago', '1990-06-03', '2026-06-09', 'activo', 'validado'],
    ['9400000013', 'Ingresa En Marzo', '1990-06-04', '2027-03-05', 'activo', 'validado'],
] as $d) { $ins->execute($d); }
$insP = $bd->prepare("INSERT INTO pago (deportista_documento, tipo, anio, mes, semana) VALUES (?, 'mensualidad', ?, ?, ?)");
$insP->execute(['9400000010', 2026, 12, 3]);
$insP->execute(['9400000011', 2026, 12, 3]);
$insP->execute(['9400000010', 2027, 3, 3]);

$admin = sesion($base, 'iniciarSesionAdministrativa', ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']);
$publico = sesion($base, 'iniciarSesionPublica', ['documento' => '1000000101', 'fecha_nacimiento' => '2014-03-12']);
comprobar('sesiones administrativa y pública creadas', $admin !== '' && $publico !== '');

echo "\n[crear]\n";
$evento = fn (array $cambios) => array_merge(['nombre' => 'Evento Prueba', 'tipo' => 'evento_extra', 'fecha' => '2027-02-10', 'lugar' => 'Sede Ficticia', 'visibilidad' => 'publica'], $cambios);
$creados = [];
foreach ([
    'competencia' => ['nombre' => 'Torneo Prueba', 'tipo' => 'competencia', 'visibilidad' => 'publica'],
    'evaluacion' => ['nombre' => 'Examen Prueba', 'tipo' => 'evaluacion', 'visibilidad' => 'publica', 'observaciones' => 'Llevar uniforme'],
    'grado' => ['nombre' => 'Grado Prueba', 'tipo' => 'grado', 'visibilidad' => 'publica'],
    'evento_extra' => ['nombre' => 'Cumpleaños especial del Maestro', 'tipo' => 'evento_extra', 'visibilidad' => 'administrativa'],
] as $tipo => $cambios) {
    [$c, $j] = peticion('POST', c('crear'), $evento($cambios), $admin);
    $creados[$tipo] = $j['datos']['id'] ?? 0;
    comprobar("crear {$tipo} -> 201 con el evento", $c === 201 && $j['datos']['tipo'] === $tipo && array_keys($j['datos']) === ['id', 'nombre', 'tipo', 'fecha', 'lugar', 'visibilidad', 'observaciones'], $c . json_encode($j));
}
[$c, $j] = peticion('POST', c('crear'), $evento(['nombre' => 'Reunión de presupuesto', 'tipo' => 'reunion_administrativa', 'visibilidad' => 'publica']), $admin);
comprobar('reunión administrativa sin confirmar_confidencial -> 400 con mensaje claro', $c === 400 && str_contains($j['mensaje'], 'confirmar_confidencial'), $c . json_encode($j));
[$c] = peticion('POST', c('crear'), $evento(['nombre' => 'Reunión de presupuesto', 'tipo' => 'reunion_administrativa', 'confirmar_confidencial' => 'true']), $admin);
comprobar('confirmar_confidencial como texto "true" (no booleano) -> 400', $c === 400, (string) $c);
[$c, $j] = peticion('POST', c('crear'), $evento(['nombre' => 'Reunión de presupuesto', 'tipo' => 'reunion_administrativa', 'visibilidad' => 'publica', 'confirmar_confidencial' => true]), $admin);
$creados['reunion_administrativa'] = $j['datos']['id'] ?? 0;
comprobar('reunión con confirmar_confidencial: true -> 201 y visibilidad forzada a administrativa (aunque pidan publica)', $c === 201 && $j['datos']['visibilidad'] === 'administrativa', $c . json_encode($j));
[$c] = peticion('POST', c('crear'), $evento(['nombre' => 'COPA FICTICIA hwang hon 2026', 'tipo' => 'competencia', 'fecha' => '2026-05-10']), $admin);
comprobar('duplicado sin distinguir mayúsculas (contra la semilla) -> 409', $c === 409, (string) $c);
[$c] = peticion('POST', c('crear'), $evento(['nombre' => 'reunion de STAFF trimestral', 'tipo' => 'evento_extra', 'fecha' => '2026-04-15']), $admin);
comprobar('duplicado sin distinguir tildes («reunion» = «Reunión») -> 409', $c === 409, (string) $c);
[$c] = peticion('POST', c('crear'), $evento(['nombre' => 'Exámen Prueba', 'tipo' => 'evaluacion']), $admin);
comprobar('«Exámen Prueba» = «Examen Prueba» en la misma fecha -> 409', $c === 409, (string) $c);
[$c] = peticion('POST', c('crear'), $evento(['nombre' => 'Examen Prueba', 'tipo' => 'evaluacion', 'fecha' => '2027-02-11']), $admin);
comprobar('mismo nombre en otra fecha -> 201', $c === 201, (string) $c);
foreach ([
    'fecha imposible' => ['fecha' => '2027-02-30'], 'fecha DD/MM/AAAA (crear exige AAAA-MM-DD)' => ['fecha' => '10/02/2027'],
    'nombre de 151 caracteres' => ['nombre' => str_repeat('n', 151)], 'lugar de 151 caracteres' => ['lugar' => str_repeat('l', 151)],
    'sin lugar' => ['lugar' => ''], 'tipo inválido' => ['tipo' => 'fiesta'], 'visibilidad inválida' => ['visibilidad' => 'privada'],
    'campo no permitido' => ['id' => 99],
] as $caso => $cambio) {
    [$c] = peticion('POST', c('crear'), $evento(array_merge(['nombre' => 'Validación ' . $caso], $cambio)), $admin);
    comprobar("{$caso} -> 400", $c === 400, (string) $c);
}
[$c] = peticion('POST', c('crear'), $evento(['nombre' => 'X']), $publico);
comprobar('público no crea -> 403', $c === 403, (string) $c);
[$c] = peticion('POST', c('crear'), $evento(['nombre' => 'X']));
comprobar('anónimo -> 401', $c === 401, (string) $c);

echo "\n[actualizar]\n";
[$c, $j] = peticion('PUT', c('actualizar', ['id' => $creados['competencia']]), ['nombre' => 'Torneo Prueba Renombrado', 'lugar' => 'Coliseo Ficticio', 'observaciones' => 'Nueva nota'], $admin);
comprobar('actualizar nombre, lugar y observaciones -> 200', $c === 200 && $j['datos']['nombre'] === 'Torneo Prueba Renombrado' && $j['datos']['lugar'] === 'Coliseo Ficticio', $c . json_encode($j));
[$c, $j] = peticion('PUT', c('actualizar', ['id' => $creados['competencia']]), ['tipo' => 'grado'], $admin);
comprobar('cambiar el tipo -> 400', $c === 400 && $j['mensaje'] === 'El tipo de un evento no se cambia.', $c . json_encode($j));
[$c] = peticion('PUT', c('actualizar', ['id' => $creados['competencia']]), ['nombre' => 'grado prueba'], $admin);
comprobar('renombrar a un evento existente en la misma fecha -> 409', $c === 409, (string) $c);
[$c] = peticion('PUT', c('actualizar', ['id' => $creados['competencia']]), ['nombre' => 'Torneo Prueba Renombrado', 'fecha' => '2027-02-10'], $admin);
comprobar('guardar su propio nombre y fecha (no es duplicado de sí mismo) -> 200', $c === 200, (string) $c);
[$c, $j] = peticion('PUT', c('actualizar', ['id' => $creados['reunion_administrativa']]), ['visibilidad' => 'publica'], $admin);
$vis = $bd->query("SELECT visibilidad FROM evento_calendario WHERE id = {$creados['reunion_administrativa']}")->fetchColumn();
comprobar('la reunión no puede volverse pública -> 400 y sigue administrativa', $c === 400 && $vis === 'administrativa', $c . ' ' . $vis);
[$c] = peticion('PUT', c('actualizar', ['id' => $creados['evento_extra']]), ['visibilidad' => 'publica'], $admin);
comprobar('un evento_extra sí cambia de visibilidad -> 200', $c === 200, (string) $c);
[$c] = peticion('PUT', c('actualizar', ['id' => 999999]), ['nombre' => 'X'], $admin);
comprobar('actualizar inexistente -> 404', $c === 404, (string) $c);
[$c] = peticion('PUT', c('actualizar', ['id' => $creados['grado']]), [], $admin);
comprobar('actualizar sin campos -> 400', $c === 400, (string) $c);
[$c] = peticion('PUT', c('actualizar', ['id' => $creados['grado']]), ['nombre' => 'X'], $publico);
comprobar('público no actualiza -> 403', $c === 403, (string) $c);
[$c, $j] = peticion('DELETE', c('eliminar', ['id' => $creados['grado']]), null, $admin);
comprobar('eliminar no existe -> 404 «Acción no reconocida»', $c === 404 && str_contains($j['mensaje'], 'eliminar'), $c . json_encode($j));

echo "\n[listar y consultar]\n";
[$c, $j] = peticion('GET', c('listar'), null, $admin);
$fechasNombres = array_map(fn ($e) => $e['fecha'] . '|' . $e['nombre'], $j['datos']);
$ordenado = $fechasNombres; sort($ordenado, SORT_STRING | SORT_FLAG_CASE);
comprobar('administrativo ve todos, ordenados por fecha y nombre', $c === 200 && count($j['datos']) === (int) $bd->query('SELECT COUNT(*) FROM evento_calendario')->fetchColumn() && $fechasNombres === $ordenado, json_encode($fechasNombres));
[$c, $j] = peticion('GET', c('listar', ['desde' => '2027-02-10', 'hasta' => '2027-02-10']), null, $admin);
comprobar('filtro por rango', $c === 200 && array_unique(array_column($j['datos'], 'fecha')) === ['2027-02-10']);
[$c, $j] = peticion('GET', c('listar', ['tipo' => 'grado']), null, $admin);
comprobar('filtro por tipo', $c === 200 && array_unique(array_column($j['datos'], 'tipo')) === ['grado']);
[$c] = peticion('GET', c('listar', ['desde' => '2027-01-01']), null, $admin);
comprobar('desde sin hasta -> 400', $c === 400, (string) $c);
[$c, $j] = peticion('GET', c('listar'), null, $publico);
comprobar('público ve solo los públicos', $c === 200 && $j['datos'] !== [] && array_unique(array_column($j['datos'], 'visibilidad')) === ['publica']);
[$c] = peticion('GET', c('consultar', ['id' => $creados['reunion_administrativa']]), null, $publico);
comprobar('público consulta un evento administrativo -> 404', $c === 404, (string) $c);
[$c, $j] = peticion('GET', c('consultar', ['id' => $creados['evaluacion']]), null, $publico);
comprobar('público consulta uno público -> 200', $c === 200 && $j['datos']['observaciones'] === 'Llevar uniforme');
[$c] = peticion('GET', c('consultar', ['id' => $creados['reunion_administrativa']]), null, $admin);
comprobar('administrativo consulta la reunión -> 200', $c === 200, (string) $c);

echo "\n[vista: cumpleaños]\n";
[$c, $j] = peticion('GET', c('vista', ['desde' => '2027-02-01', 'hasta' => '2027-03-31']), null, $admin);
comprobar('vista -> {eventos, cumpleanos, vencimientos}', $c === 200 && array_keys($j['datos']) === ['eventos', 'cumpleanos', 'vencimientos'], $c . json_encode(array_keys($j['datos'] ?? [])));
$cum = $j['datos']['cumpleanos'];
comprobar('año no bisiesto (2027): el 29/02 se muestra el 28/02, agrupado con el del 28 (cantidad 2)', ($e = dia($cum, '2027-02-28')) !== null && $e['cantidad'] === 2 && documentos($e) === ['9400000001', '9400000002'], json_encode(dia($cum, '2027-02-28')));
comprobar('cada entrada tiene fecha, cantidad y deportistas [{documento, nombre}]', array_keys($e) === ['fecha', 'cantidad', 'deportistas'] && array_keys($e['deportistas'][0]) === ['documento', 'nombre']);
comprobar('10/03: los 2 activos validados (excluye inactivo y pendiente)', documentos(dia($cum, '2027-03-10')) === ['9400000003', '9400000004'], json_encode(dia($cum, '2027-03-10')));
comprobar('entradas ordenadas por fecha', array_column($cum, 'fecha') === array_values(array_unique(array_merge(array_column($cum, 'fecha')))) && array_column($cum, 'fecha') == (function ($f) { sort($f); return $f; })(array_column($cum, 'fecha')));
[$c, $j] = peticion('GET', c('vista', ['desde' => '2028-02-01', 'hasta' => '2028-03-31']), null, $admin);
$cum = $j['datos']['cumpleanos'];
comprobar('año bisiesto (2028): el 29/02 aparece el 29/02 y el 28 solo con el del 28', documentos(dia($cum, '2028-02-29')) === ['9400000001'] && documentos(dia($cum, '2028-02-28')) === ['9400000002']);
[$c, $j] = peticion('GET', c('vista', ['desde' => '2027-02-01', 'hasta' => '2027-03-31']), null, $publico);
comprobar('público: solo su propia fila de cumpleaños (12/03, cantidad 1)', $c === 200 && $j['datos']['cumpleanos'] === [['fecha' => '2027-03-12', 'cantidad' => 1, 'deportistas' => [['documento' => '1000000101', 'nombre' => 'Samuel Reyes Ortiz']]]], json_encode($j['datos']['cumpleanos'] ?? null));
comprobar('público: en la vista solo eventos públicos', array_unique(array_column($j['datos']['eventos'], 'visibilidad')) === ['publica']);
$vencPublico = $j['datos']['vencimientos'];
comprobar('público: solo sus vencimientos', array_unique(array_merge(...array_map('documentos', $vencPublico))) === ['1000000101'], json_encode($vencPublico));

echo "\n[vista: vencimientos]\n";
[$c, $j] = peticion('GET', c('vista', ['desde' => '2027-02-01', 'hasta' => '2027-03-31']), null, $admin);
$ven = $j['datos']['vencimientos'];
comprobar('semana habitual 3 (último pago dic. 2026 en semana 3): vence el 15; 15/02 agrupa a los dos', documentos(dia($ven, '2027-02-15')) === ['9400000010', '9400000011'] && dia($ven, '2027-02-15')['cantidad'] === 2, json_encode(dia($ven, '2027-02-15')));
comprobar('la mensualidad pagada (marzo de A) no aparece: 15/03 solo B', documentos(dia($ven, '2027-03-15')) === ['9400000011'], json_encode(dia($ven, '2027-03-15')));
comprobar('nunca pagó: semana de su ingreso (9 de junio -> semana 2, vence el 8)', documentos(dia($ven, '2027-02-08')) === ['9400000012'] && documentos(dia($ven, '2027-03-08')) === ['9400000012']);
$todos = array_merge(...array_map('documentos', $ven));
comprobar('ingresa el 5 de marzo: febrero no cuenta; marzo sí (vence el 1, ya vencida al ingresar)', !in_array('9400000013', documentos(dia($ven, '2027-02-01')), true) && in_array('9400000013', documentos(dia($ven, '2027-03-01')), true));
comprobar('inactivos y pendientes sin vencimientos', !in_array('9400000005', $todos, true) && !in_array('9400000006', $todos, true) && !in_array('1000000103', $todos, true) && !in_array('1000000102', $todos, true));
// Pocas consultas: el número de SELECT no depende de cuántos deportistas haya.
$antes = comSelect(); peticion('GET', c('vista', ['desde' => '2027-02-01', 'hasta' => '2027-03-31']), null, $admin); $conPocos = comSelect() - $antes;
for ($i = 0; $i < 30; $i++) { $ins->execute([sprintf('95%08d', $i), "Relleno {$i}", '1990-01-01', '2026-01-01', 'activo', 'validado']); }
$antes = comSelect(); peticion('GET', c('vista', ['desde' => '2027-02-01', 'hasta' => '2027-03-31']), null, $admin); $conMuchos = comSelect() - $antes;
comprobar("consultas de la vista constantes: {$conPocos} SELECT con 11 activos y {$conMuchos} con 30 más (sin N+1)", $conPocos === $conMuchos && $conPocos <= 8, "{$conPocos} vs {$conMuchos}");

echo "\n[vista: rango]\n";
[$c] = peticion('GET', c('vista', ['desde' => '2028-01-01', 'hasta' => '2028-12-31']), null, $admin);
comprobar('366 días (año bisiesto completo) -> 200', $c === 200, (string) $c);
[$c, $j] = peticion('GET', c('vista', ['desde' => '2027-01-01', 'hasta' => '2028-01-02']), null, $admin);
comprobar('367 días -> 400', $c === 400 && str_contains($j['mensaje'], '366'), $c . json_encode($j));
foreach (['desde posterior a hasta' => ['desde' => '2027-03-01', 'hasta' => '2027-02-01'], 'fecha inválida' => ['desde' => '2027-02-30', 'hasta' => '2027-03-01'], 'sin rango' => []] as $caso => $q) {
    [$c] = peticion('GET', c('vista', $q), null, $admin);
    comprobar("vista {$caso} -> 400", $c === 400, (string) $c);
}

echo "\n[importar CSV]\n";
$csvComa = "\xEF\xBB\xBFnombre,tipo,fecha,lugar,visibilidad,observaciones\n"
    . "Festival de verano,evento_extra,2027-07-04,Parque Ficticio,publica,Con BOM y comas\n"
    . "\"Torneo, edición especial\",competencia,2027-08-01,Coliseo Ficticio,publica,\n"
    . "Festival de verano,evento_extra,2027-07-04,Parque Ficticio,publica,duplicado dentro del archivo\n"
    . "COPA FICTICIA HWANG HON 2026,competencia,2026-05-10,Coliseo,publica,duplicado contra la base\n"
    . "Fila mala,fiesta,2027-07-05,Sede,publica,\n"
    . "Sin lugar,evento_extra,2027-07-06,,publica,\n"
    . "Fecha mala,evento_extra,2027-02-30,Sede,publica,\n"
    . "Columnas de menos,evento_extra,2027-07-07\n"
    . "Reunión importada,reunion_administrativa,2027-07-08,Sede,publica,se fuerza administrativa\n";
[$c, $j] = peticion('POST', c('importar'), null, $admin, ['eventos.csv', $csvComa]);
$r = $j['datos'] ?? [];
comprobar('CSV con BOM y comas: 3 creados', $c === 200 && $r['creados'] === 3, $c . json_encode($j));
comprobar('omitidos: el duplicado dentro del archivo (línea 4) y el de la base (línea 5)', array_map(fn ($o) => $o['linea'], $r['omitidos']) === [4, 5] && $r['omitidos'][0] === ['linea' => 4, 'nombre' => 'Festival de verano', 'fecha' => '2027-07-04'], json_encode($r['omitidos']));
comprobar('errores con número de línea y motivo: tipo (6), lugar (7), fecha (8), columnas (9)', array_column($r['errores'], 'linea') === [6, 7, 8, 9] && str_contains($r['errores'][1]['motivo'], 'lugar'), json_encode($r['errores']));
comprobar('campo con coma entre comillas se lee bien', (int) $bd->query("SELECT COUNT(*) FROM evento_calendario WHERE nombre = 'Torneo, edición especial'")->fetchColumn() === 1);
comprobar('la reunión importada quedó administrativa', $bd->query("SELECT visibilidad FROM evento_calendario WHERE nombre = 'Reunión importada'")->fetchColumn() === 'administrativa');
$csvPuntoYComa = "nombre;tipo;fecha;lugar;visibilidad;observaciones\r\nNavidad del club;evento_extra;20/12/2027;Sede Ficticia;publica;Fecha DD/MM/AAAA\r\nHalloween;evento_extra;2027-10-31;Sede Ficticia;publica;Fecha ISO\r\nFecha rara;evento_extra;31/02/2027;Sede;publica;\r\n";
[$c, $j] = peticion('POST', c('importar'), null, $admin, ['eventos_excel.csv', $csvPuntoYComa]);
comprobar('CSV con punto y coma (Excel) y fechas DD/MM/AAAA y AAAA-MM-DD: 2 creados, 1 error (31/02)', $c === 200 && $j['datos']['creados'] === 2 && array_column($j['datos']['errores'], 'linea') === [4], $c . json_encode($j));
comprobar('la fecha 20/12/2027 se guardó como 2027-12-20', $bd->query("SELECT fecha FROM evento_calendario WHERE nombre = 'Navidad del club'")->fetchColumn() === '2027-12-20');
$grande = "nombre,tipo,fecha,lugar,visibilidad,observaciones\n";
for ($i = 1; $i <= 501; $i++) { $grande .= "Evento {$i},evento_extra,2029-01-01,Sede,publica,\n"; }
$antes = (int) $bd->query('SELECT COUNT(*) FROM evento_calendario')->fetchColumn();
[$c, $j] = peticion('POST', c('importar'), null, $admin, ['grande.csv', $grande]);
comprobar('501 filas -> 400 y no se guarda nada', $c === 400 && str_contains($j['mensaje'], '500') && (int) $bd->query('SELECT COUNT(*) FROM evento_calendario')->fetchColumn() === $antes, $c . json_encode($j));
[$c, $j] = peticion('POST', c('importar'), null, $admin, ['quinientas.csv', implode("\n", array_slice(explode("\n", $grande), 0, 501)) . "\n"]);
comprobar('exactamente 500 filas -> se importan', $c === 200 && $j['datos']['creados'] === 500, $c . json_encode($j['mensaje'] ?? null));
[$c] = peticion('POST', c('importar'), null, $admin, ['falso.csv', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n" . str_repeat("\x00\x01", 50)]);
comprobar('archivo que no es CSV (PDF renombrado) -> 400', $c === 400, (string) $c);
[$c] = peticion('POST', c('importar'), null, $admin, ['eventos.xlsx', 'x']);
comprobar('extensión distinta de .csv -> 400', $c === 400, (string) $c);
[$c, $j] = peticion('POST', c('importar'), null, $admin, ['malo.csv', "titulo,fecha\nX,2027-01-01\n"]);
comprobar('encabezado incorrecto -> 400 con las columnas esperadas', $c === 400 && str_contains($j['mensaje'], 'nombre, tipo, fecha'), $c . json_encode($j));
[$c, $j] = peticion('POST', c('importar'), null, $admin, ['latin1.csv', "nombre,tipo,fecha,lugar,visibilidad,observaciones\nReuni\xF3n,evento_extra,2027-01-01,Sede,publica,\n"]);
comprobar('archivo en Latin-1 (no UTF-8) -> 400 con indicación', $c === 400 && str_contains($j['mensaje'], 'UTF-8'), $c . json_encode($j));
[$c] = peticion('POST', c('importar'), null, $admin);
comprobar('sin archivo -> 400', $c === 400, (string) $c);
[$c] = peticion('POST', c('importar'), null, $publico, ['eventos.csv', $csvComa]);
comprobar('público no importa -> 403', $c === 403, (string) $c);
[$c] = peticion('POST', c('importar'), null, null, ['eventos.csv', $csvComa]);
comprobar('anónimo -> 401', $c === 401, (string) $c);

echo "\n[extras]\n";
$bd->exec("INSERT INTO documento (deportista_documento, tipo_documento, archivo, nombre_original, fecha_carga) VALUES ('1000000101', 'otro', '../../Config/correo.php', 'alterado.pdf', '2026-10-01')");
$idAlterado = (int) $bd->lastInsertId();
[$c, $j] = peticion('DELETE', "{$base}/api.php?recurso=documentos&accion=eliminar&id={$idAlterado}", null, $admin);
comprobar('extra 1: eliminar documento con nombre de archivo alterado -> 200 (no 500), fila borrada y el archivo de config intacto',
    $c === 200 && (int) $bd->query("SELECT COUNT(*) FROM documento WHERE id = {$idAlterado}")->fetchColumn() === 0 && is_file("{$backend}/Src/Config/correo.php"), $c . json_encode($j));
$adminChico = sesion($baseChica, 'iniciarSesionAdministrativa', ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']);
$dosMb = "%PDF-1.4\n" . str_repeat('a', 2 * 1024 * 1024);
[$c, $j] = peticion('POST', "{$baseChica}/api.php?recurso=documentos&accion=subir", null, $adminChico, ['grande.pdf', $dosMb], ['documento' => '1000000101', 'tipo_documento' => 'otro']);
comprobar('extra 2: cuerpo > post_max_size (1M en ese servidor) -> 400 de tamaño, no «documento es obligatorio»', $c === 400 && str_contains($j['mensaje'], 'tamaño máximo que acepta el servidor (1 MB)'), $c . json_encode($j));
[$c, $j] = peticion('POST', "{$baseChica}/api.php?recurso=documentos&accion=subirGeneral", null, $adminChico, ['grande.pdf', $dosMb], ['titulo' => 'X']);
comprobar('extra 2: también en subirGeneral', $c === 400 && str_contains($j['mensaje'], 'tamaño máximo que acepta el servidor'), $c . json_encode($j));
$pago = file_get_contents("{$backend}/Src/Services/PagoService.php");
comprobar('extra 3: matriculaCuenta dice «CONFIRMADO por John (21/10/2026)» y ya no «PROPUESTA, por confirmar»', str_contains($pago, 'CONFIRMADO por John') && str_contains($pago, '(21/10/2026)') && !str_contains($pago, 'PROPUESTA, por confirmar'));

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
