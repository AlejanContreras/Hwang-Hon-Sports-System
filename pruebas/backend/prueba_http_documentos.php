<?php

declare(strict_types=1);

// Bateria HTTP del paso 4 (Documentos), fuera del repositorio.
// Uso: php prueba_http_documentos.php <url base> <ruta backend>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recien cargados y Almacenamiento/ sin documentos.

$base = rtrim($argv[1], '/');
$backend = rtrim($argv[2], '/');
require __DIR__ . '/fabrica/archivos_prueba.php';
date_default_timezone_set('America/Bogota');

$carpetaTmp = __DIR__ . '/tmp_documentos';
$dirDocs = $backend . '/Almacenamiento/documentos';
$dirGen = $backend . '/Almacenamiento/generales';
$muestras = array_map('file_get_contents', archivosDePrueba($carpetaTmp, $backend . '/Src'));

$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$ok = 0; $fallos = []; $respuestas = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}
/** @return array{0: int, 1: ?array, 2: string, 3: array<int, string>} [codigo, json, cuerpo crudo, cabeceras] */
function peticion(string $metodo, string $url, ?string $cookie = null, ?array $campos = null, ?array $archivo = null): array
{
    global $respuestas;
    $cabeceras = $cookie ? "Cookie: {$cookie}\r\n" : '';
    $cuerpo = '';
    if ($campos !== null || $archivo !== null) {
        $limite = 'limite' . bin2hex(random_bytes(8));
        foreach ($campos ?? [] as $nombre => $valor) {
            $cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"{$nombre}\"\r\n\r\n{$valor}\r\n";
        }
        if ($archivo !== null) {
            [$nombreArchivo, $contenido] = $archivo;
            $cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"archivo\"; filename=\"{$nombreArchivo}\"\r\n"
                . "Content-Type: application/octet-stream\r\n\r\n{$contenido}\r\n";
        }
        $cuerpo .= "--{$limite}--\r\n";
        $cabeceras .= "Content-Type: multipart/form-data; boundary={$limite}\r\n";
    }
    $contexto = stream_context_create(['http' => ['method' => $metodo, 'header' => $cabeceras, 'content' => $cuerpo, 'ignore_errors' => true]]);
    $texto = (string) file_get_contents($url, false, $contexto);
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $m);
    $respuestas[] = implode("\n", $http_response_header) . "\n" . (str_contains(implode('', $http_response_header), 'attachment') ? '' : $texto);
    return [(int) $m[1], json_decode($texto, true), $texto, $http_response_header];
}
function cabecera(array $cabeceras, string $nombre): ?string
{
    foreach ($cabeceras as $h) {
        if (stripos($h, $nombre . ':') === 0) { return trim(substr($h, strlen($nombre) + 1)); }
    }
    return null;
}
function d(string $accion, array $query = []): string
{
    global $base;
    return "{$base}/api.php?recurso=documentos&accion={$accion}" . ($query ? '&' . http_build_query($query) : '');
}
function archivosEn(string $dir): array
{
    clearstatcache();
    return is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [];
}
function fila(int $id): ?array
{
    global $bd;
    $s = $bd->prepare('SELECT * FROM documento WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}
function login(string $correo): string
{
    global $base;
    $c = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'ignore_errors' => true,
        'content' => json_encode(['correo' => $correo, 'contrasena' => '12345678'])]]);
    file_get_contents("{$base}/api.php?recurso=auth&accion=iniciarSesionAdministrativa", false, $c);
    foreach ($http_response_header as $h) { if (preg_match('/^Set-Cookie: (HWANGHON_SESION=[^;]*)/i', $h, $m)) { return $m[1]; } }
    return '';
}
function loginPublico(string $documento, string $fecha): string
{
    global $base;
    $c = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'ignore_errors' => true,
        'content' => json_encode(['documento' => $documento, 'fecha_nacimiento' => $fecha])]]);
    file_get_contents("{$base}/api.php?recurso=auth&accion=iniciarSesionPublica", false, $c);
    foreach ($http_response_header as $h) { if (preg_match('/^Set-Cookie: (HWANGHON_SESION=[^;]*)/i', $h, $m)) { return $m[1]; } }
    return '';
}

