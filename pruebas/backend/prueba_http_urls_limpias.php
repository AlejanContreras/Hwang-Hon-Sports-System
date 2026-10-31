<?php

declare(strict_types=1);

// Batería 21: URLs limpias. Cada acción se llama con las dos formas,
//   consulta: api.php?recurso=<r>&accion=<a>&...   (la oficial)
//   ruta:     <r>/<a>?...                            (requiere mod_rewrite)
// y las respuestas deben ser idénticas. Corre contra el servidor de PHP
// (router_pruebas.php) y contra Apache (mod_rewrite real de Public/.htaccess).
// Datos ficticios. Uso: php prueba_http_urls_limpias.php <url php -S> <url Apache>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recién cargados.

[$_, $basePhp, $baseApache] = $argv;
require __DIR__ . '/guardia_apache.php';
guardiaApache((string) $baseApache);
date_default_timezone_set('America/Bogota');

$ok = 0; $fallos = []; $crudos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} " . substr($detalle, 0, 500) . "\n"; }
}
/** @return array{codigo: int, cuerpo: string, cab: array<string, string>, json: ?array} */
function pedir(string $metodo, string $url, string $cuerpo = '', array $cabeceras = [], ?string $cookie = null): array
{
    global $crudos;
    $h = '';
    foreach ($cabeceras as $k => $v) { $h .= "{$k}: {$v}\r\n"; }
    if ($cookie) { $h .= "Cookie: {$cookie}\r\n"; }
    $c = stream_context_create(['http' => ['method' => $metodo, 'header' => $h, 'content' => $cuerpo, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60]]);
    $texto = (string) @file_get_contents($url, false, $c);
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $m);
    $cab = [];
    foreach (array_slice($http_response_header ?? [], 1) as $l) {
        [$k, $v] = array_map('trim', explode(':', $l, 2)) + [1 => ''];
        $cab[strtolower($k)] = $v;
    }
    $crudos[] = $texto;
    return ['codigo' => (int) ($m[1] ?? 0), 'cuerpo' => $texto, 'cab' => $cab, 'json' => json_decode($texto, true)];
}
/** Las dos URLs de una misma acción. */
function urls(string $base, string $recurso, string $accion, array $q = []): array
{
    $consulta = $q ? http_build_query($q) : '';
    return [
        "{$base}/api.php?recurso={$recurso}&accion={$accion}" . ($consulta !== '' ? "&{$consulta}" : ''),
        "{$base}/{$recurso}/{$accion}" . ($consulta !== '' ? "?{$consulta}" : ''),
    ];
}
function multipart(array $campos, string $nombreArchivo, string $contenido): array
{
    $limite = 'limite' . bin2hex(random_bytes(6));
    $cuerpo = '';
    foreach ($campos as $k => $v) {
        $cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
    }
    $cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"archivo\"; filename=\"{$nombreArchivo}\"\r\nContent-Type: application/pdf\r\n\r\n{$contenido}\r\n--{$limite}--\r\n";
    return [$cuerpo, ['Content-Type' => "multipart/form-data; boundary={$limite}"]];
}
/** Quita lo que cambia entre dos llamadas iguales (ids, nombres en disco, fechas de carga). */
function normalizar(?array $j): ?array
{
    if ($j === null) { return null; }
    array_walk_recursive($j, function (&$v, $k): void {
        if (in_array($k, ['id', 'archivo', 'fecha_carga'], true)) { $v = '*'; }
    });
    return $j;
}
function cookieDe(array $r): ?string
{
    return preg_match('/HWANGHON_SESION=([^;]+)/', $r['cab']['set-cookie'] ?? '', $m) ? "HWANGHON_SESION={$m[1]}" : null;
}

/**
 * Llama la misma acción con las dos formas y compara. Archivos: código,
 * Content-Type, Content-Disposition y tamaño (el PDF/Excel lleva la hora de
 * generación). JSON: código y cuerpo completo ($normalizar para escrituras).
 */
