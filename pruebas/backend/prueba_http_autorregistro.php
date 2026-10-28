<?php

declare(strict_types=1);

// Bateria HTTP del cierre de Deportistas: autorregistro (RN-T.14) y carga masiva (RN-1.7/1.9/1.10).
// Uso: php prueba_http_autorregistro.php <url base> <ruta backend>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recien cargados. Solo datos ficticios.

$base = rtrim($argv[1], '/');
$backend = rtrim($argv[2], '/');
date_default_timezone_set('America/Bogota');
$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$bd->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$hoy = new DateTimeImmutable('today');
$H = $hoy->format('Y-m-d');
$haceAnios = fn (int $anios): string => $hoy->modify("-{$anios} years -20 days")->format('Y-m-d');
putenv('PYTHONIOENCODING=utf-8');

$ok = 0; $fallos = []; $crudos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} " . substr($detalle, 0, 700) . "\n"; }
}

/** Navegador mínimo: tarro de cookies propio por instancia. */
final class Navegador
{
    public array $cookies = [];
    public function cookie(): string
    {
        return implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($this->cookies), $this->cookies));
    }
    /** @return array{0: int, 1: ?array, 2: string} */
    public function pedir(string $metodo, string $url, ?string $cuerpo = null, string $tipo = 'application/json'): array
    {
        global $crudos;
        $cabeceras = ($cuerpo !== null ? "Content-Type: {$tipo}\r\n" : '') . ($this->cookies ? 'Cookie: ' . $this->cookie() . "\r\n" : '');
        $c = stream_context_create(['http' => ['method' => $metodo, 'header' => $cabeceras, 'ignore_errors' => true, 'content' => $cuerpo ?? '', 'timeout' => 300]]);
        $texto = (string) file_get_contents($url, false, $c);
        preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $m);
        foreach ($http_response_header as $h) {
            if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)(.*)$/i', $h, $mc)) {
                if ($mc[2] === '' || $mc[2] === 'deleted' || stripos($mc[3], 'expires=Thu, 01') !== false || preg_match('/expires=([^;]+)/i', $mc[3], $e) && strtotime($e[1]) < time()) {
                    unset($this->cookies[$mc[1]]);
                } else {
                    $this->cookies[$mc[1]] = $mc[2];
                }
            }
        }
        $crudos[] = $texto;
        return [(int) $m[1], json_decode($texto, true), $texto];
    }
    public function json(string $metodo, string $url, mixed $datos): array
    {
        return $this->pedir($metodo, $url, is_string($datos) ? $datos : json_encode($datos));
    }
    /** @param array<string, array{0: string, 1: string}> $archivos campo => [nombre, contenido] */
    public function multipart(string $url, array $campos, array $archivos, string $metodo = 'POST'): array
    {
        $limite = '----prueba' . bin2hex(random_bytes(8));
        $cuerpo = '';
        foreach ($campos as $k => $v) {
            foreach ((array) $v as $valor) {
                $nombre = is_array($v) ? "{$k}[]" : $k;
                $cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"{$nombre}\"\r\n\r\n{$valor}\r\n";
            }
        }
        foreach ($archivos as $k => [$nombreArchivo, $contenido]) {
            $cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"{$k}\"; filename=\"{$nombreArchivo}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$contenido}\r\n";
        }
        $cuerpo .= "--{$limite}--\r\n";
        return $this->pedir($metodo, $url, $cuerpo, "multipart/form-data; boundary={$limite}");
    }
    public function sesionId(): ?string
    {
        return $this->cookies['HWANGHON_SESION'] ?? null;
    }
}
function u(string $recurso, string $accion, array $q = []): string
{
    global $base;
    return "{$base}/api.php?recurso={$recurso}&accion={$accion}" . ($q ? '&' . http_build_query($q) : '');
}
function ar(string $accion, array $q = []): string { return u('autorregistro', $accion, $q); }
function im(string $accion): string { return u('importacion-deportistas', $accion); }
function cuenta(string $sql): int { global $bd; return (int) $bd->query($sql)->fetchColumn(); }
function comSelect(): int { global $bd; return (int) $bd->query("SHOW GLOBAL STATUS LIKE 'Com_select'")->fetch()['Value']; }
function reiniciarLimite(): void { global $bd; $bd->exec("DELETE FROM intento_acceso WHERE identificador LIKE 'autorregistro-ip:%'"); }
function formulario(string $documento, array $extra = []): array
{
    return array_merge(['documento' => $documento, 'nombre' => "Registro Ficticio {$documento}", 'fecha_nacimiento' => '1995-05-05', 'sexo' => 'femenino',
        'estatura' => 1.62, 'peso' => 55, 'autorizacion_datos' => true], $extra);
}
function sinFecha(?array $j): ?array
{
    if (isset($j['datos']['documentos']['vigente_hasta'])) { $j['datos']['documentos']['vigente_hasta'] = 'X'; }
    return $j;
}
const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

