<?php

declare(strict_types=1);

// Bateria HTTP de Auth (paso 1, Etapa 5), fuera del repositorio.
// Uso: php prueba_http_auth.php <url base> <ruta backend>
// Requiere: base recien cargada (esquema v4 + semilla), router_pruebas.php sirviendo,
// Config/correo.php en modo 'archivo'.

$base = rtrim($argv[1], '/');
$backend = rtrim($argv[2], '/');
$carpetaSesiones = $backend . '/Almacenamiento/sesiones';
$carpetaCorreos = $backend . '/Almacenamiento/correos';
date_default_timezone_set('America/Bogota');

$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$bd->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");

$ok = 0; $fallos = []; $respuestas = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}

/** @return array{0: int, 1: ?array, 2: string, 3: string} [codigo, json, cookie nueva, cuerpo crudo] */
function peticion(string $metodo, string $url, ?array $cuerpo = null, ?string $cookie = null): array
{
    global $respuestas;
    $cabeceras = "Content-Type: application/json\r\n";
    if ($cookie !== null && $cookie !== '') { $cabeceras .= "Cookie: {$cookie}\r\n"; }
    $contexto = stream_context_create(['http' => [
        'method' => $metodo, 'header' => $cabeceras, 'ignore_errors' => true,
        'content' => $cuerpo === null ? '' : json_encode($cuerpo),
    ]]);
    $texto = (string) file_get_contents($url, false, $contexto);
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $m);
    $nuevaCookie = '';
    foreach ($http_response_header as $h) {
        if (preg_match('/^Set-Cookie: (HWANGHON_SESION=[^;]*)/i', $h, $c)) { $nuevaCookie = $c[1]; }
    }
    $respuestas[] = $texto;
    return [(int) $m[1], json_decode($texto, true), $nuevaCookie, $texto];
}

$auth = "{$base}/api.php?recurso=auth";
$dep = "{$base}/api.php?recurso=deportistas";
$adm = "{$base}/api.php?recurso=usuarios-administrativos";

function loginAdmin(string $correo, string $clave): array
{
    global $auth;
    return peticion('POST', "{$auth}&accion=iniciarSesionAdministrativa", ['correo' => $correo, 'contrasena' => $clave]);
}
function loginPublico(string $documento, string $fecha): array
{
    global $auth;
    return peticion('POST', "{$auth}&accion=iniciarSesionPublica", ['documento' => $documento, 'fecha_nacimiento' => $fecha]);
}
function intento(string $ambito, string $id): ?array
{
    global $bd;
    $s = $bd->prepare('SELECT * FROM intento_acceso WHERE ambito = ? AND identificador = ?');
    $s->execute([$ambito, $id]);
    return $s->fetch() ?: null;
}
function cuenta(string $correo): array
{
    global $bd;
    $s = $bd->prepare('SELECT * FROM usuario_administrativo WHERE correo = ?');
    $s->execute([$correo]);
    return $s->fetch();
}
function archivoSesion(string $cookie): string
{
    global $carpetaSesiones;
    clearstatcache();
    return $carpetaSesiones . '/sess_' . substr($cookie, strlen('HWANGHON_SESION='));
}
function moverUltimaActividad(string $cookie, int $segundosAtras): void
{
    $archivo = archivoSesion($cookie);
    $contenido = file_get_contents($archivo);
    $contenido = preg_replace('/ultima_actividad\|i:\d+;/', 'ultima_actividad|i:' . (time() - $segundosAtras) . ';', $contenido);
    file_put_contents($archivo, $contenido);
}
function ultimaActividad(string $cookie): ?int
{
    $contenido = @file_get_contents(archivoSesion($cookie));
    return ($contenido !== false && preg_match('/ultima_actividad\|i:(\d+);/', $contenido, $m)) ? (int) $m[1] : null;
}
function correosGuardados(): array
{
    global $carpetaCorreos;
    return is_dir($carpetaCorreos) ? glob($carpetaCorreos . '/*.eml') : [];
}
/** El correo se escribe DESPUÉS de responder (envío diferido): sondeo corto, hasta 3 s. */
function tokenDelUltimoCorreo(array $antes): ?string
{
    for ($i = 0; $i < 30; $i++) {
        clearstatcache();
        $nuevos = array_values(array_diff(correosGuardados(), $antes));
        if (count($nuevos) === 1 && preg_match('/token=([0-9a-f]{64})/', (string) file_get_contents($nuevos[0]), $m)) {
            return $m[1];
        }
        usleep(100000);
    }
    return null;
}
/** Espera corta para confirmar que NO llega un correo nuevo (el envío es diferido). */
function sinCorreoNuevo(array $antes): bool
{
    usleep(1500000);
    clearstatcache();
    return correosGuardados() === $antes;
}

