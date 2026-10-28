<?php

declare(strict_types=1);

// Bateria de las correcciones de la revision general (23/10/2026). Fuera del repositorio, datos ficticios.
// Uso: php prueba_http_revision.php <url base php -S> <url servidor con ORIGENES_PERMITIDOS> <url Apache> <ruta backend>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recien cargados. NUNCA dispara un correo real:
// toda solicitud de recuperacion sobre una cuenta existente se hace con un enlace vigente ya puesto en la BD.

[$_, $base, $baseLista, $baseApache, $backend] = $argv;
date_default_timezone_set('America/Bogota');
$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$bd->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$hoy = new DateTimeImmutable('today');
$H = $hoy->format('Y-m-d');
$ahora = fn (string $mod = 'now') => (new DateTimeImmutable($mod))->format('Y-m-d H:i:s');

$ok = 0; $fallos = []; $crudos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} " . substr($detalle, 0, 500) . "\n"; }
}
/** @return array{0: int, 1: ?array, 2: array<string, string>, 3: string} */
function pedir(string $metodo, string $url, ?string $cuerpo = null, array $cabeceras = [], ?string $cookie = null): array
{
    global $crudos;
    $h = '';
    foreach ($cabeceras as $k => $v) { $h .= "{$k}: {$v}\r\n"; }
    if ($cookie) { $h .= "Cookie: {$cookie}\r\n"; }
    $c = stream_context_create(['http' => ['method' => $metodo, 'header' => $h, 'ignore_errors' => true, 'content' => $cuerpo ?? '', 'timeout' => 60]]);
    $texto = (string) file_get_contents($url, false, $c);
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $m);
    $resp = [];
    foreach (array_slice($http_response_header, 1) as $l) {
        [$k, $v] = array_map('trim', explode(':', $l, 2)) + [1 => ''];
        $resp[strtolower($k)] = isset($resp[strtolower($k)]) ? $resp[strtolower($k)] . "\n" . $v : $v;
    }
    $crudos[] = $texto;
    return [(int) $m[1], json_decode($texto, true), $resp, $texto];
}
function json(string $metodo, string $url, array $datos, ?string $cookie = null, array $extra = []): array
{
    return pedir($metodo, $url, json_encode($datos), ['Content-Type' => 'application/json'] + $extra, $cookie);
}
function u(string $recurso, string $accion, array $q = [], ?string $b = null): string
{
    global $base;
    return ($b ?? $base) . "/api.php?recurso={$recurso}&accion={$accion}" . ($q ? '&' . http_build_query($q) : '');
}
function login(string $correo, string $clave, ?string $b = null): array
{
    [$c, $j, $h] = json('POST', u('auth', 'iniciarSesionAdministrativa', [], $b), ['correo' => $correo, 'contrasena' => $clave]);
    preg_match('/HWANGHON_SESION=([^;]+)/', $h['set-cookie'] ?? '', $m);
    return [$c, isset($m[1]) ? "HWANGHON_SESION={$m[1]}" : null, $j, $h];
}
function cuenta(string $sql): int { global $bd; return (int) $bd->query($sql)->fetchColumn(); }
$hashBase = password_hash('12345678', PASSWORD_DEFAULT);
$restaurarClaves = function () use ($bd, $hashBase): void {
    $bd->exec("UPDATE usuario_administrativo SET contrasena = " . $bd->quote($hashBase) . ", token_recuperacion = NULL, token_recuperacion_expira = NULL WHERE correo IN ('maestro@hwanghon.local', 'desarrollador@hwanghon.local')");
    $bd->exec('DELETE FROM intento_acceso');
};
$restaurarClaves();
[, $admin] = login('maestro@hwanghon.local', '12345678');
comprobar('sesión administrativa de referencia', $admin !== null);
$hostPrueba = parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT);
$nuevoUsuario = fn (string $n) => ['correo' => "csrf{$n}@hwanghon.local", 'nombre' => "Usuario CSRF {$n}", 'contrasena' => 'ClaveSegura1'];

