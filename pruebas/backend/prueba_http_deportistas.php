<?php

declare(strict_types=1);

// Las fechas (date(), 'today') se calculan con la hora de Bogotá, como el backend.
date_default_timezone_set('America/Bogota');

// Regresion HTTP de Deportistas contra el esquema v4 (fuera del repositorio).
// Uso: php prueba_http_deportistas.php <url base del servidor de pruebas>
// Requiere la base recien cargada (esquema v4 + semilla) y router_pruebas.php sirviendo.

$base = rtrim($argv[1], '/');
$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$ok = 0; $fallos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}

/** @return array{0: int, 1: ?array, 2: string} [codigo HTTP, json, cookie de sesion recibida] */
function peticion(string $metodo, string $url, ?array $cuerpo = null, ?string $cookie = null): array
{
    $cabeceras = "Content-Type: application/json\r\n";
    if ($cookie !== null) { $cabeceras .= "Cookie: {$cookie}\r\n"; }
    $contexto = stream_context_create(['http' => [
        'method' => $metodo, 'header' => $cabeceras, 'ignore_errors' => true,
        'content' => $cuerpo === null ? '' : json_encode($cuerpo),
    ]]);
    $texto = file_get_contents($url, false, $contexto);
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $m);
    $nuevaCookie = '';
    foreach ($http_response_header as $h) {
        if (preg_match('/^Set-Cookie: (HWANGHON_SESION=[^;]+)/i', $h, $c)) { $nuevaCookie = $c[1]; }
    }
    return [(int) $m[1], json_decode((string) $texto, true), $nuevaCookie];
}

function sesion(string $ambito, string $identificador): string
{
    global $base;
    [, , $cookie] = peticion('GET', "{$base}/__sesion?ambito={$ambito}&identificador=" . urlencode($identificador));
    return $cookie;
}

function fila(string $documento): ?array
{
    global $bd;
    $s = $bd->prepare('SELECT * FROM deportista WHERE documento = ?');
    $s->execute([$documento]);
    return $s->fetch() ?: null;
}

$api = "{$base}/api.php?recurso=deportistas";
$admin = sesion('administrativa', 'admin.fundador@hwanghonficticio.test');
comprobar('Sesion administrativa creada', $admin !== '');

$adulto = [
    'documento' => '9100000001', 'nombre' => 'Adulto Ficticio Prueba', 'fecha_nacimiento' => '1995-05-05',
    'lugar_nacimiento' => 'Fusagasugá', 'sexo' => 'masculino', 'estatura' => 1.72, 'peso' => 70.5,
    'telefono' => '+57 300 123 4567', 'direccion' => 'Calle Ficticia 10 # 20-30', 'correo' => 'adulto@ficticio.test',
    'experiencia_previa' => 'Karate en un club ficticio, retiro en 2020', 'autorizacion_datos' => true,
];
$menor = [
    'documento' => '9100000002', 'nombre' => 'Menor Ficticio Prueba', 'fecha_nacimiento' => '2016-02-02', 'sexo' => 'femenino',
    'estatura' => 1.30, 'peso' => 30, 'correo' => 'adulto@ficticio.test',
    'acudiente' => ['documento' => '9100000099', 'nombre' => 'Acudiente Ficticio', 'relacion' => 'madre'],
];

echo "\n[sin sesion y errores de ruta]\n";
[$c] = peticion('GET', "{$api}&accion=listar");
comprobar('Sin sesion: listar -> 401', $c === 401, (string) $c);
[$c] = peticion('POST', "{$api}&accion=listar", null, $admin);
comprobar('Metodo equivocado -> 405', $c === 405, (string) $c);
[$c] = peticion('GET', "{$api}&accion=inexistente", null, $admin);
comprobar('Accion desconocida -> 404', $c === 404, (string) $c);

