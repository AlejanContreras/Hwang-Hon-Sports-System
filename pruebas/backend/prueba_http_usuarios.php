<?php

declare(strict_types=1);

// Bateria HTTP de Usuarios administrativos (paso 2, Etapa 5), fuera del repositorio.
// Uso: php prueba_http_usuarios.php <url base>
// Requiere: esquema v4 + semilla + base_datos/cuentas_prueba_locales.sql recien cargados.

$base = rtrim($argv[1], '/');
date_default_timezone_set('America/Bogota');
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
/** @return array{0: int, 1: ?array, 2: string} */
function peticion(string $metodo, string $url, ?array $cuerpo = null, ?string $cookie = null): array
{
    global $respuestas;
    $cabeceras = "Content-Type: application/json\r\n" . ($cookie ? "Cookie: {$cookie}\r\n" : '');
    $contexto = stream_context_create(['http' => ['method' => $metodo, 'header' => $cabeceras, 'ignore_errors' => true,
        'content' => $cuerpo === null ? '' : json_encode($cuerpo)]]);
    $texto = (string) file_get_contents($url, false, $contexto);
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $m);
    $cookieNueva = '';
    foreach ($http_response_header as $h) {
        if (preg_match('/^Set-Cookie: (HWANGHON_SESION=[^;]*)/i', $h, $c)) { $cookieNueva = $c[1]; }
    }
    $respuestas[] = $texto;
    return [(int) $m[1], json_decode($texto, true), $cookieNueva];
}
function login(string $correo, string $clave = '12345678'): array
{
    global $base;
    return peticion('POST', "{$base}/api.php?recurso=auth&accion=iniciarSesionAdministrativa", ['correo' => $correo, 'contrasena' => $clave]);
}
function u(string $accion, ?string $correo = null): string
{
    global $base;
    return "{$base}/api.php?recurso=usuarios-administrativos&accion={$accion}" . ($correo === null ? '' : '&correo=' . urlencode($correo));
}
function cuenta(string $correo): ?array
{
    global $bd;
    $s = $bd->prepare('SELECT * FROM usuario_administrativo WHERE correo = ?');
    $s->execute([$correo]);
    return $s->fetch() ?: null;
}

[, , $M] = login('maestro@hwanghon.local');
[, , $D] = login('desarrollador@hwanghon.local');
[, , $A1] = login('administrativo1@hwanghon.local');
[, , $P] = peticion('POST', "{$base}/api.php?recurso=auth&accion=iniciarSesionPublica", ['documento' => '1000000101', 'fecha_nacimiento' => '2014-03-12']);
comprobar('sesiones de prueba creadas (maestro, desarrollador, administrativo1, pública)', $M !== '' && $D !== '' && $A1 !== '' && $P !== '');

echo "\n[1. Quién puede]\n";
$acciones = [
    ['GET', u('listar'), null], ['GET', u('consultar', 'maestro@hwanghon.local'), null],
    ['POST', u('crear'), ['correo' => 'x@hwanghon.local', 'nombre' => 'X', 'contrasena' => '12345678']],
    ['PUT', u('actualizar', 'inactivo@hwanghon.local'), ['nombre' => 'X']],
    ['PUT', u('desactivar', 'inactivo@hwanghon.local'), null], ['PUT', u('reactivar', 'inactivo@hwanghon.local'), null],
    ['PUT', u('restablecerContrasena', 'inactivo@hwanghon.local'), ['contrasena_nueva' => 'OtraClave1']],
];
foreach ($acciones as [$metodo, $url, $cuerpo]) {
    preg_match('/accion=(\w+)/', $url, $a);
    [$c, $j] = peticion($metodo, $url, $cuerpo, $A1);
    comprobar("no principal -> 403 en {$a[1]}", $c === 403 && $j['mensaje'] === 'Solo las cuentas principales pueden gestionar las cuentas administrativas.', $c . json_encode($j));
}
comprobar('el no principal no cambió nada (inactivo sigue igual, x@ no existe)', cuenta('x@hwanghon.local') === null && cuenta('inactivo@hwanghon.local')['nombre'] === 'Inactivo (prueba)');
[$c] = peticion('GET', u('listar'), null, $P);
comprobar('sesión pública -> 403 (AmbitoMiddleware)', $c === 403, (string) $c);
[$c] = peticion('GET', u('listar'));
comprobar('sin sesión -> 401', $c === 401, (string) $c);
[$c] = peticion('DELETE', u('eliminar', 'inactivo@hwanghon.local'), null, $M);
comprobar('la acción eliminar ya no existe -> 404', $c === 404, (string) $c);
// Permiso leído de la BD en cada petición: si a administrativo1 se le da el rol en la BD, entra; al quitarlo, 403.
$bd->exec("UPDATE usuario_administrativo SET es_administrador_principal = 1 WHERE correo = 'administrativo1@hwanghon.local'");
[$c1] = peticion('GET', u('listar'), null, $A1);
$bd->exec("UPDATE usuario_administrativo SET es_administrador_principal = 0 WHERE correo = 'administrativo1@hwanghon.local'");
[$c2] = peticion('GET', u('listar'), null, $A1);
comprobar('es_administrador_principal se lee de la BD en cada petición (misma cookie: 200 con el rol, 403 sin él)', $c1 === 200 && $c2 === 403, "{$c1} {$c2}");