// ==================================================================
echo "\n[1) CSRF]\n";
[$c, $j] = pedir('POST', u('usuarios-administrativos', 'crear'), json_encode($nuevoUsuario('1')), ['Content-Type' => 'text/plain', 'Origin' => 'http://atacante.example'], $admin);
comprobar('POST text/plain con Origin ajeno a usuarios-administrativos&crear -> 403, sin crear', $c === 403 && cuenta("SELECT COUNT(*) FROM usuario_administrativo WHERE correo = 'csrf1@hwanghon.local'") === 0, $c . json_encode($j));
[$c, $j] = json('POST', u('usuarios-administrativos', 'crear'), $nuevoUsuario('2'), $admin);
comprobar('el mismo POST sin Origin y con application/json sigue funcionando -> 201', $c === 201, $c . json_encode($j));
[$c] = json('POST', u('usuarios-administrativos', 'crear'), $nuevoUsuario('3'), $admin, ['Origin' => "http://{$hostPrueba}"]);
comprobar('Origin del mismo host:puerto -> aceptado (201)', $c === 201, (string) $c);
foreach (['Origin de otro puerto' => ['Origin' => 'http://' . parse_url($base, PHP_URL_HOST) . ':9999'], 'Origin "null"' => ['Origin' => 'null'],
    'Sec-Fetch-Site cross-site' => ['Sec-Fetch-Site' => 'cross-site'], 'Sec-Fetch-Site same-site' => ['Sec-Fetch-Site' => 'same-site']] as $n => $cab) {
    [$c, $j] = json('POST', u('usuarios-administrativos', 'crear'), $nuevoUsuario('4'), $admin, $cab);
    comprobar("{$n} -> 403", $c === 403, $c . json_encode($j));
}
[$c] = json('POST', u('usuarios-administrativos', 'crear'), $nuevoUsuario('5'), $admin, ['Sec-Fetch-Site' => 'same-origin', 'Origin' => "http://{$hostPrueba}"]);
comprobar('Sec-Fetch-Site same-origin + Origin propio -> 201', $c === 201, (string) $c);
[$c] = pedir('PUT', u('deportistas', 'actualizar', ['documento' => '1000000103']), json_encode(['peso' => 70]), ['Content-Type' => 'application/json', 'Origin' => 'http://atacante.example'], $admin);
comprobar('también PUT (cualquier método distinto de GET) con Origin ajeno -> 403', $c === 403, (string) $c);
[$c] = pedir('GET', u('deportistas', 'listar'), null, ['Origin' => 'http://atacante.example', 'Sec-Fetch-Site' => 'cross-site'], $admin);
comprobar('GET no se bloquea (lectura): 200', $c === 200, (string) $c);
[$c] = pedir('POST', u('auth', 'iniciarSesionAdministrativa'), json_encode(['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']), ['Content-Type' => 'application/json', 'Origin' => 'http://atacante.example']);
comprobar('login desde otro sitio -> 403 (las rutas públicas también)', $c === 403, (string) $c);
[$c, $j] = pedir('POST', u('usuarios-administrativos', 'crear'), json_encode($nuevoUsuario('6')), ['Content-Type' => 'text/plain'], $admin);
comprobar('cuerpo JSON con Content-Type text/plain (sin Origin) -> 415', $c === 415, $c . json_encode($j));
[$c] = pedir('POST', u('usuarios-administrativos', 'crear'), 'correo=x', ['Content-Type' => 'application/x-www-form-urlencoded'], $admin);
comprobar('form-urlencoded -> 415', $c === 415, (string) $c);
[$c] = json('POST', u('usuarios-administrativos', 'crear'), $nuevoUsuario('7'), $admin, ['Content-Type' => 'application/json; charset=utf-8']);
comprobar('application/json; charset=utf-8 -> aceptado', $c === 201, (string) $c);
$lim = '----r' . bin2hex(random_bytes(6));
$multi = "--{$lim}\r\nContent-Disposition: form-data; name=\"documento\"\r\n\r\n1000000103\r\n--{$lim}\r\nContent-Disposition: form-data; name=\"tipo_documento\"\r\n\r\nconsentimiento\r\n--{$lim}\r\nContent-Disposition: form-data; name=\"archivo\"; filename=\"c.pdf\"\r\nContent-Type: application/pdf\r\n\r\n%PDF-1.4\n%%EOF\n\r\n--{$lim}--\r\n";
[$c] = pedir('POST', u('documentos', 'subir'), $multi, ['Content-Type' => "multipart/form-data; boundary={$lim}"], $admin);
comprobar('subida multipart sin Origin: exenta del 415 -> 201', $c === 201, (string) $c);
[$c] = pedir('POST', u('documentos', 'subir'), $multi, ['Content-Type' => "multipart/form-data; boundary={$lim}", 'Origin' => 'http://atacante.example'], $admin);
comprobar('pero la subida multipart con Origin ajeno -> 403', $c === 403, (string) $c);
[$cL] = pedir('POST', u('auth', 'iniciarSesionAdministrativa', [], $baseLista), json_encode(['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']), ['Content-Type' => 'application/json', 'Origin' => 'http://front.example:5173']);
[$cL2] = pedir('POST', u('auth', 'iniciarSesionAdministrativa', [], $baseLista), json_encode(['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']), ['Content-Type' => 'application/json', 'Origin' => 'https://otro.example']);
comprobar('ORIGENES_PERMITIDOS (variable de entorno): el origen listado entra (200) y otro no (403)', $cL === 200 && $cL2 === 403, "{$cL} {$cL2}");

// ==================================================================
echo "\n[2) bloqueo atómico: 30 peticiones paralelas contra Apache]\n";
$paralelo = function (string $url, array $cuerpo, int $n): array {
    $mh = curl_multi_init();
    $hs = [];
    for ($i = 0; $i < $n; $i++) {
        $h = curl_init($url);
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($cuerpo), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
        curl_multi_add_handle($mh, $h);
        $hs[] = $h;
    }
    do { $estado = curl_multi_exec($mh, $activos); curl_multi_select($mh); } while ($activos && $estado === CURLM_OK);
    $codigos = [];
    foreach ($hs as $h) { $codigos[] = curl_getinfo($h, CURLINFO_HTTP_CODE); curl_multi_remove_handle($mh, $h); curl_close($h); }
    curl_multi_close($mh);
    return array_count_values($codigos);
};
[$cA] = pedir('GET', "{$baseApache}/api.php");
comprobar('Apache (multihilo) responde la API', $cA === 200, (string) $cA);
$bd->exec('DELETE FROM intento_acceso');
$cod = $paralelo("{$baseApache}/api.php?recurso=auth&accion=iniciarSesionAdministrativa", ['correo' => 'nadie.paralelo@hwanghon.local', 'contrasena' => 'ClaveErrada9'], 30);
$fila = $bd->query("SELECT * FROM intento_acceso WHERE ambito = 'administrativa' AND identificador = 'nadie.paralelo@hwanghon.local'")->fetch();
comprobar('administrativo: 30 paralelas con clave errada -> intentos_fallidos = 4 exacto y bloqueo fijado', (int) $fila['intentos_fallidos'] === 4 && $fila['bloqueado_hasta'] > $ahora(), json_encode($fila));
comprobar('el resto recibe 429 (como máximo 3 respuestas 401)', ($cod[401] ?? 0) <= 3 && ($cod[401] ?? 0) + ($cod[429] ?? 0) === 30 && ($cod[429] ?? 0) >= 27, json_encode($cod));
$cod = $paralelo("{$baseApache}/api.php?recurso=auth&accion=iniciarSesionPublica", ['documento' => '1000000101', 'fecha_nacimiento' => '2000-01-01'], 30);
$fila = $bd->query("SELECT * FROM intento_acceso WHERE ambito = 'publica' AND identificador = '1000000101'")->fetch();
comprobar('público: 30 paralelas con fecha errada -> 4 exacto, el resto 429', (int) $fila['intentos_fallidos'] === 4 && ($cod[401] ?? 0) <= 3 && ($cod[429] ?? 0) >= 27 && array_sum($cod) === 30, json_encode([$fila, $cod]));
[$c] = json('POST', u('auth', 'iniciarSesionPublica'), ['documento' => '1000000101', 'fecha_nacimiento' => '2014-03-12']);
comprobar('durante el bloqueo ni la fecha correcta entra -> 429', $c === 429, (string) $c);
$bd->exec('DELETE FROM intento_acceso');
foreach ([1, 2, 3] as $i) { [$c] = login('administrativo1@hwanghon.local', 'mala'); }
[$c4, $s4] = login('administrativo1@hwanghon.local', '12345678');
comprobar('3 fallos y luego la clave correcta -> entra y el contador se borra', $c === 401 && $c4 === 200 && cuenta("SELECT COUNT(*) FROM intento_acceso WHERE identificador = 'administrativo1@hwanghon.local'") === 0, "{$c} {$c4}");
foreach ([1, 2, 3, 4] as $i) { [$c] = login('administrativo1@hwanghon.local', 'mala'); }
comprobar('el cuarto fallo seguido -> 429 (secuencial, igual que antes)', $c === 429, (string) $c);
$bd->exec('DELETE FROM intento_acceso');