// Cuentas de prueba propias (ficticias).
$clave = 'ClavePrueba1';
$hash = password_hash($clave, PASSWORD_DEFAULT);
$crear = $bd->prepare("INSERT INTO usuario_administrativo (correo, contrasena, nombre, rol, es_administrador_principal, activa, creado_por_correo) VALUES (?, ?, ?, 'staff', ?, ?, NULL)");
$crear->execute(['prueba.admin@ficticio.test', $hash, 'Admin de Prueba', 1, 1]);
$crear->execute(['prueba.staff@ficticio.test', $hash, 'Staff de Prueba', 0, 1]);
$crear->execute(['prueba.inactiva@ficticio.test', $hash, 'Inactiva de Prueba', 0, 0]);
$crear->execute(['prueba.bloqueo@ficticio.test', $hash, 'Bloqueo de Prueba', 0, 1]);
$crear->execute(['prueba.recupera@ficticio.test', $hash, 'Recupera de Prueba', 0, 1]);
$crear->execute(['prueba.cambia@ficticio.test', $hash, 'Cambia de Prueba', 0, 1]);
$crear->execute(['prueba.desactivar@ficticio.test', $hash, 'Desactivar de Prueba', 0, 1]);

$MSG = 'Correo o contraseña incorrectos.';

echo "\n[B. login administrativo]\n";
[$c, $j, $cookieAdmin] = loginAdmin('prueba.admin@ficticio.test', $clave);
comprobar('acierto -> 200 con {ambito, correo, nombre, es_administrador_principal} y cookie', $c === 200 && $cookieAdmin !== ''
    && $j['datos'] === ['ambito' => 'administrativa', 'correo' => 'prueba.admin@ficticio.test', 'nombre' => 'Admin de Prueba', 'es_administrador_principal' => true], json_encode($j));
[$c, $j] = loginAdmin('  PRUEBA.Staff@Ficticio.TEST ', $clave);
comprobar('correo con espacios y mayúsculas se normaliza -> 200', $c === 200 && $j['datos']['correo'] === 'prueba.staff@ficticio.test' && $j['datos']['es_administrador_principal'] === false);
[$c1, $j1] = loginAdmin('prueba.staff@ficticio.test', 'ClaveErrada1');
[$c2, $j2] = loginAdmin('no.existe@ficticio.test', $clave);
[$c3, $j3] = loginAdmin('prueba.inactiva@ficticio.test', $clave);
comprobar('clave errada, correo inexistente y cuenta desactivada -> mismo 401 y mismo mensaje',
    $c1 === 401 && $c2 === 401 && $c3 === 401 && $j1 == $j2 && $j2 == $j3 && $j1['mensaje'] === $MSG, json_encode([$j1, $j2, $j3]));
comprobar('cada uno de esos fallos sumó 1 al contador (también el inexistente)',
    intento('administrativa', 'prueba.staff@ficticio.test')['intentos_fallidos'] == 1 && intento('administrativa', 'no.existe@ficticio.test')['intentos_fallidos'] == 1
    && intento('administrativa', 'prueba.inactiva@ficticio.test')['intentos_fallidos'] == 1);