$admin = new Navegador();
$admin->json('POST', u('auth', 'iniciarSesionAdministrativa'), ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']);
$publico = new Navegador();
$publico->json('POST', u('auth', 'iniciarSesionPublica'), ['documento' => '1000000101', 'fecha_nacimiento' => '2014-03-12']);
comprobar('sesiones administrativa y pública de referencia', $admin->sesionId() !== null && $publico->sesionId() !== null);
reiniciarLimite();

// ==================================================================
echo "\n[A) autorregistro: método, cuerpo y campos]\n";
$x = new Navegador();
[$c] = $x->pedir('GET', ar('registrar'));
comprobar('GET registrar -> 405', $c === 405, (string) $c);
[$c] = $x->pedir('GET', ar('otraCosa'));
comprobar('acción no expuesta -> 404', $c === 404, (string) $c);
foreach (['vacío' => '', 'JSON mal formado' => '{"documento":', 'lista JSON' => '[1,2]', 'texto' => 'hola'] as $n => $cuerpo) {
    [$c] = $x->pedir('POST', ar('registrar'), $cuerpo);
    comprobar("cuerpo {$n} -> 400", $c === 400, (string) $c);
}
foreach (['documento', 'nombre', 'fecha_nacimiento', 'sexo', 'estatura', 'peso'] as $campo) {
    $f = formulario('9600000001'); unset($f[$campo]);
    [$c, $j] = $x->json('POST', ar('registrar'), $f);
    comprobar("sin {$campo} -> 400 nombrando el campo", $c === 400 && str_contains($j['mensaje'] ?? '', $campo), $c . json_encode($j));
}
[$c, $j] = $x->json('POST', ar('registrar'), formulario('9600000001', ['autorizacion_datos' => false]));
comprobar('autorizacion_datos false -> 400', $c === 400 && str_contains($j['mensaje'], 'autorización'), $c . json_encode($j));
$f = formulario('9600000001'); unset($f['autorizacion_datos']);
[$c] = $x->json('POST', ar('registrar'), $f);
comprobar('sin autorizacion_datos -> 400', $c === 400, (string) $c);
[$c, $j] = $x->json('POST', ar('registrar'), formulario('9600000001', ['sexo' => 'otro']));
comprobar('sexo inválido -> 400 (regla de crear)', $c === 400 && str_contains($j['mensaje'], 'sexo'), json_encode($j));
[$c, $j] = $x->json('POST', ar('registrar'), formulario('9600000001', ['campo_raro' => 1]));
comprobar('campo desconocido -> 400', $c === 400 && str_contains($j['mensaje'], 'campo_raro'), json_encode($j));
$menor = formulario('9600000002', ['fecha_nacimiento' => $haceAnios(10)]);
[$c, $j] = $x->json('POST', ar('registrar'), $menor);
[$cA, $jA] = $admin->json('POST', u('deportistas', 'crear'), $menor);
comprobar('menor sin acudiente -> mismo error que crear del administrativo', $c === 400 && $c === $cA && $j['mensaje'] === $jA['mensaje'], json_encode([$j, $jA]));
[$c, $j] = $x->json('POST', ar('registrar'), formulario('9600000002', ['fecha_nacimiento' => $haceAnios(10), 'acudiente' => ['documento' => '1000000001']]));
[$c2, $j2] = $x->json('POST', ar('registrar'), formulario('9600000002', ['fecha_nacimiento' => $haceAnios(10), 'acudiente' => ['documento' => '9699999999']]));
comprobar('acudiente sin nombre: mismo 400 exista o no (no revela acudientes)', $c === 400 && $c2 === 400 && $j['mensaje'] === $j2['mensaje'], json_encode([$j, $j2]));
[$c] = $x->pedir('POST', ar('registrar'), json_encode(formulario('9600000001', ['experiencia_previa' => str_repeat('a', 140000)])));
comprobar('cuerpo de más de 128 KB -> 413', $c === 413, (string) $c);
comprobar('ninguno de los intentos inválidos creó deportistas', cuenta("SELECT COUNT(*) FROM deportista WHERE documento LIKE '96%'") === 0);

// ==================================================================
echo "\n[A) autorregistro: registro, campos forzados y respuesta]\n";
reiniciarLimite();
$r1 = new Navegador();
$antes = cuenta('SELECT COUNT(*) FROM deportista');
[$c, $jReal] = $r1->json('POST', ar('registrar'), formulario('9600000010', [
    'fecha_nacimiento' => $haceAnios(12), 'nombre' => 'Menor Ñandú Registro',
    'acudiente' => ['documento' => '9600000910', 'nombre' => 'Acudiente Registro Ficticio', 'relacion' => 'madre', 'correo' => 'familia@correoficticio.test'],
    'estado' => 'inactivo', 'estado_registro' => 'validado', 'validado_por_correo' => 'intruso@x.test', 'validado_por' => 'intruso', 'grado_actual' => 'Negro', 'fecha_ingreso' => '2020-01-01',
]));
$fila = $bd->query("SELECT * FROM deportista WHERE documento = '9600000010'")->fetch();
comprobar('registrar -> 200 y crea UN deportista', $c === 200 && cuenta('SELECT COUNT(*) FROM deportista') === $antes + 1, $c . json_encode($jReal));
comprobar('campos forzados por el servidor: pendiente, activo, sin validador, sin grado, ingreso hoy', $fila && $fila['estado_registro'] === 'pendiente_validacion' && $fila['estado'] === 'activo'
    && $fila['validado_por_correo'] === null && $fila['grado_actual'] === null && $fila['fecha_ingreso'] === $H, json_encode($fila));
comprobar('acudiente nuevo creado con el registro', cuenta("SELECT COUNT(*) FROM acudiente_tutor WHERE documento = '9600000910'") === 1 && $fila['acudiente_documento'] === '9600000910');
comprobar('respuesta mínima: solo mensaje y estado de documentos (sin datos ni ids)', array_keys($jReal['datos']) === ['mensaje', 'documentos']
    && array_keys($jReal['datos']['documentos']) === ['carga_habilitada', 'vigente_hasta', 'tipos_permitidos', 'cargados'] && !str_contains(json_encode($jReal), '9600000010'), json_encode($jReal));
comprobar('vigencia del permiso: 2 horas', abs(strtotime($jReal['datos']['documentos']['vigente_hasta']) - (time() + 7200)) <= 5, $jReal['datos']['documentos']['vigente_hasta']);
$hermano = new Navegador();
[$c] = $hermano->json('POST', ar('registrar'), formulario('9600000011', ['fecha_nacimiento' => $haceAnios(9), 'correo' => 'familia@correoficticio.test',
    'acudiente' => ['documento' => '9600000910', 'nombre' => 'Acudiente Registro Ficticio', 'relacion' => 'madre', 'correo' => 'familia@correoficticio.test']]));
comprobar('hermano con el mismo acudiente y el mismo correo -> registrado (correo compartible)', $c === 200 && cuenta("SELECT COUNT(*) FROM deportista WHERE acudiente_documento = '9600000910'") === 2);

echo "\n[A) anti-spam: trampa, duplicado y límite por IP]\n";
$bot = new Navegador();
$antes = cuenta('SELECT COUNT(*) FROM deportista');
[$c, $jBot] = $bot->json('POST', ar('registrar'), formulario('9600000020', ['sitio_web' => 'http://spam.example']));
comprobar('campo trampa con valor -> 200 idéntico a un registro exitoso, sin crear nada', $c === 200 && sinFecha($jBot) === sinFecha($jReal) && cuenta('SELECT COUNT(*) FROM deportista') === $antes, json_encode($jBot));
comprobar('la respuesta trampa también deja cookie de sesión (indistinguible)', $bot->sesionId() !== null);
[$c] = $bot->multipart(ar('subirDocumento'), ['tipo_documento' => 'documento_identidad'], ['archivo' => ['id.pdf', PDF]]);
comprobar('el bot no puede subir documentos (permiso vacío) -> 403', $c === 403, (string) $c);
$dup = new Navegador();
$samuelAntes = $bd->query("SELECT * FROM deportista WHERE documento = '1000000101'")->fetch();
[$c, $jDup] = $dup->json('POST', ar('registrar'), formulario('1000000101', ['nombre' => 'Intento Suplantar']));
comprobar('documento ya registrado -> misma respuesta 200 (no revela que existe)', $c === 200 && sinFecha($jDup) === sinFecha($jReal), json_encode($jDup));
comprobar('el deportista existente no cambió', $bd->query("SELECT * FROM deportista WHERE documento = '1000000101'")->fetch() === $samuelAntes);
[$c] = $dup->multipart(ar('subirDocumento'), ['tipo_documento' => 'fotografia'], ['archivo' => ['f.pdf', PDF]]);
comprobar('con el documento ajeno no se pueden tocar sus documentos -> 403', $c === 403, (string) $c);
[$c, $j] = $dup->pedir('GET', ar('listarDocumentos'));
comprobar('ni listarlos -> 403', $c === 403, (string) $c);
$fila = $bd->query("SELECT intentos_fallidos, bloqueado_hasta FROM intento_acceso WHERE ambito = 'publica' AND identificador = 'autorregistro-ip:127.0.0.1'")->fetch();
comprobar('contador por IP en intento_acceso (ámbito publica, identificador con prefijo), 4 intentos válidos hasta ahora (registro, hermano, trampa, duplicado)', $fila && (int) $fila['intentos_fallidos'] === 4 && $fila['bloqueado_hasta'] > date('Y-m-d H:i:s'), json_encode($fila));
$bd->exec("UPDATE intento_acceso SET intentos_fallidos = 9 WHERE identificador = 'autorregistro-ip:127.0.0.1'");
[$c] = (new Navegador())->json('POST', ar('registrar'), formulario('9600000030'));
comprobar('intento 10 de la ventana -> 200', $c === 200, (string) $c);
[$c, $j] = (new Navegador())->json('POST', ar('registrar'), formulario('9600000031'));
comprobar('intento 11 -> 429 sin crear', $c === 429 && cuenta("SELECT COUNT(*) FROM deportista WHERE documento = '9600000031'") === 0, $c . json_encode($j));
[$c] = (new Navegador())->json('POST', ar('registrar'), formulario('9600000032', ['sitio_web' => 'x']));
comprobar('la trampa también respeta el límite (429)', $c === 429, (string) $c);
[$c] = (new Navegador())->json('POST', ar('registrar'), formulario('9600000033', ['sexo' => 'x']));
comprobar('un formulario inválido responde 400 sin gastar cupo (no cuenta)', $c === 400, (string) $c);
$bd->exec("UPDATE intento_acceso SET bloqueado_hasta = '2000-01-01 00:00:00' WHERE identificador = 'autorregistro-ip:127.0.0.1'");
[$c] = (new Navegador())->json('POST', ar('registrar'), formulario('9600000034'));
comprobar('al terminar la ventana se reinicia el cupo -> 200', $c === 200 && (int) $bd->query("SELECT intentos_fallidos FROM intento_acceso WHERE identificador = 'autorregistro-ip:127.0.0.1'")->fetchColumn() === 1, (string) $c);
[$c, $j] = (new Navegador())->json('POST', u('auth', 'iniciarSesionPublica'), ['documento' => 'autorregistro-ip:127.0.0.1', 'fecha_nacimiento' => '2000-01-01']);
comprobar('el identificador del contador no es usable como documento de login (más de 20 caracteres) -> 400', $c === 400, (string) $c);
reiniciarLimite();

// ==================================================================
echo "\n[A) documentos del registro]\n";
[$c] = (new Navegador())->multipart(ar('subirDocumento'), ['tipo_documento' => 'documento_identidad'], ['archivo' => ['id.pdf', PDF]]);
comprobar('subir sin haberse registrado -> 403', $c === 403, (string) $c);
[$c] = $admin->multipart(ar('subirDocumento'), ['tipo_documento' => 'documento_identidad'], ['archivo' => ['id.pdf', PDF]]);
comprobar('subir con sesión administrativa pero sin permiso de registro -> 403', $c === 403, (string) $c);
[$c] = $publico->multipart(ar('subirDocumento'), ['tipo_documento' => 'documento_identidad'], ['archivo' => ['id.pdf', PDF]]);
comprobar('subir con cuenta pública sin permiso de registro -> 403', $c === 403, (string) $c);
[$c, $j] = $r1->multipart(ar('subirDocumento'), ['tipo_documento' => 'documento_identidad', 'documento' => '1000000101'], ['archivo' => ['id.pdf', PDF]]);
comprobar('subir indicando un documento ajeno -> 403', $c === 403, $c . json_encode($j));
[$c] = $r1->multipart(ar('subirDocumento'), ['tipo_documento' => 'consentimiento'], ['archivo' => ['c.pdf', PDF]]);
comprobar('tipo que solo carga el club (consentimiento) -> 403', $c === 403, (string) $c);
[$c] = $r1->multipart(ar('subirDocumento'), ['tipo_documento' => 'documento_identidad'], ['archivo' => ['virus.exe', 'MZ' . str_repeat("\0", 100)]]);
comprobar('formato no permitido (.exe) -> 400', $c === 400, (string) $c);
[$c] = $r1->multipart(ar('subirDocumento'), ['tipo_documento' => 'documento_identidad'], ['archivo' => ['falso.pdf', 'esto no es un pdf']]);
comprobar('extensión .pdf con contenido que no es PDF -> 400', $c === 400, (string) $c);
[$c] = $r1->multipart(ar('subirDocumento'), ['tipo_documento' => 'documento_identidad'], ['archivo' => ['grande.pdf', PDF . str_repeat('A', 10 * 1024 * 1024)]]);
comprobar('archivo de más de 10 MB -> 400 (tope de DocumentoService)', $c === 400, (string) $c);
[$c, $j] = $r1->multipart(ar('subirDocumento'), ['tipo_documento' => 'documento_identidad'], ['archivo' => ['identidad Ñ.pdf', PDF]]);
$idDoc = $j['datos']['id'] ?? 0;
comprobar('subir identidad propia -> 201', $c === 201 && $j['datos']['deportista_documento'] === '9600000010' && $j['datos']['nombre_original'] === 'identidad Ñ.pdf', $c . json_encode($j));
comprobar('queda en la misma tabla/campo que los documentos del club (RN-6.7)', cuenta("SELECT COUNT(*) FROM documento WHERE id = {$idDoc} AND deportista_documento = '9600000010' AND tipo_documento = 'documento_identidad'") === 1);
[$c] = $r1->multipart(ar('subirDocumento'), ['tipo_documento' => 'documento_identidad'], ['archivo' => ['otra.pdf', PDF]]);
comprobar('mismo tipo otra vez -> 409 (usar reemplazar)', $c === 409, (string) $c);
[$c, $j] = $r1->multipart(ar('reemplazarDocumento', ['id' => $idDoc]), [], ['archivo' => ['identidad_v2.pdf', PDF]]);
comprobar('reemplazar el propio -> 200 con el nombre nuevo', $c === 200 && $j['datos']['nombre_original'] === 'identidad_v2.pdf', $c . json_encode($j));
[$c] = $r1->multipart(ar('reemplazarDocumento', ['id' => 1]), [], ['archivo' => ['x.pdf', PDF]]);
comprobar('reemplazar un documento ajeno (de la semilla) -> 404', $c === 404, (string) $c);
[$c] = $r1->pedir('DELETE', ar('eliminarDocumento', ['id' => 1]));
comprobar('eliminar un documento ajeno -> 404 y no se borra', $c === 404 && cuenta('SELECT COUNT(*) FROM documento WHERE id = 1') === 1, (string) $c);
[$c, $j] = $r1->multipart(ar('subirDocumento'), ['tipo_documento' => 'fotografia'], ['archivo' => ['foto.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')]]);
$idFoto = $j['datos']['id'] ?? 0;
comprobar('subir fotografía (imagen) -> 201', $c === 201, $c . json_encode($j));
[$c, $j] = $r1->pedir('DELETE', ar('eliminarDocumento', ['id' => $idFoto]));
comprobar('eliminar el propio -> 200', $c === 200 && cuenta("SELECT COUNT(*) FROM documento WHERE id = {$idFoto}") === 0, (string) $c);
[$c, $j] = $r1->pedir('GET', ar('listarDocumentos'));
comprobar('listarDocumentos: solo los suyos (1)', $c === 200 && count($j['datos']['documentos']) === 1 && $j['datos']['documentos'][0]['deportista_documento'] === '9600000010', json_encode($j));
[$c] = $r1->pedir('POST', ar('listarDocumentos'));
comprobar('listarDocumentos con POST -> 405', $c === 405, (string) $c);

