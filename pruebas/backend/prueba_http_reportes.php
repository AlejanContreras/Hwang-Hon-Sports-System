<?php

declare(strict_types=1);

// Bateria HTTP del paso 11 (Reportes y exportacion), fuera del repositorio. Fechas relativas a hoy (Bogota).
// Uso: php prueba_http_reportes.php <url base> <ruta backend> <archivo log de memoria>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recien cargados; servidor con memory_limit=128M y
// MEMORIA_LOG apuntando al archivo dado. Solo datos ficticios. Lee los archivos con lectores/*.py (stdlib).

$base = rtrim($argv[1], '/');
$backend = rtrim($argv[2], '/');
$logMemoria = $argv[3];
date_default_timezone_set('America/Bogota');
$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$bd->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$hoy = new DateTimeImmutable('today');
$H = $hoy->format('Y-m-d');
$d = fn (string $mod): string => $hoy->modify($mod)->format('Y-m-d');
$dirTmp = __DIR__ . '/descargas_p11';
@mkdir($dirTmp);
putenv('PYTHONIOENCODING=utf-8');

$ok = 0; $fallos = []; $crudos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} " . substr($detalle, 0, 600) . "\n"; }
}
/** @return array{0: int, 1: array<string, string>, 2: string} */
function peticion(string $metodo, string $url, ?string $cookie = null): array
{
    global $crudos;
    $contexto = stream_context_create(['http' => ['method' => $metodo, 'header' => $cookie ? "Cookie: {$cookie}\r\n" : '', 'ignore_errors' => true, 'timeout' => 300]]);
    $cuerpo = (string) file_get_contents($url, false, $contexto);
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $m);
    $cabeceras = [];
    foreach (array_slice($http_response_header, 1) as $linea) {
        [$k, $v] = array_map('trim', explode(':', $linea, 2)) + [1 => ''];
        $cabeceras[strtolower($k)] = $v;
    }
    $crudos[] = $cuerpo;
    return [(int) $m[1], $cabeceras, $cuerpo];
}
function r(string $accion, array $query = []): string
{
    global $base;
    return "{$base}/api.php?recurso=reportes&accion={$accion}" . ($query ? '&' . http_build_query($query) : '');
}
function api(string $recurso, string $accion, array $query, string $cookie): array
{
    global $base;
    [, , $cuerpo] = peticion('GET', "{$base}/api.php?recurso={$recurso}&accion={$accion}&" . http_build_query($query), $cookie);
    return json_decode($cuerpo, true)['datos'] ?? [];
}
function sesion(string $accion, array $cuerpo): string
{
    global $base;
    $c = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'ignore_errors' => true, 'content' => json_encode($cuerpo)]]);
    file_get_contents("{$base}/api.php?recurso=auth&accion={$accion}", false, $c);
    foreach ($http_response_header as $h) { if (preg_match('/^Set-Cookie: (HWANGHON_SESION=[^;]*)/i', $h, $m)) { return $m[1]; } }
    return '';
}
function leer(string $lector, string $cuerpo): ?array
{
    global $dirTmp;
    $archivo = $dirTmp . '/a_' . bin2hex(random_bytes(4));
    file_put_contents($archivo, $cuerpo);
    $salida = shell_exec('python -I ' . escapeshellarg(__DIR__ . "/lectores/{$lector}") . ' ' . escapeshellarg($archivo) . ' 2>&1');
    unlink($archivo);
    $datos = json_decode((string) $salida, true);
    if (!is_array($datos)) { echo "    (lector: " . substr((string) $salida, 0, 400) . ")\n"; }
    return is_array($datos) ? $datos : null;
}
/** Descarga y abre un xlsx; devuelve [codigo, cabeceras, libro|null, cuerpo]. */
function xlsx(string $accion, array $q, string $cookie): array
{
    [$c, $h, $cuerpo] = peticion('GET', r($accion, $q), $cookie);
    return [$c, $h, $c === 200 ? leer('lector_xlsx.py', $cuerpo) : null, $cuerpo];
}
function v(?array $libro, string $hoja, string $ref): mixed
{
    return $libro['hojas'][$hoja]['celdas'][$ref]['v'] ?? null;
}
function s(?array $libro, string $hoja, string $ref): ?int
{
    return $libro['hojas'][$hoja]['celdas'][$ref]['s'] ?? null;
}
/** Fila (numero) cuya columna $col vale $valor, o null. */
function filaDe(?array $libro, string $hoja, string $col, string $valor): ?int
{
    foreach ($libro['hojas'][$hoja]['celdas'] ?? [] as $ref => $celda) {
        if (preg_match('/^' . $col . '(\d+)$/', $ref, $m) && $celda['v'] === $valor) { return (int) $m[1]; }
    }
    return null;
}
function letra(int $i): string
{
    $n = $i + 1; $l = '';
    while ($n > 0) { $n--; $l = chr(65 + $n % 26) . $l; $n = intdiv($n, 26); }
    return $l;
}
function comSelect(): int
{
    global $bd;
    return (int) $bd->query("SHOW GLOBAL STATUS LIKE 'Com_select'")->fetch()['Value'];
}
function consultasDe(string $url, string $cookie): int
{
    $antes = comSelect();
    peticion('GET', $url, $cookie);
    return comSelect() - $antes;
}
/** Archivos del backend (sin sesiones) con tamano y fecha: para comprobar que nada se escribe en disco. */
function fotoDisco(): array
{
    global $backend;
    $foto = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $ruta = str_replace('\\', '/', $f->getPathname());
        if (!str_contains($ruta, '/Almacenamiento/sesiones/')) { $foto[$ruta] = $f->getSize() . '|' . $f->getMTime(); }
    }
    ksort($foto);
    return $foto;
}
function cabecerasOk(array $h, string $cuerpo, string $tipo, string $patronNombre): bool
{
    return ($h['content-type'] ?? '') === $tipo && (int) ($h['content-length'] ?? -1) === strlen($cuerpo)
        && preg_match('/^attachment; filename="' . $patronNombre . '"; filename\*=UTF-8\'\'' . $patronNombre . '$/', $h['content-disposition'] ?? '') === 1;
}
const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