// Deportistas de prueba: un adulto vacío (9 tipos) y un menor pendiente sin documentos.
$bd->exec("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro) VALUES "
    . "('9100000500', 'Adulto Documentos Prueba', '1990-01-01', 'masculino', 1.70, 70, 1, '2026-01-01', 'activo', 'validado'), "
    . "('9100000501', 'Menor Pendiente Prueba', '2015-01-01', 'femenino', 1.30, 30, 1, '2026-01-01', 'activo', 'pendiente_validacion')");

$admin = login('maestro@hwanghon.local');
$publicoMenor = loginPublico('1000000101', '2014-03-12');
comprobar('sesiones administrativa y pública creadas', $admin !== '' && $publicoMenor !== '');
$archivosIniciales = [archivosEn($dirDocs), archivosEn($dirGen)];
comprobar('Almacenamiento empieza sin documentos', $archivosIniciales === [[], []], json_encode($archivosIniciales));

echo "\n[1. una subida válida por tipo y su descarga]\n";
$porTipo = [
    'documento_identidad' => 'pdf', 'formulario_inscripcion' => 'jpg', 'documento_acudiente' => 'png', 'fotografia' => 'webp',
    'consentimiento' => 'doc', 'certificado_ascenso' => 'docx', 'competencia' => 'xlsx', 'preparacion_fisica' => 'xls', 'otro' => 'pdf',
];
$mimes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
$ids = [];
foreach ($porTipo as $tipo => $ext) {
    $nombre = "prueba_{$tipo}.{$ext}";
    [$c, $j] = peticion('POST', d('subir'), $admin, ['documento' => '9100000500', 'tipo_documento' => $tipo], [$nombre, $muestras[$ext]]);
    $ids[$tipo] = $j['datos']['id'] ?? 0;
    comprobar("subir {$tipo} (.{$ext}) -> 201 con id, deportista, tipo, nombre_original y fecha_carga", $c === 201
        && array_keys($j['datos']) === ['id', 'deportista_documento', 'tipo_documento', 'nombre_original', 'fecha_carga']
        && $j['datos']['nombre_original'] === $nombre && $j['datos']['fecha_carga'] === date('Y-m-d'), $c . json_encode($j));
    $f = fila($ids[$tipo]);
    comprobar("  en disco: nombre aleatorio (32 hex + .{$ext}) con el mismo contenido", $f !== null && preg_match('/^[0-9a-f]{32}\.' . $ext . '$/', $f['archivo']) === 1
        && hash_file('sha256', "{$dirDocs}/{$f['archivo']}") === hash('sha256', $muestras[$ext]));
    [$c, , $cuerpo, $h] = peticion('GET', d('descargar', ['id' => $ids[$tipo]]), $admin);
    comprobar("  descargar -> mismo hash, nombre_original en la cabecera, {$mimes[$ext]} y nosniff", $c === 200
        && hash('sha256', $cuerpo) === hash('sha256', $muestras[$ext])
        && str_contains((string) cabecera($h, 'Content-Disposition'), "filename*=UTF-8''" . rawurlencode($nombre))
        && cabecera($h, 'Content-Type') === $mimes[$ext] && cabecera($h, 'X-Content-Type-Options') === 'nosniff', $c . ' ' . cabecera($h, 'Content-Disposition'));
}
[$c, $j] = peticion('POST', d('subir'), $admin, ['documento' => '9100000501', 'tipo_documento' => 'formulario_inscripcion'], ['formulario.jpeg', $muestras['jpeg']]);
comprobar('subir .jpeg -> 201', $c === 201, (string) $c);
$idJpeg = $j['datos']['id'] ?? 0;
[$c, $j] = peticion('GET', d('listar', ['documento' => '9100000500']), $admin);
comprobar('listar ?documento= -> los 9, ordenados por tipo', $c === 200 && count($j['datos']) === 9
    && array_column($j['datos'], 'tipo_documento') === ['certificado_ascenso', 'competencia', 'consentimiento', 'documento_acudiente', 'documento_identidad', 'formulario_inscripcion', 'fotografia', 'otro', 'preparacion_fisica'], json_encode(array_column($j['datos'], 'tipo_documento')));