echo "\n[2-3. listar y consultar]\n";
[$c, $j] = peticion('GET', u('listar'), null, $M);
$lista = $j['datos'] ?? [];
$esperado = $bd->query('SELECT correo FROM usuario_administrativo ORDER BY nombre, correo')->fetchAll(PDO::FETCH_COLUMN);
comprobar('listar -> 200, todas las cuentas (7), orden nombre, correo', $c === 200 && array_column($lista, 'correo') === $esperado && count($lista) === 7, json_encode(array_column($lista, 'correo')));
$campos = ['correo', 'nombre', 'rol', 'es_administrador_principal', 'activa', 'creado_por_correo'];
comprobar('cada cuenta trae exactamente los 6 campos, con booleanos', array_filter($lista, fn ($f) => array_keys($f) !== $campos || !is_bool($f['activa']) || !is_bool($f['es_administrador_principal'])) === []);
[$c, $j] = peticion('GET', u('consultar', 'inactivo@hwanghon.local'), null, $D);
comprobar('consultar -> 200 con los mismos campos', $c === 200 && array_keys($j['datos']) === $campos && $j['datos']['activa'] === false);
[$c] = peticion('GET', u('consultar', 'nadie@hwanghon.local'), null, $M);
comprobar('consultar inexistente -> 404', $c === 404, (string) $c);
[$c] = peticion('GET', u('consultar', 'no-es-correo'), null, $M);
comprobar('consultar con correo mal formado -> 400', $c === 400, (string) $c);

echo "\n[4. crear]\n";
$nueva = ['correo' => '  Tesorero@HwangHon.local ', 'nombre' => 'Tesorero (prueba)', 'rol' => 'tesorero', 'contrasena' => 'ClaveTesorero1'];
[$c, $j] = peticion('POST', u('crear'), $nueva, $M);
comprobar('crear -> 201 sin rol principal, activa y creado_por = sesión', $c === 201 && $j['datos'] === ['correo' => 'tesorero@hwanghon.local', 'nombre' => 'Tesorero (prueba)',
    'rol' => 'tesorero', 'es_administrador_principal' => false, 'activa' => true, 'creado_por_correo' => 'maestro@hwanghon.local'], $c . json_encode($j));
