<?php

declare(strict_types=1);

// Bateria HTTP del paso 8 (Competencias), fuera del repositorio.
// Uso: php prueba_http_competencias.php <url base>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recien cargados.

$base = rtrim($argv[1], '/');
date_default_timezone_set('America/Bogota');
$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$bd->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$hoy = new DateTimeImmutable('today');

$ok = 0; $fallos = []; $crudos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}
/** @return array{0: int, 1: ?array} */
function peticion(string $metodo, string $url, ?array $cuerpo = null, ?string $cookie = null): array
{
    global $crudos;
    $cabeceras = "Content-Type: application/json\r\n" . ($cookie ? "Cookie: {$cookie}\r\n" : '');
    $contexto = stream_context_create(['http' => ['method' => $metodo, 'header' => $cabeceras, 'ignore_errors' => true,
        'content' => $cuerpo === null ? '' : json_encode($cuerpo)]]);
    $texto = (string) file_get_contents($url, false, $contexto);
    $crudos[] = [$cookie, $texto];
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $m);
    return [(int) $m[1], json_decode($texto, true)];
}
function sesion(string $accion, array $cuerpo): string
{
    global $base;
    $c = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'ignore_errors' => true, 'content' => json_encode($cuerpo)]]);
    file_get_contents("{$base}/api.php?recurso=auth&accion={$accion}", false, $c);
    foreach ($http_response_header as $h) { if (preg_match('/^Set-Cookie: (HWANGHON_SESION=[^;]*)/i', $h, $m)) { return $m[1]; } }
    return '';
}
function k(string $accion, array $query = []): string
{
    global $base;
    return "{$base}/api.php?recurso=competencias&accion={$accion}" . ($query ? '&' . http_build_query($query) : '');
}
function participacion(string $doc, int $evento, string $modalidad): ?array
{
    global $bd;
    $s = $bd->prepare('SELECT * FROM participacion_competencia WHERE deportista_documento = ? AND competencia_evento_id = ? AND modalidad = ?');
    $s->execute([$doc, $evento, $modalidad]);
    return $s->fetch() ?: null;
}
function cuenta(string $sql): int
{
    global $bd;
    return (int) $bd->query($sql)->fetchColumn();
}
function comSelect(): int
{
    global $bd;
    return (int) $bd->query("SHOW GLOBAL STATUS LIKE 'Com_select'")->fetch()['Value'];
}

// Eventos y torneos de prueba.
$futuro = $hoy->modify('+40 days')->format('Y-m-d');
$insE = $bd->prepare("INSERT INTO evento_calendario (id, nombre, tipo, fecha, lugar, visibilidad, observaciones) VALUES (?, ?, ?, ?, ?, ?, NULL)");
$insE->execute([10, 'Torneo Futuro Prueba', 'competencia', $futuro, 'Chía', 'publica']);
$insE->execute([11, 'Torneo Administrativo Prueba', 'competencia', '2026-06-01', 'Cúcuta', 'administrativa']);
$insE->execute([12, 'Torneo Pasado Prueba', 'competencia', '2025-09-10', 'Coliseo 100% Soacha_Norte', 'publica']);
$insE->execute([13, 'Examen No Es Torneo', 'evaluacion', '2026-12-01', 'Sede', 'publica']);
$insE->execute([14, 'Torneo Sin Competencia', 'competencia', '2027-01-15', 'Bogotá', 'publica']);
$insC = $bd->prepare('INSERT INTO competencia (evento_id, plataforma, tipo_torneo) VALUES (?, ?, ?)');
$insC->execute([10, 'Fitofan', 'nacional']);
$insC->execute([11, null, 'departamental']);
$insC->execute([12, 'Liga', 'departamental']);
// Deportistas de prueba.
$insD = $bd->prepare("INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, autorizacion_datos, fecha_ingreso, estado, estado_registro) VALUES (?, ?, ?, ?, 1.70, ?, 1, '2024-01-01', ?, ?)");
$cumple18 = (new DateTimeImmutable($futuro))->modify('-18 years')->modify('-5 days')->format('Y-m-d');
foreach ([
    ['9600000001', 'Peleador Adulto', '1990-05-05', 'masculino', 72.3, 'activo', 'validado'],
    ['9600000002', 'Peleadora Junior', (new DateTimeImmutable($futuro))->modify('-13 years -2 months')->format('Y-m-d'), 'femenino', 38.0, 'activo', 'validado'],
    ['9600000003', 'Inactivo Compite', '1995-01-01', 'masculino', 60, 'inactivo', 'validado'],
    ['9600000004', 'Cumple Dieciocho Antes', $cumple18, 'masculino', 74.0, 'activo', 'validado'],
    ['9600000005', 'Menor Siete', $hoy->modify('-7 years')->format('Y-m-d'), 'femenino', 22, 'activo', 'validado'],
    ['9600000006', 'Pendiente Compite', '1995-01-01', 'femenino', 0.01, 'activo', 'pendiente_validacion'],
] as $d) { $insD->execute($d); }
$bd->exec("UPDATE deportista SET peso = 0 WHERE documento = '9600000006'");