[$c, $j] = peticion('GET', d('consultar', ['id' => $ids['otro']]), $admin);
comprobar('consultar ?id= -> solo metadatos', $c === 200 && array_keys($j['datos']) === ['id', 'deportista_documento', 'tipo_documento', 'nombre_original', 'fecha_carga']);
[$c] = peticion('GET', d('consultar', ['id' => 999999]), $admin);
comprobar('consultar id inexistente -> 404', $c === 404, (string) $c);
[$c] = peticion('GET', d('consultar', ['id' => 'abc']), $admin);
comprobar('id no numérico -> 400', $c === 400, (string) $c);

echo "\n[2. rechazos]\n";
$antes = archivosEn($dirDocs);
$rechazos = [
    'extensión no permitida para el tipo (identidad .docx)' => ['documento_identidad', ['x.docx', $muestras['docx']], 400],
    'extensión no permitida para el tipo (consentimiento .jpg)' => ['consentimiento', ['x.jpg', $muestras['jpg']], 400],
    '.pdf que en realidad es texto' => ['otro', ['falso.pdf', "esto es texto plano\n"], 400],
    'HTML disfrazado de .pdf' => ['otro', ['pagina.pdf', '<!DOCTYPE html><html><body>x</body></html>'], 400],
    'SVG disfrazado de .png' => ['fotografia', ['imagen.png', '<svg xmlns="http://www.w3.org/2000/svg"/>'], 400],
    '.heic (no se acepta)' => ['fotografia', ['foto.heic', $muestras['jpg']], 400],
    '.svg (no se acepta)' => ['fotografia', ['foto.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'], 400],
    'archivo vacío' => ['otro', ['vacio.pdf', ''], 400],
    'archivo de 10 MB + 1 byte' => ['otro', ['grande.pdf', "%PDF-1.4\n" . str_repeat('a', 10 * 1024 * 1024 + 1 - 9)], 400],
    'sin el campo archivo' => ['otro', null, 400],
    'tipo_documento inválido' => ['cedula', ['x.pdf', $muestras['pdf']], 400],
];
foreach ($rechazos as $caso => [$tipo, $archivo, $esperado]) {
    [$c, $j] = peticion('POST', d('subir'), $admin, ['documento' => '9100000501', 'tipo_documento' => $tipo], $archivo);
    comprobar("{$caso} -> {$esperado}", $c === $esperado, $c . ' ' . ($j['mensaje'] ?? ''));
}
[$c, $j] = peticion('POST', d('subir'), $admin, ['documento' => '9100000501', 'tipo_documento' => 'otro'], ['limite.pdf', "%PDF-1.4\n" . str_repeat('a', 10 * 1024 * 1024 - 9)]);
comprobar('archivo de exactamente 10 MB -> 201', $c === 201, $c . ' ' . ($j['mensaje'] ?? ''));
$idLimite = $j['datos']['id'] ?? 0;
[$c] = peticion('POST', d('subir'), $admin, ['documento' => '0000000000', 'tipo_documento' => 'otro'], ['x.pdf', $muestras['pdf']]);
comprobar('deportista inexistente -> 404', $c === 404, (string) $c);
$despues = array_values(array_diff(archivosEn($dirDocs), $antes));
comprobar('los rechazos no dejaron archivos en disco (solo el de 10 MB válido)', count($despues) === 1 && $despues[0] === fila($idLimite)['archivo'], json_encode($despues));

echo "\n[3. duplicado y reemplazar]\n";
[$c, $j] = peticion('POST', d('subir'), $admin, ['documento' => '9100000500', 'tipo_documento' => 'documento_identidad'], ['otra_cedula.pdf', $muestras['pdf']]);
comprobar('segundo archivo en un tipo único -> 409 «usa reemplazar»', $c === 409 && str_contains($j['mensaje'], 'reemplazar'), $c . json_encode($j));
[$c] = peticion('POST', d('subir'), $admin, ['documento' => '9100000500', 'tipo_documento' => 'otro'], ['otro_mas.pdf', $muestras['pdf']]);
comprobar('un tipo acumulable admite varios archivos -> 201', $c === 201, (string) $c);
$viejo = fila($ids['documento_identidad'])['archivo'];
$nuevoContenido = $muestras['png'];
[$c, $j] = peticion('POST', d('reemplazar', ['id' => $ids['documento_identidad']]), $admin, [], ['cedula_nueva.png', $nuevoContenido]);
$nuevo = fila($ids['documento_identidad']);
clearstatcache();
comprobar('reemplazar -> 200 con el nuevo nombre_original y mismo id', $c === 200 && $j['datos']['nombre_original'] === 'cedula_nueva.png' && $j['datos']['id'] === $ids['documento_identidad'], $c . json_encode($j));
comprobar('reemplazar borró el archivo anterior del disco y guardó el nuevo', !is_file("{$dirDocs}/{$viejo}") && is_file("{$dirDocs}/{$nuevo['archivo']}") && $nuevo['archivo'] !== $viejo);
[$c, , $cuerpo] = peticion('GET', d('descargar', ['id' => $ids['documento_identidad']]), $admin);
comprobar('la descarga entrega el contenido nuevo', $c === 200 && $cuerpo === $nuevoContenido);
[$c] = peticion('POST', d('reemplazar', ['id' => $ids['documento_identidad']]), $admin, [], ['cedula.docx', $muestras['docx']]);
comprobar('reemplazar respeta las extensiones del tipo (.docx en identidad -> 400)', $c === 400, (string) $c);
[$c] = peticion('PUT', d('reemplazar', ['id' => $ids['documento_identidad']]), $admin);
comprobar('reemplazar con PUT -> 405', $c === 405, (string) $c);