echo "\n[A) privacidad y validación por el administrativo]\n";
[$c, $j] = (new Navegador())->json('POST', u('auth', 'iniciarSesionPublica'), ['documento' => '9600000010', 'fecha_nacimiento' => $haceAnios(12)]);
comprobar('el registro pendiente no entra por la cuenta pública -> 403 «Tu registro está en revisión.»', $c === 403 && $j['mensaje'] === 'Tu registro está en revisión.', $c . json_encode($j));
[$c] = (new Navegador())->pedir('GET', u('deportistas', 'listar'));
comprobar('anónimo no lista deportistas -> 401', $c === 401, (string) $c);
[$c] = (new Navegador())->pedir('GET', u('deportistas', 'consultar', ['documento' => '9600000010']));
comprobar('anónimo no consulta deportistas -> 401', $c === 401, (string) $c);
[$c] = $r1->pedir('GET', u('deportistas', 'listar'));
comprobar('el registrante tampoco: su permiso no es una cuenta -> 401', $c === 401, (string) $c);
[$c] = $r1->pedir('GET', u('documentos', 'listar', ['documento' => '9600000010']));
comprobar('ni puede usar el recurso documentos -> 401', $c === 401, (string) $c);
[$c] = $r1->pedir('GET', ar('listarDocumentos'));
comprobar('y su permiso sigue vivo después de esos 401', $c === 200, (string) $c);
[$c, $j] = $publico->pedir('GET', u('deportistas', 'consultar', ['documento' => '9600000010']));
comprobar('una cuenta pública no ve el registro pendiente -> 404', $c === 404, (string) $c);
[$c] = $publico->pedir('GET', u('documentos', 'listar', ['documento' => '9600000010']));
comprobar('ni sus documentos -> 404', $c === 404, (string) $c);
[$c, $j] = $publico->pedir('GET', u('deportistas', 'listar'));
comprobar('la cuenta pública solo se lista a sí misma', $c === 200 && array_column($j['datos'], 'documento') === ['1000000101'], json_encode(array_column($j['datos'] ?? [], 'documento')));
[$c, $j] = $admin->pedir('GET', u('deportistas', 'listar', ['estado_registro' => 'pendiente_validacion']));
comprobar('el administrativo ve el registro en la lista de pendientes', $c === 200 && in_array('9600000010', array_column($j['datos'], 'documento'), true));
[$c, $j] = $admin->pedir('GET', u('documentos', 'listar', ['documento' => '9600000010']));
comprobar('y ve sus documentos', $c === 200 && count($j['datos']) === 1, json_encode($j));
[$c, $j] = $admin->json('PUT', u('deportistas', 'actualizar', ['documento' => '9600000010']), ['estado_registro' => 'validado']);
comprobar('el administrativo lo valida (queda su correo como validador)', $c === 200 && $bd->query("SELECT validado_por_correo FROM deportista WHERE documento = '9600000010'")->fetchColumn() === 'maestro@hwanghon.local', $c . json_encode($j));
[$c] = $r1->multipart(ar('subirDocumento'), ['tipo_documento' => 'fotografia'], ['archivo' => ['foto.pdf', PDF]]);
comprobar('ya validado: el registrante no puede tocar documentos -> 403', $c === 403, (string) $c);
[$c] = $r1->pedir('GET', ar('listarDocumentos'));
comprobar('y el permiso quedó cerrado', $c === 403, (string) $c);
[$c] = $admin->pedir('DELETE', u('deportistas', 'eliminar', ['documento' => '9600000011']));
comprobar('el administrativo puede eliminar un pendiente (no hay «rechazado»)', $c === 200 && cuenta("SELECT COUNT(*) FROM deportista WHERE documento = '9600000011'") === 0, (string) $c);