echo "\n[administrativo: lectura]\n";
[$c, $j] = peticion('GET', "{$api}&accion=listar", null, $admin);
$lista = $j['datos'] ?? [];
comprobar('listar -> 200 con los 3 deportistas', $c === 200 && count($lista) === 3, (string) $c);
$campos = array_keys($lista[0] ?? []);
comprobar('listar trae los campos nuevos (lugar_nacimiento, telefono, direccion, correo, experiencia_previa)',
    array_diff(['lugar_nacimiento', 'telefono', 'direccion', 'correo', 'experiencia_previa'], $campos) === []);
comprobar('listar nunca trae estado_animico y si validado_por_correo', !in_array('estado_animico', $campos, true) && in_array('validado_por_correo', $campos, true));
comprobar('listar calcula edad y categoria_edad', isset($lista[0]['edad']) && array_key_exists('categoria_edad', $lista[0]));
[$c, $j] = peticion('GET', "{$api}&accion=listar&estado_registro=pendiente_validacion", null, $admin);
comprobar('listar con filtro estado_registro -> 1 fila', $c === 200 && count($j['datos']) === 1);
[$c] = peticion('GET', "{$api}&accion=listar&correo=x", null, $admin);
comprobar('listar con filtro no permitido -> 400', $c === 400, (string) $c);
[$c, $j] = peticion('GET', "{$api}&accion=consultar&documento=1000000101", null, $admin);
comprobar('consultar -> 200 con acudiente (telefono y correo del acudiente incluidos)',
    $c === 200 && ($j['datos']['acudiente']['telefono'] ?? null) === '3000000001' && ($j['datos']['acudiente']['correo'] ?? null) === 'marcela.reyes@correoficticio.test');
[$c] = peticion('GET', "{$api}&accion=consultar&documento=0000000000", null, $admin);
comprobar('consultar inexistente -> 404', $c === 404, (string) $c);

echo "\n[administrativo: crear]\n";
[$c, $j] = peticion('POST', "{$api}&accion=crear", $adulto, $admin);
$f = fila('9100000001');
comprobar('crear adulto con campos nuevos -> 201', $c === 201, json_encode($j));
comprobar('crear guarda en la BD lugar_nacimiento, telefono, direccion, correo y experiencia_previa',
    $f !== null && $f['lugar_nacimiento'] === 'Fusagasugá' && $f['telefono'] === '+57 300 123 4567'
    && $f['direccion'] === 'Calle Ficticia 10 # 20-30' && $f['correo'] === 'adulto@ficticio.test'
    && $f['experiencia_previa'] === 'Karate en un club ficticio, retiro en 2020');
comprobar('crear queda validado y activo', $f !== null && $f['estado_registro'] === 'validado' && $f['estado'] === 'activo');
[$c] = peticion('POST', "{$api}&accion=crear", $adulto, $admin);
comprobar('crear documento repetido -> 409', $c === 409, (string) $c);
[$c] = peticion('POST', "{$api}&accion=crear", array_diff_key($menor, ['acudiente' => 1]), $admin);
comprobar('crear menor sin acudiente -> 400', $c === 400, (string) $c);
[$c] = peticion('POST', "{$api}&accion=crear", $menor, $admin);
comprobar('crear menor con acudiente nuevo y el mismo correo de otro deportista (RN-1.10) -> 201', $c === 201, (string) $c);
comprobar('el acudiente se creo en la misma operacion', (int) $bd->query("SELECT COUNT(*) FROM acudiente_tutor WHERE documento = '9100000099'")->fetchColumn() === 1);