echo "\n[4. eliminar y fallas de la base de datos]\n";
$archivoOtro = fila($ids['otro'])['archivo'];
[$c, $j] = peticion('DELETE', d('eliminar', ['id' => $ids['otro']]), $admin);
clearstatcache();
comprobar('eliminar -> 200, borra la fila y el archivo', $c === 200 && $j['datos'] === ['eliminado' => true] && fila($ids['otro']) === null && !is_file("{$dirDocs}/{$archivoOtro}"), (string) $c);
[$c] = peticion('DELETE', d('eliminar', ['id' => $ids['otro']]), $admin);
comprobar('eliminar de nuevo -> 404', $c === 404, (string) $c);
$bd->exec("CREATE TRIGGER prueba_falla_insert BEFORE INSERT ON documento FOR EACH ROW BEGIN IF NEW.nombre_original = 'falla_insert.pdf' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla forzada'; END IF; END");
$antes = archivosEn($dirDocs);
[$c, $j] = peticion('POST', d('subir'), $admin, ['documento' => '9100000500', 'tipo_documento' => 'otro'], ['falla_insert.pdf', $muestras['pdf']]);
comprobar('si el INSERT falla -> 500 genérico y el archivo guardado se borra (sin huérfanos)', $c === 500 && $j['mensaje'] === 'Error interno del servidor.' && archivosEn($dirDocs) === $antes, $c . ' ' . json_encode(array_diff(archivosEn($dirDocs), $antes)));
$bd->exec('DROP TRIGGER prueba_falla_insert');
$bd->exec("CREATE TRIGGER prueba_falla_update BEFORE UPDATE ON documento FOR EACH ROW BEGIN IF NEW.nombre_original = 'falla_update.pdf' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla forzada'; END IF; END");
$antes = archivosEn($dirDocs);
$filaAntes = fila($ids['consentimiento']);
[$c] = peticion('POST', d('reemplazar', ['id' => $ids['consentimiento']]), $admin, [], ['falla_update.pdf', $muestras['pdf']]);
comprobar('si el UPDATE de reemplazar falla -> 500; se borra el nuevo y el anterior sigue intacto', $c === 500 && archivosEn($dirDocs) === $antes && fila($ids['consentimiento']) == $filaAntes, (string) $c);
$bd->exec('DROP TRIGGER prueba_falla_update');