echo "\n[A) confirmar y vencimiento]\n";
$r2 = new Navegador();
$r2->json('POST', ar('registrar'), formulario('9600000040'));
[$c] = $r2->multipart(ar('subirDocumento'), ['tipo_documento' => 'formulario_inscripcion'], ['archivo' => ['form.pdf', PDF]]);
comprobar('registro 2: sube formulario -> 201', $c === 201, (string) $c);
[$c, $j] = $r2->pedir('POST', ar('confirmar'));
comprobar('confirmar -> 200', $c === 200 && str_contains($j['datos']['mensaje'], 'club'), json_encode($j));
foreach (['subirDocumento' => fn () => $r2->multipart(ar('subirDocumento'), ['tipo_documento' => 'fotografia'], ['archivo' => ['f.pdf', PDF]]),
    'listarDocumentos' => fn () => $r2->pedir('GET', ar('listarDocumentos')), 'confirmar otra vez' => fn () => $r2->pedir('POST', ar('confirmar'))] as $n => $op) {
    [$c] = $op();
    comprobar("tras confirmar: {$n} -> 403", $c === 403, (string) $c);
}
[$c] = $r2->pedir('GET', ar('confirmar'));
comprobar('confirmar con GET -> 405', $c === 405, (string) $c);
$r3 = new Navegador();
$r3->json('POST', ar('registrar'), formulario('9600000041'));
$archivoSesion = $backend . '/Almacenamiento/sesiones/sess_' . $r3->sesionId();
$contenido = (string) @file_get_contents($archivoSesion);
file_put_contents($archivoSesion, (string) preg_replace('/s:6:"expira";i:\d+;/', 's:6:"expira";i:1;', $contenido));
[$c] = $r3->multipart(ar('subirDocumento'), ['tipo_documento' => 'fotografia'], ['archivo' => ['f.pdf', PDF]]);
comprobar('permiso vencido (2 h simuladas en el archivo de sesión) -> 403', $contenido !== '' && $c === 403, $c . ' ' . strlen($contenido));
[$c] = $r3->pedir('GET', ar('listarDocumentos'));
comprobar('y no revive', $c === 403, (string) $c);