$admin = sesion('iniciarSesionAdministrativa', ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678']);
$publico = sesion('iniciarSesionPublica', ['documento' => '1000000101', 'fecha_nacimiento' => '2014-03-12']);
comprobar('sesiones administrativa y pública creadas', $admin !== '' && $publico !== '');

echo "\n[crear modo a: evento existente]\n";
[$c, $j] = peticion('POST', k('crear'), ['evento_id' => 14, 'plataforma' => 'Fitofan', 'tipo_torneo' => 'nacional'], $admin);
comprobar('evento de tipo competencia sin torneo -> 201 con evento + competencia + participantes vacíos', $c === 201 && $j['datos']['evento_id'] === 14 && $j['datos']['tipo_torneo'] === 'nacional'
    && $j['datos']['participantes'] === [] && $j['datos']['num_participantes'] === 0, $c . json_encode($j));
[$c] = peticion('POST', k('crear'), ['evento_id' => 14, 'tipo_torneo' => 'nacional'], $admin);
comprobar('el evento ya tiene competencia -> 409', $c === 409, (string) $c);
[$c] = peticion('POST', k('crear'), ['evento_id' => 13, 'tipo_torneo' => 'nacional'], $admin);
comprobar('evento de otro tipo (evaluación) -> 422', $c === 422, (string) $c);
[$c] = peticion('POST', k('crear'), ['evento_id' => 9999, 'tipo_torneo' => 'nacional'], $admin);
comprobar('evento inexistente -> 404', $c === 404, (string) $c);
[$c] = peticion('POST', k('crear'), ['evento_id' => 14, 'evento' => ['nombre' => 'X'], 'tipo_torneo' => 'nacional'], $admin);
comprobar('los dos modos a la vez -> 422', $c === 422, (string) $c);
[$c] = peticion('POST', k('crear'), ['tipo_torneo' => 'nacional'], $admin);
comprobar('sin evento_id ni evento -> 400', $c === 400, (string) $c);
[$c] = peticion('POST', k('crear'), ['evento_id' => 14, 'tipo_torneo' => 'mundial'], $admin);
comprobar('tipo_torneo inválido -> 400', $c === 400, (string) $c);

echo "\n[crear modo b: excepcional, evento + competencia en una transacción]\n";
$nuevo = ['nombre' => 'Municipal Soacha IMRDS', 'fecha' => '2027-02-01', 'lugar' => 'Soacha', 'visibilidad' => 'publica', 'observaciones' => 'No planeado'];
[$c, $j] = peticion('POST', k('crear'), ['evento' => $nuevo, 'plataforma' => null, 'tipo_torneo' => 'departamental'], $admin);
$idNuevo = $j['datos']['evento_id'] ?? 0;
comprobar('evento nuevo + competencia -> 201', $c === 201 && $j['datos']['nombre'] === 'Municipal Soacha IMRDS' && $j['datos']['plataforma'] === null, $c . json_encode($j));
comprobar('el evento quedó de tipo competencia (forzado)', $bd->query("SELECT tipo FROM evento_calendario WHERE id = {$idNuevo}")->fetchColumn() === 'competencia');
[$c] = peticion('POST', k('crear'), ['evento' => ['nombre' => 'copa ficticia HWANG hon 2026', 'fecha' => '2026-05-10', 'lugar' => 'X', 'visibilidad' => 'publica'], 'tipo_torneo' => 'nacional'], $admin);
comprobar('duplicado nombre + fecha sin distinguir mayúsculas -> 409 (sin crear nada)', $c === 409 && cuenta("SELECT COUNT(*) FROM evento_calendario WHERE fecha = '2026-05-10'") === 1, (string) $c);
[$c] = peticion('POST', k('crear'), ['evento' => array_merge($nuevo, ['nombre' => 'Otro', 'fecha' => '2027-02-30']), 'tipo_torneo' => 'nacional'], $admin);
comprobar('fecha inválida (validación de Calendario) -> 400', $c === 400, (string) $c);
[$c] = peticion('POST', k('crear'), ['evento' => array_merge($nuevo, ['nombre' => 'Otro', 'tipo' => 'grado']), 'tipo_torneo' => 'nacional'], $admin);
comprobar('evento con tipo distinto de competencia -> 422', $c === 422, (string) $c);
$bd->exec("CREATE TRIGGER prueba_falla_competencia BEFORE INSERT ON competencia FOR EACH ROW BEGIN IF NEW.plataforma = 'falla' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla forzada'; END IF; END");
[$c] = peticion('POST', k('crear'), ['evento' => array_merge($nuevo, ['nombre' => 'Torneo Que Falla']), 'plataforma' => 'falla', 'tipo_torneo' => 'nacional'], $admin);
comprobar('si falla la fila de competencia -> 500 y el evento tampoco queda (rollback)', $c === 500 && cuenta("SELECT COUNT(*) FROM evento_calendario WHERE nombre = 'Torneo Que Falla'") === 0, (string) $c);
$bd->exec('DROP TRIGGER prueba_falla_competencia');

echo "\n[actualizar]\n";
[$c, $j] = peticion('PUT', k('actualizar', ['id' => 10]), ['plataforma' => 'Liga Cundinamarca', 'tipo_torneo' => 'internacional'], $admin);
comprobar('actualizar plataforma y tipo_torneo -> 200', $c === 200 && $j['datos']['plataforma'] === 'Liga Cundinamarca' && $j['datos']['tipo_torneo'] === 'internacional', $c . json_encode($j));
[$c] = peticion('PUT', k('actualizar', ['id' => 10]), ['nombre' => 'Otro nombre'], $admin);
comprobar('datos del evento (nombre) -> 400 (se editan en Calendario)', $c === 400, (string) $c);
[$c] = peticion('PUT', k('actualizar', ['id' => 9999]), ['plataforma' => 'X'], $admin);
comprobar('torneo inexistente -> 404', $c === 404, (string) $c);

echo "\n[sugerencia]\n";
[$c, $j] = peticion('GET', k('sugerencia', ['deportista_documento' => '9600000004', 'competencia_evento_id' => 10]), null, $admin);
$edadHoy = $hoy->diff(new DateTimeImmutable($cumple18))->y;
comprobar("edad a la FECHA DEL TORNEO (18 en {$futuro}; hoy tiene {$edadHoy}) -> mayores", $c === 200 && $j['datos']['edad_a_la_fecha'] === 18 && $j['datos']['categoria_edad_sugerida'] === 'mayores' && $edadHoy === 17, $c . json_encode($j));
comprobar('respuesta con edad_a_la_fecha, categoria_edad_sugerida, peso_registrado, categoria_peso_sugerida (+ recomendacion_peso)', array_keys($j['datos']) === ['edad_a_la_fecha', 'categoria_edad_sugerida', 'peso_registrado', 'categoria_peso_sugerida', 'recomendacion_peso']
    && $j['datos']['peso_registrado'] == 74.0 && $j['datos']['categoria_peso_sugerida'] === '-75');
[$c, $j] = peticion('GET', k('sugerencia', ['deportista_documento' => '9600000004', 'competencia_evento_id' => 10, 'modalidad' => 'formas']), null, $admin);
comprobar('modalidad que no es combate -> categoria_peso_sugerida null', $c === 200 && $j['datos']['categoria_peso_sugerida'] === null && $j['datos']['categoria_edad_sugerida'] === 'mayores');
[$c, $j] = peticion('GET', k('sugerencia', ['deportista_documento' => '9600000006', 'competencia_evento_id' => 10, 'modalidad' => 'combate_individual']), null, $admin);
comprobar('sin peso registrado -> sin sugerencia de peso (null), no error', $c === 200 && $j['datos']['peso_registrado'] === null && $j['datos']['categoria_peso_sugerida'] === null, $c . json_encode($j));
[$c, $j] = peticion('GET', k('sugerencia', ['deportista_documento' => '9600000002', 'competencia_evento_id' => 10, 'modalidad' => 'combate_individual']), null, $admin);
comprobar('junior femenino 38 kg -> junior, -38 (corte oficial inclusivo)', $c === 200 && $j['datos']['categoria_edad_sugerida'] === 'junior' && $j['datos']['categoria_peso_sugerida'] === '-38', json_encode($j['datos'] ?? null));
[$c, $j] = peticion('GET', k('sugerencia', ['deportista_documento' => '9600000001', 'competencia_evento_id' => 10]), null, $admin);
comprobar('recomendación (PROPUESTA): 72,3 kg supera -70 por 2,3 -> puede recortar', $c === 200 && str_contains((string) $j['datos']['recomendacion_peso'], 'recortar') && str_contains((string) $j['datos']['recomendacion_peso'], '2,3'), json_encode($j['datos']['recomendacion_peso'] ?? null));
[$c] = peticion('GET', k('sugerencia', ['deportista_documento' => '0000000000', 'competencia_evento_id' => 10]), null, $admin);
comprobar('deportista inexistente -> 404', $c === 404, (string) $c);

echo "\n[inscribir]\n";
[$c, $j] = peticion('POST', k('inscribir'), ['competencia_evento_id' => 10, 'participantes' => [
    ['deportista_documento' => '9600000001', 'modalidad' => 'combate_individual'],
    ['deportista_documento' => '9600000002', 'modalidad' => 'formas'],
    ['deportista_documento' => '9600000004', 'modalidad' => 'combate_individual'],
    ['deportista_documento' => '9600000003', 'modalidad' => 'salto_alto'],
    ['deportista_documento' => '9600000001', 'modalidad' => 'combate_equipos', 'categoria_edad' => 'mayores', 'categoria_peso' => '-70'],
]], $admin);
comprobar('inscribir 5 (incluido un inactivo) -> 201 con las 5 participaciones', $c === 201 && count($j['datos']) === 5, $c . json_encode($j));
$p1 = participacion('9600000001', 10, 'combate_individual');
comprobar('sin categorías: se aplican las sugeridas (36 años a la fecha -> senior, 72,3 kg -> -75)', $p1['categoria_edad'] === 'senior' && $p1['categoria_peso'] === '-75', json_encode($p1));
comprobar('formas: categoria_peso queda null; junior por la edad a la fecha del torneo', participacion('9600000002', 10, 'formas')['categoria_peso'] === null && participacion('9600000002', 10, 'formas')['categoria_edad'] === 'junior');
comprobar('cumple 18 antes del torneo: inscrito en mayores (hoy sería juvenil)', participacion('9600000004', 10, 'combate_individual')['categoria_edad'] === 'mayores');
comprobar('categorías enviadas se respetan (-70 en vez de -75)', participacion('9600000001', 10, 'combate_equipos')['categoria_peso'] === '-70');
comprobar('inactivo inscrito (RN-3.9)', participacion('9600000003', 10, 'salto_alto') !== null);
comprobar('resultado, puesto, medalla y combates quedan nulos al inscribir', $p1['puesto'] === null && $p1['medalla'] === null && $p1['resultado'] === null && $p1['combates_ganados'] === null);
$antes = cuenta('SELECT COUNT(*) FROM participacion_competencia');
[$c, $j] = peticion('POST', k('inscribir'), ['competencia_evento_id' => 10, 'participantes' => [
    ['deportista_documento' => '9600000002', 'modalidad' => 'salto_largo'],
    ['deportista_documento' => '9600000001', 'modalidad' => 'combate_individual'],
]], $admin);
comprobar('lote con un duplicado de PK -> 409 indicando cuál y nadie del lote queda inscrito', $c === 409 && str_contains($j['mensaje'], '9600000001') && str_contains($j['mensaje'], 'combate_individual')
    && cuenta('SELECT COUNT(*) FROM participacion_competencia') === $antes, $c . json_encode($j));
[$c] = peticion('POST', k('inscribir'), ['competencia_evento_id' => 10, 'participantes' => [
    ['deportista_documento' => '9600000002', 'modalidad' => 'salto_largo'], ['deportista_documento' => '9600000002', 'modalidad' => 'salto_largo'],
]], $admin);
comprobar('repetido dentro del mismo lote -> 409 sin inscribir', $c === 409 && cuenta('SELECT COUNT(*) FROM participacion_competencia') === $antes, (string) $c);
[$c] = peticion('POST', k('inscribir'), ['competencia_evento_id' => 10, 'participantes' => [
    ['deportista_documento' => '9600000002', 'modalidad' => 'salto_largo'], ['deportista_documento' => '0000000000', 'modalidad' => 'formas'],
]], $admin);
comprobar('deportista inexistente en el lote -> 404 y nadie queda inscrito (todo o nada)', $c === 404 && cuenta('SELECT COUNT(*) FROM participacion_competencia') === $antes, (string) $c);
// Rollback real: falla forzada en la 2.a inserción.
$bd->exec("CREATE TRIGGER prueba_falla_participacion BEFORE INSERT ON participacion_competencia FOR EACH ROW BEGIN IF NEW.modalidad = 'salto_largo' AND NEW.deportista_documento = '9600000003' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla forzada'; END IF; END");
[$c] = peticion('POST', k('inscribir'), ['competencia_evento_id' => 10, 'participantes' => [
    ['deportista_documento' => '9600000002', 'modalidad' => 'salto_largo'], ['deportista_documento' => '9600000003', 'modalidad' => 'salto_largo'],
]], $admin);
comprobar('falla de la BD a mitad del lote -> 500 y rollback (la 1.a tampoco queda)', $c === 500 && participacion('9600000002', 10, 'salto_largo') === null, (string) $c);
$bd->exec('DROP TRIGGER prueba_falla_participacion');
[$c, $j] = peticion('POST', k('inscribir'), ['competencia_evento_id' => 10, 'participantes' => [['deportista_documento' => '9600000005', 'modalidad' => 'formas']]], $admin);
comprobar('menor de 8 años sin categoría de edad (no hay sugerida) -> 422', $c === 422, $c . json_encode($j));
[$c] = peticion('POST', k('inscribir'), ['competencia_evento_id' => 10, 'participantes' => [['deportista_documento' => '9600000005', 'modalidad' => 'formas', 'categoria_edad' => 'infantil']]], $admin);
comprobar('menor de 8 con categoría enviada -> 201', $c === 201, (string) $c);
[$c] = peticion('POST', k('inscribir'), ['competencia_evento_id' => 10, 'participantes' => [['deportista_documento' => '9600000006', 'modalidad' => 'formas']]], $admin);
comprobar('pendiente de validación: no se restringe -> 201', $c === 201, (string) $c);
[$c] = peticion('POST', k('inscribir'), ['competencia_evento_id' => 12, 'participantes' => [['deportista_documento' => '9600000001', 'modalidad' => 'combate_individual']]], $admin);
comprobar('torneo de fecha pasada (carga retroactiva) -> 201', $c === 201, (string) $c);
$cincuentaYUno = array_map(fn ($i) => ['deportista_documento' => '9600000001', 'modalidad' => 'formas'], range(1, 51));
foreach ([
    '51 participantes' => [10, $cincuentaYUno], 'modalidad inválida' => [10, [['deportista_documento' => '9600000001', 'modalidad' => 'kata']]],
    'categoria_edad con el nombre para mostrar (Sénior)' => [10, [['deportista_documento' => '9600000001', 'modalidad' => 'salto_largo', 'categoria_edad' => 'Sénior']]],
    'lista vacía' => [10, []],
] as $caso => [$evento, $lista]) {
    [$c] = peticion('POST', k('inscribir'), ['competencia_evento_id' => $evento, 'participantes' => $lista], $admin);
    comprobar("{$caso} -> 400", $c === 400, (string) $c);
}
[$c] = peticion('POST', k('inscribir'), ['competencia_evento_id' => 9999, 'participantes' => [['deportista_documento' => '9600000001', 'modalidad' => 'formas']]], $admin);
comprobar('torneo inexistente -> 404', $c === 404, (string) $c);

echo "\n[actualizarParticipacion]\n";
$pkFuturo = ['deportista_documento' => '9600000001', 'competencia_evento_id' => 10, 'modalidad' => 'combate_individual'];
[$c, $j] = peticion('PUT', k('actualizarParticipacion', $pkFuturo), ['puesto' => 1], $admin);
comprobar('resultado (puesto) en torneo futuro -> 422', $c === 422, $c . json_encode($j));
[$c] = peticion('PUT', k('actualizarParticipacion', $pkFuturo), ['combates_ganados' => 2], $admin);
comprobar('combates en torneo futuro -> 422', $c === 422, (string) $c);
[$c, $j] = peticion('PUT', k('actualizarParticipacion', $pkFuturo), ['categoria_peso' => '-70', 'notas_oponente' => 'Rival zurdo', 'puesto' => null], $admin);
comprobar('en torneo futuro sí se editan categoría y notas (y null en puesto no es cargar resultado) -> 200', $c === 200 && $j['datos']['categoria_peso'] === '-70' && $j['datos']['notas_oponente'] === 'Rival zurdo', $c . json_encode($j));
$pkPasado = ['deportista_documento' => '1000000102', 'competencia_evento_id' => 1, 'modalidad' => 'combate_individual'];
$antesFila = participacion('1000000102', 1, 'combate_individual');
[$c, $j] = peticion('PUT', k('actualizarParticipacion', $pkPasado), ['puesto' => 2], $admin);
$despues = participacion('1000000102', 1, 'combate_individual');
comprobar('actualización parcial: cambia el puesto y lo no enviado no se toca (combates 2/1, medalla bronce)', $c === 200 && (int) $despues['puesto'] === 2 && (int) $despues['combates_ganados'] === 2 && $despues['medalla'] === 'bronce' && $despues['categoria_edad'] === $antesFila['categoria_edad'], json_encode($despues));
[$c] = peticion('PUT', k('actualizarParticipacion', $pkPasado), ['resultado' => 'Tercer lugar', 'medalla' => null], $admin);
[$c2] = peticion('PUT', k('actualizarParticipacion', $pkPasado), ['resultado' => null], $admin);
$despues = participacion('1000000102', 1, 'combate_individual');
comprobar('null explícito limpia el campo (medalla y luego resultado)', $c === 200 && $c2 === 200 && $despues['medalla'] === null && $despues['resultado'] === null);
[$c, $j] = peticion('PUT', k('actualizarParticipacion', ['deportista_documento' => '1000000102', 'competencia_evento_id' => 1, 'modalidad' => 'defensa_personal']), ['combates_ganados' => 1], $admin);
comprobar('combates en modalidad que no es combate -> 422', $c === 422, $c . json_encode($j));
[$c] = peticion('PUT', k('actualizarParticipacion', ['deportista_documento' => '1000000102', 'competencia_evento_id' => 1, 'modalidad' => 'defensa_personal']), ['combates_ganados' => null, 'puesto' => 3, 'medalla' => 'oro'], $admin);
comprobar('combates null en modalidad no combate se acepta; puesto 3 con medalla oro (sin validar coherencia) -> 200', $c === 200, (string) $c);
[$c] = peticion('PUT', k('actualizarParticipacion', $pkPasado), ['puesto' => 3], $admin);
comprobar('dos terceros lugares en el mismo torneo (puesto no único) -> 200', $c === 200 && cuenta('SELECT COUNT(*) FROM participacion_competencia WHERE competencia_evento_id = 1 AND puesto = 3') === 2);
foreach (['puesto 0' => ['puesto' => 0], 'puesto 100' => ['puesto' => 100], 'combates 128' => ['combates_ganados' => 128], 'combates -1' => ['combates_perdidos' => -1],
    'medalla inválida' => ['medalla' => 'platino'], 'modalidad en el cuerpo' => ['modalidad' => 'formas'], 'categoria_edad null' => ['categoria_edad' => null],
    'campo desconocido' => ['monto' => 1], 'categoria_peso de 31 caracteres' => ['categoria_peso' => str_repeat('x', 31)]] as $caso => $cuerpo) {
    [$c] = peticion('PUT', k('actualizarParticipacion', $pkPasado), $cuerpo, $admin);
    comprobar("{$caso} -> 400", $c === 400, (string) $c);
}
[$c] = peticion('PUT', k('actualizarParticipacion', ['deportista_documento' => '1000000102', 'competencia_evento_id' => 1, 'modalidad' => 'formas']), ['puesto' => 1], $admin);
comprobar('participación inexistente -> 404', $c === 404, (string) $c);

echo "\n[quitarParticipante]\n";
[$c, $j] = peticion('DELETE', k('quitarParticipante', ['deportista_documento' => '9600000003', 'competencia_evento_id' => 10, 'modalidad' => 'salto_alto']), null, $admin);
comprobar('quitar -> 200 {eliminado: true}', $c === 200 && $j['datos'] === ['eliminado' => true] && participacion('9600000003', 10, 'salto_alto') === null, (string) $c);
[$c] = peticion('DELETE', k('quitarParticipante', ['deportista_documento' => '9600000003', 'competencia_evento_id' => 10, 'modalidad' => 'salto_alto']), null, $admin);
comprobar('quitar otra vez -> 404', $c === 404, (string) $c);
[$c] = peticion('DELETE', k('eliminar', ['id' => 10]), null, $admin);
comprobar('eliminar torneo no existe -> 404', $c === 404, (string) $c);

echo "\n[consultar y vista pública]\n";
[$c, $j] = peticion('GET', k('consultar', ['id' => 10]), null, $admin);
comprobar('admin: consultar trae evento + competencia + todos los participantes con nombre y notas_oponente', $c === 200 && count($j['datos']['participantes']) === 6
    && array_key_exists('notas_oponente', $j['datos']['participantes'][0]) && $j['datos']['participantes'][0]['nombre'] !== null, $c . json_encode($j['datos']['participantes'] ?? null));
comprobar('num_participantes cuenta deportistas distintos (Peleador Adulto en 2 modalidades cuenta 1)', $j['datos']['num_participantes'] === 5, (string) ($j['datos']['num_participantes'] ?? '?'));
[$c, $j] = peticion('GET', k('consultar', ['id' => 1]), null, $publico);
comprobar('público: torneo público -> solo su propia participación, sin notas_oponente', $c === 200 && count($j['datos']['participantes']) === 1
    && $j['datos']['participantes'][0]['deportista_documento'] === '1000000101' && !array_key_exists('notas_oponente', $j['datos']['participantes'][0]), json_encode($j['datos']['participantes'] ?? null));
[$c] = peticion('GET', k('consultar', ['id' => 11]), null, $publico);
comprobar('público: torneo administrativo -> 404', $c === 404, (string) $c);
[$c, $j] = peticion('GET', k('listar'), null, $publico);
$ids = array_column($j['datos'], 'evento_id');
comprobar('público: listar solo torneos públicos, con indicador participa', $c === 200 && !in_array(11, $ids, true) && array_unique(array_column($j['datos'], 'visibilidad')) === ['publica']
    && array_column($j['datos'], 'participa', 'evento_id')[1] === true && array_column($j['datos'], 'participa', 'evento_id')[10] === false, json_encode(array_column($j['datos'], 'participa', 'evento_id')));
comprobar('notas_oponente nunca sale al público', array_filter($crudos, fn ($r) => $r[0] === $publico && str_contains($r[1], 'notas_oponente')) === []);
[$c, $j] = peticion('GET', k('listar', ['deportista' => '1000000101']), null, $publico);
comprobar('público: filtrar por su propio documento -> sus torneos', $c === 200 && array_column($j['datos'], 'evento_id') === [1]);
[$c] = peticion('GET', k('listar', ['deportista' => '9600000001']), null, $publico);
comprobar('público: filtrar por otro deportista -> 404', $c === 404, (string) $c);
foreach (['proximo' => ['GET', k('proximo'), null], 'crear' => ['POST', k('crear'), ['evento_id' => 14, 'tipo_torneo' => 'nacional']],
    'inscribir' => ['POST', k('inscribir'), ['competencia_evento_id' => 1, 'participantes' => []]], 'sugerencia' => ['GET', k('sugerencia', ['deportista_documento' => '1000000101', 'competencia_evento_id' => 1]), null]] as $accion => [$m, $u, $cu]) {
    [$c] = peticion($m, $u, $cu, $publico);
    comprobar("público: {$accion} -> 403", $c === 403, (string) $c);
}
[$c] = peticion('GET', k('listar'));
comprobar('anónimo -> 401', $c === 401, (string) $c);

echo "\n[listar con filtros y próximo]\n";
[$c, $j] = peticion('GET', k('listar'), null, $admin);
$fechas = array_column($j['datos'], 'fecha');
$ordenadas = $fechas; rsort($ordenadas);
comprobar('admin: todos los torneos (incluido el administrativo), por fecha descendente', $c === 200 && in_array(11, array_column($j['datos'], 'evento_id'), true) && $fechas === $ordenadas && !array_key_exists('participa', $j['datos'][0]), json_encode($fechas));
[$c, $j] = peticion('GET', k('listar', ['deportista' => '9600000001']), null, $admin);
comprobar('filtro deportista -> torneos donde está inscrito (10 y 12)', $c === 200 && array_column($j['datos'], 'evento_id') === [10, 12], json_encode(array_column($j['datos'], 'evento_id')));
[$c, $j] = peticion('GET', k('listar', ['desde' => '2026-01-01', 'hasta' => '2026-12-31']), null, $admin);
comprobar('filtro desde + hasta', $c === 200 && array_filter($j['datos'], fn ($t) => $t['fecha'] < '2026-01-01' || $t['fecha'] > '2026-12-31') === [] && count($j['datos']) >= 2);
[$c, $j] = peticion('GET', k('listar', ['desde' => '2027-01-01']), null, $admin);
comprobar('filtro solo desde', $c === 200 && array_column($j['datos'], 'evento_id') == [$idNuevo, 14], json_encode(array_column($j['datos'], 'evento_id')));
[$c, $j] = peticion('GET', k('listar', ['lugar' => '100%']), null, $admin);
comprobar('filtro lugar con % literal -> solo «Coliseo 100% Soacha_Norte»', $c === 200 && array_column($j['datos'], 'evento_id') === [12], json_encode(array_column($j['datos'], 'evento_id')));
[$c, $j] = peticion('GET', k('listar', ['lugar' => 'a_N']), null, $admin);
comprobar('filtro lugar con _ literal', $c === 200 && array_column($j['datos'], 'evento_id') === [12]);
[$c, $j] = peticion('GET', k('listar', ['lugar' => 'cucuta']), null, $admin);
comprobar('filtro lugar sin distinguir tildes ni mayúsculas (cucuta -> Cúcuta)', $c === 200 && array_column($j['datos'], 'evento_id') === [11]);
[$c, $j] = peticion('GET', k('listar', ['tipo_torneo' => 'departamental']), null, $admin);
comprobar('filtro tipo_torneo', $c === 200 && array_unique(array_column($j['datos'], 'tipo_torneo')) === ['departamental']);
[$c] = peticion('GET', k('listar', ['desde' => '2026-13-01']), null, $admin);
comprobar('fecha inválida en filtro -> 400', $c === 400, (string) $c);
$antes = comSelect(); peticion('GET', k('listar'), null, $admin); $pocos = comSelect() - $antes;
for ($i = 0; $i < 10; $i++) {
    $insE->execute([100 + $i, "Relleno {$i}", 'competencia', '2024-01-' . sprintf('%02d', $i + 1), 'X', 'publica']);
    $insC->execute([100 + $i, null, 'nacional']);
    $bd->prepare("INSERT INTO participacion_competencia (deportista_documento, competencia_evento_id, modalidad, categoria_edad) VALUES ('9600000001', ?, 'formas', 'mayores')")->execute([100 + $i]);
}
$antes = comSelect(); peticion('GET', k('listar'), null, $admin); $muchos = comSelect() - $antes;
comprobar("listar con consultas constantes: {$pocos} SELECT con 7 torneos y {$muchos} con 17 (sin N+1)", $pocos === $muchos && $pocos <= 3, "{$pocos} vs {$muchos}");
[$c, $j] = peticion('GET', k('proximo'), null, $admin);
comprobar('próximo: el primer torneo con fecha >= hoy, con nombre, fecha y num_participantes', $c === 200 && $j['datos']['evento_id'] === 10 && $j['datos']['fecha'] === $futuro && $j['datos']['num_participantes'] === 5, $c . json_encode($j));
$bd->exec("UPDATE evento_calendario SET fecha = '2020-01-01' WHERE fecha >= '" . $hoy->format('Y-m-d') . "' AND tipo = 'competencia'");
[$c, $j] = peticion('GET', k('proximo'), null, $admin);
comprobar('sin torneos futuros -> null', $c === 200 && $j['datos'] === null, $c . json_encode($j));

echo "\n[buscador para inscribir]\n";
[$c, $j] = peticion('GET', "{$base}/api.php?recurso=deportistas&accion=buscar&texto=" . rawurlencode('Inactivo Compite'), null, $admin);
comprobar('deportistas&accion=buscar ya incluye inactivos (sin parámetro nuevo)', $c === 200 && ($j['datos'][0]['estado'] ?? null) === 'inactivo', $c . json_encode($j));

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