[$c] = peticion('POST', "{$auth}&accion=iniciarSesionAdministrativa", ['correo' => 'prueba.admin@ficticio.test']);
comprobar('falta la contraseña -> 400', $c === 400, (string) $c);
[$c] = loginAdmin(str_repeat('a', 140) . '@ficticio.test', $clave);
comprobar('correo de más de 150 caracteres -> 400 y no cuenta como fallo', $c === 400 && intento('administrativa', str_repeat('a', 140) . '@ficticio.test') === null, (string) $c);
[$c] = loginAdmin('sin-arroba', $clave);
comprobar('correo mal formado -> 400 y no cuenta', $c === 400 && intento('administrativa', 'sin-arroba') === null);

$codigos = [];
for ($i = 1; $i <= 4; $i++) { [$codigos[$i], $jj] = loginAdmin('prueba.bloqueo@ficticio.test', 'Errada' . $i . 'xx'); }
comprobar('fallos 1 a 3 -> 401; el 4.º -> 429', $codigos[1] === 401 && $codigos[2] === 401 && $codigos[3] === 401 && $codigos[4] === 429, json_encode($codigos));
comprobar('el 4.º fallo dice «Intenta de nuevo en 15 minutos.»', ($jj['mensaje'] ?? '') === 'Demasiados intentos fallidos. Intenta de nuevo en 15 minutos.', json_encode($jj));
$fila = intento('administrativa', 'prueba.bloqueo@ficticio.test');
$diferencia = strtotime($fila['bloqueado_hasta']) - time();
comprobar('BD: intentos_fallidos = 4 y bloqueado_hasta = ahora + 15 min (hora de Bogotá)', $fila['intentos_fallidos'] == 4 && $diferencia > 890 && $diferencia <= 900, $fila['bloqueado_hasta']);
[$c, $j, $cookieBloqueo] = loginAdmin('prueba.bloqueo@ficticio.test', $clave);
comprobar('clave CORRECTA durante el bloqueo -> 429, sin sesión', $c === 429 && $cookieBloqueo === '', (string) $c);
$bd->exec("UPDATE intento_acceso SET bloqueado_hasta = '" . date('Y-m-d H:i:s', time() - 60) . "' WHERE identificador = 'prueba.bloqueo@ficticio.test'");
[$c] = loginAdmin('prueba.bloqueo@ficticio.test', 'OtraErrada1');
$fila = intento('administrativa', 'prueba.bloqueo@ficticio.test');
comprobar('bloqueo vencido: el siguiente fallo -> 401 y cuenta como 1 (no 5), bloqueo borrado', $c === 401 && $fila['intentos_fallidos'] == 1 && $fila['bloqueado_hasta'] === null, json_encode($fila));
[$c] = loginAdmin('prueba.bloqueo@ficticio.test', $clave);
comprobar('el acierto reinicia el contador (fila eliminada)', $c === 200 && intento('administrativa', 'prueba.bloqueo@ficticio.test') === null, (string) $c);
for ($i = 1; $i <= 4; $i++) { [$codigos[$i], $jj] = loginAdmin('fantasma@ficticio.test', 'Errada' . $i . 'xx'); }
[$c5, $j5] = loginAdmin('fantasma@ficticio.test', 'Otra1234');
comprobar('un correo inexistente también se bloquea (4.º -> 429 y siguiente -> 429)', $codigos[4] === 429 && $c5 === 429 && str_starts_with($j5['mensaje'], 'Demasiados intentos fallidos.'), json_encode([$codigos, $j5]));

echo "\n[C. login público]\n";
[$c, $j, $cookiePublica] = loginPublico('1000000101', '2014-03-12');
comprobar('acierto -> 200 con {ambito, documento, nombre} y cookie', $c === 200 && $cookiePublica !== ''
    && $j['datos'] === ['ambito' => 'publica', 'documento' => '1000000101', 'nombre' => 'Samuel Reyes Ortiz'], json_encode($j));