// ==================================================================
echo "\n[B) importar: permisos y archivo]\n";
$csvBase = "\xEF\xBB\xBFdocumento;nombre;fecha_nacimiento;sexo;estatura;peso\n9500000001;Prueba Uno;01/01/1990;M;1,70;70\n";
[$c] = (new Navegador())->multipart(im('previsualizar'), [], ['archivo' => ['a.csv', $csvBase]]);
comprobar('anónimo -> 401', $c === 401, (string) $c);
[$c] = $publico->multipart(im('previsualizar'), [], ['archivo' => ['a.csv', $csvBase]]);
comprobar('cuenta pública -> 403', $c === 403, (string) $c);
[$c] = $r1->multipart(im('previsualizar'), [], ['archivo' => ['a.csv', $csvBase]]);
comprobar('sesión de autorregistro -> 401', $c === 401, (string) $c);
[$c] = $admin->pedir('GET', im('previsualizar'));
comprobar('GET previsualizar -> 405', $c === 405, (string) $c);
[$c] = $admin->pedir('GET', im('aplicar'));
comprobar('GET aplicar -> 405', $c === 405, (string) $c);
[$c] = $admin->multipart(im('otra'), [], []);
comprobar('acción desconocida -> 404', $c === 404, (string) $c);
$zip = "PK\x03\x04" . str_repeat("\0", 26) . 'xl/workbook.xml' . str_repeat('x', 200);
foreach (['sin archivo' => [[], 400], '.xlsx subido como .csv' => [['archivo' => ['libro.csv', $zip]], 400], 'extensión .txt' => [['archivo' => ['a.txt', $csvBase]], 400],
    'archivo vacío' => [['archivo' => ['vacio.csv', '']], 400], 'solo encabezado' => [['archivo' => ['e.csv', "documento;nombre\n"]], 400],
    'sin columna documento' => [['archivo' => ['s.csv', "nombre;peso\nAna;50\n"]], 400], 'solo filas en blanco' => [['archivo' => ['b.csv', "documento;nombre\n;\n\n  \n"]], 400],
    'más de 1 MB' => [['archivo' => ['g.csv', "documento;nombre\n" . str_repeat("9500000002;Nombre largo\n", 50000)]], 413]] as $n => [$archivos, $esperado]) {
    [$c, $j, $t] = $admin->multipart(im('previsualizar'), [], $archivos);
    comprobar("{$n} -> {$esperado}", $c === $esperado && ($j['exito'] ?? null) === false, $c . ' ' . substr($t, 0, 160));
}
$mil = "documento;nombre;fecha_nacimiento;sexo;estatura;peso\n";
for ($i = 0; $i < 1001; $i++) { $mil .= sprintf("95%08d;Masivo %d;1990-01-01;femenino;1.6;55\n", $i + 1000, $i); }
[$c] = $admin->multipart(im('previsualizar'), [], ['archivo' => ['mil.csv', $mil]]);
comprobar('1.001 filas -> 422', $c === 422, (string) $c);

echo "\n[B) importar: clasificación (UTF-8 con BOM y punto y coma)]\n";
// Semilla: 1000000101 Samuel (menor, acudiente 1000000001), 1000000103 Fabián (adulto).
$correoFabian = $bd->query("SELECT correo FROM deportista WHERE documento = '1000000103'")->fetchColumn();
$bd->exec("UPDATE deportista SET correo = 'fabian.ficticio@correoficticio.test' WHERE documento = '1000000103'");
$csv = "\xEF\xBB\xBF Documento ; Nombre ;Fecha de Nacimiento;Sexo;Estatura;Peso;Correo;Teléfono;Experiencia previa;Autorización de datos;Acudiente documento;Acudiente nombre;Acudiente relación;Género;Grupo étnico;Columna Rara\n"
    . "9500000001;  Ñandú Peñaranda  ;15/04/1995;M;1,72;70,5;nandu@correoficticio.test;300 111 2222;\"Practicó taekwondo;\nclub \"\"Los Tigres\"\"\";sí;;;;masculino;x;y\n"
    . ";;;;;;;;;;;;;;;\n"
    . "\n"
    . "9500000002;=cmd|' /C calc'!A0;1990-02-02;F;1.60;55;;;;no;;;;;;\n"
    . "1000000103;Fabián Ficticio Editado;;;;;fabian.ficticio@correoficticio.test;;;;;;;;;\n"
    . "1000000101;;;;;;;;;;;;;;;\n"
    . "9500000003;Sin Peso;1990-03-03;F;1.5;;;;;;;;;;;\n"
    . "9500000004;Fecha Mala;31/02/2000;M;1.7;60;;;;;;;;;;\n"
    . "9500000005;Sexo Malo;1990-01-01;x;1.7;60;;;;;;;;;;\n"
    . "9500000006;Peso Enorme;1990-01-01;M;1.7;1500;;;;;;;;;;\n"
    . "9500000007;Duplicado A;1990-01-01;M;1.7;60;;;;;;;;;;\n"
    . "9500000007;Duplicado B;1990-01-01;M;1.7;61;;;;;;;;;;\n"
    . "9500000008;Adulto Correo A;1980-01-01;M;1.7;60;compartido@correoficticio.test;;;;;;;;;\n"
    . "9500000009;Adulto Correo B;1981-01-01;F;1.6;55;compartido@correoficticio.test;;;;;;;;;\n"
    . "9500000010;Adulto Choca Con Juliana;1979-01-01;M;1.7;60;juliana.castillo@correoficticio.test;;;;;;;;;\n"
    . "9500000011;Hermano Menor Uno;" . $haceAnios(10) . ";M;1.4;35;tutor@correoficticio.test;;;;9500000900;Tutor Adulto Ficticio;padre;;;\n"
    . "9500000012;Hermana Menor Dos;" . $haceAnios(8) . ";F;1.3;30;tutor@correoficticio.test;;;;9500000900;;;;;\n"
    . "9500000013;Tutor Adulto Ficticio;1975-01-01;M;1.75;80;tutor@correoficticio.test;;;;;;;;;\n"
    . "9500000014;Menor Sin Acudiente;" . $haceAnios(11) . ";M;1.4;35;;;;;;;;;;\n";