$fila = cuenta('tesorero@hwanghon.local');
comprobar('BD: hash bcrypt de la contraseña inicial, sin token', password_verify('ClaveTesorero1', $fila['contrasena']) && $fila['token_recuperacion'] === null && (int) $fila['es_administrador_principal'] === 0);
[$c] = login('tesorero@hwanghon.local', 'ClaveTesorero1');
comprobar('la cuenta nueva inicia sesión', $c === 200, (string) $c);
[$c, $j] = peticion('POST', u('crear'), ['correo' => 'TESORERO@hwanghon.local', 'nombre' => 'Otro', 'contrasena' => '12345678'], $D);
comprobar('correo duplicado (aunque cambien mayúsculas) -> 409', $c === 409 && str_starts_with($j['mensaje'], 'Ya existe una cuenta'), $c . json_encode($j));
[$c, $j] = peticion('POST', u('crear'), ['correo' => 'principal@hwanghon.local', 'nombre' => 'X', 'contrasena' => '12345678', 'es_administrador_principal' => true], $M);
comprobar('intentar crear con es_administrador_principal -> 400 y no se crea', $c === 400 && cuenta('principal@hwanghon.local') === null, $c . json_encode($j));
[$c, $j] = peticion('POST', u('crear'), ['correo' => 'c7@hwanghon.local', 'nombre' => 'X', 'contrasena' => '1234567'], $M);
comprobar('contraseña de 7 caracteres -> 400', $c === 400 && $j['mensaje'] === 'La contraseña debe tener al menos 8 caracteres.', $c . json_encode($j));
[$c, $j] = peticion('POST', u('crear'), ['correo' => 'c73@hwanghon.local', 'nombre' => 'X', 'contrasena' => str_repeat('a', 73)], $M);
comprobar('contraseña de 73 bytes -> 400', $c === 400 && $j['mensaje'] === 'La contraseña no puede superar 72 bytes (72 caracteres sin tildes; cada tilde, ñ o símbolo especial ocupa 2 o más).', $c . json_encode($j));
[$c] = peticion('POST', u('crear'), ['correo' => 'c8@hwanghon.local', 'nombre' => 'X', 'contrasena' => str_repeat('ñ', 36)], $M);
comprobar('contraseña de 72 bytes exactos (36 ñ) -> 201', $c === 201, (string) $c);
foreach ([
    'sin nombre' => ['correo' => 'n1@hwanghon.local', 'contrasena' => '12345678'],
    'nombre de 151 caracteres' => ['correo' => 'n2@hwanghon.local', 'nombre' => str_repeat('a', 151), 'contrasena' => '12345678'],
    'rol de 31 caracteres' => ['correo' => 'n3@hwanghon.local', 'nombre' => 'X', 'rol' => str_repeat('r', 31), 'contrasena' => '12345678'],
    'correo de 151 caracteres' => ['correo' => str_repeat('a', 137) . '@hwanghon.local', 'nombre' => 'X', 'contrasena' => '12345678'],
    'sin contraseña' => ['correo' => 'n4@hwanghon.local', 'nombre' => 'X'],
] as $caso => $cuerpo) {
    [$c] = peticion('POST', u('crear'), $cuerpo, $M);
    comprobar("crear {$caso} -> 400", $c === 400, (string) $c);
}

echo "\n[5. actualizar]\n";
[$c, $j] = peticion('PUT', u('actualizar', 'tesorero@hwanghon.local'), ['nombre' => 'Tesorero Editado', 'rol' => null], $D);
comprobar('actualizar nombre y rol (rol a null) -> 200', $c === 200 && $j['datos']['nombre'] === 'Tesorero Editado' && $j['datos']['rol'] === null, $c . json_encode($j));
foreach (['activa' => false, 'es_administrador_principal' => true, 'contrasena' => 'NuevaClave1', 'creado_por_correo' => 'x@hwanghon.local'] as $campo => $valor) {
    [$c] = peticion('PUT', u('actualizar', 'tesorero@hwanghon.local'), [$campo => $valor], $M);
    comprobar("actualizar {$campo} -> 400 (no lo toca)", $c === 400, (string) $c);
}
$fila = cuenta('tesorero@hwanghon.local');
comprobar('la cuenta sigue activa, no principal y con su contraseña', (int) $fila['activa'] === 1 && (int) $fila['es_administrador_principal'] === 0 && password_verify('ClaveTesorero1', $fila['contrasena']));
[$c] = peticion('PUT', u('actualizar', 'tesorero@hwanghon.local'), [], $M);
comprobar('actualizar sin campos -> 400', $c === 400, (string) $c);
[$c] = peticion('PUT', u('actualizar', 'nadie@hwanghon.local'), ['nombre' => 'X'], $M);
comprobar('actualizar inexistente -> 404', $c === 404, (string) $c);
[$c, $j] = peticion('PUT', u('actualizar', 'maestro@hwanghon.local'), ['correo' => 'maestro2@hwanghon.local'], $M);
comprobar('cambiar el PROPIO correo -> 409', $c === 409 && cuenta('maestro@hwanghon.local') !== null, $c . json_encode($j));
[$c] = peticion('PUT', u('actualizar', 'maestro@hwanghon.local'), ['correo' => 'MAESTRO@hwanghon.local', 'nombre' => 'Maestro (prueba)'], $M);
comprobar('enviar el mismo correo propio (sin cambio real) -> 200', $c === 200, (string) $c);
[$c, $j] = peticion('PUT', u('actualizar', 'tesorero@hwanghon.local'), ['correo' => 'desarrollador@hwanghon.local'], $M);
comprobar('cambiar a un correo que ya existe -> 409', $c === 409 && cuenta('tesorero@hwanghon.local') !== null, $c . json_encode($j));
// Procedimiento: staff.instructor validó al deportista 1000000103 en la semilla.
[$c, $j] = peticion('PUT', u('actualizar', 'staff.instructor@hwanghonficticio.test'), ['correo' => 'instructora@hwanghon.local', 'nombre' => 'Instructora'], $M);
$dep = $bd->query("SELECT validado_por_correo FROM deportista WHERE documento = '1000000103'")->fetchColumn();
comprobar('cambiar correo de otra cuenta -> 200 por el procedimiento (nombre también cambia)', $c === 200 && $j['datos']['correo'] === 'instructora@hwanghon.local'
    && $j['datos']['nombre'] === 'Instructora' && cuenta('staff.instructor@hwanghonficticio.test') === null, $c . json_encode($j));