[$c1, $j1] = loginPublico('1000000101', '2014-03-13');
[$c2, $j2] = loginPublico('9999999999', '2014-03-12');
comprobar('fecha errada y documento inexistente -> mismo 401 y mismo mensaje', $c1 === 401 && $c2 === 401 && $j1 == $j2, json_encode([$j1, $j2]));
[$c] = loginPublico('1000000101', '12/03/2014');
comprobar('fecha mal formada -> 400 y no cuenta', $c === 400 && intento('publica', '1000000101')['intentos_fallidos'] == 1, (string) $c);
[$c] = loginPublico('1000000101', '2014-02-30');
comprobar('fecha imposible (30 de febrero) -> 400', $c === 400, (string) $c);
[$c] = loginPublico(str_repeat('1', 21), '2014-03-12');
comprobar('documento de más de 20 caracteres -> 400 y no cuenta', $c === 400 && intento('publica', str_repeat('1', 21)) === null, (string) $c);
for ($i = 1; $i <= 4; $i++) { [$codigos[$i]] = loginPublico('1000000103', '2000-01-0' . $i); }
[$c5] = loginPublico('1000000103', '1998-11-05');
comprobar('público: 4.º fallo -> 429 y con datos correctos durante el bloqueo -> 429', $codigos[4] === 429 && $c5 === 429, json_encode([$codigos, $c5]));
$bd->exec("UPDATE intento_acceso SET bloqueado_hasta = '" . date('Y-m-d H:i:s', time() - 1) . "' WHERE ambito = 'publica' AND identificador = '1000000103'");
[$c, $j, $cookieInactivo] = loginPublico('1000000103', '1998-11-05');
comprobar('deportista INACTIVO entra con normalidad (tras vencer el bloqueo) -> 200 y reinicia contador',
    $c === 200 && $cookieInactivo !== '' && intento('publica', '1000000103') === null, (string) $c);
loginPublico('1000000102', '2000-01-01');
loginPublico('1000000102', '2000-01-02');
[$c, $j, $cookiePendiente] = loginPublico('1000000102', '2005-07-20');
comprobar('pendiente_validacion con datos correctos -> 403 «Tu registro está en revisión.» sin sesión',
    $c === 403 && $j['mensaje'] === 'Tu registro está en revisión.' && $cookiePendiente === '', $c . json_encode($j));
comprobar('pendiente: no cuenta como fallo y reinicia el contador (tenía 2)', intento('publica', '1000000102') === null);

echo "\n[Sesión y middleware]\n";
[$c] = peticion('GET', "{$auth}&accion=sesionActual");
comprobar('sesionActual sin cookie -> 401', $c === 401, (string) $c);
[$c] = peticion('GET', "{$dep}&accion=listar");
comprobar('ruta autenticada sin cookie -> 401', $c === 401, (string) $c);
[$c, $j] = peticion('GET', "{$auth}&accion=sesionActual", null, $cookieAdmin);
comprobar('sesionActual administrativa -> {ambito, identificador, nombre, es_administrador_principal}', $c === 200
    && $j['datos'] === ['ambito' => 'administrativa', 'identificador' => 'prueba.admin@ficticio.test', 'nombre' => 'Admin de Prueba', 'es_administrador_principal' => true], json_encode($j));
[$c, $j] = peticion('GET', "{$auth}&accion=sesionActual", null, $cookiePublica);
comprobar('sesionActual pública -> sin es_administrador_principal', $c === 200
    && $j['datos'] === ['ambito' => 'publica', 'identificador' => '1000000101', 'nombre' => 'Samuel Reyes Ortiz'], json_encode($j));
comprobar('las sesiones se guardan en backend/Almacenamiento/sesiones/', is_file(archivoSesion($cookieAdmin)));
$ini = json_decode((string) file_get_contents("{$base}/__ini"), true);
comprobar('al arrancar, Sesion fija save_path propio y gc_maxlifetime >= 7200',
    str_ends_with(str_replace('\\', '/', $ini['save_path']), '/Almacenamiento/sesiones') && (int) $ini['gc_maxlifetime'] >= 7200, json_encode($ini));