echo "\n[5. ámbito público]\n";
[$c, $j] = peticion('POST', d('subir'), $publicoMenor, ['documento' => '1000000101', 'tipo_documento' => 'fotografia'], ['mi_foto.jpg', $muestras['jpg']]);
comprobar('público sube lo suyo en un tipo único vacío (fotografía) -> 201', $c === 201, $c . json_encode($j));
$idFotoPublica = $j['datos']['id'] ?? 0;
[$c, $j] = peticion('POST', d('subir'), $publicoMenor, ['documento' => '1000000101', 'tipo_documento' => 'fotografia'], ['otra_foto.jpg', $muestras['jpg']]);
comprobar('público: tipo ya cargado -> 409', $c === 409, (string) $c);
[$c] = peticion('POST', d('subir'), $publicoMenor, ['documento' => '1000000101', 'tipo_documento' => 'consentimiento'], ['c.pdf', $muestras['pdf']]);
comprobar('público: tipo acumulable -> 403', $c === 403, (string) $c);
[$c] = peticion('POST', d('subir'), $publicoMenor, ['documento' => '9100000500', 'tipo_documento' => 'fotografia'], ['f.jpg', $muestras['jpg']]);
comprobar('público: subir a otro deportista -> 404', $c === 404, (string) $c);
[$c] = peticion('POST', d('reemplazar', ['id' => $idFotoPublica]), $publicoMenor, [], ['f.jpg', $muestras['jpg']]);
comprobar('público: reemplazar (aun lo suyo) -> 403', $c === 403, (string) $c);
[$c] = peticion('DELETE', d('eliminar', ['id' => $idFotoPublica]), $publicoMenor);
comprobar('público: eliminar (aun lo suyo) -> 403', $c === 403 && fila($idFotoPublica) !== null, (string) $c);
[$c, $j] = peticion('GET', d('listar'), $publicoMenor);
comprobar('público: listar sin ?documento= -> solo los suyos', $c === 200 && array_unique(array_column($j['datos'], 'deportista_documento')) === ['1000000101'], json_encode($j['datos']));
[$c] = peticion('GET', d('listar', ['documento' => '9100000500']), $publicoMenor);
comprobar('público: listar de otro deportista -> 404', $c === 404, (string) $c);
[$c] = peticion('GET', d('consultar', ['id' => $ids['consentimiento']]), $publicoMenor);
comprobar('público: consultar documento ajeno -> 404', $c === 404, (string) $c);
[$c] = peticion('GET', d('descargar', ['id' => $ids['consentimiento']]), $publicoMenor);
comprobar('público: descargar documento ajeno -> 404', $c === 404, (string) $c);
[$c, , $cuerpo] = peticion('GET', d('descargar', ['id' => $idFotoPublica]), $publicoMenor);
comprobar('público: descargar lo suyo -> 200, mismo contenido', $c === 200 && $cuerpo === $muestras['jpg'], (string) $c);
[$c] = peticion('GET', d('faltantes', ['documento' => '1000000101']), $publicoMenor);
comprobar('público: faltantes -> 403', $c === 403, (string) $c);
[$c] = peticion('GET', d('listar'));
comprobar('sin sesión -> 401', $c === 401, (string) $c);

echo "\n[6. faltantes]\n";
[$c, $j] = peticion('GET', d('faltantes', ['documento' => '1000000101']), $admin);
comprobar('menor (tiene identidad de la semilla y la foto subida) -> críticos: formulario y acudiente; no críticos: ninguno', $c === 200
    && $j['datos'] === ['criticos' => ['formulario_inscripcion', 'documento_acudiente'], 'no_criticos' => []], json_encode($j['datos']));
[$c, $j] = peticion('GET', d('faltantes', ['documento' => '1000000103']), $admin);
comprobar('mayor sin identidad, formulario ni foto -> críticos: identidad y formulario (sin acudiente); no críticos: fotografía', $c === 200
    && $j['datos'] === ['criticos' => ['documento_identidad', 'formulario_inscripcion'], 'no_criticos' => ['fotografia']], json_encode($j['datos']));
[$c, $j] = peticion('GET', d('faltantes'), $admin);
$lista = array_column($j['datos'] ?? [], null, 'documento');
$claves = array_map('strval', array_keys($lista));
sort($claves);
comprobar('sin ?documento=: solo los pendientes de validación', $c === 200 && $claves === ['1000000102', '9100000501'], json_encode($claves));
comprobar('  pendiente adulto con identidad -> críticos: formulario; no críticos: fotografía', ($lista['1000000102']['criticos'] ?? null) === ['formulario_inscripcion'] && ($lista['1000000102']['no_criticos'] ?? null) === ['fotografia'], json_encode($lista['1000000102'] ?? null));
comprobar('  pendiente menor con solo el formulario -> críticos: identidad y acudiente; no críticos: fotografía', ($lista['9100000501']['criticos'] ?? null) === ['documento_identidad', 'documento_acudiente'] && ($lista['9100000501']['no_criticos'] ?? null) === ['fotografia'], json_encode($lista['9100000501'] ?? null));
[$c] = peticion('GET', d('faltantes', ['documento' => '0000000000']), $admin);
comprobar('faltantes de deportista inexistente -> 404', $c === 404, (string) $c);