comprobar('el procedimiento actualizó deportista.validado_por_correo', $dep === 'instructora@hwanghon.local', (string) $dep);
// Llave reflexiva: maestro creó a administrativo1, inactivo y tesorero. Lo cambia el desarrollador.
[$c] = peticion('PUT', u('actualizar', 'maestro@hwanghon.local'), ['correo' => 'aldrin@hwanghon.local'], $D);
$creados = $bd->query("SELECT COUNT(*) FROM usuario_administrativo WHERE creado_por_correo = 'aldrin@hwanghon.local'")->fetchColumn();
comprobar('cambiar el correo de la cuenta principal (lo hace la otra principal) -> 200 y creado_por_correo de sus cuentas se actualiza', $c === 200 && (int) $creados >= 3, "{$c} {$creados}");
[$c] = peticion('GET', u('listar'), null, $M);
comprobar('la sesión abierta con el correo viejo deja de valer -> 401', $c === 401, (string) $c);
[$c, , $M] = login('aldrin@hwanghon.local');
comprobar('entra con el correo nuevo y la misma contraseña', $c === 200, (string) $c);

echo "\n[6. desactivar y reactivar]\n";
[$c, $j] = peticion('PUT', u('desactivar', 'aldrin@hwanghon.local'), null, $M);
comprobar('desactivar la propia cuenta -> 409', $c === 409 && $j['mensaje'] === 'No puedes desactivar tu propia cuenta.', $c . json_encode($j));
[$c, $j] = peticion('PUT', u('desactivar', 'desarrollador@hwanghon.local'), null, $M);
comprobar('desactivar una cuenta principal -> 409', $c === 409 && (int) cuenta('desarrollador@hwanghon.local')['activa'] === 1, $c . json_encode($j));
[$c] = peticion('GET', u('listar'), null, $A1);
comprobar('administrativo1 tiene sesión válida antes de desactivarlo (403 de permiso, no 401)', $c === 403, (string) $c);
[$c, $j] = peticion('PUT', u('desactivar', 'administrativo1@hwanghon.local'), null, $M);
comprobar('desactivar -> 200 activa false', $c === 200 && $j['datos']['activa'] === false && (int) cuenta('administrativo1@hwanghon.local')['activa'] === 0, $c . json_encode($j));
[$c, $j] = peticion('PUT', u('desactivar', 'administrativo1@hwanghon.local'), null, $D);
comprobar('desactivar de nuevo (idempotente) -> 200', $c === 200 && $j['datos']['activa'] === false, (string) $c);
[$c, $j] = peticion('GET', "{$base}/api.php?recurso=deportistas&accion=listar", null, $A1);
comprobar('la sesión abierta de la cuenta desactivada -> 401 en su siguiente petición', $c === 401 && $j['mensaje'] === 'Tu sesión ya no es válida.', $c . json_encode($j));
[$c] = login('administrativo1@hwanghon.local');
comprobar('la cuenta desactivada no inicia sesión (401 genérico)', $c === 401, (string) $c);
[$c, $j] = peticion('PUT', u('reactivar', 'administrativo1@hwanghon.local'), null, $D);
comprobar('reactivar -> 200 activa true', $c === 200 && $j['datos']['activa'] === true, $c . json_encode($j));
[$c] = peticion('PUT', u('reactivar', 'administrativo1@hwanghon.local'), null, $M);
comprobar('reactivar de nuevo (idempotente) -> 200', $c === 200, (string) $c);
$bd->exec('DELETE FROM intento_acceso');
[$c] = login('administrativo1@hwanghon.local');
comprobar('la cuenta reactivada vuelve a entrar', $c === 200, (string) $c);
[$c] = peticion('PUT', u('desactivar', 'nadie@hwanghon.local'), null, $M);
comprobar('desactivar inexistente -> 404', $c === 404, (string) $c);
[$c] = peticion('GET', u('desactivar', 'tesorero@hwanghon.local'), null, $M);
comprobar('desactivar con GET -> 405', $c === 405, (string) $c);