moverUltimaActividad($cookieAdmin, 7100);
[$c] = peticion('GET', "{$dep}&accion=listar", null, $cookieAdmin);
comprobar('administrativa con 7100 s de inactividad sigue válida -> 200 y renueva la marca', $c === 200 && abs(ultimaActividad($cookieAdmin) - time()) <= 2, (string) $c);
moverUltimaActividad($cookieAdmin, 7201);
[$c] = peticion('GET', "{$dep}&accion=listar", null, $cookieAdmin);
comprobar('administrativa con 7201 s de inactividad -> 401 y el archivo de sesión se destruye', $c === 401 && !is_file(archivoSesion($cookieAdmin)), (string) $c);
moverUltimaActividad($cookiePublica, 1700);
[$c] = peticion('GET', "{$dep}&accion=listar", null, $cookiePublica);
comprobar('pública con 1700 s de inactividad sigue válida -> 200', $c === 200, (string) $c);
moverUltimaActividad($cookiePublica, 1801);
[$c] = peticion('GET', "{$auth}&accion=sesionActual", null, $cookiePublica);
comprobar('pública con 1801 s de inactividad -> 401', $c === 401, (string) $c);

[, , $cookieDesactivar] = loginAdmin('prueba.desactivar@ficticio.test', $clave);
[$c] = peticion('GET', "{$dep}&accion=listar", null, $cookieDesactivar);
comprobar('cuenta activa: la cookie funciona -> 200', $c === 200);
$bd->exec("UPDATE usuario_administrativo SET activa = 0 WHERE correo = 'prueba.desactivar@ficticio.test'");
[$c, $j] = peticion('GET', "{$dep}&accion=listar", null, $cookieDesactivar);
comprobar('cuenta desactivada en la BD: siguiente petición con la misma cookie -> 401 «Tu sesión ya no es válida.»', $c === 401 && $j['mensaje'] === 'Tu sesión ya no es válida.', $c . json_encode($j));
$bd->exec("UPDATE usuario_administrativo SET activa = 1 WHERE correo = 'prueba.desactivar@ficticio.test'");
[$c] = peticion('GET', "{$dep}&accion=listar", null, $cookieDesactivar);
comprobar('la sesión quedó cerrada: aunque se reactive la cuenta, la vieja cookie -> 401', $c === 401, (string) $c);
[, , $cookieDesactivar] = loginAdmin('prueba.desactivar@ficticio.test', $clave);
$bd->exec("UPDATE usuario_administrativo SET activa = 0 WHERE correo = 'prueba.desactivar@ficticio.test'");
[$c, $j] = peticion('GET', "{$adm}&accion=listar", null, $cookieDesactivar);
comprobar('ruta solo administrativa (AmbitoMiddleware) usa el mismo chequeo -> 401', $c === 401 && $j['mensaje'] === 'Tu sesión ya no es válida.', $c . json_encode($j));
[$c] = peticion('GET', "{$adm}&accion=listar", null, $cookieInactivo);
comprobar('ruta solo administrativa con sesión pública -> 403', $c === 403, (string) $c);

[, , $cookieCerrar] = loginAdmin('prueba.admin@ficticio.test', $clave);
[$c1, $j1] = peticion('POST', "{$auth}&accion=cerrarSesion", null, $cookieCerrar);
[$c2, $j2] = peticion('POST', "{$auth}&accion=cerrarSesion", null, $cookieCerrar);
[$c3] = peticion('POST', "{$auth}&accion=cerrarSesion");
comprobar('cerrar sesión dos veces (y sin cookie) -> 200 sin error, {sesion_cerrada: true}', $c1 === 200 && $c2 === 200 && $c3 === 200 && $j1['datos'] === ['sesion_cerrada' => true]);
[$c] = peticion('GET', "{$auth}&accion=sesionActual", null, $cookieCerrar);
comprobar('después de cerrar, sesionActual -> 401', $c === 401, (string) $c);