// ==================================================================
echo "\n[3) sesiones tras cambio de contraseña]\n";
[, $dev1] = login('desarrollador@hwanghon.local', '12345678');
[, $dev2] = login('desarrollador@hwanghon.local', '12345678');
[$c] = pedir('GET', u('usuarios-administrativos', 'listar'), null, [], $dev1);
comprobar('sesión del Desarrollador válida antes del cambio', $c === 200, (string) $c);
[$c] = json('PUT', u('usuarios-administrativos', 'restablecerContrasena', ['correo' => 'desarrollador@hwanghon.local']), ['contrasena_nueva' => 'ClaveNuevaDev1'], $admin);
comprobar('Aldrin restablece la contraseña del Desarrollador (RN-T.12) -> 200', $c === 200, (string) $c);
[$c, $j] = pedir('GET', u('usuarios-administrativos', 'listar'), null, [], $dev1);
[$c2] = pedir('GET', u('deportistas', 'listar'), null, [], $dev2);
comprobar('las sesiones abiertas del Desarrollador -> 401 en su siguiente petición', $c === 401 && $c2 === 401, "{$c} {$c2} " . json_encode($j));
[$c] = pedir('GET', u('deportistas', 'listar'), null, [], $dev1);
comprobar('y la sesión quedó destruida (sigue 401)', $c === 401, (string) $c);
[$cN, $devN] = login('desarrollador@hwanghon.local', 'ClaveNuevaDev1');
[$c] = pedir('GET', u('deportistas', 'listar'), null, [], $devN);
comprobar('con la contraseña nueva entra y su sesión vale', $cN === 200 && $c === 200, "{$cN} {$c}");
[, $m1] = login('maestro@hwanghon.local', '12345678');
[, $m2] = login('maestro@hwanghon.local', '12345678');
[$c, $j, $hC] = json('POST', u('auth', 'cambiarContrasena'), ['contrasena_actual' => '12345678', 'contrasena_nueva' => 'ClaveNuevaMaestro1'], $m1);
preg_match('/HWANGHON_SESION=([^;]+)/', $hC['set-cookie'] ?? '', $mm);
$m1 = isset($mm[1]) ? "HWANGHON_SESION={$mm[1]}" : $m1;
comprobar('cambiar mi contraseña -> 200 y la sesión se renueva (nuevo id de sesión)', $c === 200 && isset($mm[1]), $c . json_encode($j));
[$c] = pedir('GET', u('deportistas', 'listar'), null, [], $m1);
[$c2] = pedir('GET', u('deportistas', 'listar'), null, [], $m2);
comprobar('la sesión que cambió sigue (200) y la otra del mismo usuario cae (401)', $c === 200 && $c2 === 401, "{$c} {$c2}");
[$c] = pedir('GET', u('deportistas', 'listar'), null, [], $admin);
comprobar('la sesión de referencia de Aldrin (anterior al cambio) también cae', $c === 401, (string) $c);
$restaurarClaves();
[, $admin] = login('maestro@hwanghon.local', '12345678');

