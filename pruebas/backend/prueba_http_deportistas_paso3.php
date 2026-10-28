<?php

declare(strict_types=1);

// Bateria HTTP del paso 3 (Deportistas: buscar, categoria_peso, record), fuera del repositorio.
// Uso: php prueba_http_deportistas_paso3.php <url base>
// Requiere: esquema v4 + semilla + base_datos/cuentas_prueba_locales.sql recien cargados.

$base = rtrim($argv[1], '/');
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
$dep = "{$base}/api.php?recurso=deportistas";
function buscar(string $texto, ?string $cookie): array
{
    global $dep;
    return peticion('GET', "{$dep}&accion=buscar&texto=" . rawurlencode($texto), null, $cookie);
}

$insertar = $bd->prepare('INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro, grado_actual, telefono, discapacidad) '
    . 'VALUES (?, ?, ?, ?, 1.50, ?, 1, ?, ?, ?, ?, ?, ?)');
function nacimientoParaEdad(int $edad): string
{
    return date('Y-m-d', strtotime("-{$edad} years -40 days"));
}

// Datos de buscar.
$hoy = date('Y-m-d');
$insertar->execute(['7000000001', 'Ana 100% Real', '2000-01-01', 'femenino', '55.00', $hoy, 'activo', 'validado', null, '3001112233', 'Asma']);
$insertar->execute(['7000000002', 'Juan_Pablo Prueba', '2000-01-01', 'masculino', '70.00', $hoy, 'activo', 'validado', null, null, null]);
$insertar->execute(['7000000003', 'Juan Pablo Prueba', '2000-01-01', 'masculino', '70.00', $hoy, 'activo', 'validado', null, null, null]);
$insertar->execute(['7000000004', 'Barra\\Invertida Prueba', '2000-01-01', 'masculino', '70.00', $hoy, 'activo', 'validado', null, null, null]);
for ($i = 25; $i >= 1; $i--) {
    $insertar->execute([sprintf('71%08d', $i), sprintf('Buscable Repetido %02d', $i), '2000-01-01', 'femenino', '60.00', $hoy,
        $i % 3 === 0 ? 'inactivo' : 'activo', $i % 4 === 0 ? 'pendiente_validacion' : 'validado', 'amarillo', null, null]);
}

// Datos de categoria_peso: [categoria de edad, edad, sexo, peso, esperado].
$tablas = [
    'Infantil' => [9, ['masculino' => [20, 25, 30, 35], 'femenino' => [20, 25, 30, 35]]],
    'Junior' => [13, ['masculino' => [40, 45, 50, 55, 60], 'femenino' => [38, 43, 48, 53, 58]]], // OFICIAL Fedecolhap v3.1 2024
    'Juvenil (15 años)' => [15, ['masculino' => [45, 50, 55, 60, 65, 70, 75], 'femenino' => [43, 48, 53, 58, 63, 68, 73]]], // OFICIAL
    'Juvenil (17 años)' => [17, ['masculino' => [45, 50, 55, 60, 65, 70, 75], 'femenino' => [43, 48, 53, 58, 63, 68, 73]]],
    'Mayores' => [25, ['masculino' => [55, 60, 65, 70, 75, 80, 85], 'femenino' => [50, 55, 60, 65, 70, 75, 80]]],
    'Sénior' => [40, ['masculino' => [55, 60, 65, 70, 75, 80, 85], 'femenino' => [50, 55, 60, 65, 70, 75, 80]]],
    'Máster' => [50, ['masculino' => [55, 60, 65, 70, 75, 80, 85], 'femenino' => [50, 55, 60, 65, 70, 75, 80]]],
];
$casos = [];
$n = 0;
foreach ($tablas as $categoria => [$edad, $porSexo]) {
    foreach ($porSexo as $sexo => $cortes) {
        $casos[] = [$categoria, $edad, $sexo, $cortes[0] - 5, '-' . $cortes[0]];
        foreach ($cortes as $k => $corte) {
            $casos[] = [$categoria, $edad, $sexo, $corte, '-' . $corte];
            $siguiente = $cortes[$k + 1] ?? null;
            $casos[] = [$categoria, $edad, $sexo, $corte + 0.01, $siguiente === null ? '+' . $corte : '-' . $siguiente];
        }
    }
}
$casos[] = ['menor de 8 años', 7, 'masculino', 25, null];
$casos[] = ['peso faltante (0)', 25, 'femenino', 0, null];
$documentoCaso = [];
foreach ($casos as $i => [$categoria, $edad, $sexo, $peso, $esperado]) {
    $documento = sprintf('8%09d', $i + 1);
    $documentoCaso[$i] = $documento;
    $insertar->execute([$documento, "Peso Caso {$i}", nacimientoParaEdad($edad), $sexo, sprintf('%.2f', $peso), $hoy, 'activo', 'validado', null, null, null]);
}