echo "\n[E. cambiar contraseña]\n";
$cambiar = "{$auth}&accion=cambiarContrasena";
[$c] = peticion('POST', $cambiar, ['contrasena_actual' => $clave, 'contrasena_nueva' => 'NuevaClave99']);
comprobar('sin sesión -> 401', $c === 401, (string) $c);
[, , $cookiePub2] = loginPublico('1000000101', '2014-03-12');
[$c] = peticion('POST', $cambiar, ['contrasena_actual' => 'x', 'contrasena_nueva' => 'NuevaClave99'], $cookiePub2);
comprobar('ámbito público -> 403', $c === 403, (string) $c);
[, , $cookieCambia] = loginAdmin('prueba.cambia@ficticio.test', $clave);
$bd->exec("UPDATE usuario_administrativo SET token_recuperacion = '" . str_repeat('a', 64) . "', token_recuperacion_expira = '" . date('Y-m-d H:i:s', time() + 3000) . "' WHERE correo = 'prueba.cambia@ficticio.test'");
[$c, $j] = peticion('POST', $cambiar, ['contrasena_actual' => 'NoEsLaActual1', 'contrasena_nueva' => 'NuevaClave99'], $cookieCambia);
comprobar('contraseña actual incorrecta -> 400', $c === 400 && $j['mensaje'] === 'La contraseña actual no es correcta.', $c . json_encode($j));
[$c, $j] = peticion('POST', $cambiar, ['contrasena_actual' => $clave, 'contrasena_nueva' => '1234567'], $cookieCambia);
comprobar('nueva de 7 caracteres -> 400', $c === 400 && $j['mensaje'] === 'La contraseña debe tener al menos 8 caracteres.', $c . json_encode($j));
[$c, $j] = peticion('POST', $cambiar, ['contrasena_actual' => $clave, 'contrasena_nueva' => str_repeat('a', 73)], $cookieCambia);
comprobar('nueva de 73 bytes -> 400 «no puede superar 72 bytes»', $c === 400 && $j['mensaje'] === 'La contraseña no puede superar 72 bytes (72 caracteres sin tildes; cada tilde, ñ o símbolo especial ocupa 2 o más).', $c . json_encode($j));
[$c, $j] = peticion('POST', $cambiar, ['contrasena_actual' => $clave, 'contrasena_nueva' => 'NuevaClave99'], $cookieCambia);
$fila = cuenta('prueba.cambia@ficticio.test');
comprobar('acierto -> 200 {contrasena_cambiada: true}, hash nuevo y token en NULL', $c === 200 && $j['datos'] === ['contrasena_cambiada' => true]
    && password_verify('NuevaClave99', $fila['contrasena']) && $fila['token_recuperacion'] === null && $fila['token_recuperacion_expira'] === null, $c . json_encode($j));
[$c] = loginAdmin('prueba.cambia@ficticio.test', 'NuevaClave99');
[$cVieja] = loginAdmin('prueba.cambia@ficticio.test', $clave);
comprobar('login con la nueva -> 200; con la vieja -> 401', $c === 200 && $cVieja === 401, "{$c} {$cVieja}");

if (getenv('SALTAR_RECUPERACION') === '1') {
    echo "\n[F. recuperación por correo] OMITIDA: Config/correo.php no está en modo 'archivo' (evita envíos reales)\n";
    goto seguridad;
}
echo "\n[F. recuperación por correo]\n";
$solicitar = "{$auth}&accion=solicitarRecuperacionContrasena";
$restablecer = "{$auth}&accion=restablecerContrasena";
$MSG_REC = 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.';
$antes = correosGuardados();
[$c1, $j1, , $t1] = peticion('POST', $solicitar, ['correo' => 'prueba.recupera@ficticio.test']);
$token = tokenDelUltimoCorreo($antes);
$antes2 = correosGuardados();
[$c2, $j2, , $t2] = peticion('POST', $solicitar, ['correo' => 'nadie@ficticio.test']);
comprobar('cuenta existente e inexistente -> mismo 200 y mismo cuerpo', $c1 === 200 && $c2 === 200 && $t1 === $t2 && $j1['datos']['mensaje'] === $MSG_REC, $t1 . ' | ' . $t2);
comprobar('solo la existente generó correo (modo archivo) y el token se lee del enlace', $token !== null && sinCorreoNuevo($antes2));
$fila = cuenta('prueba.recupera@ficticio.test');
comprobar('BD: solo el SHA-256 del token (64 hex), distinto del token del correo', preg_match('/^[0-9a-f]{64}$/', $fila['token_recuperacion']) === 1
    && $fila['token_recuperacion'] === hash('sha256', $token) && $fila['token_recuperacion'] !== $token);