echo "\n[7. documentos generales]\n";
[$c, $j] = peticion('POST', d('subirGeneral'), $admin, ['titulo' => 'Teoría de ascenso — verde'], ['teoria_verde.pdf', $muestras['pdf']]);
$idGeneral = $j['datos']['id'] ?? 0;
comprobar('subirGeneral (admin) -> 201 con id, titulo, nombre_original y fecha_carga', $c === 201 && array_keys($j['datos']) === ['id', 'titulo', 'nombre_original', 'fecha_carga'] && $j['datos']['titulo'] === 'Teoría de ascenso — verde', $c . json_encode($j));
[$c, $j] = peticion('GET', d('listarGenerales'), $publicoMenor);
comprobar('listarGenerales para el público -> incluye el nuevo', $c === 200 && in_array($idGeneral, array_column($j['datos'], 'id'), true));
[$c, , $cuerpo, $h] = peticion('GET', d('descargarGeneral', ['id' => $idGeneral]), $publicoMenor);
comprobar('descargarGeneral para el público -> mismo contenido y nombre', $c === 200 && $cuerpo === $muestras['pdf'] && str_contains((string) cabecera($h, 'Content-Disposition'), 'teoria_verde.pdf'));
[$c] = peticion('POST', d('subirGeneral'), $publicoMenor, ['titulo' => 'X'], ['x.pdf', $muestras['pdf']]);
comprobar('subirGeneral público -> 403', $c === 403, (string) $c);
[$c] = peticion('POST', d('reemplazarGeneral', ['id' => $idGeneral]), $publicoMenor, [], ['x.pdf', $muestras['pdf']]);
comprobar('reemplazarGeneral público -> 403', $c === 403, (string) $c);
[$c] = peticion('DELETE', d('eliminarGeneral', ['id' => $idGeneral]), $publicoMenor);
comprobar('eliminarGeneral público -> 403', $c === 403, (string) $c);
[$c] = peticion('GET', d('listarGenerales'));
comprobar('listarGenerales sin sesión -> 401', $c === 401, (string) $c);
[$c] = peticion('POST', d('subirGeneral'), $admin, [], ['x.pdf', $muestras['pdf']]);
comprobar('subirGeneral sin titulo -> 400', $c === 400, (string) $c);
[$c] = peticion('POST', d('subirGeneral'), $admin, ['titulo' => str_repeat('t', 151)], ['x.pdf', $muestras['pdf']]);
comprobar('subirGeneral con titulo de 151 caracteres -> 400', $c === 400, (string) $c);
[$c] = peticion('POST', d('subirGeneral'), $admin, ['titulo' => 'Malo'], ['notas.txt', "texto\n"]);
comprobar('subirGeneral con .txt -> 400', $c === 400, (string) $c);
$viejoGeneral = $bd->query("SELECT archivo FROM documento_general WHERE id = {$idGeneral}")->fetchColumn();
[$c, $j] = peticion('POST', d('reemplazarGeneral', ['id' => $idGeneral]), $admin, ['titulo' => 'Teoría verde (2026)'], ['teoria_verde.xlsx', $muestras['xlsx']]);
$nuevoGeneral = $bd->query("SELECT archivo FROM documento_general WHERE id = {$idGeneral}")->fetchColumn();
clearstatcache();
comprobar('reemplazarGeneral -> 200, título y archivo nuevos, el anterior borrado del disco', $c === 200 && $j['datos']['titulo'] === 'Teoría verde (2026)'
    && !is_file("{$dirGen}/{$viejoGeneral}") && is_file("{$dirGen}/{$nuevoGeneral}"), $c . json_encode($j));
[$c] = peticion('DELETE', d('eliminarGeneral', ['id' => $idGeneral]), $admin);
clearstatcache();
comprobar('eliminarGeneral -> 200, borra fila y archivo', $c === 200 && !is_file("{$dirGen}/{$nuevoGeneral}")
    && $bd->query("SELECT COUNT(*) FROM documento_general WHERE id = {$idGeneral}")->fetchColumn() == 0, (string) $c);