echo "\n[7. restablecer contraseña de otra cuenta]\n";
[$c, $j] = peticion('PUT', u('restablecerContrasena', 'aldrin@hwanghon.local'), ['contrasena_nueva' => 'NuevaClave99'], $M);
comprobar('restablecer la propia -> 409 «Usa “cambiar mi contraseña”»', $c === 409 && $j['mensaje'] === 'Para tu propia cuenta usa «cambiar mi contraseña».', $c . json_encode($j));
[$c] = peticion('PUT', u('restablecerContrasena', 'desarrollador@hwanghon.local'), ['contrasena_nueva' => '1234567'], $M);
comprobar('contraseña de 7 caracteres -> 400', $c === 400, (string) $c);
[$c] = peticion('PUT', u('restablecerContrasena', 'desarrollador@hwanghon.local'), ['contrasena_nueva' => str_repeat('b', 73)], $M);
comprobar('contraseña de 73 bytes -> 400', $c === 400, (string) $c);
[$c] = peticion('PUT', u('restablecerContrasena', 'desarrollador@hwanghon.local'), [], $M);
comprobar('sin contrasena_nueva -> 400', $c === 400, (string) $c);
[$c] = peticion('PUT', u('restablecerContrasena', 'nadie@hwanghon.local'), ['contrasena_nueva' => 'NuevaClave99'], $M);
comprobar('restablecer de cuenta inexistente -> 404', $c === 404, (string) $c);
$bd->exec("UPDATE usuario_administrativo SET token_recuperacion = '" . str_repeat('c', 64) . "', token_recuperacion_expira = '" . date('Y-m-d H:i:s', time() + 3000) . "' WHERE correo = 'desarrollador@hwanghon.local'");
$bd->exec("INSERT INTO intento_acceso (ambito, identificador, intentos_fallidos, bloqueado_hasta) VALUES ('administrativa', 'desarrollador@hwanghon.local', 4, '" . date('Y-m-d H:i:s', time() + 600) . "')");
[$c, $j] = peticion('PUT', u('restablecerContrasena', 'desarrollador@hwanghon.local'), ['contrasena_nueva' => 'ClaveDelDev1'], $M);
$fila = cuenta('desarrollador@hwanghon.local');
comprobar('Aldrin restablece la del Desarrollador -> 200 {contrasena_restablecida: true}', $c === 200 && $j['datos'] === ['contrasena_restablecida' => true], $c . json_encode($j));
comprobar('BD: hash nuevo, token y vencimiento en NULL', password_verify('ClaveDelDev1', $fila['contrasena']) && $fila['token_recuperacion'] === null && $fila['token_recuperacion_expira'] === null);
comprobar('el contador/bloqueo de intentos de esa cuenta se reinició', $bd->query("SELECT COUNT(*) FROM intento_acceso WHERE identificador = 'desarrollador@hwanghon.local'")->fetchColumn() == 0);
[$c] = peticion('GET', u('listar'), null, $D);
comprobar('M4: la sesión ya abierta del Desarrollador cae tras restablecer su contraseña -> 401', $c === 401, (string) $c);
[$cNueva, , $D2] = login('desarrollador@hwanghon.local', 'ClaveDelDev1');
[$cVieja] = login('desarrollador@hwanghon.local', '12345678');
comprobar('entra con la nueva; la vieja -> 401', $cNueva === 200 && $cVieja === 401, "{$cNueva} {$cVieja}");
[$c] = peticion('PUT', u('restablecerContrasena', 'aldrin@hwanghon.local'), ['contrasena_nueva' => 'ClaveDeAldrin1'], $D2);
[$cA] = login('aldrin@hwanghon.local', 'ClaveDeAldrin1');
comprobar('y viceversa: el Desarrollador restablece la de Aldrin -> 200 y Aldrin entra con ella', $c === 200 && $cA === 200, "{$c} {$cA}");

echo "\n[Seguridad]\n";
$filtradas = array_filter($respuestas, fn ($r) => preg_match('/"(contrasena|token_recuperacion|token_recuperacion_expira)"\s*:/', $r) === 1 || str_contains($r, '$2y$'));
comprobar('ninguna de las ' . count($respuestas) . ' respuestas trae contrasena, token_recuperacion ni un hash', $filtradas === [], implode("\n", $filtradas));

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