$expira = strtotime($fila['token_recuperacion_expira']) - time();
comprobar('BD: vence en 1 hora (hora de Bogotá)', $expira > 3590 && $expira <= 3600, (string) $expira);
$hashAntes = $fila['token_recuperacion'];
$antes = correosGuardados();
[$c] = peticion('POST', $solicitar, ['correo' => 'prueba.recupera@ficticio.test']);
comprobar('con el enlace VIGENTE, otra solicitud -> 200, no cambia el hash ni envía correo (un enlace vigente nunca se reemplaza)', $c === 200 && sinCorreoNuevo($antes) && cuenta('prueba.recupera@ficticio.test')['token_recuperacion'] === $hashAntes);
$antes = correosGuardados();
[$c] = peticion('POST', $solicitar, ['correo' => 'prueba.inactiva@ficticio.test']);
comprobar('cuenta desactivada: mismo 200 y no genera token ni correo', $c === 200 && sinCorreoNuevo($antes) && cuenta('prueba.inactiva@ficticio.test')['token_recuperacion'] === null);
[$c] = peticion('POST', $solicitar, ['correo' => '']);
comprobar('correo vacío -> 400 (dato mal formado)', $c === 400, (string) $c);

loginAdmin('prueba.recupera@ficticio.test', 'Errada1xx');
loginAdmin('prueba.recupera@ficticio.test', 'Errada2xx');
[$c, $j] = peticion('POST', $restablecer, ['token' => $token, 'contrasena_nueva' => '1234567']);
comprobar('restablecer con clave corta -> 400 (el token sigue guardado)', $c === 400 && cuenta('prueba.recupera@ficticio.test')['token_recuperacion'] === $hashAntes, (string) $c);
[$c] = peticion('POST', $restablecer, ['token' => 'zz' . substr($token, 2), 'contrasena_nueva' => 'Restablecida1']);
comprobar('token mal formado -> 400', $c === 400, (string) $c);
[$c, $j] = peticion('POST', $restablecer, ['token' => $token, 'contrasena_nueva' => 'Restablecida1']);
$fila = cuenta('prueba.recupera@ficticio.test');
comprobar('restablecer OK -> 200 {contrasena_restablecida: true}', $c === 200 && $j['datos'] === ['contrasena_restablecida' => true], $c . json_encode($j));
comprobar('BD: hash nuevo, token y vencimiento en NULL', password_verify('Restablecida1', $fila['contrasena']) && $fila['token_recuperacion'] === null && $fila['token_recuperacion_expira'] === null);
comprobar('el contador de intentos de ese correo quedó en cero (tenía 2)', intento('administrativa', 'prueba.recupera@ficticio.test') === null);
[$c, $j, $cookieRest] = peticion('POST', $restablecer, ['token' => $token, 'contrasena_nueva' => 'OtraMas123']);
comprobar('reutilizar el token -> 400 «El enlace no es válido o ya venció.» y no inicia sesión', $c === 400 && $j['mensaje'] === 'El enlace no es válido o ya venció.' && $cookieRest === '', $c . json_encode($j));
[$c] = loginAdmin('prueba.recupera@ficticio.test', 'Restablecida1');
comprobar('login con la clave restablecida -> 200', $c === 200, (string) $c);