function igual(string $nombre, string $base, string $metodo, string $recurso, string $accion, array $q = [], string $cuerpo = '', array $cab = [], ?string $cookie = null, ?int $esperado = null, bool $normalizar = false): array
{
    [$uq, $ur] = urls($base, $recurso, $accion, $q);
    $a = pedir($metodo, $uq, $cuerpo, $cab, $cookie);
    $b = pedir($metodo, $ur, $cuerpo, $cab, $cookie);
    $esJson = str_starts_with($a['cab']['content-type'] ?? '', 'application/json');
    if ($esJson) {
        $iguales = $normalizar ? normalizar($a['json']) == normalizar($b['json']) : $a['cuerpo'] === $b['cuerpo'];
    } else {
        $iguales = ($a['cab']['content-type'] ?? '') === ($b['cab']['content-type'] ?? '')
            && ($a['cab']['content-disposition'] ?? '') === ($b['cab']['content-disposition'] ?? '')
            && $a['cuerpo'] !== '' && abs(strlen($a['cuerpo']) - strlen($b['cuerpo'])) <= 64
            && substr($a['cuerpo'], 0, 4) === substr($b['cuerpo'], 0, 4);
    }
    $codigo = $esperado === null ? '' : " -> {$esperado}";
    comprobar("{$nombre}{$codigo}: las dos formas responden igual", $a['codigo'] === $b['codigo'] && ($esperado === null || $a['codigo'] === $esperado) && $iguales,
        "consulta {$a['codigo']} " . substr($a['cuerpo'], 0, 200) . " | ruta {$b['codigo']} " . substr($b['cuerpo'], 0, 200));
    return [$a, $b];
}

$PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$JSON = ['Content-Type' => 'application/json'];