// ------------------------------------------------------------------
// Datos ficticios
// ------------------------------------------------------------------
$E1 = '9900000001'; $E2 = '9900000002'; $E3 = '9900000003'; $E4 = '9900000004';
$nacE1 = $hoy->modify('-12 years -40 days')->format('Y-m-d');
$bd->exec("INSERT INTO acudiente_tutor (documento, nombre, relacion, telefono, correo) VALUES ('9900000901', 'Acudiente Ñoña Prueba', 'madre', '3001234567', 'acu@ficticio.test')");
$insD = $bd->prepare("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, telefono, correo, discapacidad, autorizacion_datos, fecha_ingreso, estado, estado_registro, grado_actual, acudiente_documento) VALUES (?, ?, ?, 'masculino', 1.50, 40, ?, ?, ?, 1, ?, ?, ?, ?, ?)");
foreach ([
    [$E1, 'Ñandú Pérez "Comillas"', $nacE1, '3000000009', 'nandu@ficticio.test', 'ASMA-PRUEBA-SALUD', '2026-01-10', 'activo', 'validado', 'Amarillo', '9900000901'],
    [$E2, '=SUMA(1;2) Fórmula', '1990-02-02', '+573001112233', '@arroba@ficticio.test', null, '2026-01-10', 'activo', 'validado', null, null],
    [$E3, "-Guion\x02 Inactivo", '1985-03-03', null, null, null, '2025-06-01', 'inactivo', 'validado', 'Verde', null],
    [$E4, 'Pendiente Excluido', '1995-04-04', null, null, null, '2026-09-01', 'activo', 'pendiente_validacion', null, null],
] as $x) { $insD->execute($x); }

// Últimos 8 sábados (antes de hoy).
$sabado = $hoy->modify('-1 day');
while ($sabado->format('N') !== '6') { $sabado = $sabado->modify('-1 day'); }
$sabados = [];
for ($i = 7; $i >= 0; $i--) { $sabados[] = $sabado->modify('-' . (7 * $i) . ' days')->format('Y-m-d'); }
$insA = $bd->prepare('INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES (?, ?, ?)');
foreach ($sabados as $i => $f) {
    $insA->execute([$E1, $f, (int) !in_array($i, [1, 4], true)]);   // 6 de 8
    $insA->execute([$E2, $f, (int) in_array($i, [0, 3, 7], true)]); // 3 de 8
    if ($i < 4) { $insA->execute([$E3, $f, 0]); }                    // 0 de 4
}

$insPago = $bd->prepare('INSERT INTO pago (deportista_documento, tipo, anio, mes, semana) VALUES (?, ?, ?, ?, ?)');
$anio = (int) $hoy->format('Y');
foreach ([[$E1, 'mensualidad', $anio, 1, 2], [$E1, 'mensualidad', $anio, 2, 3], [$E1, 'matricula', $anio, 0, null], [$E2, 'mensualidad', $anio, 1, 2]] as $x) { $insPago->execute($x); }

$insE = $bd->prepare("INSERT INTO evento_calendario (nombre, tipo, fecha, lugar, visibilidad) VALUES (?, 'competencia', ?, 'Coliseo ficticio', ?)");
$insC = $bd->prepare("INSERT INTO competencia (evento_id, plataforma, tipo_torneo) VALUES (?, '=Plataforma', ?)");
$torneo = function (string $nombre, string $fecha, string $vis, string $tipo) use ($bd, $insE, $insC): int {
    $insE->execute([$nombre, $fecha, $vis]);
    $id = (int) $bd->lastInsertId();
    $insC->execute([$id, $tipo]);
    return $id;
};
$T1 = $torneo('Copa Ñandú Nacional', $d('-60 days'), 'publica', 'nacional');
$T2 = $torneo('Open Internacional Reservado', $d('-30 days'), 'administrativa', 'internacional');
$T3 = $torneo('Torneo Futuro P11', $d('+20 days'), 'publica', 'departamental');
$insP = $bd->prepare('INSERT INTO participacion_competencia (deportista_documento, competencia_evento_id, modalidad, categoria_edad, categoria_peso, puesto, medalla, combates_ganados, combates_perdidos, notas_oponente) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [$E1, $T1, 'combate_individual', 'junior', '-40 kg', 1, 'oro', 3, 1, 'SECRETO-OPONENTE-P11'],
    [$E2, $T1, 'formas', 'mayores', null, 2, 'plata', null, null, null],
    [$E1, $T2, 'formas', 'junior', null, 3, 'bronce', null, null, 'SECRETO-OPONENTE-P11'],
    [$E3, $T2, 'salto_alto', 'mayores', null, 5, 'ninguna', null, null, null],
    [$E1, $T3, 'combate_individual', 'junior', null, null, null, null, null, null],
] as $x) { $insP->execute($x); }

$insS = $bd->prepare("INSERT INTO sesion_examen (fecha, fecha_grado, lugar, estado) VALUES (?, ?, 'Sede ficticia', ?)");
$insS->execute([$d('-50 days'), $d('-45 days'), 'cerrada']); $S1 = (int) $bd->lastInsertId();
$insS->execute([$d('-5 days'), $d('+2 days'), 'abierta']); $S2 = (int) $bd->lastInsertId();
$insDet = $bd->prepare("INSERT INTO detalle_evaluacion (sesion_examen_id, deportista_documento, modalidad, grado_evaluado, estado, nota, aspectos_fallidos, recomendaciones, aprobado) VALUES (?, ?, ?, ?, 'completado', ?, ?, ?, ?)");
foreach (['teorico' => 8, 'fisico' => 4, 'combate' => 7, 'rompimiento' => 6, 'formas' => 9, 'llaves' => 6] as $m => $n) {
    $insDet->execute([$S1, $E1, $m, 'Naranja', $n, $m === 'fisico' ? 'Resistencia baja' : null, $m === 'fisico' ? "Correr 3 veces por semana\x01" : null, (int) ($n >= 5)]);
}
foreach (['teorico' => 5, 'fisico' => 5, 'combate' => 5] as $m => $n) { $insDet->execute([$S1, $E2, $m, 'Blanco', $n, null, null, 1]); }
$insDet->execute([$S2, $E1, 'teorico', 'Verde', 10, null, null, 1]);
$bd->prepare("INSERT INTO entrega_cinturon (sesion_examen_id, deportista_documento, cinturon_entregado, fecha_entrega, confirmado_por) VALUES (?, ?, 1, ?, 'administrativo')")->execute([$S1, $E1, $d('-45 days')]);