// ==================================================================
echo "\n[4) recuperación de contraseña]\n";
$vence = $ahora('+40 minutes');
$bd->exec("UPDATE usuario_administrativo SET token_recuperacion = '" . str_repeat('a', 64) . "', token_recuperacion_expira = '{$vence}' WHERE correo = 'maestro@hwanghon.local'");
[$c, $j, $h] = json('POST', u('auth', 'solicitarRecuperacionContrasena'), ['correo' => 'maestro@hwanghon.local']);
$tok = $bd->query("SELECT token_recuperacion, token_recuperacion_expira FROM usuario_administrativo WHERE correo = 'maestro@hwanghon.local'")->fetch();
comprobar('(b) con un enlace vigente no se genera otro: el token y su vencimiento no cambian', $c === 200 && $tok['token_recuperacion'] === str_repeat('a', 64) && $tok['token_recuperacion_expira'] === $vence, json_encode($tok));
comprobar('la respuesta es el mensaje genérico, con Content-Length exacto (se entrega antes del trabajo lento)', ($j['datos']['mensaje'] ?? '') === 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.' && (int) ($h['content-length'] ?? -1) > 0, json_encode([$j, $h['content-length'] ?? null]));
[$c2, $j2] = json('POST', u('auth', 'solicitarRecuperacionContrasena'), ['correo' => 'no.existe@hwanghon.local']);
comprobar('cuenta inexistente -> la misma respuesta exacta', $c2 === 200 && $j2 === $j);
$bd->exec("UPDATE usuario_administrativo SET token_recuperacion = NULL, token_recuperacion_expira = NULL WHERE correo = 'maestro@hwanghon.local'");

// ==================================================================
echo "\n[5) endurecimiento]\n";
[$c, , $h] = pedir('GET', u('deportistas', 'listar'), null, [], $admin);
comprobar('respuesta JSON con X-Content-Type-Options: nosniff y sin X-Powered-By', ($h['x-content-type-options'] ?? '') === 'nosniff' && !isset($h['x-powered-by']), json_encode($h));
[$c, , $h] = json('POST', u('auth', 'iniciarSesionAdministrativa'), ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678'], null, ['X-Forwarded-Proto' => 'https']);
comprobar('TRUST_PROXY=false: X-Forwarded-Proto https NO marca la cookie secure', $c === 200 && stripos($h['set-cookie'] ?? '', 'secure') === false, $h['set-cookie'] ?? '');

// ==================================================================
echo "\n[6) parámetros de la URL]\n";
foreach (['recurso como arreglo' => "{$base}/api.php?recurso[]=deportistas&accion=listar", 'accion como arreglo' => "{$base}/api.php?recurso=deportistas&accion[]=listar",
    'parámetro como arreglo' => u('deportistas', 'consultar') . '&documento[]=1000000101', 'recurso con UTF-8 inválido' => "{$base}/api.php?recurso=%FF%FE&accion=listar",
    'parámetro con UTF-8 inválido' => u('deportistas', 'buscar') . '&texto=%C3%28ab'] as $n => $url) {
    [$c, $j, , $t] = pedir('GET', $url, null, [], $admin);
    comprobar("{$n} -> 400 en JSON válido", $c === 400 && is_array($j) && $j['exito'] === false, $c . ' ' . $t);
}

// ==================================================================
echo "\n[7) fechas con bytes de control]\n";
foreach (['asistencia listarPantalla fecha=%00' => u('asistencia', 'listarPantalla') . '&fecha=%00', 'asistencia listarPantalla fecha válida + NUL' => u('asistencia', 'listarPantalla') . '&fecha=2026-03-07%00',
    'asistencia listarPantalla fecha con salto de línea final' => u('asistencia', 'listarPantalla') . '&fecha=2026-03-07%0A', 'historial torneos desde=%00' => u('historial', 'torneos') . '&desde=%00',
    'calendario vista desde=%00' => u('calendario', 'vista') . '&desde=%00&hasta=2026-12-31', 'reportes asistencia desde=%00' => u('reportes', 'asistencia') . '&desde=%00&hasta=2026-10-01'] as $n => $url) {
    [$c, $j] = pedir('GET', $url, null, [], $admin);
    comprobar("{$n} -> 400", $c === 400, $c . json_encode($j));
}
[$c] = pedir('DELETE', u('asistencia', 'eliminarFecha') . '&fecha=%00', null, [], $admin);
comprobar('asistencia eliminarFecha fecha=%00 -> 400', $c === 400, (string) $c);
[$c] = json('POST', u('auth', 'iniciarSesionPublica'), ['documento' => '1000000101', 'fecha_nacimiento' => "2014-03-12\u{0000}"]);
comprobar('login público con fecha + NUL en el JSON -> 400', $c === 400, (string) $c);
$bd->exec('DELETE FROM intento_acceso');

// ==================================================================
echo "\n[8) campos TEXT]\n";
$largo = str_repeat('a', 70000);
$evento = ['nombre' => 'Evento Texto Largo', 'tipo' => 'evento_extra', 'fecha' => '2026-12-12', 'lugar' => 'Sede', 'visibilidad' => 'publica'];
[$c, $j] = json('POST', u('calendario', 'crear'), $evento + ['observaciones' => $largo], $admin);
comprobar('calendario observaciones de 70.000 caracteres -> 400 claro', $c === 400 && str_contains($j['mensaje'], '65.000 bytes'), $c . json_encode($j));
[$c] = json('POST', u('calendario', 'crear'), $evento + ['observaciones' => str_repeat('a', 65000)], $admin);
comprobar('65.000 bytes justos -> aceptado (201)', $c === 201, (string) $c);
[$c, $j] = json('POST', u('calendario', 'crear'), ['nombre' => 'Evento Tildes Largo'] + $evento + ['observaciones' => str_repeat('á', 33000)], $admin);
comprobar('33.000 «á» (66.000 bytes) -> 400: el tope es en bytes', $c === 400, $c . json_encode($j));
[$c, $j] = json('PUT', u('competencias', 'actualizarParticipacion', ['deportista_documento' => '1000000101', 'competencia_evento_id' => 1, 'modalidad' => 'formas']), ['notas_oponente' => $largo], $admin);
comprobar('notas_oponente de 70.000 -> 400', $c === 400 && str_contains($j['mensaje'], 'notas_oponente'), $c . json_encode($j));
[$c, $j] = json('PUT', u('evaluaciones', 'calificar', ['sesion_id' => 1, 'deportista_documento' => '1000000101', 'modalidad' => 'teorico']), ['observaciones' => $largo], $admin);
comprobar('observaciones de examen de 70.000 -> 400', $c === 400 && str_contains($j['mensaje'], 'observaciones'), $c . json_encode($j));
[$c, $j] = json('PUT', u('evaluaciones', 'calificar', ['sesion_id' => 1, 'deportista_documento' => '1000000101', 'modalidad' => 'teorico']), ['recomendaciones' => $largo], $admin);
comprobar('recomendaciones de examen de 70.000 -> 400', $c === 400, (string) $c);

// ==================================================================
echo "\n[9) descarga con el archivo ausente en disco]\n";
[$c, $j] = pedir('GET', u('documentos', 'descargar', ['id' => 1]), null, [], $admin);
comprobar('documento cuya fila existe pero el archivo no -> 404 claro (antes 500)', $c === 404 && str_contains($j['mensaje'], 'no está disponible'), $c . json_encode($j));
$bd->exec("INSERT INTO documento_general (titulo, archivo, nombre_original, fecha_carga) VALUES ('General sin archivo', '" . str_repeat('b', 32) . ".pdf', 'g.pdf', '2026-10-01')");
$idGeneral = (int) $bd->lastInsertId();
[$c, $j] = pedir('GET', u('documentos', 'descargarGeneral', ['id' => $idGeneral]), null, [], $admin);
comprobar('documento general sin archivo -> 404', $c === 404, $c . json_encode($j));

// ==================================================================
echo "\n[11) calendario no mueve eventos de otros módulos]\n";
$insE = $bd->prepare("INSERT INTO evento_calendario (nombre, tipo, fecha, lugar, visibilidad) VALUES (?, ?, ?, 'Sede ficticia', 'publica')");
$insE->execute(['Examen sesión cerrada', 'evaluacion', '2026-08-01']); $evExamen = (int) $bd->lastInsertId();
$insE->execute(['Grado sesión cerrada', 'grado', '2026-08-08']); $evGrado = (int) $bd->lastInsertId();
$bd->exec("INSERT INTO sesion_examen (fecha, fecha_grado, lugar, estado, evento_examen_id, evento_grado_id) VALUES ('2026-08-01', '2026-08-08', 'Sede ficticia', 'cerrada', {$evExamen}, {$evGrado})");
foreach (['fecha' => '2026-08-15', 'lugar' => 'Otra sede', 'visibilidad' => 'administrativa'] as $campo => $valor) {
    [$c, $j] = json('PUT', u('calendario', 'actualizar', ['id' => $evExamen]), [$campo => $valor], $admin);
    comprobar("evento de examen de una sesión cerrada: cambiar {$campo} -> 409 indicando Evaluaciones", $c === 409 && str_contains($j['mensaje'], 'Evaluaciones'), $c . json_encode($j));
}
[$c, $j] = json('PUT', u('calendario', 'actualizar', ['id' => $evGrado]), ['fecha' => '2026-08-09'], $admin);
comprobar('evento de grado de la misma sesión -> 409', $c === 409, (string) $c);
[$c, $j] = json('PUT', u('calendario', 'actualizar', ['id' => $evExamen]), ['nombre' => 'Examen renombrado', 'observaciones' => 'Nota', 'fecha' => '2026-08-01'], $admin);
comprobar('nombre y observaciones (y la misma fecha) siguen permitidos -> 200', $c === 200 && $j['datos']['nombre'] === 'Examen renombrado', $c . json_encode($j));
[$c, $j] = json('PUT', u('calendario', 'actualizar', ['id' => 1]), ['fecha' => '2026-05-11'], $admin);
comprobar('torneo con resultados: mover la fecha -> 409 indicando Competencias', $c === 409 && str_contains($j['mensaje'], 'Competencias'), $c . json_encode($j));
[$c] = json('PUT', u('calendario', 'actualizar', ['id' => 1]), ['lugar' => 'Coliseo nuevo'], $admin);
comprobar('torneo: cambiar el lugar -> 409', $c === 409, (string) $c);
[$c] = json('PUT', u('calendario', 'actualizar', ['id' => 4]), ['fecha' => '2026-06-27', 'lugar' => 'Parque'], $admin);
comprobar('un evento propio del calendario sí se mueve -> 200', $c === 200, (string) $c);

// ==================================================================
echo "\n[12) entrega de cinturón de una sesión antigua]\n";
$bd->exec("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro, grado_actual) VALUES ('9300000001', 'Cinturones Ficticio', '1995-01-01', 'masculino', 1.7, 70, 1, '2024-01-01', 'activo', 'validado', 'Naranja')");
$bd->exec("INSERT INTO sesion_examen (fecha, fecha_grado, lugar, estado) VALUES ('2025-11-01', '2025-11-08', 'Sede', 'cerrada')"); $sA = (int) $bd->lastInsertId();
$bd->exec("INSERT INTO sesion_examen (fecha, fecha_grado, lugar, estado) VALUES ('2026-04-01', '2026-04-08', 'Sede', 'cerrada')"); $sB = (int) $bd->lastInsertId();
$insDet = $bd->prepare("INSERT INTO detalle_evaluacion (sesion_examen_id, deportista_documento, modalidad, grado_evaluado, estado, nota, aprobado) VALUES (?, '9300000001', ?, ?, 'completado', 8, 1)");
foreach (['teorico', 'fisico', 'combate', 'rompimiento', 'formas', 'llaves'] as $m) { $insDet->execute([$sA, $m, 'Amarillo']); $insDet->execute([$sB, $m, 'Naranja']); }
$bd->exec("INSERT INTO entrega_cinturon (sesion_examen_id, deportista_documento, cinturon_entregado, fecha_entrega, confirmado_por) VALUES ({$sB}, '9300000001', 1, '2026-04-08', 'administrativo')");
[$c, $j] = json('PUT', u('evaluaciones', 'confirmarEntregaCinturon', ['sesion_id' => $sA, 'deportista_documento' => '9300000001']), [], $admin);
$grado = $bd->query("SELECT grado_actual FROM deportista WHERE documento = '9300000001'")->fetchColumn();
comprobar('confirmar la entrega de la sesión antigua: se marca, pero el grado NO baja (sigue Naranja) y avisa', $c === 200 && $j['datos']['cinturon_entregado'] === true && $grado === 'Naranja' && $j['datos']['grado_actual'] === 'Naranja' && str_contains($j['datos']['aviso'] ?? '', 'posterior'), $c . json_encode($j));
comprobar('la entrega antigua quedó registrada', cuenta("SELECT COUNT(*) FROM entrega_cinturon WHERE sesion_examen_id = {$sA} AND cinturon_entregado = 1") === 1);

// ==================================================================
echo "\n[13) asistencia en fecha pasada y fecha de ingreso]\n";
$sab = $hoy->modify('-1 day');
while ($sab->format('N') !== '6') { $sab = $sab->modify('-1 day'); }
$sab = $sab->modify('-14 days')->format('Y-m-d');
$bd->exec("DELETE FROM asistencia WHERE fecha = '{$sab}'");
$insD = $bd->prepare("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro) VALUES (?, ?, '1995-01-01', 'femenino', 1.6, 55, 1, ?, 'activo', 'validado')");
$insD->execute(['9300000002', 'Ingreso Posterior Ausente', $H]);
$insD->execute(['9300000003', 'Ingreso Posterior Presente', $H]);
$insD->execute(['9300000004', 'Ingreso Anterior Ausente', '2024-01-01']);
[$c, $j] = json('POST', u('asistencia', 'tomar'), ['fecha' => $sab, 'presentes' => ['9300000003']], $admin);
comprobar('tomar asistencia de una fecha pasada -> 201/200', in_array($c, [200, 201], true), $c . json_encode($j));
comprobar('sin fila «ausente» para quien ingresó después de esa fecha', cuenta("SELECT COUNT(*) FROM asistencia WHERE fecha = '{$sab}' AND deportista_documento = '9300000002'") === 0);
comprobar('quien ingresó después pero viene presente sí queda registrado', cuenta("SELECT COUNT(*) FROM asistencia WHERE fecha = '{$sab}' AND deportista_documento = '9300000003' AND asistio = 1") === 1);
comprobar('quien ya había ingresado queda ausente, como siempre', cuenta("SELECT COUNT(*) FROM asistencia WHERE fecha = '{$sab}' AND deportista_documento = '9300000004' AND asistio = 0") === 1);

// ==================================================================
echo "\n[17) limpieza de intento_acceso]\n";
require $backend . '/Src/Utils/ExcepcionNegocio.php';
require $backend . '/Src/Config/database.php';
require $backend . '/Src/Repositories/BaseRepository.php';
require $backend . '/Src/Repositories/IntentoAccesoRepository.php';
$bd->exec('DELETE FROM intento_acceso');
$ins = $bd->prepare('INSERT INTO intento_acceso (ambito, identificador, intentos_fallidos, bloqueado_hasta) VALUES (?, ?, ?, ?)');
$ins->execute(['administrativa', 'viejo@x.test', 4, $ahora('-10 days')]);
$ins->execute(['publica', 'autorregistro-ip:10.0.0.1', 3, $ahora('-8 days')]);
$ins->execute(['publica', 'reciente', 4, $ahora('-2 days')]);
$ins->execute(['publica', 'vigente', 4, $ahora('+10 minutes')]);
$ins->execute(['publica', 'sinbloqueo', 2, null]);
$borradas = (new IntentoAccesoRepository())->limpiarViejos($ahora('-7 days'));
$quedan = $bd->query('SELECT identificador FROM intento_acceso ORDER BY identificador')->fetchAll(PDO::FETCH_COLUMN);
comprobar('borra las filas con fecha vencida hace más de 7 días; deja la reciente, la vigente y la sin bloqueo', $borradas === 2 && $quedan === ['reciente', 'sinbloqueo', 'vigente'], json_encode([$borradas, $quedan]));
$bd->exec('DELETE FROM intento_acceso');

// ==================================================================
echo "\n[20) base_datos/ no se sirve por HTTP]\n";
[$c] = pedir('GET', preg_replace('#/backend/Public$#', '', $baseApache) . '/base_datos/esquema_hwanghon_v4.sql');
comprobar('Apache: base_datos/esquema_hwanghon_v4.sql -> 403', $c === 403, (string) $c);

// ==================================================================
echo "\n[A1) eliminar un pendiente con documentos]\n";
$carpetaDocs = $backend . '/Almacenamiento/documentos';
$sembrarPendiente = function (string $doc, ?string $acudiente, int $nDocs) use ($bd, $carpetaDocs, $H): array {
    $bd->prepare("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro, acudiente_documento) VALUES (?, 'Pendiente Ficticio', '2015-03-03', 'femenino', 1.3, 30, 1, ?, 'activo', 'pendiente_validacion', ?)")
        ->execute([$doc, $H, $acudiente]);
    $archivos = [];
    $insDoc = $bd->prepare("INSERT INTO documento (deportista_documento, tipo_documento, archivo, nombre_original, fecha_carga) VALUES (?, ?, ?, ?, ?)");
    foreach (array_slice(['formulario_inscripcion', 'documento_identidad', 'documento_acudiente', 'fotografia'], 0, $nDocs) as $tipo) {
        $nombre = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents("{$carpetaDocs}/{$nombre}", "%PDF-1.4\n% ficticio\n");
        $insDoc->execute([$doc, $tipo, $nombre, "{$tipo}.pdf", $H]);
        $archivos[] = $nombre;
    }
    return $archivos;
};
$enDisco = function (array $archivos) use ($carpetaDocs): int {
    clearstatcache(); // is_file() guarda en caché el último archivo consultado
    return count(array_filter($archivos, fn ($a) => is_file("{$carpetaDocs}/{$a}")));
};
$bd->exec("INSERT INTO acudiente_tutor (documento, nombre, relacion) VALUES ('9399000001', 'Acudiente Ficticio', 'madre')");
$archA = $sembrarPendiente('9399000011', '9399000001', 4);
[$c, $j] = pedir('DELETE', u('deportistas', 'eliminar', ['documento' => '9399000011']), null, [], $admin);
comprobar('pendiente con 4 documentos -> 200', $c === 200 && ($j['datos']['eliminado'] ?? null) === true, $c . json_encode($j));
comprobar('sin filas de documento ni deportista en la BD', cuenta("SELECT COUNT(*) FROM documento WHERE deportista_documento = '9399000011'") === 0 && cuenta("SELECT COUNT(*) FROM deportista WHERE documento = '9399000011'") === 0);
comprobar('los 4 archivos ya no están en disco', $enDisco($archA) === 0);
comprobar('el acudiente que quedó sin deportistas se borra', cuenta("SELECT COUNT(*) FROM acudiente_tutor WHERE documento = '9399000001'") === 0);

$archB = $sembrarPendiente('9399000012', null, 3);
$bd->exec("CREATE TRIGGER prueba_falla_borrar_documento BEFORE DELETE ON documento FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla forzada'");
try {
    [$c, $j, , $t] = pedir('DELETE', u('deportistas', 'eliminar', ['documento' => '9399000012']), null, [], $admin);
} finally {
    $bd->exec('DROP TRIGGER IF EXISTS prueba_falla_borrar_documento');
}
comprobar('falla forzada al borrar las filas -> 500 sin detalles internos', $c === 500 && !preg_match('/falla forzada|SQLSTATE/', $t), $c . $t);
comprobar('tras la falla: filas, deportista y los 3 archivos siguen', cuenta("SELECT COUNT(*) FROM documento WHERE deportista_documento = '9399000012'") === 3 && cuenta("SELECT COUNT(*) FROM deportista WHERE documento = '9399000012'") === 1 && $enDisco($archB) === 3);

$bd->exec("INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES ('9399000012', '{$H}', 1)");
[$c, $j] = pedir('DELETE', u('deportistas', 'eliminar', ['documento' => '9399000012']), null, [], $admin);
comprobar('pendiente con asistencia -> 409 con el mensaje de otros módulos', $c === 409 && str_contains($j['mensaje'] ?? '', 'otros módulos'), $c . json_encode($j));
comprobar('tras el 409: filas y archivos intactos (se revierte la transacción)', cuenta("SELECT COUNT(*) FROM documento WHERE deportista_documento = '9399000012'") === 3 && $enDisco($archB) === 3);
$bd->exec("DELETE FROM asistencia WHERE deportista_documento = '9399000012'");
[$c] = pedir('DELETE', u('deportistas', 'eliminar', ['documento' => '9399000012']), null, [], $admin);
comprobar('sin la asistencia ya se elimina, con sus archivos', $c === 200 && $enDisco($archB) === 0, (string) $c);

$archC = $sembrarPendiente('9399000013', null, 1);
$bd->exec("UPDATE deportista SET estado_registro = 'validado' WHERE documento = '9399000013'");
[$c, $j] = pedir('DELETE', u('deportistas', 'eliminar', ['documento' => '9399000013']), null, [], $admin);
comprobar('validado -> el mismo 409 de antes', $c === 409 && ($j['mensaje'] ?? '') === 'Solo se eliminan registros pendientes de validación. Para un deportista validado, cambie su estado a inactivo.', $c . json_encode($j));
comprobar('validado: su documento y archivo siguen', cuenta("SELECT COUNT(*) FROM documento WHERE deportista_documento = '9399000013'") === 1 && $enDisco($archC) === 1);
[, , $hPub] = pedir('GET', "{$base}/__sesion?ambito=publica&identificador=9300000001");
preg_match('/HWANGHON_SESION=([^;]+)/', $hPub['set-cookie'] ?? '', $m);
$pub = "HWANGHON_SESION={$m[1]}";
$archD = $sembrarPendiente('9399000014', null, 2);
[$c] = pedir('DELETE', u('deportistas', 'eliminar', ['documento' => '9399000014']), null, [], $pub);
comprobar('ámbito público -> 403 y no borra nada', $c === 403 && cuenta("SELECT COUNT(*) FROM documento WHERE deportista_documento = '9399000014'") === 2 && $enDisco($archD) === 2, (string) $c);
pedir('DELETE', u('deportistas', 'eliminar', ['documento' => '9399000014']), null, [], $admin);
$bd->exec("DELETE FROM documento WHERE deportista_documento = '9399000013'");
foreach ($archC as $a) { @unlink("{$carpetaDocs}/{$a}"); }

// ==================================================================
echo "\n[A2) competencias.actualizar: fecha, lugar y visibilidad]\n";
$bd->exec("INSERT INTO evento_calendario (nombre, tipo, fecha, lugar, visibilidad) VALUES ('Torneo Ficticio A2', 'competencia', '" . $hoy->modify('+20 days')->format('Y-m-d') . "', 'Coliseo viejo', 'administrativa')");
$idA2 = (int) $bd->lastInsertId();
$bd->exec("INSERT INTO competencia (evento_id, plataforma, tipo_torneo) VALUES ({$idA2}, 'Fitofan', 'departamental')");
$fechaNueva = $hoy->modify('+40 days')->format('Y-m-d');
$evento = fn () => $bd->query("SELECT fecha, lugar, visibilidad FROM evento_calendario WHERE id = {$idA2}")->fetch();
$enCalendario = function (string $accion, ?string $cookie) use ($fechaNueva, $idA2): ?array {
    [, $j] = pedir('GET', u('calendario', $accion, ['desde' => $fechaNueva, 'hasta' => $fechaNueva]), null, [], $cookie);
    $lista = $accion === 'vista' ? ($j['datos']['eventos'] ?? []) : ($j['datos'] ?? []);
    foreach ($lista as $e) { if ((int) $e['id'] === $idA2) { return $e; } }
    return null;
};
[$c, $j] = json('PUT', u('competencias', 'actualizar', ['id' => $idA2]), ['fecha' => $fechaNueva], $admin);
comprobar('cambiar la fecha -> 200 y queda en evento_calendario', $c === 200 && $evento()['fecha'] === $fechaNueva, $c . json_encode($j));
[$c, $j] = json('PUT', u('competencias', 'actualizar', ['id' => $idA2]), ['lugar' => 'Coliseo municipal de Soacha'], $admin);
comprobar('cambiar el lugar -> 200', $c === 200 && $evento()['lugar'] === 'Coliseo municipal de Soacha', $c . json_encode($j));
comprobar('administrativa: calendario.listar y vista la muestran con la fecha y el lugar nuevos', ($enCalendario('listar', $admin)['lugar'] ?? '') === 'Coliseo municipal de Soacha' && ($enCalendario('vista', $admin)['fecha'] ?? '') === $fechaNueva);
comprobar('público: aún no la ve (visibilidad administrativa)', $enCalendario('listar', $pub) === null && $enCalendario('vista', $pub) === null);
[$c, $j] = json('PUT', u('competencias', 'actualizar', ['id' => $idA2]), ['visibilidad' => 'publica'], $admin);
comprobar('cambiar la visibilidad -> 200', $c === 200 && $evento()['visibilidad'] === 'publica', $c . json_encode($j));
comprobar('público: ahora la ve en calendario.listar y vista', ($enCalendario('listar', $pub)['visibilidad'] ?? '') === 'publica' && $enCalendario('vista', $pub) !== null);
[$c, $j] = pedir('GET', u('competencias', 'consultar', ['id' => $idA2]), null, [], $admin);
comprobar('competencias.consultar refleja fecha, lugar y visibilidad', $c === 200 && str_contains(json_encode($j, JSON_UNESCAPED_UNICODE), $fechaNueva) && str_contains(json_encode($j, JSON_UNESCAPED_UNICODE), 'Coliseo municipal de Soacha'), $c . json_encode($j));
[$c, $j] = json('PUT', u('competencias', 'actualizar', ['id' => $idA2]), ['tipo_torneo' => 'nacional', 'lugar' => 'Coliseo nuevo', 'visibilidad' => 'administrativa'], $admin);
comprobar('varios campos a la vez (tipo_torneo + lugar + visibilidad) -> 200', $c === 200 && $evento()['lugar'] === 'Coliseo nuevo' && cuenta("SELECT COUNT(*) FROM competencia WHERE evento_id = {$idA2} AND tipo_torneo = 'nacional'") === 1, $c . json_encode($j));
$antes = $evento();
foreach ([
    'fecha inexistente -> 400' => ['fecha' => $hoy->format('Y') . '-02-30'],
    'fecha con formato inválido -> 400' => ['fecha' => '30/05/2026'],
    'lugar de 151 caracteres -> 400' => ['lugar' => str_repeat('a', 151)],
    'lugar vacío -> 400' => ['lugar' => '   '],
    'visibilidad inválida -> 400' => ['visibilidad' => 'secreta'],
] as $nombre => $cuerpo) {
    [$c, $j] = json('PUT', u('competencias', 'actualizar', ['id' => $idA2]), $cuerpo, $admin);
    comprobar($nombre, $c === 400, $c . json_encode($j));
}
comprobar('los 400 no cambian el evento', $evento() == $antes);
[$c, $j] = json('PUT', u('competencias', 'actualizar', ['id' => $idA2]), ['nombre' => 'Otro nombre'], $admin);
comprobar('nombre -> 400 con el mensaje nuevo (remite a Calendario)', $c === 400 && ($j['mensaje'] ?? '') === 'Aquí se editan plataforma, tipo_torneo, fecha, lugar y visibilidad; el nombre y las observaciones del evento se editan en Calendario. Campos no permitidos (nombre).', $c . json_encode($j));
[$c, $j] = json('PUT', u('calendario', 'actualizar', ['id' => $idA2]), ['lugar' => 'Otro'], $admin);
comprobar('calendario.actualizar de un torneo -> 409 que remite a Competencias (actualizar el torneo)', $c === 409 && str_contains($j['mensaje'] ?? '', 'Competencias (actualizar el torneo)'), $c . json_encode($j));
$bd->exec("INSERT INTO evento_calendario (nombre, tipo, fecha, lugar, visibilidad) VALUES ('Torneo Ficticio A2', 'evento_extra', '" . $hoy->modify('+50 days')->format('Y-m-d') . "', 'Sede', 'publica')");
[$c, $j] = json('PUT', u('competencias', 'actualizar', ['id' => $idA2]), ['fecha' => $hoy->modify('+50 days')->format('Y-m-d')], $admin);
comprobar('misma fecha y nombre que otro evento -> 409', $c === 409, $c . json_encode($j));
// Con resultados: no se puede mover a una fecha futura; a una pasada sí.
$bd->exec("UPDATE evento_calendario SET fecha = '" . $hoy->modify('-5 days')->format('Y-m-d') . "' WHERE id = {$idA2}");
$bd->exec("INSERT INTO participacion_competencia (deportista_documento, competencia_evento_id, modalidad, categoria_edad, puesto, medalla) VALUES ('9300000001', {$idA2}, 'formas', 'mayores', 1, 'oro')");
[$c, $j] = json('PUT', u('competencias', 'actualizar', ['id' => $idA2]), ['fecha' => $hoy->modify('+3 days')->format('Y-m-d')], $admin);
comprobar('con resultados + fecha futura -> 422', $c === 422 && ($j['mensaje'] ?? '') === 'El torneo ya tiene resultados registrados: no se puede mover a una fecha futura.', $c . json_encode($j));
comprobar('el 422 no cambia la fecha', $evento()['fecha'] === $hoy->modify('-5 days')->format('Y-m-d'));
[$c, $j] = json('PUT', u('competencias', 'actualizar', ['id' => $idA2]), ['fecha' => $hoy->modify('-6 days')->format('Y-m-d'), 'lugar' => 'Coliseo corregido'], $admin);
comprobar('con resultados + fecha pasada (corrección) -> 200', $c === 200 && $evento()['fecha'] === $hoy->modify('-6 days')->format('Y-m-d'), $c . json_encode($j));
[$c, $j] = json('PUT', u('competencias', 'actualizar', ['id' => $idA2]), ['lugar' => 'Coliseo final', 'visibilidad' => 'publica'], $admin);
comprobar('con resultados, sin tocar la fecha: lugar y visibilidad -> 200', $c === 200 && $evento()['lugar'] === 'Coliseo final', $c . json_encode($j));

echo "\n[fugas]\n";
$todo = implode("\n", $crudos);
comprobar('ninguna respuesta trae rutas, SQL, trazas ni hashes', !preg_match('/SQLSTATE|Stack trace|htdocs|\.php on line|\$2y\$/i', $todo));
$restaurarClaves();

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