$filasAntes = [cuenta('SELECT COUNT(*) FROM deportista'), cuenta('SELECT COUNT(*) FROM acudiente_tutor'), md5(json_encode($bd->query('SELECT * FROM deportista ORDER BY documento')->fetchAll()))];
[$c, $j, $t] = $admin->multipart(im('previsualizar'), [], ['archivo' => ['carga.csv', $csv]]);
$filasDespues = [cuenta('SELECT COUNT(*) FROM deportista'), cuenta('SELECT COUNT(*) FROM acudiente_tutor'), md5(json_encode($bd->query('SELECT * FROM deportista ORDER BY documento')->fetchAll()))];
comprobar('previsualizar -> 200', $c === 200, $c . substr($t, 0, 300));
comprobar('previsualizar NO escribe nada (conteos y contenido idénticos)', $filasAntes === $filasDespues);
$p = []; foreach ($j['datos']['filas'] ?? [] as $f) { $p[$f['linea'] . ':' . $f['documento']] = $f; }
$cl = fn (string $clave) => $p[$clave]['clasificacion'] ?? null;
comprobar('detecta UTF-8 y punto y coma', $j['datos']['archivo'] === ['codificacion' => 'UTF-8', 'separador' => ';', 'filas' => 17], json_encode($j['datos']['archivo'] ?? null));
comprobar('avisos: género y grupo étnico excluidos (RN-1.6) y columna desconocida', count($j['datos']['avisos']) === 3 && str_contains($j['datos']['avisos'][0], 'Género') && str_contains($j['datos']['avisos'][0], 'RN-1.6') && str_contains($j['datos']['avisos'][2], 'Columna Rara'), json_encode($j['datos']['avisos']));
comprobar('fila 2 (ñ, espacios sobrantes, celda con comillas y salto de línea) -> nueva', $cl('2:9500000001') === 'nueva' && $p['2:9500000001']['nombre'] === 'Ñandú Peñaranda', json_encode($p['2:9500000001'] ?? null));
comprobar('las filas en blanco (incluida ";;;;") se saltan: la siguiente es la línea 6 (la celda multilínea ocupa 2-3)', $cl('6:9500000002') === 'nueva', json_encode(array_keys($p)));
comprobar('fórmula =cmd como nombre -> nueva (se guardará como texto)', $p['6:9500000002']['nombre'] === "=cmd|' /C calc'!A0");
comprobar('existente con cambios -> actualizar con campo, actual y nuevo (RN-1.9)', $cl('7:1000000103') === 'actualizar' && $p['7:1000000103']['cambios'] === [['campo' => 'nombre', 'actual' => 'Fabián Esteban Rincón Peña', 'nuevo' => 'Fabián Ficticio Editado']], json_encode($p['7:1000000103'] ?? null));
comprobar('existente con celdas vacías -> sin_cambios (vacío no borra)', $cl('8:1000000101') === 'sin_cambios', json_encode($p['8:1000000101'] ?? null));
comprobar('sin peso -> incompleta nombrando el campo', $cl('9:9500000003') === 'incompleta' && $p['9:9500000003']['faltantes'] === ['peso'], json_encode($p['9:9500000003'] ?? null));
comprobar('fecha 31/02 -> invalida', $cl('10:9500000004') === 'invalida' && str_contains($p['10:9500000004']['motivo'], 'fecha_nacimiento'), json_encode($p['10:9500000004'] ?? null));
comprobar('sexo x -> invalida', $cl('11:9500000005') === 'invalida' && str_contains($p['11:9500000005']['motivo'], 'sexo'));
comprobar('peso 1500 -> invalida (fuera de rango)', $cl('12:9500000006') === 'invalida' && str_contains($p['12:9500000006']['motivo'], 'peso'));
comprobar('documento repetido en el archivo -> ambas filas duplicada', $cl('13:9500000007') === 'duplicada' && $cl('14:9500000007') === 'duplicada' && str_contains($p['13:9500000007']['motivo'], '13, 14'), json_encode([$p['13:9500000007'] ?? null]));
comprobar('dos adultos del archivo con el mismo correo -> conflicto_correo los dos', $cl('15:9500000008') === 'conflicto_correo' && $cl('16:9500000009') === 'conflicto_correo', json_encode([$p['15:9500000008'] ?? null]));
comprobar('adulto con el correo de un adulto ya registrado -> conflicto_correo', $cl('17:9500000010') === 'conflicto_correo' && str_contains($p['17:9500000010']['motivo'], '1000000102'), json_encode($p['17:9500000010'] ?? null));
comprobar('el adulto ya registrado que el archivo actualiza con su mismo correo no choca consigo mismo', $cl('7:1000000103') === 'actualizar');
comprobar('hermanos menores con el correo del acudiente y un adulto que es su tutor -> sin conflicto (RN-1.10)', $cl('18:9500000011') === 'nueva' && $cl('19:9500000012') === 'nueva' && $cl('20:9500000013') === 'nueva', json_encode([$p['18:9500000011'] ?? null, $p['19:9500000012'] ?? null, $p['20:9500000013'] ?? null]));
comprobar('el segundo hermano no repite nombre/relación del acudiente nuevo (ya viene en la fila anterior)', $cl('19:9500000012') === 'nueva');
comprobar('menor sin acudiente -> incompleta (acudiente_documento)', $cl('21:9500000014') === 'incompleta' && $p['21:9500000014']['faltantes'] === ['acudiente_documento'], json_encode($p['21:9500000014'] ?? null));
$r = $j['datos']['resumen'];
comprobar('resumen con totales', $r === ['total' => 17, 'nueva' => 5, 'actualizar' => 1, 'sin_cambios' => 1, 'incompleta' => 2, 'invalida' => 3, 'conflicto_correo' => 3, 'duplicada' => 2], json_encode($r));