// Datos del record: 1000000103 con dos participaciones con combates (3/0 y 1/2).
$bd->exec("INSERT INTO participacion_competencia (deportista_documento, competencia_evento_id, modalidad, categoria_edad, combates_ganados, combates_perdidos) VALUES "
    . "('1000000103', 1, 'combate_equipos', 'mayores', 3, 0), ('1000000103', 1, 'salto_largo', 'mayores', 1, 2)");

[, , $admin] = peticion('POST', "{$base}/api.php?recurso=auth&accion=iniciarSesionAdministrativa", ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']);
[, , $publica] = peticion('POST', "{$base}/api.php?recurso=auth&accion=iniciarSesionPublica", ['documento' => '1000000101', 'fecha_nacimiento' => '2014-03-12']);
comprobar('sesiones administrativa y pública creadas', $admin !== '' && $publica !== '');

echo "\n[1. buscar]\n";
foreach (['a' => '1 carácter', ' a ' => '1 carácter tras recortar espacios', '' => 'vacío'] as $texto => $caso) {
    [$c, $j] = buscar($texto, $admin);
    comprobar("buscar con {$caso} -> 400", $c === 400 && $j['mensaje'] === 'Escribe al menos 2 caracteres para buscar.', $c . json_encode($j));
}
[$c] = peticion('GET', "{$dep}&accion=buscar", null, $admin);
comprobar('buscar sin parámetro texto -> 400', $c === 400, (string) $c);
[$c, $j] = buscar('1000000', $admin);
$docs = array_column($j['datos'] ?? [], 'documento');
comprobar('por inicio del documento: los 3 de la semilla, incluido el pendiente (1000000102) y el inactivo (1000000103)', $c === 200 && $docs === ['1000000103', '1000000102', '1000000101'], json_encode($docs));
comprobar('cada resultado trae solo documento, nombre, estado, estado_registro y grado_actual', array_filter($j['datos'], fn ($f) => array_keys($f) !== ['documento', 'nombre', 'estado', 'estado_registro', 'grado_actual']) === [], json_encode($j['datos'][0] ?? null));
comprobar('incluye estados inactivo y pendiente_validacion', in_array('inactivo', array_column($j['datos'], 'estado'), true) && in_array('pendiente_validacion', array_column($j['datos'], 'estado_registro'), true));
[$c, $j] = buscar('000000101', $admin);
comprobar('el documento solo coincide por el INICIO (texto del medio -> 0 resultados)', $c === 200 && $j['datos'] === [], json_encode($j['datos']));
[$c, $j] = buscar('REYES', $admin);
comprobar('por nombre parcial sin importar mayúsculas (REYES -> Samuel Reyes Ortiz)', $c === 200 && array_column($j['datos'], 'documento') === ['1000000101'], json_encode($j['datos']));
[$c, $j] = buscar('fabian esteban rincon pena', $admin);
comprobar('sin importar tildes ni ñ (fabian esteban rincon pena -> Fabián Esteban Rincón Peña)', $c === 200 && array_column($j['datos'], 'documento') === ['1000000103'], json_encode($j['datos']));
[$c, $j] = buscar('0%', $admin);
comprobar('% es literal: "0%" solo encuentra "Ana 100% Real"', $c === 200 && array_column($j['datos'], 'documento') === ['7000000001'], json_encode(array_column($j['datos'], 'nombre')));
[$c, $j] = buscar('%%', $admin);
comprobar('"%%" literal -> 0 resultados (como comodín traería todo)', $c === 200 && $j['datos'] === [], (string) count($j['datos'] ?? []));
[$c, $j] = buscar('n_P', $admin);
comprobar('_ es literal: "n_P" encuentra Juan_Pablo y no "Juan Pablo"', $c === 200 && array_column($j['datos'], 'documento') === ['7000000002'], json_encode(array_column($j['datos'], 'nombre')));
[$c, $j] = buscar('a\\I', $admin);
comprobar('la barra invertida es literal: "a\\I" encuentra "Barra\\Invertida Prueba"', $c === 200 && array_column($j['datos'], 'documento') === ['7000000004'], json_encode(array_column($j['datos'], 'nombre')));
[$c, $j] = buscar('Buscable', $admin);
$nombres = array_column($j['datos'], 'nombre');
$esperados = array_map(fn ($i) => sprintf('Buscable Repetido %02d', $i), range(1, 20));
comprobar('máximo 20 resultados, orden nombre y documento (hay 25 coincidencias)', $c === 200 && $nombres === $esperados, json_encode($nombres));
[$c] = buscar('Reyes', $publica);
comprobar('ámbito público -> 403', $c === 403, (string) $c);
[$c] = buscar('Reyes', null);
comprobar('sin sesión -> 401', $c === 401, (string) $c);
[$c] = peticion('POST', "{$dep}&accion=buscar&texto=Reyes", null, $admin);
comprobar('buscar con POST -> 405', $c === 405, (string) $c);

echo "\n[2. categoria_peso]\n";
[$c, $j] = peticion('GET', "{$dep}&accion=listar", null, $admin);
$porDocumento = array_column($j['datos'] ?? [], null, 'documento');
foreach ($casos as $i => [$categoria, $edad, $sexo, $peso, $esperado]) {
    $fila = $porDocumento[$documentoCaso[$i]] ?? null;
    $obtenido = ($fila !== null && array_key_exists('categoria_peso', $fila)) ? $fila['categoria_peso'] : 'SIN FILA';
    $edadCategoria = $fila['categoria_edad'] ?? '?';
    comprobar(sprintf('%s %s, %d años (%s), %.2f kg -> %s', $categoria, $sexo, $edad, $edadCategoria, $peso, var_export($esperado, true)),
        $fila !== null && $obtenido === $esperado, var_export($obtenido, true));
}
$casoQuince = array_search('Juvenil (15 años)', array_column($casos, 0), true);
comprobar('a los 15 años la categoría por edad es Juvenil (tabla oficial Juvenil)', ($porDocumento[$documentoCaso[$casoQuince]]['categoria_edad'] ?? '') === 'Juvenil');
comprobar('categoria_peso es texto (ej. "-55", "+85")', is_string($porDocumento['1000000103']['categoria_peso'] ?? null));
$columnas = $bd->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'hwanghon' AND table_name = 'deportista' AND column_name = 'categoria_peso'")->fetchColumn();
comprobar('categoria_peso no se guarda (no existe esa columna en deportista)', (int) $columnas === 0);
[$c, $j] = peticion('GET', "{$dep}&accion=consultar&documento=1000000103", null, $admin);
comprobar('consultar también trae categoria_peso (Fabián, 78.50 kg, Mayores masculino -> -80)', $c === 200 && $j['datos']['categoria_peso'] === '-80', json_encode($j['datos']['categoria_peso'] ?? null));
[$c, $j] = peticion('GET', "{$dep}&accion=listar", null, $publica);
comprobar('público: listar trae solo la suya con categoria_peso (Samuel, 12 años, 45.30 kg, Junior masculino -> -50)', $c === 200 && count($j['datos']) === 1 && $j['datos'][0]['categoria_peso'] === '-50', json_encode($j['datos'][0]['categoria_peso'] ?? null));
[$c, $j] = peticion('GET', "{$dep}&accion=consultar&documento=1000000101", null, $publica);
comprobar('público: consultar la propia trae categoria_peso', $c === 200 && $j['datos']['categoria_peso'] === '-50');

echo "\n[3. récord de combates]\n";
[$c, $j] = peticion('GET', "{$dep}&accion=consultar&documento=1000000103", null, $admin);
comprobar('2 participaciones con combates (3/0 y 1/2) -> 4 ganados, 2 perdidos', $c === 200 && $j['datos']['combates_ganados'] === 4 && $j['datos']['combates_perdidos'] === 2, json_encode([$j['datos']['combates_ganados'] ?? null, $j['datos']['combates_perdidos'] ?? null]));
[$c, $j] = peticion('GET', "{$dep}&accion=consultar&documento=1000000102", null, $admin);
comprobar('2 participaciones, una con combates NULL (2/1 y NULL) -> 2/1', $j['datos']['combates_ganados'] === 2 && $j['datos']['combates_perdidos'] === 1);
[$c, $j] = peticion('GET', "{$dep}&accion=consultar&documento=7000000002", null, $admin);
comprobar('sin participaciones -> 0/0 (enteros)', $j['datos']['combates_ganados'] === 0 && $j['datos']['combates_perdidos'] === 0);
[$c, $j] = peticion('GET', "{$dep}&accion=consultar&documento=1000000101", null, $publica);
comprobar('público, ficha propia (1 participación con combates NULL) -> 0/0', $c === 200 && $j['datos']['combates_ganados'] === 0 && $j['datos']['combates_perdidos'] === 0);
[$c] = peticion('GET', "{$dep}&accion=consultar&documento=1000000103", null, $publica);
comprobar('público, ficha ajena -> 404 (no ve el récord de otro)', $c === 404, (string) $c);
[$c, $j] = peticion('GET', "{$dep}&accion=listar", null, $admin);
comprobar('listar NO trae el récord (evita una consulta por fila)', !array_key_exists('combates_ganados', $j['datos'][0]) && !array_key_exists('combates_perdidos', $j['datos'][0]));

echo "\n[5. comprobaciones sin cambio]\n";
[$c] = peticion('PUT', "{$dep}&accion=actualizar&documento=1000000101", ['grado_actual' => 'verde'], $admin);
comprobar('grado_actual editable a mano por el administrativo (texto libre)', $c === 200 && $bd->query("SELECT grado_actual FROM deportista WHERE documento = '1000000101'")->fetchColumn() === 'verde', (string) $c);
[$c] = peticion('PUT', "{$dep}&accion=actualizar&documento=1000000101", ['grado_actual' => 'negro'], $publica);
comprobar('el público no edita grado_actual -> 403', $c === 403, (string) $c);
[$c] = peticion('PUT', "{$dep}&accion=actualizar&documento=1000000101", ['peso' => 46, 'discapacidad' => null, 'telefono' => '3001234567', 'direccion' => 'Calle 9', 'correo' => 'Samuel.Nuevo@CorreoFicticio.test'], $publica);
$correo = $bd->query("SELECT correo FROM deportista WHERE documento = '1000000101'")->fetchColumn();
comprobar('el público edita peso, discapacidad, teléfono, dirección y correo (correo normalizado a minúsculas)', $c === 200 && $correo === 'samuel.nuevo@correoficticio.test', $c . ' ' . $correo);
[$c, $j] = peticion('GET', "{$dep}&accion=listar&estado_registro=pendiente_validacion", null, $admin);
$pendientes = $bd->query("SELECT documento FROM deportista WHERE estado_registro = 'pendiente_validacion' ORDER BY nombre, documento")->fetchAll(PDO::FETCH_COLUMN);
comprobar('listar administrativo filtra pendientes de validación', $c === 200 && array_column($j['datos'], 'documento') === $pendientes && count($pendientes) === 7, json_encode(array_column($j['datos'], 'documento')));
[$c, $j] = peticion('POST', "{$dep}&accion=crear", ['documento' => '9300000001', 'nombre' => 'X', 'fecha_nacimiento' => '2000-01-01', 'sexo' => 'masculino', 'estatura' => 1.7, 'peso' => 70, 'correo' => 'mal'], $admin);
comprobar('correo inválido del deportista -> 400 con el mensaje de Validador', $c === 400 && $j['mensaje'] === 'El campo "correo" debe ser un correo electrónico válido de máximo 150 caracteres.', $c . json_encode($j));
[$c, $j] = peticion('POST', "{$dep}&accion=crear", ['documento' => '9300000002', 'nombre' => str_repeat('n', 151), 'fecha_nacimiento' => '2000-01-01', 'sexo' => 'masculino', 'estatura' => 1.7, 'peso' => 70], $admin);
comprobar('texto demasiado largo -> 400 «admite máximo 150 caracteres.»', $c === 400 && $j['mensaje'] === 'El campo "nombre" admite máximo 150 caracteres.', $c . json_encode($j));
[$c] = peticion('POST', "{$dep}&accion=crear", ['documento' => 9300000003, 'nombre' => 'Documento Numerico', 'fecha_nacimiento' => '2000-01-01', 'sexo' => 'masculino', 'estatura' => 1.7, 'peso' => 70], $admin);
comprobar('documento enviado como número en el JSON sigue aceptándose', $c === 201, (string) $c);

echo "\n[Seguridad]\n";
[$c, $j] = buscar('Ana 100', $admin);
comprobar('buscar no expone salud ni contacto (Ana tiene teléfono y discapacidad en la BD)', !str_contains(json_encode($j), '3001112233') && !str_contains(json_encode($j), 'Asma'));
comprobar('ninguna respuesta trae estado_animico', array_filter($respuestas, fn ($r) => str_contains($r, 'estado_animico')) === []);

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