$invalidos = [
    'telefono con letras' => ['telefono' => '300-ABC'],
    'telefono con guiones' => ['telefono' => '300-123-4567'],
    'telefono con + en medio' => ['telefono' => '300+1234'],
    'telefono de 21 caracteres' => ['telefono' => str_repeat('1', 21)],
    'correo sin arroba' => ['correo' => 'correo.ficticio.test'],
    'correo sin dominio' => ['correo' => 'nombre@'],
    'correo de 151 caracteres' => ['correo' => str_repeat('a', 139) . '@ficticio.test'],
    'lugar_nacimiento de 101 caracteres' => ['lugar_nacimiento' => str_repeat('a', 101)],
    'direccion de 151 caracteres' => ['direccion' => str_repeat('a', 151)],
    'experiencia_previa de 16001 caracteres' => ['experiencia_previa' => str_repeat('a', 16001)],
    'campo desconocido' => ['estado_animico' => 'feliz'],
];
foreach ($invalidos as $caso => $cambio) {
    [$c, $j] = peticion('POST', "{$api}&accion=crear", array_merge($adulto, ['documento' => '9100000050'], $cambio), $admin);
    comprobar("crear con {$caso} -> 400", $c === 400, $c . ' ' . json_encode($j));
}
[$c] = peticion('POST', "{$api}&accion=crear", array_merge($adulto, ['documento' => '9100000051', 'telefono' => '3001234567', 'correo' => null, 'direccion' => '  ']), $admin);
$f = fila('9100000051');
comprobar('crear con telefono solo digitos, correo null y direccion en blanco -> 201 (opcionales)', $c === 201 && $f['correo'] === null && $f['direccion'] === null, (string) $c);
comprobar('Ningun caso invalido quedo guardado', fila('9100000050') === null);

echo "\n[administrativo: actualizar y eliminar]\n";
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=9100000001", ['lugar_nacimiento' => 'Soacha', 'experiencia_previa' => null, 'correo' => null], $admin);
$f = fila('9100000001');
comprobar('actualizar lugar_nacimiento, experiencia_previa y correo -> 200 y BD', $c === 200 && $f['lugar_nacimiento'] === 'Soacha' && $f['experiencia_previa'] === null && $f['correo'] === null);
comprobar('actualizar no toca los campos no enviados', $f['telefono'] === '+57 300 123 4567' && $f['direccion'] === 'Calle Ficticia 10 # 20-30');
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=9100000001", ['telefono' => 'abc'], $admin);
comprobar('actualizar con telefono invalido -> 400', $c === 400, (string) $c);
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=1000000101", ['acudiente' => ['nombre' => 'Marcela Reyes Ortiz Editada']], $admin);
$acu = $bd->query("SELECT * FROM acudiente_tutor WHERE documento = '1000000001'")->fetch();
comprobar('editar nombre del acudiente conserva su telefono y correo', $c === 200 && $acu['nombre'] === 'Marcela Reyes Ortiz Editada' && $acu['telefono'] === '3000000001' && $acu['correo'] === 'marcela.reyes@correoficticio.test');
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=1000000102", ['estado_registro' => 'validado'], $admin);
$f = fila('1000000102');
comprobar('validar registro pendiente -> 200 y guarda quien valido', $c === 200 && $f['estado_registro'] === 'validado' && $f['validado_por_correo'] === 'admin.fundador@hwanghonficticio.test');
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=1000000102", ['estado_registro' => 'pendiente_validacion'], $admin);
comprobar('volver de validado a pendiente -> 409', $c === 409, (string) $c);
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=9100000002", ['acudiente' => ['documento' => '1000000001']], $admin);
comprobar('reasignar acudiente -> 200 y borra el acudiente que quedo huerfano', $c === 200
    && fila('9100000002')['acudiente_documento'] === '1000000001'
    && (int) $bd->query("SELECT COUNT(*) FROM acudiente_tutor WHERE documento = '9100000099'")->fetchColumn() === 0);
[$c] = peticion('DELETE', "{$api}&accion=eliminar&documento=9100000001", null, $admin);
comprobar('eliminar un validado -> 409', $c === 409 && fila('9100000001') !== null, (string) $c);
$bd->exec("UPDATE deportista SET estado_registro = 'pendiente_validacion' WHERE documento = '9100000001'");
[$c] = peticion('DELETE', "{$api}&accion=eliminar&documento=9100000001", null, $admin);
comprobar('eliminar un pendiente -> 200 y lo borra de la BD', $c === 200 && fila('9100000001') === null, (string) $c);