foreach (['php -S (router_pruebas)' => $basePhp, 'Apache (mod_rewrite)' => $baseApache] as $servidor => $B) {
    echo "\n===== {$servidor}: {$B} =====\n";

    echo "\n[sin sesión]\n";
    igual('auth.sesionActual sin sesión', $B, 'GET', 'auth', 'sesionActual', [], '', [], null, 401);
    igual('deportistas.listar sin sesión', $B, 'GET', 'deportistas', 'listar', [], '', [], null, 401);
    igual('recurso inexistente', $B, 'GET', 'noExiste', 'listar', [], '', [], null, 404);
    igual('acción inexistente (recurso público)', $B, 'GET', 'auth', 'noExiste', [], '', [], null, 404);
    igual('acción inexistente en recurso autenticado, sin sesión (primero la sesión)', $B, 'GET', 'deportistas', 'noExiste', [], '', [], null, 401);
    igual('método equivocado', $B, 'GET', 'auth', 'iniciarSesionAdministrativa', [], '', [], null, 405);
    igual('415: login con text/plain', $B, 'POST', 'auth', 'iniciarSesionAdministrativa', [], '{}', ['Content-Type' => 'text/plain'], null, 415);
    igual('CSRF: POST con Origin de otro sitio', $B, 'POST', 'auth', 'cerrarSesion', [], '', ['Origin' => 'http://malicioso.example'], null, 403);
    igual('autorregistro.registrar inválido (pública, sin sesión)', $B, 'POST', 'autorregistro', 'registrar', [], json_encode(['documento' => 'x']), $JSON, null);

    echo "\n[ámbito administrativo]\n";
    $credAdmin = json_encode(['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']);
    [$la, $lb] = igual('login administrativo', $B, 'POST', 'auth', 'iniciarSesionAdministrativa', [], $credAdmin, $JSON, null, 200);
    $admin = cookieDe($la);
    comprobar('las dos formas entregan su cookie de sesión', $admin !== null && cookieDe($lb) !== null);
    igual('auth.sesionActual', $B, 'GET', 'auth', 'sesionActual', [], '', [], $admin, 200);
    igual('deportistas.consultar', $B, 'GET', 'deportistas', 'consultar', ['documento' => '1000000101'], '', [], $admin, 200);
    igual('deportistas.listar con filtros en la consulta', $B, 'GET', 'deportistas', 'listar', ['estado' => 'activo'], '', [], $admin, 200);
    igual('deportistas.consultar inexistente', $B, 'GET', 'deportistas', 'consultar', ['documento' => '9999999999'], '', [], $admin, 404);
    igual('acción inexistente con sesión', $B, 'GET', 'deportistas', 'noExiste', [], '', [], $admin, 404);
    igual('competencias.proximo', $B, 'GET', 'competencias', 'proximo', [], '', [], $admin, 200);
    $anio = date('Y');
    igual('calendario.vista', $B, 'GET', 'calendario', 'vista', ['desde' => "{$anio}-03-01", 'hasta' => "{$anio}-03-31"], '', [], $admin, 200);
    igual('pagos.deudores', $B, 'GET', 'pagos', 'deudores', [], '', [], $admin, 200);
    igual('usuarios-administrativos.listar (recurso con guion)', $B, 'GET', 'usuarios-administrativos', 'listar', [], '', [], $admin, 200);
    igual('deportistas.actualizar (PUT JSON, mismo dato)', $B, 'PUT', 'deportistas', 'actualizar', ['documento' => '1000000101'], json_encode(['telefono' => '3001112233']), $JSON, $admin, 200);

    [$cuerpoMp, $cabMp] = multipart(['documento' => '1000000101', 'tipo_documento' => 'otro'], 'prueba.pdf', $PDF);
    [$sa, $sb] = igual('documentos.subir (multipart)', $B, 'POST', 'documentos', 'subir', [], $cuerpoMp, $cabMp, $admin, 201, true);
    $ids = [(int) ($sa['json']['datos']['id'] ?? 0), (int) ($sb['json']['datos']['id'] ?? 0)];
    comprobar('cada forma creó su propio documento', $ids[0] > 0 && $ids[1] > 0 && $ids[0] !== $ids[1], json_encode($ids));
    igual('documentos.descargar (archivo)', $B, 'GET', 'documentos', 'descargar', ['id' => $ids[0]], '', [], $admin, 200);
    igual('reportes.deportistas (Excel)', $B, 'GET', 'reportes', 'deportistas', [], '', [], $admin, 200);
    igual('reportes.deportistaFicha (PDF)', $B, 'GET', 'reportes', 'deportistaFicha', ['deportista' => '1000000101'], '', [], $admin, 200);
    $ea = pedir('DELETE', urls($B, 'documentos', 'eliminar', ['id' => $ids[0]])[0], '', [], $admin);
    $eb = pedir('DELETE', urls($B, 'documentos', 'eliminar', ['id' => $ids[1]])[1], '', [], $admin);
    comprobar('documentos.eliminar (DELETE) con cada forma -> 200 y mismo cuerpo', $ea['codigo'] === 200 && $ea['cuerpo'] === $eb['cuerpo'], $ea['cuerpo'] . ' | ' . $eb['cuerpo']);

    echo "\n[ámbito público]\n";
    $credPub = json_encode(['documento' => '1000000101', 'fecha_nacimiento' => '2014-03-12']);
    [$pa] = igual('login público', $B, 'POST', 'auth', 'iniciarSesionPublica', [], $credPub, $JSON, null, 200);
    $pub = cookieDe($pa);
    igual('deportistas.consultar propio', $B, 'GET', 'deportistas', 'consultar', ['documento' => '1000000101'], '', [], $pub, 200);
    igual('deportistas.consultar ajeno (404, igual que inexistente)', $B, 'GET', 'deportistas', 'consultar', ['documento' => '1000000102'], '', [], $pub, 404);
    igual('competencias.proximo (solo administrativo)', $B, 'GET', 'competencias', 'proximo', [], '', [], $pub, 403);
    igual('usuarios-administrativos.listar (política solo_administrativa)', $B, 'GET', 'usuarios-administrativos', 'listar', [], '', [], $pub, 403);
    igual('reportes.deportistas (política solo_administrativa)', $B, 'GET', 'reportes', 'deportistas', [], '', [], $pub, 403);
    igual('documentos.listarGenerales', $B, 'GET', 'documentos', 'listarGenerales', [], '', [], $pub, 200);
    igual('cerrar sesión pública', $B, 'POST', 'auth', 'cerrarSesion', [], '', [], $pub, 200);

    echo "\n[solo forma por ruta: bordes]\n";
    $r = fn (string $sufijo, ?string $cookie = null) => pedir('GET', $B . $sufijo, '', [], $cookie);
    $x = $r('/deportistas/consultar/?documento=1000000101', $admin);
    comprobar('barra final -> 200, igual que sin ella', $x['codigo'] === 200 && $x['cuerpo'] === $r('/deportistas/consultar?documento=1000000101', $admin)['cuerpo'], (string) $x['codigo']);
    $x = $r('/api.php/deportistas/consultar?documento=1000000101', $admin);
    comprobar('api.php en la ruta (…/api.php/deportistas/consultar) -> 200', $x['codigo'] === 200 && ($x['json']['datos']['documento'] ?? '') === '1000000101', $x['codigo'] . $x['cuerpo']);
    $x = $r('/api.php');
    comprobar('/api.php sin nada -> health check 200', $x['codigo'] === 200 && str_contains($x['cuerpo'], 'API activa'), $x['codigo'] . $x['cuerpo']);
    $x = $r('/deportistas');
    comprobar('ruta sin acción -> 404 «Acción no reconocida…»', $x['codigo'] === 404 && str_starts_with($x['json']['mensaje'] ?? '', 'Acción no reconocida'), $x['codigo'] . $x['cuerpo']);
    $x = $r('/deportistas/');
    comprobar('ruta sin acción con barra final -> 404', $x['codigo'] === 404 && str_starts_with($x['json']['mensaje'] ?? '', 'Acción no reconocida'), $x['codigo'] . $x['cuerpo']);
    $x = $r('/noExiste');
    comprobar('recurso desconocido sin acción -> 404 «Recurso no reconocido»', $x['codigo'] === 404 && str_starts_with($x['json']['mensaje'] ?? '', 'Recurso no reconocido'), $x['codigo'] . $x['cuerpo']);
    $x = $r('/deportistas/consultar/extra?documento=1000000101', $admin);
    comprobar('segmentos de más -> 404 «Acción no reconocida»', $x['codigo'] === 404 && str_starts_with($x['json']['mensaje'] ?? '', 'Acción no reconocida'), $x['codigo'] . $x['cuerpo']);
    $x = $r('/deportistas//consultar', $admin);
    comprobar('barra doble -> 404', $x['codigo'] === 404, $x['codigo'] . $x['cuerpo']);
    $x = $r('/deport%2Eistas/listar', $admin);
    comprobar('carácter no permitido en el recurso -> 404 «Recurso no reconocido.»', $x['codigo'] === 404 && ($x['json']['mensaje'] ?? '') === 'Recurso no reconocido.', $x['codigo'] . $x['cuerpo']);
    $x = $r('/deportistas/con%20sultar', $admin);
    comprobar('carácter no permitido en la acción -> 404 «Acción no reconocida.»', $x['codigo'] === 404 && ($x['json']['mensaje'] ?? '') === 'Acción no reconocida.', $x['codigo'] . $x['cuerpo']);
    $x = $r('/deportistas/%C3%B1', $admin);
    comprobar('bytes UTF-8 en la acción -> 404, nunca 500', $x['codigo'] === 404, $x['codigo'] . $x['cuerpo']);
    $x = $r('/otra/cosa?recurso=deportistas&accion=consultar&documento=1000000101', $admin);
    comprobar('si la consulta trae recurso y accion, manda la consulta', $x['codigo'] === 200 && ($x['json']['datos']['documento'] ?? '') === '1000000101', $x['codigo'] . $x['cuerpo']);
    $x = $r('/deportistas/consultar?documento=1000000101&recurso[]=x', $admin);
    comprobar('parámetro como lista -> 400 de siempre', $x['codigo'] === 400, $x['codigo'] . $x['cuerpo']);
}