// Enlace VIGENTE (vence en 59 minutos): no se genera otro.
$bd->exec("UPDATE usuario_administrativo SET token_recuperacion_expira = '" . date('Y-m-d H:i:s', time() + 3540) . "', token_recuperacion = '" . str_repeat('b', 64) . "' WHERE correo = 'prueba.recupera@ficticio.test'");
$antes = correosGuardados();
peticion('POST', $solicitar, ['correo' => 'prueba.recupera@ficticio.test']);
comprobar('token vigente -> no se genera uno nuevo ni se envía correo', sinCorreoNuevo($antes) && cuenta('prueba.recupera@ficticio.test')['token_recuperacion'] === str_repeat('b', 64));
// Enlace VENCIDO (expiró hace un minuto): sí se genera uno nuevo.
$bd->exec("UPDATE usuario_administrativo SET token_recuperacion_expira = '" . date('Y-m-d H:i:s', time() - 60) . "' WHERE correo = 'prueba.recupera@ficticio.test'");
$antes = correosGuardados();
peticion('POST', $solicitar, ['correo' => 'prueba.recupera@ficticio.test']);
$tokenVencido = tokenDelUltimoCorreo($antes);
comprobar('token vencido -> sí se genera uno nuevo y llega su correo', $tokenVencido !== null && cuenta('prueba.recupera@ficticio.test')['token_recuperacion'] === hash('sha256', (string) $tokenVencido));
$bd->exec("UPDATE usuario_administrativo SET token_recuperacion_expira = '" . date('Y-m-d H:i:s', time() - 1) . "' WHERE correo = 'prueba.recupera@ficticio.test'");
[$c] = peticion('POST', $restablecer, ['token' => $tokenVencido, 'contrasena_nueva' => 'Vencida123']);
comprobar('token vencido -> 400', $c === 400 && password_verify('Restablecida1', cuenta('prueba.recupera@ficticio.test')['contrasena']), (string) $c);

$bd->exec("UPDATE usuario_administrativo SET token_recuperacion = NULL, token_recuperacion_expira = NULL WHERE correo = 'prueba.recupera@ficticio.test'");
$antes = correosGuardados();
peticion('POST', $solicitar, ['correo' => 'prueba.recupera@ficticio.test']);
$tokenDesactivada = tokenDelUltimoCorreo($antes);
$bd->exec("UPDATE usuario_administrativo SET activa = 0 WHERE correo = 'prueba.recupera@ficticio.test'");
[$c] = peticion('POST', $restablecer, ['token' => $tokenDesactivada, 'contrasena_nueva' => 'Desactiva123']);
comprobar('token válido de una cuenta desactivada -> 400', $tokenDesactivada !== null && $c === 400 && password_verify('Restablecida1', cuenta('prueba.recupera@ficticio.test')['contrasena']), (string) $c);

seguridad:
echo "\n[Seguridad]\n";
clearstatcache();
$vacios = array_filter(glob($carpetaSesiones . '/sess_*'), fn ($f) => filesize($f) === 0);
comprobar('no quedan archivos de sesión vacíos (cookies viejas no abren sesiones nuevas)', $vacios === [], implode(', ', $vacios));
[$c, , $cookieNueva] = peticion('GET', "{$auth}&accion=sesionActual", null, 'HWANGHON_SESION=idquenoexiste123');
comprobar('cookie de una sesión inexistente -> 401 y no entrega una cookie de sesión nueva', $c === 401 && ($cookieNueva === '' || $cookieNueva === 'HWANGHON_SESION=deleted'), $cookieNueva);
$filtradas = array_filter($respuestas, fn ($r) => preg_match('/"(contrasena|token_recuperacion|token_recuperacion_expira)"\s*:/', $r) === 1
    || str_contains($r, '$2y$'));
comprobar('ninguna de las ' . count($respuestas) . ' respuestas trae contrasena, token_recuperacion ni un hash', $filtradas === [], implode("\n", $filtradas));

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