echo "\n[publico: cuenta del propio deportista]\n";
$publica = sesion('publica', '1000000102');
comprobar('Sesion publica creada', $publica !== '');
[$c, $j] = peticion('GET', "{$api}&accion=listar", null, $publica);
comprobar('listar publico -> solo su registro, sin validado_por_correo ni estado_animico', $c === 200 && count($j['datos']) === 1
    && $j['datos'][0]['documento'] === '1000000102' && !array_key_exists('validado_por_correo', $j['datos'][0]) && !array_key_exists('estado_animico', $j['datos'][0]));
[$c] = peticion('GET', "{$api}&accion=consultar&documento=1000000102", null, $publica);
comprobar('consultar el propio -> 200', $c === 200, (string) $c);
[$c] = peticion('GET', "{$api}&accion=consultar&documento=1000000101", null, $publica);
comprobar('consultar uno ajeno -> 404 (no revela si existe)', $c === 404, (string) $c);

$propios = ['peso' => 61.2, 'discapacidad' => 'Asma leve', 'telefono' => '+57 301 000 0000', 'direccion' => 'Carrera Ficticia 99', 'correo' => 'juliana.nueva@correoficticio.test'];
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=1000000102", $propios, $publica);
$f = fila('1000000102');
comprobar('RN-1.4: publico edita peso, discapacidad, telefono, direccion y correo -> 200', $c === 200, (string) $c);
comprobar('RN-1.4: los cinco campos quedan guardados en la BD', (float) $f['peso'] === 61.2 && $f['discapacidad'] === 'Asma leve'
    && $f['telefono'] === '+57 301 000 0000' && $f['direccion'] === 'Carrera Ficticia 99' && $f['correo'] === 'juliana.nueva@correoficticio.test');
foreach (['nombre' => 'Otro Nombre', 'lugar_nacimiento' => 'Bogotá', 'experiencia_previa' => 'Nada', 'estado' => 'inactivo', 'grado_actual' => 'negro'] as $campo => $valor) {
    [$c] = peticion('PUT', "{$api}&accion=actualizar&documento=1000000102", [$campo => $valor], $publica);
    comprobar("publico no puede editar {$campo} -> 403", $c === 403, (string) $c);
}
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=1000000102", ['correo' => 'no-es-correo'], $publica);
comprobar('publico con correo invalido -> 400', $c === 400, (string) $c);
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=1000000101", ['telefono' => '3000000000'], $publica);
comprobar('publico edita un registro ajeno -> 404', $c === 404 && fila('1000000101')['telefono'] === '3000000001', (string) $c);
[$c] = peticion('POST', "{$api}&accion=crear", $adulto, $publica);
comprobar('publico no puede crear -> 403', $c === 403, (string) $c);
[$c] = peticion('DELETE', "{$api}&accion=eliminar&documento=1000000102", null, $publica);
comprobar('publico no puede eliminar -> 403', $c === 403, (string) $c);

echo "\n[acudiente: telefono y correo]\n";
function acudiente(string $documento): ?array
{
    global $bd;
    $s = $bd->prepare('SELECT * FROM acudiente_tutor WHERE documento = ?');
    $s->execute([$documento]);
    return $s->fetch() ?: null;
}
$menorBase = ['nombre' => 'Menor Ficticio Dos', 'fecha_nacimiento' => '2015-08-08', 'sexo' => 'masculino', 'estatura' => 1.35, 'peso' => 32];

[$c, $j] = peticion('POST', "{$api}&accion=crear", array_merge($menorBase, ['documento' => '9100000003',
    'acudiente' => ['documento' => '9100000098', 'nombre' => 'Acudiente Con Contacto', 'relacion' => 'padre',
                    'telefono' => '+57 310 000 0000', 'correo' => 'acudiente.contacto@ficticio.test']]), $admin);