$admin = sesion('iniciarSesionAdministrativa', ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']);
$publico = sesion('iniciarSesionPublica', ['documento' => $E1, 'fecha_nacimiento' => $nacE1]);
comprobar('sesiones administrativa y pública creadas', $admin !== '' && $publico !== '');
$fotoInicial = fotoDisco();

// ------------------------------------------------------------------
echo "\n[permisos, métodos y errores antes de generar]\n";
$acciones = ['deportistaFicha' => ['deportista' => $E1], 'deportistas' => [], 'asistencia' => ['desde' => $sabados[0], 'hasta' => $H], 'pagos' => [], 'torneos' => [], 'sesionExamen' => ['id' => $S1]];
foreach ($acciones as $a => $q) {
    [$c] = peticion('GET', r($a, $q));
    comprobar("{$a}: sin sesión -> 401", $c === 401, (string) $c);
    [$c, $h] = peticion('GET', r($a, $q), $publico);
    comprobar("{$a}: ámbito público -> 403 (JSON)", $c === 403 && str_starts_with($h['content-type'] ?? '', 'application/json'), (string) $c);
    [$c] = peticion('POST', r($a, $q), $admin);
    comprobar("{$a}: POST -> 405", $c === 405, (string) $c);
}
[$c] = peticion('GET', r('generar'), $admin);
comprobar('la acción vieja "generar" ya no existe -> 404', $c === 404, (string) $c);
foreach ([
    'ficha sin deportista' => ['deportistaFicha', [], 400], 'ficha de documento inexistente' => ['deportistaFicha', ['deportista' => '9999999999'], 404],
    'deportistas con estado inválido' => ['deportistas', ['estado' => 'retirado'], 400],
    'asistencia sin desde' => ['asistencia', ['hasta' => $H], 400], 'asistencia sin hasta' => ['asistencia', ['desde' => $H], 400],
    'asistencia con fecha mal formada' => ['asistencia', ['desde' => '2026-02-30', 'hasta' => $H], 400],
    'asistencia con desde > hasta' => ['asistencia', ['desde' => $H, 'hasta' => $d('-1 day')], 400],
    'asistencia con rango de 367 días' => ['asistencia', ['desde' => $d('-366 days'), 'hasta' => $H], 422],
    'asistencia con estado inválido' => ['asistencia', ['desde' => $sabados[0], 'hasta' => $H, 'estado' => 'x'], 400],
    'pagos con anio no numérico' => ['pagos', ['anio' => 'abc'], 400], 'pagos con anio 2019' => ['pagos', ['anio' => '2019'], 400],
    'pagos con anio dentro de dos años' => ['pagos', ['anio' => (string) ($anio + 2)], 400],
    'torneos con fecha mal formada' => ['torneos', ['desde' => '21/10/2026'], 400], 'torneos con desde > hasta' => ['torneos', ['desde' => $H, 'hasta' => $d('-10 days')], 400],
    'sesión sin id' => ['sesionExamen', [], 400], 'sesión con id inválido' => ['sesionExamen', ['id' => 'abc'], 400],
    'sesión inexistente' => ['sesionExamen', ['id' => 999999], 404],
] as $n => [$a, $q, $esperado]) {
    [$c, $h, $cuerpo] = peticion('GET', r($a, $q), $admin);
    $j = json_decode($cuerpo, true);
    comprobar("{$n} -> {$esperado} en JSON", $c === $esperado && str_starts_with($h['content-type'] ?? '', 'application/json') && ($j['exito'] ?? null) === false, $c . ' ' . substr($cuerpo, 0, 150));
}
[$c] = peticion('GET', r('asistencia', ['desde' => $d('-365 days'), 'hasta' => $H]), $admin);
comprobar('asistencia con rango de exactamente 366 días -> 200 (borde)', $c === 200, (string) $c);

// ------------------------------------------------------------------
echo "\n[B) deportistas]\n";
[$c, $h, $L, $cuerpo] = xlsx('deportistas', [], $admin);
comprobar('200 con cabeceras exactas (tipo, longitud, nombre con fecha de Bogotá)', $c === 200 && cabecerasOk($h, $cuerpo, XLSX, "deportistas_{$H}\\.xlsx"), json_encode($h));
comprobar('zip íntegro (CRC) y XML bien formado; sin fórmulas', $L !== null && $L['crc_malo'] === null && $L['formulas'] === 0);
$hoja = 'Deportistas';
comprobar('encabezado con el nombre del club y fecha de generación', str_contains((string) v($L, $hoja, 'A1'), 'Hwang Hon') && str_contains((string) v($L, $hoja, 'A3'), "Generado el {$H}"), (string) v($L, $hoja, 'A1'));
$titulos = array_map(fn ($i) => v($L, $hoja, letra($i) . '5'), range(0, 10));
comprobar('columnas exactas', $titulos === ['N°', 'Documento', 'Nombre', 'Fecha de nacimiento', 'Edad', 'Categoría de edad', 'Grado actual', 'Estado', 'Fecha de ingreso', 'Teléfono', 'Correo'], json_encode($titulos));
$f1 = filaDe($L, $hoja, 'B', $E1);
comprobar('E1: nombre con ñ, tilde y comillas intacto; edad 12, Junior, grado, contacto', $f1 !== null && v($L, $hoja, "C{$f1}") === 'Ñandú Pérez "Comillas"' && v($L, $hoja, "E{$f1}") == 12
    && v($L, $hoja, "F{$f1}") === 'Junior' && v($L, $hoja, "G{$f1}") === 'Amarillo' && v($L, $hoja, "D{$f1}") === $nacE1 && v($L, $hoja, "J{$f1}") === '3000000009', json_encode($f1));
$f2 = filaDe($L, $hoja, 'B', $E2);
comprobar('inyección: "=SUMA..." , "+57..." y "@arroba" son cadenas (t=s) con estilo quotePrefix', $f2 !== null && v($L, $hoja, "C{$f2}") === '=SUMA(1;2) Fórmula' && $L['hojas'][$hoja]['celdas']["C{$f2}"]['t'] === 's'
    && in_array(s($L, $hoja, "C{$f2}"), $L['quote_prefix'], true) && v($L, $hoja, "J{$f2}") === '+573001112233' && v($L, $hoja, "K{$f2}") === '@arroba@ficticio.test' && in_array(s($L, $hoja, "K{$f2}"), $L['quote_prefix'], true), json_encode($L['hojas'][$hoja]['celdas']["C{$f2}"] ?? null));
$f3 = filaDe($L, $hoja, 'B', $E3);
comprobar('carácter de control en el nombre se elimina (XML válido) y "-Guion" queda literal', $f3 !== null && v($L, $hoja, "C{$f3}") === '-Guion Inactivo' && v($L, $hoja, "G{$f2}") === 'Ingresado', json_encode(v($L, $hoja, "C{$f3}")));
comprobar('sin filtro: validados activos e inactivos; el pendiente de validación no sale', $f3 !== null && filaDe($L, $hoja, 'B', $E4) === null && filaDe($L, $hoja, 'B', '1000000102') === null && filaDe($L, $hoja, 'B', '1000000103') !== null);
$todoTexto = json_encode($L, JSON_UNESCAPED_UNICODE);
comprobar('no exporta salud, sexo, acudiente ni campos excluidos', !str_contains($todoTexto, 'ASMA-PRUEBA') && !str_contains($todoTexto, 'masculino') && !str_contains($todoTexto, 'Acudiente Ñoña') && !preg_match('/g[eé]nero|[eé]tnico|v[ií]ctima/iu', $todoTexto));
$esperados = (int) $bd->query("SELECT COUNT(*) FROM deportista WHERE estado_registro = 'validado'")->fetchColumn();
comprobar("filas de datos = validados en BD ({$esperados})", $L['hojas'][$hoja]['filas'] - 5 === $esperados, (string) $L['hojas'][$hoja]['filas']);
[$c, $h, $L, $cuerpo] = xlsx('deportistas', ['estado' => 'inactivo'], $admin);
$docs = []; foreach ($L['hojas'][$hoja]['celdas'] ?? [] as $ref => $celda) { if (preg_match('/^B(\d+)$/', $ref, $m) && $m[1] > 5) { $docs[] = $celda['v']; } }
comprobar('estado=inactivo: solo inactivos, nombre de archivo con el filtro', $c === 200 && in_array($E3, $docs, true) && !in_array($E1, $docs, true) && cabecerasOk($h, $cuerpo, XLSX, "deportistas_inactivos_{$H}\\.xlsx"), json_encode($docs));

// ------------------------------------------------------------------
echo "\n[C) asistencia]\n";
$p1 = api('asistencia', 'porcentaje', ['documento' => $E1, 'ventana' => 'todo'], $admin);
[$c, $h, $L, $cuerpo] = xlsx('asistencia', ['desde' => $p1['desde'], 'hasta' => $H], $admin);
$hoja = 'Asistencia';
comprobar('200, cabeceras y archivo íntegro', $c === 200 && cabecerasOk($h, $cuerpo, XLSX, "asistencia_{$p1['desde']}_a_{$H}_{$H}\\.xlsx") && $L['crc_malo'] === null && $L['formulas'] === 0, $c . json_encode($h));
$filaTitulos = 6;
comprobar('encabezado: club, rango, leyenda y fecha de generación', str_contains((string) v($L, $hoja, 'A1'), 'Hwang Hon') && str_contains((string) v($L, $hoja, 'A2'), "Rango: {$p1['desde']} a {$H}")
    && str_starts_with((string) v($L, $hoja, 'A3'), 'X = asistió') && str_contains((string) v($L, $hoja, 'A4'), $H));
$fechasBd = $bd->query("SELECT DISTINCT a.fecha FROM asistencia a JOIN deportista d ON d.documento = a.deportista_documento WHERE d.estado_registro = 'validado' AND a.fecha BETWEEN '{$p1['desde']}' AND '{$H}' ORDER BY a.fecha")->fetchAll(PDO::FETCH_COLUMN);
$nf = count($fechasBd);
$primeraFecha = new DateTimeImmutable($fechasBd[0]);
comprobar("columnas de fecha = fechas de sesión del rango ({$nf}), con día de la semana", v($L, $hoja, "E{$filaTitulos}") === 'Sáb ' . $primeraFecha->format('d/m/Y') && v($L, $hoja, letra(4 + $nf) . $filaTitulos) === 'Asistió'
    && v($L, $hoja, letra(6 + $nf) . $filaTitulos) === '% asistencia', json_encode([v($L, $hoja, "E{$filaTitulos}"), v($L, $hoja, letra(4 + $nf) . $filaTitulos)]));
$colPct = letra(6 + $nf); $colTot = letra(4 + $nf); $colSes = letra(5 + $nf);
comprobar('RN-7.1: columna «Condición de salud» con la discapacidad de cada deportista', v($L, $hoja, "D{$filaTitulos}") === 'Condición de salud' && v($L, $hoja, 'D' . filaDe($L, $hoja, 'B', $E1)) === 'ASMA-PRUEBA-SALUD' && v($L, $hoja, 'D' . filaDe($L, $hoja, 'B', $E2)) === null && in_array(s($L, $hoja, 'D' . filaDe($L, $hoja, 'B', $E1)), $L['quote_prefix'], true));
foreach ([$E1, $E2, $E3] as $doc) {
    $p = api('asistencia', 'porcentaje', ['documento' => $doc, 'ventana' => 'todo'], $admin);
    $rr = xlsx('asistencia', ['desde' => max($p['desde'], $d('-365 days')), 'hasta' => $H], $admin)[2];
    $col = [];
    foreach ($rr['hojas'][$hoja]['celdas'] ?? [] as $ref => $celda) {
        if (preg_match('/^([A-Z]+)6$/', $ref, $m) && in_array($celda['v'], ['Asistió', 'Sesiones', '% asistencia'], true)) { $col[$celda['v']] = $m[1]; }
    }
    $f = filaDe($rr, $hoja, 'B', $doc);
    comprobar("{$doc}: % = AsistenciaService::porcentaje ({$p['porcentaje']}), asistió {$p['presentes']} de {$p['sesiones']}", $f !== null && count($col) === 3
        && v($rr, $hoja, $col['% asistencia'] . $f) == $p['porcentaje'] && v($rr, $hoja, $col['Asistió'] . $f) == $p['presentes'] && v($rr, $hoja, $col['Sesiones'] . $f) == $p['sesiones'], json_encode([$p, $f, $col]));
}
$f1 = filaDe($L, $hoja, 'B', $E1);
$marcas = array_map(fn ($i) => [v($L, $hoja, letra(4 + $i) . $f1), s($L, $hoja, letra(4 + $i) . $f1)], array_keys($fechasBd));
$iS = array_search($sabados[1], $fechasBd, true);
comprobar('marcas: X verde si asistió, F roja si faltó', $marcas[$iS] === ['F', 8] && $marcas[array_search($sabados[0], $fechasBd, true)] === ['X', 7], json_encode($marcas));
$f3 = filaDe($L, $hoja, 'B', $E3);
$iUlt = array_search($sabados[7], $fechasBd, true);
comprobar('sin registro ese día -> celda vacía (E3 solo tiene las 4 primeras fechas)', $f3 !== null && v($L, $hoja, letra(4 + $iUlt) . $f3) === null && v($L, $hoja, letra(4 + array_search($sabados[0], $fechasBd, true)) . $f3) === 'F');
$fSemilla = filaDe($L, $hoja, 'B', '1000000101');
comprobar('deportista con menos de 4 sesiones en el rango -> "Datos incompletos" (misma regla RN-7.8)', $fSemilla !== null && v($L, $hoja, "{$colPct}{$fSemilla}") === 'Datos incompletos', json_encode(v($L, $hoja, "{$colPct}{$fSemilla}")));
$ultima = $L['hojas'][$hoja]['filas'];
$presentesBd = $bd->query("SELECT a.fecha, SUM(a.asistio) FROM asistencia a JOIN deportista d ON d.documento = a.deportista_documento WHERE d.estado_registro = 'validado' AND a.fecha BETWEEN '{$p1['desde']}' AND '{$H}' GROUP BY a.fecha ORDER BY a.fecha")->fetchAll(PDO::FETCH_KEY_PAIR);
$filaFinal = array_map(fn ($i) => (int) v($L, $hoja, letra(4 + $i) . $ultima), array_keys($fechasBd));
comprobar('fila final: asistentes por fecha = SUM(asistio) en BD', v($L, $hoja, "A{$ultima}") === 'Asistentes por fecha' && $filaFinal === array_map('intval', array_values($presentesBd)), json_encode([$filaFinal, $presentesBd]));
[$c, , $L] = xlsx('asistencia', ['desde' => $sabados[0], 'hasta' => $H, 'estado' => 'inactivo'], $admin);
comprobar('estado=inactivo: solo E3', $c === 200 && filaDe($L, $hoja, 'B', $E3) !== null && filaDe($L, $hoja, 'B', $E1) === null);
[$c, , $L] = xlsx('asistencia', ['desde' => '2021-01-01', 'hasta' => '2021-06-30'], $admin);
comprobar('rango sin datos -> archivo válido con solo encabezados y fila final en 0', $c === 200 && $L['crc_malo'] === null && v($L, $hoja, 'A6') === 'N°' && v($L, $hoja, 'E6') === 'Asistió' && v($L, $hoja, 'A7') === 'Asistentes por fecha' && v($L, $hoja, 'E7') == 0, json_encode($L['hojas'][$hoja]['celdas'] ?? null));

// ------------------------------------------------------------------
echo "\n[D) pagos]\n";
[$c, $h, $L, $cuerpo] = xlsx('pagos', [], $admin);
comprobar('200, dos hojas (Planilla, Deudores), cabeceras y archivo íntegro', $c === 200 && $L['orden'] === ['Planilla', 'Deudores'] && cabecerasOk($h, $cuerpo, XLSX, "pagos_{$anio}_{$H}\\.xlsx") && $L['crc_malo'] === null && $L['formulas'] === 0, json_encode($L['orden'] ?? null));
$hoja = 'Planilla';
$filaT = 6;
comprobar('columnas: matrícula aparte y los 12 meses', v($L, $hoja, "E{$filaT}") === 'Matrícula' && v($L, $hoja, "F{$filaT}") === 'Enero' && v($L, $hoja, "Q{$filaT}") === 'Diciembre' && v($L, $hoja, "R{$filaT}") === 'Meses pagados');
foreach ([$E1, $E2] as $doc) {
    $estado = api('pagos', 'estado', ['documento' => $doc, 'anio' => $anio], $admin);
    $f = filaDe($L, $hoja, 'B', $doc);
    $bien = $f !== null;
    $detalle = [];
    foreach ($estado['meses'] as $m) {
        $ref = letra(4 + $m['mes']) . $f;
        $esperado = $m['pagado'] ? [$m['semana'], 7] : ($m['vencido'] ? [$m['en_deuda'] ? 'Debe' : null, 8] : [null, 9]);
        $real = [v($L, $hoja, $ref), s($L, $hoja, $ref)];
        if ($real != $esperado) { $bien = false; $detalle[] = [$m['mes'], $real, $esperado]; }
    }
    comprobar("{$doc}: cada mes coincide con PagoService (semana pagada en verde, vencido en rojo, «Debe» si es deuda)", $bien, json_encode($detalle));
}
$f1 = filaDe($L, $hoja, 'B', $E1);
comprobar('E1: semanas 2 y 3 de enero y febrero; matrícula pagada; 2 meses pagados', v($L, $hoja, "F{$f1}") == 2 && v($L, $hoja, "G{$f1}") == 3 && v($L, $hoja, "E{$f1}") === 'Pagada' && s($L, $hoja, "E{$f1}") === 7 && v($L, $hoja, "R{$f1}") == 2);
$f2 = filaDe($L, $hoja, 'B', $E2);
comprobar('E2 sin matrícula -> «Pendiente» en rojo', v($L, $hoja, "E{$f2}") === 'Pendiente' && s($L, $hoja, "E{$f2}") === 8);
$deudores = api('pagos', 'deudores', [], $admin);
$hojaD = []; for ($f = 6; $f <= ($L['hojas']['Deudores']['filas'] ?? 0); $f++) { $hojaD[] = [v($L, 'Deudores', "B{$f}"), v($L, 'Deudores', "D{$f}"), (int) v($L, 'Deudores', "E{$f}")]; }
$meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$esperadoD = array_map(fn ($x) => [$x['documento'], implode(', ', array_map(fn ($m) => $meses[$m], $x['meses_en_deuda'])), count($x['meses_en_deuda'])], $deudores);
comprobar('hoja Deudores = PagoService::deudores (mismos documentos, meses y cantidad, mismo orden)', $hojaD === $esperadoD && $esperadoD !== [], json_encode([$hojaD, $esperadoD]));
comprobar('E1 aparece como deudor (asistió después de vencer meses sin pagar)', in_array($E1, array_column($deudores, 'documento'), true));
[$c, , $L] = xlsx('pagos', ['anio' => (string) ($anio - 1), 'estado' => 'inactivo'], $admin);
comprobar('anio anterior + estado=inactivo: archivo válido, solo inactivos', $c === 200 && filaDe($L, 'Planilla', 'B', $E3) !== null && filaDe($L, 'Planilla', 'B', $E1) === null && str_contains((string) v($L, 'Planilla', 'A2'), 'Año ' . ($anio - 1)));

// ------------------------------------------------------------------
echo "\n[E) torneos]\n";
[$c, $h, $L, $cuerpo] = xlsx('torneos', [], $admin);
$hist = api('historial', 'torneos', [], $admin);
comprobar('200, hojas Participaciones y Medallería, archivo íntegro', $c === 200 && $L['orden'] === ['Participaciones', 'Medallería'] && cabecerasOk($h, $cuerpo, XLSX, "torneos_{$H}\\.xlsx") && $L['crc_malo'] === null && $L['formulas'] === 0, json_encode($L['orden'] ?? null));
$hoja = 'Participaciones';
comprobar('una fila por participación = HistorialService::torneos (' . count($hist) . ')', $L['hojas'][$hoja]['filas'] - 5 === count($hist), (string) ($L['hojas'][$hoja]['filas'] ?? 0));
comprobar('el torneo futuro no sale; el administrativo sí (es reporte del admin)', !str_contains(json_encode($L, JSON_UNESCAPED_UNICODE), 'Torneo Futuro P11') && filaDe($L, $hoja, 'B', 'Open Internacional Reservado') !== null);
$f = null;
foreach ($L['hojas'][$hoja]['celdas'] ?? [] as $ref => $celda) { if (preg_match('/^F(\d+)$/', $ref, $m) && $celda['v'] === $E1 && v($L, $hoja, "B{$m[1]}") === 'Copa Ñandú Nacional') { $f = (int) $m[1]; } }
comprobar('fila de oro: columnas y valores (plataforma "=Plataforma" literal)', $f !== null && v($L, $hoja, "A{$f}") === $d('-60 days') && v($L, $hoja, "C{$f}") === 'Nacional' && v($L, $hoja, "D{$f}") === '=Plataforma'
    && in_array(s($L, $hoja, "D{$f}"), $L['quote_prefix'], true) && v($L, $hoja, "K{$f}") == 1 && v($L, $hoja, "L{$f}") === 'Oro' && v($L, $hoja, "M{$f}") == 3 && v($L, $hoja, "N{$f}") == 1 && v($L, $hoja, "J{$f}") === '-40 kg', json_encode($f));
$med = ['nacional' => [0, 0, 0], 'internacional' => [0, 0, 0], 'departamental' => [0, 0, 0]];
foreach ($hist as $t) { $i = array_search($t['medalla'], ['oro', 'plata', 'bronce'], true); if ($i !== false) { $med[$t['tipo_torneo']][$i]++; } }
$hojaM = [];
foreach ([6, 7, 8] as $fm) { $hojaM[strtolower((string) v($L, 'Medallería', "A{$fm}"))] = [(int) v($L, 'Medallería', "B{$fm}"), (int) v($L, 'Medallería', "C{$fm}"), (int) v($L, 'Medallería', "D{$fm}")]; }
$tot = [(int) v($L, 'Medallería', 'B9'), (int) v($L, 'Medallería', 'C9'), (int) v($L, 'Medallería', 'D9'), (int) v($L, 'Medallería', 'E9')];
$sum = array_map(fn ($i) => array_sum(array_column($med, $i)), [0, 1, 2]);
comprobar('medallería por tipo y total = conteo de las participaciones («ninguna» no suma)', $hojaM === $med && $tot === array_merge($sum, [array_sum($sum)]) && v($L, 'Medallería', 'A9') === 'Total', json_encode([$hojaM, $med, $tot]));
[$c, , $L] = xlsx('torneos', ['desde' => $d('-45 days'), 'hasta' => $H], $admin);
comprobar('desde/hasta filtra (solo el internacional, 2 filas)', $c === 200 && $L['hojas']['Participaciones']['filas'] - 5 === 2 && filaDe($L, 'Participaciones', 'B', 'Open Internacional Reservado') !== null);
[$c, , $L] = xlsx('torneos', ['desde' => '2021-01-01', 'hasta' => '2021-12-31'], $admin);
comprobar('rango sin torneos -> solo encabezados y medallería en ceros', $c === 200 && $L['hojas']['Participaciones']['filas'] === 5 && (int) v($L, 'Medallería', 'E9') === 0);

// ------------------------------------------------------------------
echo "\n[F) sesión de examen]\n";
[$c, $h, $L, $cuerpo] = xlsx('sesionExamen', ['id' => $S1], $admin);
$ses = api('evaluaciones', 'consultar', ['id' => $S1], $admin);
$hoja = 'Resultados';
comprobar('200, cabeceras y archivo íntegro', $c === 200 && cabecerasOk($h, $cuerpo, XLSX, "sesion_examen_{$S1}_{$H}\\.xlsx") && $L['crc_malo'] === null && $L['formulas'] === 0);
comprobar('encabezado: sesión, estado cerrada y aviso PROVISIONAL', str_contains((string) v($L, $hoja, 'A2'), "Sesión #{$S1}") && v($L, $hoja, 'A3') === 'Estado: cerrada' && str_starts_with((string) v($L, $hoja, 'A4'), 'PROVISIONAL') && v($L, $hoja, 'M7') === 'Resultado general (PROVISIONAL)');
comprobar('columnas de las seis modalidades en orden', array_map(fn ($i) => v($L, $hoja, letra($i) . '7'), range(5, 10)) === ['Teórico', 'Físico', 'Combate', 'Rompimiento', 'Formas', 'Llaves']);
$bien = true;
foreach ($ses['participantes'] as $p) {
    $f = filaDe($L, $hoja, 'B', $p['deportista_documento']);
    $notas = array_column($p['modalidades'], 'nota', 'modalidad');
    foreach (['teorico', 'fisico', 'combate', 'rompimiento', 'formas', 'llaves'] as $j => $m) {
        if (v($L, $hoja, letra(5 + $j) . $f) != ($notas[$m] ?? null)) { $bien = false; }
    }
    if (v($L, $hoja, "L{$f}") != $p['resultado_general']['promedio'] || v($L, $hoja, "E{$f}") !== $p['grado_evaluado']) { $bien = false; }
}
comprobar('notas, promedio y grado evaluado = EvaluacionService::consultar', $bien && count($ses['participantes']) === 2);
$f1 = filaDe($L, $hoja, 'B', $E1);
comprobar('E1: aprobado (6.67), físico 4 en rojo, cinturón entregado con fecha', abs((float) v($L, $hoja, "L{$f1}") - 6.67) < 0.001 && v($L, $hoja, "M{$f1}") === 'Aprobado' && s($L, $hoja, "G{$f1}") === 8 && s($L, $hoja, "F{$f1}") === 7
    && v($L, $hoja, "N{$f1}") === 'Sí, ' . $d('-45 days') . ' (administrativo)', json_encode([v($L, $hoja, "L{$f1}"), v($L, $hoja, "N{$f1}")]));
$f2 = filaDe($L, $hoja, 'B', $E2);
comprobar('E2 con tres notas: incompleto, promedio vacío, sin registro de entrega', v($L, $hoja, "M{$f2}") === 'Incompleto' && v($L, $hoja, "L{$f2}") === null && v($L, $hoja, "K{$f2}") === null && v($L, $hoja, "N{$f2}") === 'Sin registro');
[$c, , $L] = xlsx('sesionExamen', ['id' => $S2], $admin);
comprobar('sesión abierta: se permite y se marca EN CURSO', $c === 200 && str_starts_with((string) v($L, $hoja, 'A3'), 'Estado: EN CURSO'));

// ------------------------------------------------------------------
echo "\n[A) ficha PDF]\n";
[$c, $h, $cuerpo] = peticion('GET', r('deportistaFicha', ['deportista' => $E1]), $admin);
$pdf = leer('lector_pdf.py', $cuerpo);
$texto = $pdf['texto'] ?? '';
comprobar('200, application/pdf, longitud exacta y nombre limpio', $c === 200 && cabecerasOk($h, $cuerpo, 'application/pdf', "ficha_{$E1}_{$H}\\.pdf"), json_encode($h));
comprobar('empieza con %PDF y termina con %%EOF', str_starts_with($cuerpo, '%PDF-') && rtrim($cuerpo) !== '' && str_ends_with(rtrim($cuerpo), '%%EOF') && ($pdf['inicio'] ?? '') === '%PDF-');
comprobar('tildes, ñ y comillas se ven (conversión a Windows-1252)', str_contains($texto, 'Ñandú Pérez "Comillas"') && str_contains($texto, 'Categoría de edad') && str_contains($texto, 'Último examen'), substr($texto, 0, 300));
comprobar('perfil: documento, edad, categoría, grado, ingreso, estado', str_contains($texto, $E1) && str_contains($texto, '12 años') && str_contains($texto, 'Junior') && str_contains($texto, 'Amarillo') && str_contains($texto, '2026-01-10') && str_contains($texto, 'Activo'));
comprobar('menor de edad: datos del acudiente', str_contains($texto, 'Acudiente Ñoña Prueba (madre)') && str_contains($texto, '3001234567'));
comprobar('último examen = sesión cerrada (no la abierta): grado Naranja, promedio 6.67, aprobado, aviso PROVISIONAL', str_contains($texto, 'Naranja') && str_contains($texto, '6.67') && str_contains($texto, 'Aprobado') && str_contains($texto, 'PROVISIONAL') && !str_contains($texto, 'Verde'));
comprobar('qué mejorar deducido: nota baja de físico, aspectos fallidos y recomendaciones', str_contains($texto, 'Físico: nota 4.00 (menor de 5).') && str_contains($texto, 'Resistencia baja') && str_contains($texto, 'Correr 3 veces por semana') && str_contains($texto, 'Por mejorar'));
comprobar('últimos torneos realizados con puesto y medalla (sin el futuro)', str_contains($texto, 'Copa Ñandú Nacional') && str_contains($texto, 'Open Internacional Reservado') && str_contains($texto, 'Oro') && str_contains($texto, 'Bronce') && !str_contains($texto, 'Torneo Futuro'));
$p90 = api('asistencia', 'porcentaje', ['documento' => $E1], $admin);
$pTodo = api('asistencia', 'porcentaje', ['documento' => $E1, 'ventana' => 'todo'], $admin);
comprobar("asistencia con AsistenciaService::porcentaje (90 días: {$p90['porcentaje']} %, total: {$pTodo['porcentaje']} %)", str_contains($texto, number_format((float) $p90['porcentaje'], 1) . " % ({$p90['presentes']} de {$p90['sesiones']}") && str_contains($texto, number_format((float) $pTodo['porcentaje'], 1) . ' %'));
comprobar('permanencia: 2 meses pagados, primero y último', str_contains($texto, 'Meses pagados') && str_contains($texto, "{$anio}-01") && str_contains($texto, "{$anio}-02"));
comprobar('RN-10.3: perfil con peso, categoría de peso (DeportistaService) y condición de salud', str_contains($texto, 'Peso:') && str_contains($texto, '40,0 kg') && str_contains($texto, 'Categoría de peso:') && str_contains($texto, '-40') && str_contains($texto, 'Condición de salud:') && str_contains($texto, 'ASMA-PRUEBA-SALUD'), substr($texto, 0, 600));
comprobar('solo hechos: sin «aptitud», sin notas_oponente ni sexo (RN-1.6)', stripos($texto, 'aptitud') === false && !str_contains($texto, 'SECRETO') && stripos($texto, 'masculino') === false);
[$c, , $cuerpo] = peticion('GET', r('deportistaFicha', ['deportista' => $E3]), $admin);
$t3 = leer('lector_pdf.py', $cuerpo)['texto'] ?? '';
comprobar('adulto con pocos datos: ficha válida, sin acudiente, «Sin exámenes», asistencia 0.0 %', $c === 200 && str_contains($t3, 'Sin exámenes en sesiones cerradas.') && !str_contains($t3, 'Acudiente') && str_contains($t3, '0.0 % (0 de 4'), substr($t3, 0, 300));

// ------------------------------------------------------------------
echo "\n[consultas constantes]\n";
$urls = ['ficha' => r('deportistaFicha', ['deportista' => $E1]), 'deportistas' => r('deportistas'), 'asistencia' => r('asistencia', ['desde' => $sabados[0], 'hasta' => $H]),
    'pagos' => r('pagos'), 'torneos' => r('torneos'), 'sesionExamen' => r('sesionExamen', ['id' => $S1])];
$antes = [];
foreach ($urls as $n => $u) { $antes[$n] = consultasDe($u, $admin); }
$insG = $bd->prepare("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro) VALUES (?, ?, '2000-01-01', 'femenino', 1.6, 55, 1, '2026-01-01', 'activo', 'validado')");
for ($i = 10; $i < 40; $i++) {
    $doc = "99000001{$i}";
    $insG->execute([$doc, "Carga {$i}"]);
    foreach ($sabados as $f) { $insA->execute([$doc, $f, $i % 2]); }
    $insPago->execute([$doc, 'mensualidad', $anio, 1, 1]);
    $insP->execute([$doc, $T1, 'formas', 'mayores', null, $i, 'ninguna', null, null, 'x']);
    foreach (['teorico', 'fisico', 'combate', 'rompimiento', 'formas', 'llaves'] as $m) { $insDet->execute([$S1, $doc, $m, 'Blanco', 7, null, null, 1]); }
}
foreach (['teorico' => 9, 'fisico' => 9] as $m => $n) { $insDet->execute([$S2, $E2, $m, 'Verde', $n, null, null, 1]); }
foreach ($urls as $n => $u) {
    $despues = consultasDe($u, $admin);
    comprobar("{$n}: mismas consultas con 30 deportistas más ({$antes[$n]} -> {$despues})", $antes[$n] === $despues && $antes[$n] > 0);
}

// ------------------------------------------------------------------
echo "\n[volumen y memoria]\n";
$valores = []; $params = [];
for ($i = 0; $i < 10000; $i++) {
    $valores[] = "(?, ?, '2001-05-05', 'masculino', 1.7, 60, 1, '2025-01-01', 'activo', 'validado')";
    array_push($params, sprintf('98%08d', $i), $i % 1000 === 0 ? "=CMD|' /C calc'!A{$i}" : "Volumen Ñ {$i}");
    if (count($valores) === 1000) {
        $bd->prepare('INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro) VALUES ' . implode(',', $valores))->execute($params);
        $valores = []; $params = [];
    }
}
file_put_contents($logMemoria, '');
$t0 = microtime(true);
[$c, $h, $L, $cuerpo] = xlsx('deportistas', [], $admin);
$seg = round(microtime(true) - $t0, 2);
$filas = $L['hojas']['Deportistas']['filas'] ?? 0;
comprobar("10.000+ filas: archivo íntegro ({$filas} filas, " . round(strlen($cuerpo) / 1048576, 1) . " MB, {$seg} s), sin fórmulas, longitud exacta", $c === 200 && $L['crc_malo'] === null && $L['formulas'] === 0 && $filas >= 10006 && (int) $h['content-length'] === strlen($cuerpo));
$fCmd = filaDe($L, 'Deportistas', 'B', '9800000000');
comprobar('"=CMD|..." (DDE) queda como texto literal con quotePrefix', $fCmd !== null && v($L, 'Deportistas', "C{$fCmd}") === "=CMD|' /C calc'!A0" && in_array(s($L, 'Deportistas', "C{$fCmd}"), $L['quote_prefix'], true));
// 500 deportistas × 52 sábados.
$sab52 = [];
for ($i = 51; $i >= 0; $i--) { $sab52[] = $sabado->modify('-' . (7 * $i) . ' days')->format('Y-m-d'); }
$bd->exec("DELETE FROM asistencia WHERE deportista_documento LIKE '98%'");
for ($k = 0; $k < 500; $k += 50) {
    $valores = []; $params = [];
    for ($i = $k; $i < $k + 50; $i++) {
        foreach ($sab52 as $j => $f) { $valores[] = '(?, ?, ?)'; array_push($params, sprintf('98%08d', $i), $f, (int) (($i + $j) % 3 !== 0)); }
    }
    $bd->prepare('INSERT IGNORE INTO asistencia (deportista_documento, fecha, asistio) VALUES ' . implode(',', $valores))->execute($params);
}
$t0 = microtime(true);
[$c, $h, $L, $cuerpo] = xlsx('asistencia', ['desde' => $sab52[0], 'hasta' => $H], $admin);
$seg = round(microtime(true) - $t0, 2);
$memoria = [];
foreach (file($logMemoria, FILE_IGNORE_NEW_LINES) as $linea) { [$uri, $bytes] = explode("\t", $linea); $memoria[$uri] = (int) $bytes; }
$picoAsis = 0; $picoDep = 0;
foreach ($memoria as $uri => $bytes) {
    if (str_contains($uri, 'accion=asistencia')) { $picoAsis = max($picoAsis, $bytes); }
    if (str_contains($uri, 'accion=deportistas')) { $picoDep = max($picoDep, $bytes); }
}
$cols = count(array_filter(array_keys($L['hojas']['Asistencia']['celdas'] ?? []), fn ($k) => preg_match('/^[A-Z]+6$/', $k)));
comprobar("500 × 52: planilla íntegra ({$cols} columnas, " . (($L['hojas']['Asistencia']['filas'] ?? 0) - 7) . " filas, {$seg} s) con memory_limit=128M; pico " . round($picoAsis / 1048576, 1) . ' MB', $c === 200 && $L['crc_malo'] === null && $cols >= 52 + 6 && $picoAsis > 0 && $picoAsis < 128 * 1048576, $c . ' ' . substr($cuerpo, 0, 200));
comprobar('10.000 deportistas: pico de memoria ' . round($picoDep / 1048576, 1) . ' MB < 128 MB', $picoDep > 0 && $picoDep < 128 * 1048576);

// ------------------------------------------------------------------
echo "\n[disco y fugas]\n";
comprobar('nada se escribió en disco (backend/ idéntico salvo sesiones; Almacenamiento sin archivos nuevos)', fotoDisco() === $fotoInicial, json_encode(array_diff_assoc(fotoDisco(), $fotoInicial)));
$todo = implode("\n", $crudos);
comprobar('ninguna respuesta trae notas_oponente, contraseñas ni hash', !str_contains($todo, 'SECRETO-OPONENTE') && !str_contains($todo, 'contrasena') && !str_contains($todo, '$2y$'));
@rmdir($dirTmp);

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