echo "\n[Apache: carpetas internas siguen sin acceso web]\n";
$raiz = preg_replace('#/backend/Public$#', '', $baseApache);
foreach (['/backend/Src/Config/database.php', '/backend/Src/', '/backend/Instalacion/', '/backend/Almacenamiento/', '/base_datos/esquema_hwanghon_v4.sql'] as $ruta) {
    $c = pedir('GET', $raiz . $ruta)['codigo'];
    comprobar("{$ruta} -> 403", $c === 403, (string) $c);
}
$c = pedir('GET', $baseApache . '/../Src/Config/database.php')['codigo'];
comprobar('Public/../Src/Config/database.php -> 403 (la ruta no escapa de Public)', $c === 403 || $c === 404, (string) $c);
$x = pedir('GET', $baseApache . '/');
comprobar('Apache: la carpeta Public/ -> health check (DirectoryIndex api.php)', $x['codigo'] === 200 && str_contains($x['cuerpo'], 'API activa'), $x['codigo'] . $x['cuerpo']);

echo "\n[fugas]\n";
$todo = implode("\n", array_filter($crudos, fn ($t) => !str_starts_with($t, '%PDF') && !str_starts_with($t, 'PK')));
comprobar('ninguna respuesta trae rutas, SQL, trazas ni hashes', !preg_match('/SQLSTATE|Stack trace|htdocs|\.php on line|\$2y\$|<b>(Warning|Fatal)/i', $todo));

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