comprobar('crear menor con acudiente nuevo + telefono/correo -> 201', $c === 201, $c . ' ' . json_encode($j));
[$c, $j] = peticion('GET', "{$api}&accion=consultar&documento=9100000003", null, $admin);
comprobar('consultar muestra telefono y correo del acudiente nuevo', $c === 200
    && ($j['datos']['acudiente']['telefono'] ?? null) === '+57 310 000 0000'
    && ($j['datos']['acudiente']['correo'] ?? null) === 'acudiente.contacto@ficticio.test');

$invalidosAcudiente = [
    'telefono del acudiente con letras' => ['telefono' => '310-ABC', 'campo' => 'acudiente.telefono'],
    'telefono del acudiente de 21 caracteres' => ['telefono' => str_repeat('3', 21), 'campo' => 'acudiente.telefono'],
    'correo del acudiente invalido' => ['correo' => 'sin-arroba.test', 'campo' => 'acudiente.correo'],
    'correo del acudiente de 151 caracteres' => ['correo' => str_repeat('a', 139) . '@ficticio.test', 'campo' => 'acudiente.correo'],
];
foreach ($invalidosAcudiente as $caso => $cambio) {
    $campo = $cambio['campo']; unset($cambio['campo']);
    [$c, $j] = peticion('POST', "{$api}&accion=crear", array_merge($menorBase, ['documento' => '9100000004',
        'acudiente' => array_merge(['documento' => '9100000097', 'nombre' => 'Acudiente Invalido', 'relacion' => 'madre'], $cambio)]), $admin);
    comprobar("crear con {$caso} -> 400 y el mensaje nombra {$campo}", $c === 400 && str_contains($j['mensaje'] ?? '', $campo), $c . ' ' . json_encode($j));
}
comprobar('Ningun acudiente ni deportista invalido quedo guardado', acudiente('9100000097') === null && fila('9100000004') === null);

$originalAcudiente = acudiente('1000000001');
[$c] = peticion('POST', "{$api}&accion=crear", array_merge($menorBase, ['documento' => '9100000005',
    'acudiente' => ['documento' => '1000000001', 'telefono' => '3999999999', 'correo' => 'otro@ficticio.test']]), $admin);
$reutilizado = acudiente('1000000001');
comprobar('reutilizar acudiente existente con otro telefono/correo -> 201', $c === 201, (string) $c);
comprobar('el acudiente reutilizado conserva su telefono y correo originales',
    $reutilizado['telefono'] === $originalAcudiente['telefono'] && $reutilizado['correo'] === $originalAcudiente['correo']
    && fila('9100000005')['acudiente_documento'] === '1000000001');

[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=9100000003",
    ['acudiente' => ['telefono' => '3201112233', 'correo' => 'acudiente.editado@ficticio.test']], $admin);
$a = acudiente('9100000098');
comprobar('editar telefono/correo del acudiente actual -> 200 y BD', $c === 200 && $a['telefono'] === '3201112233'
    && $a['correo'] === 'acudiente.editado@ficticio.test' && $a['nombre'] === 'Acudiente Con Contacto', (string) $c);
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=9100000003", ['acudiente' => ['telefono' => null, 'correo' => null]], $admin);
$a = acudiente('9100000098');
comprobar('poner telefono/correo del acudiente en null -> 200 y BD', $c === 200 && $a['telefono'] === null && $a['correo'] === null, (string) $c);
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=9100000003", ['acudiente' => ['correo' => 'mal']], $admin);
comprobar('editar con correo de acudiente invalido -> 400', $c === 400, (string) $c);

$publicaMenor = sesion('publica', '1000000101');
$antes = acudiente('1000000001');
[$c] = peticion('PUT', "{$api}&accion=actualizar&documento=1000000101", ['acudiente' => ['telefono' => '3000000999']], $publicaMenor);
comprobar('publico enviando "acudiente" -> 403 y el acudiente no cambia', $c === 403 && acudiente('1000000001') == $antes, (string) $c);

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