echo "\n[B) importar: aplicar]\n";
$samuel = $bd->query("SELECT * FROM deportista WHERE documento = '1000000101'")->fetch();
[$c, $j] = $admin->multipart(im('aplicar'), [], ['archivo' => ['carga.csv', $csv]]);
$res = []; foreach ($j['datos']['filas'] ?? [] as $f) { $res[$f['documento']][] = $f['resultado']; }
comprobar('aplicar sin confirmación: crea las nuevas', $c === 200 && $j['datos']['resumen']['creadas'] === 5, $c . json_encode($j['datos']['resumen'] ?? $j));
comprobar('sin confirmación NO actualiza (omitida) y el nombre sigue igual', ($res['1000000103'] ?? null) === ['omitida'] && $bd->query("SELECT nombre FROM deportista WHERE documento = '1000000103'")->fetchColumn() === 'Fabián Esteban Rincón Peña', json_encode($res['1000000103'] ?? null));
comprobar('incompletas, inválidas, duplicadas y en conflicto: rechazadas sin escribir', $j['datos']['resumen']['rechazadas'] === 10 && cuenta("SELECT COUNT(*) FROM deportista WHERE documento IN ('9500000003','9500000004','9500000005','9500000006','9500000007','9500000008','9500000009','9500000010','9500000014')") === 0, json_encode($j['datos']['resumen']));
$nuevo = $bd->query("SELECT * FROM deportista WHERE documento = '9500000001'")->fetch();
comprobar('alta por CSV: validado, validado_por_correo = quien carga, datos convertidos (fecha, decimales, sexo, sí)', $nuevo['estado_registro'] === 'validado' && $nuevo['validado_por_correo'] === 'maestro@hwanghon.local'
    && $nuevo['fecha_nacimiento'] === '1995-04-15' && (float) $nuevo['estatura'] === 1.72 && (float) $nuevo['peso'] === 70.5 && $nuevo['sexo'] === 'masculino' && (int) $nuevo['autorizacion_datos'] === 1 && $nuevo['estado'] === 'activo', json_encode($nuevo));
comprobar('celda con comillas y salto de línea guardada completa', $nuevo['experiencia_previa'] === "Practicó taekwondo;\nclub \"Los Tigres\"", json_encode($nuevo['experiencia_previa']));
comprobar('nombre "=cmd|..." guardado como texto literal', $bd->query("SELECT nombre FROM deportista WHERE documento = '9500000002'")->fetchColumn() === "=cmd|' /C calc'!A0");
comprobar('hermanos: un solo acudiente nuevo para los dos', cuenta("SELECT COUNT(*) FROM acudiente_tutor WHERE documento = '9500000900'") === 1 && cuenta("SELECT COUNT(*) FROM deportista WHERE acudiente_documento = '9500000900'") === 2);
comprobar('sin cambios: Samuel intacto', $bd->query("SELECT * FROM deportista WHERE documento = '1000000101'")->fetch() == $samuel);
$csv2 = "documento,nombre,peso,estado_registro\n1000000103,Fabián Ficticio Editado,81,pendiente_validacion\n9500000001,Ñandú Peñaranda,72,pendiente_validacion\n";
[$c, $j] = $admin->multipart(im('previsualizar'), [], ['archivo' => ['c2.csv', $csv2]]);
comprobar('coma como separador detectada; estado_registro ignorado con aviso', $c === 200 && $j['datos']['archivo']['separador'] === ',' && str_contains(implode(' ', $j['datos']['avisos']), 'lo fija el sistema'), json_encode($j['datos'] ?? $j));
[$c, $j] = $admin->multipart(im('aplicar'), ['confirmar_actualizacion' => ['1000000103']], ['archivo' => ['c2.csv', $csv2]]);
$res = array_column($j['datos']['filas'] ?? [], 'resultado', 'documento');
comprobar('aplicar con confirmación: solo se actualiza el confirmado', $c === 200 && $res === ['1000000103' => 'actualizada', '9500000001' => 'omitida'], json_encode($j));
$fab = $bd->query("SELECT * FROM deportista WHERE documento = '1000000103'")->fetch();
comprobar('actualizado: nombre y peso nuevos; documento y estado_registro intactos', $fab['nombre'] === 'Fabián Ficticio Editado' && (float) $fab['peso'] === 81.0 && $fab['estado_registro'] === 'validado', json_encode($fab));
comprobar('el no confirmado sigue con su peso', (float) $bd->query("SELECT peso FROM deportista WHERE documento = '9500000001'")->fetchColumn() === 70.5);
[$c, $j] = $admin->multipart(im('aplicar'), ['confirmar_actualizacion' => '9500000001, 1000000103'], ['archivo' => ['c2.csv', $csv2]]);
comprobar('confirmar_actualizacion también como texto separado por comas', $c === 200 && array_column($j['datos']['filas'], 'resultado', 'documento')['9500000001'] === 'actualizada', json_encode($j));

echo "\n[B) importar: Windows-1252 y coma]\n";
$csv1252 = mb_convert_encoding("documento,nombre,fecha_nacimiento,sexo,estatura,peso,dirección\n9500000020,José Muñoz Peña,1988-08-08,masculino,1.8,77,\"Calle 1, Soacha\"\n", 'Windows-1252', 'UTF-8');
[$c, $j] = $admin->multipart(im('previsualizar'), [], ['archivo' => ['w.csv', $csv1252]]);
comprobar('Windows-1252 detectado, tildes y ñ convertidas, coma dentro de comillas', $c === 200 && $j['datos']['archivo']['codificacion'] === 'Windows-1252' && $j['datos']['filas'][0]['nombre'] === 'José Muñoz Peña' && $j['datos']['filas'][0]['clasificacion'] === 'nueva', json_encode($j['datos'] ?? $j));
[$c, $j] = $admin->multipart(im('aplicar'), [], ['archivo' => ['w.csv', $csv1252]]);
comprobar('aplicado: dirección con coma guardada', $c === 200 && $bd->query("SELECT direccion FROM deportista WHERE documento = '9500000020'")->fetchColumn() === 'Calle 1, Soacha');