echo "\n[8. nombres con tildes y caracteres raros]\n";
$raro = "Cédula Ñandú (copia) #1 'año' – ok 😀.pdf";
[$c, $j] = peticion('POST', d('subir'), $admin, ['documento' => '9100000501', 'tipo_documento' => 'documento_identidad'], [$raro, $muestras['pdf']]);
$idRaro = $j['datos']['id'] ?? 0;
comprobar('nombre con tildes, ñ, emoji y símbolos se guarda tal cual', $c === 201 && $j['datos']['nombre_original'] === $raro, $c . json_encode($j['datos']['nombre_original'] ?? null));
[$c, , , $h] = peticion('GET', d('descargar', ['id' => $idRaro]), $admin);
$disposicion = (string) cabecera($h, 'Content-Disposition');
preg_match("/filename\\*=UTF-8''(\\S+)/", $disposicion, $m);
comprobar('la descarga conserva el nombre exacto en filename* (UTF-8)', $c === 200 && rawurldecode($m[1] ?? '') === $raro, $disposicion);
comprobar('y trae un filename ASCII de respaldo sin comillas ni rutas', preg_match('/filename="[A-Za-z0-9._-]+"/', $disposicion) === 1, $disposicion);
$largo = str_repeat('n', 200) . '.pdf';
[$c, $j] = peticion('POST', d('subir'), $admin, ['documento' => '9100000501', 'tipo_documento' => 'otro'], [$largo, $muestras['pdf']]);
comprobar('nombre de 204 caracteres -> se recorta a 150 conservando ".pdf"', $c === 201 && mb_strlen($j['datos']['nombre_original']) === 150 && str_ends_with($j['datos']['nombre_original'], '.pdf'), $c . ' ' . mb_strlen($j['datos']['nombre_original'] ?? ''));

echo "\n[Limpieza y seguridad]\n";
$nombresDisco = array_merge(
    $bd->query('SELECT archivo FROM documento')->fetchAll(PDO::FETCH_COLUMN),
    $bd->query('SELECT archivo FROM documento_general')->fetchAll(PDO::FETCH_COLUMN)
);
$expuestos = array_filter($respuestas, function ($r) use ($nombresDisco) {
    foreach ($nombresDisco as $n) { if (str_contains($r, $n)) { return true; } }
    return preg_match('/[0-9a-f]{32}\.(pdf|jpe?g|png|webp|docx?|xlsx?)/', $r) === 1;
});
comprobar('ninguna de las ' . count($respuestas) . ' respuestas expone un nombre de archivo en disco', $expuestos === [], implode("\n---\n", array_slice($expuestos, 0, 2)));
// Borra por la API todo lo que creó la prueba.
foreach ($bd->query("SELECT id FROM documento WHERE id > 3")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    peticion('DELETE', d('eliminar', ['id' => $id]), $admin);
}
comprobar('después de eliminar por la API, Almacenamiento/documentos y generales quedan vacíos', archivosEn($dirDocs) === [] && archivosEn($dirGen) === [], json_encode([archivosEn($dirDocs), archivosEn($dirGen)]));
array_map('unlink', glob($carpetaTmp . '/*'));
@rmdir($carpetaTmp);

echo "\n[tipos MIME de contenedores OLE (libmagic nuevo)]\n";
// El libmagic de PHP 8.3+ reporta los .doc/.xls antiguos como application/x-ole-storage
// (el de 8.2, como application/CDFV2). Se prueba la lista directamente: el MIME detectado
// depende de la versión instalada, así que no se puede forzar con un archivo.
require_once $backend . '/Src/Utils/ExcepcionNegocio.php';
require_once $backend . '/Src/Utils/ArchivoSubido.php';
comprobar('doc y xls aceptan application/x-ole-storage', ArchivoSubido::tipoAceptado('doc', 'application/x-ole-storage') && ArchivoSubido::tipoAceptado('xls', 'application/x-ole-storage'));
comprobar('doc y xls siguen aceptando application/CDFV2 (libmagic anterior)', ArchivoSubido::tipoAceptado('doc', 'application/CDFV2') && ArchivoSubido::tipoAceptado('xls', 'application/CDFV2'));
comprobar('x-ole-storage NO vale para pdf, docx ni imágenes', !ArchivoSubido::tipoAceptado('pdf', 'application/x-ole-storage') && !ArchivoSubido::tipoAceptado('docx', 'application/x-ole-storage') && !ArchivoSubido::tipoAceptado('jpg', 'application/x-ole-storage'));

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