echo "\n[B) importar: falla aislada por fila]\n";
$bd->exec("CREATE TRIGGER prueba_falla_importacion BEFORE INSERT ON deportista FOR EACH ROW BEGIN IF NEW.nombre = 'FALLA FORZADA' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla forzada'; END IF; END");
$csvF = "documento;nombre;fecha_nacimiento;sexo;estatura;peso\n9500000030;Antes De La Falla;1990-01-01;F;1.6;55\n9500000031;FALLA FORZADA;1990-01-01;F;1.6;55\n9500000032;Despues De La Falla;1990-01-01;F;1.6;55\n";
[$c, $j, $t] = $admin->multipart(im('aplicar'), [], ['archivo' => ['f.csv', $csvF]]);
$bd->exec('DROP TRIGGER prueba_falla_importacion');
$res = array_column($j['datos']['filas'] ?? [], 'resultado', 'documento');
comprobar('una fila que falla en la BD no revierte las demás', $c === 200 && $res === ['9500000030' => 'creada', '9500000031' => 'error', '9500000032' => 'creada']
    && cuenta("SELECT COUNT(*) FROM deportista WHERE documento IN ('9500000030','9500000032')") === 2 && cuenta("SELECT COUNT(*) FROM deportista WHERE documento = '9500000031'") === 0, json_encode($j));
comprobar('el error de esa fila no revela SQL ni rutas', !preg_match('/SQLSTATE|falla forzada|\.php|C:\\\\/i', $t), $t);

echo "\n[B) importar: 1.000 filas y consultas constantes]\n";
$mil = "documento;nombre;fecha_nacimiento;sexo;estatura;peso;correo\n";
for ($i = 0; $i < 1000; $i++) { $mil .= sprintf("95%08d;Masivo Ñ %d;1990-01-01;femenino;1,6;55;masivo%d@correoficticio.test\n", $i + 1000, $i, $i); }
$t0 = microtime(true);
[$c, $j] = $admin->multipart(im('previsualizar'), [], ['archivo' => ['mil.csv', $mil]]);
comprobar('1.000 filas: previsualizar -> 200, todas nuevas (' . round(microtime(true) - $t0, 2) . ' s)', $c === 200 && $j['datos']['resumen']['nueva'] === 1000, $c . json_encode($j['datos']['resumen'] ?? $j));
$medir = function (string $accion, string $contenido, array $campos = []) use ($admin): int {
    $antes = comSelect();
    $admin->multipart(im($accion), $campos, ['archivo' => ['m.csv', $contenido]]);
    return comSelect() - $antes;
};
$filasCsv = fn (int $n, int $desde) => "documento;nombre;fecha_nacimiento;sexo;estatura;peso;correo;acudiente_documento;acudiente_nombre;acudiente_relacion\n"
    . implode('', array_map(fn ($i) => sprintf("94%08d;Constante %d;1990-01-01;F;1.6;55;c%d@correoficticio.test;94%08d;Acudiente %d;madre\n", $i, $i, $i, $i + 500000, $i), range($desde, $desde + $n - 1)));
$q10 = $medir('previsualizar', $filasCsv(10, 1)); $q500 = $medir('previsualizar', $filasCsv(500, 1));
comprobar("previsualizar: mismas consultas con 10 y 500 filas ({$q10} -> {$q500})", $q10 === $q500 && $q10 > 0);
$a10 = $medir('aplicar', $filasCsv(10, 1)); $a200 = $medir('aplicar', $filasCsv(200, 100));
comprobar("aplicar (altas): mismos SELECT con 10 y 200 altas ({$a10} -> {$a200})", $a10 === $a200 && $a10 > 0);
$u10 = "documento;peso\n" . implode('', array_map(fn ($i) => sprintf("94%08d;60\n", $i), range(1, 10)));
$u200 = "documento;peso\n" . implode('', array_map(fn ($i) => sprintf("94%08d;60\n", $i), range(100, 299)));
$pu10 = $medir('previsualizar', $u10); $pu200 = $medir('previsualizar', $u200);
comprobar("previsualizar actualizaciones: mismas consultas con 10 y 200 ({$pu10} -> {$pu200})", $pu10 === $pu200);

echo "\n[B) seguridad]\n";
$archivosTmp = glob(sys_get_temp_dir() . '/php*') ?: [];
$foto = function () use ($backend): array {
    $r = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend, FilesystemIterator::SKIP_DOTS)) as $f) {
        $ruta = str_replace('\\', '/', $f->getPathname());
        if (!str_contains($ruta, '/Almacenamiento/sesiones/')) { $r[$ruta] = $f->getSize() . '|' . $f->getMTime(); }
    }
    return $r;
};
$antes = $foto();
$admin->multipart(im('previsualizar'), [], ['archivo' => ['carga.csv', $csv]]);
$admin->multipart(im('aplicar'), [], ['archivo' => ['carga.csv', $csv]]);
comprobar('importar no deja nada en disco (backend/ idéntico)', $foto() === $antes, json_encode(array_diff_assoc($foto(), $antes)));
$todo = implode("\n", $crudos);
comprobar('ninguna respuesta revela rutas, SQL ni contraseñas', !preg_match('/SQLSTATE|C:\\\\\\\\|htdocs|\.php"|contrasena|\$2y\$/i', $todo), substr($todo, 0, 100));
[$c, , $t] = $admin->pedir('GET', u('reportes', 'deportistas'));
$archivoX = __DIR__ . '/tmp_rep.xlsx'; file_put_contents($archivoX, $t);
$lib = json_decode((string) shell_exec('python -I ' . escapeshellarg(__DIR__ . '/lectores/lector_xlsx.py') . ' ' . escapeshellarg($archivoX)), true); @unlink($archivoX);
$celda = null; foreach ($lib['hojas']['Deportistas']['celdas'] ?? [] as $ref => $v) { if ($v['v'] === "=cmd|' /C calc'!A0") { $celda = $v; } }
comprobar('el nombre "=cmd|..." importado sale en el reporte como texto (t=s, quotePrefix), nunca fórmula', $c === 200 && $celda !== null && $celda['t'] === 's' && in_array($celda['s'], $lib['quote_prefix'], true) && $lib['formulas'] === 0, json_encode($celda));
$bd->exec("UPDATE deportista SET correo = " . $bd->quote((string) $correoFabian) . " WHERE documento = '1000000103'");

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
