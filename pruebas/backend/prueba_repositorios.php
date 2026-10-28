<?php

declare(strict_types=1);

// Las fechas (date(), 'today') se calculan con la hora de Bogotá, como el backend.
date_default_timezone_set('America/Bogota');

// Bateria de Repositories y Models contra el esquema v4 (fuera del repositorio).
// Uso: php prueba_repositorios.php <ruta a backend/Src>
// Requiere la base recien cargada (esquema v4 + semilla).

$src = $argv[1];
require $src . '/Config/database.php';
require $src . '/Repositories/BaseRepository.php';
foreach (glob($src . '/Models/*.php') as $f) { require $f; }
foreach (glob($src . '/Repositories/*.php') as $f) { if (basename($f) !== 'BaseRepository.php') { require $f; } }

$ok = 0; $fallos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}
function lanza(callable $f, string $clase, ?string $codigo = null): bool
{
    try { $f(); } catch (Throwable $e) {
        return $e instanceof $clase && ($codigo === null || (string) $e->getCode() === $codigo);
    }
    return false;
}

$bd = Database::obtenerConexion();

// ------------------------------------------------------------------
// 1. CRUD generico por tabla (14 tablas)
// ------------------------------------------------------------------
// [repo, modelo, filas en semilla, datos nuevos, llave (args de obtenerPorId/eliminar) o null si es AUTO_INCREMENT,
//  cambios para actualizar, filtro por columna nueva [columna, valor, filas esperadas tras crear]]
$tablas = [
    'acudiente_tutor' => ['AcudienteTutorRepository', 'AcudienteTutorModel', 1,
        ['documento' => '9000000001', 'nombre' => 'Acudiente Prueba', 'relacion' => 'padre', 'telefono' => '3009999999', 'correo' => 'acu@ficticio.test'],
        ['9000000001'], ['telefono' => '3001111111', 'correo' => null], ['correo', 'acu@ficticio.test', 1]],
    'usuario_administrativo' => ['UsuarioAdministrativoRepository', 'UsuarioAdministrativoModel', 3,
        ['correo' => 'prueba@ficticio.test', 'contrasena' => '$2y$10$hashficticio', 'nombre' => 'Usuario Prueba', 'rol' => 'staff',
         'es_administrador_principal' => false, 'activa' => true, 'creado_por_correo' => 'admin.fundador@hwanghonficticio.test',
         'token_recuperacion' => null, 'token_recuperacion_expira' => null],
        ['prueba@ficticio.test'], ['activa' => false, 'nombre' => 'Usuario Prueba Editado'], ['activa', 1, 4]],
    'deportista' => ['DeportistaRepository', 'DeportistaModel', 3,
        ['documento' => '9000000101', 'nombre' => 'Deportista Prueba', 'fecha_nacimiento' => '2000-01-01', 'lugar_nacimiento' => 'Soacha',
         'sexo' => 'femenino', 'estatura' => 1.6, 'peso' => 55.25, 'telefono' => '+57 300 000 0000', 'direccion' => 'Calle Ficticia 1',
         'correo' => 'dep@ficticio.test', 'discapacidad' => null, 'experiencia_previa' => 'Judo 2 años', 'estado_animico' => null,
         'sede_entrenamiento' => 'Club Hwang Hon', 'autorizacion_datos' => true, 'grado_actual' => null, 'fecha_ingreso' => '2026-01-01',
         'estado' => 'activo', 'estado_registro' => 'validado', 'acudiente_documento' => null, 'validado_por_correo' => null],
        ['9000000101'], ['direccion' => 'Calle Ficticia 2', 'lugar_nacimiento' => null], ['lugar_nacimiento', 'Soacha', 2]],
    'documento' => ['DocumentoRepository', 'DocumentoModel', 3,
        ['id' => 0, 'deportista_documento' => '1000000101', 'tipo_documento' => 'fotografia', 'archivo' => 'abc.pdf',
         'nombre_original' => 'foto_original.pdf', 'fecha_carga' => '2026-02-02'],
        null, ['nombre_original' => 'foto_renombrada.pdf'], ['nombre_original', 'foto_original.pdf', 1]],
    'documento_general' => ['DocumentoGeneralRepository', 'DocumentoGeneralModel', 1,
        ['id' => 0, 'titulo' => 'Reglamento ficticio', 'archivo' => 'def.pdf', 'nombre_original' => 'reglamento.pdf', 'fecha_carga' => '2026-04-01'],
        null, ['titulo' => 'Reglamento ficticio v2'], ['titulo', 'Reglamento ficticio', 1]],
    'asistencia' => ['AsistenciaRepository', 'AsistenciaModel', 3,
        ['deportista_documento' => '1000000103', 'fecha' => '2026-03-21', 'asistio' => false],
        ['1000000103', '2026-03-21'], ['asistio' => true], ['asistio', 0, 2]],
    'pago' => ['PagoRepository', 'PagoModel', 3,
        ['id' => 0, 'deportista_documento' => '1000000103', 'tipo' => 'mensualidad', 'anio' => 2026, 'mes' => 4, 'semana' => 3],
        null, ['semana' => null], ['mes', 4, 1]],
    'evento_calendario' => ['EventoCalendarioRepository', 'EventoCalendarioModel', 5,
        ['id' => 0, 'nombre' => 'Grado prueba', 'tipo' => 'grado', 'fecha' => '2026-11-01', 'lugar' => 'Sede', 'visibilidad' => 'publica', 'observaciones' => null],
        null, ['observaciones' => 'Nota'], ['tipo', 'grado', 2]],
    'competencia' => ['CompetenciaRepository', 'CompetenciaModel', 1,
        ['evento_id' => 4, 'plataforma' => null, 'tipo_torneo' => 'nacional'],
        [4], ['plataforma' => 'Otra'], ['tipo_torneo', 'nacional', 1]],
    'participacion_competencia' => ['ParticipacionCompetenciaRepository', 'ParticipacionCompetenciaModel', 3,
        ['deportista_documento' => '1000000103', 'competencia_evento_id' => 1, 'modalidad' => 'salto_alto', 'categoria_edad' => 'mayores',
         'categoria_peso' => null, 'puesto' => 2, 'resultado' => null, 'medalla' => 'plata', 'combates_ganados' => null,
         'combates_perdidos' => null, 'notas_oponente' => null],
        ['1000000103', 1, 'salto_alto'], ['puesto' => 255], ['puesto', 2, 1]],
    'sesion_examen' => ['SesionExamenRepository', 'SesionExamenModel', 1,
        ['id' => 0, 'fecha' => '2026-10-20', 'fecha_grado' => '2026-10-27', 'lugar' => 'Sede', 'estado' => 'abierta',
         'evento_examen_id' => null, 'evento_grado_id' => null],
        null, ['estado' => 'cerrada', 'evento_examen_id' => 3], ['fecha_grado', '2026-10-27', 1]],
    'detalle_evaluacion' => ['DetalleEvaluacionRepository', 'DetalleEvaluacionModel', 3,
        ['sesion_examen_id' => 1, 'deportista_documento' => '1000000101', 'modalidad' => 'formas', 'grado_evaluado' => 'amarillo',
         'estado' => 'completado', 'fecha_completado' => '2026-04-27', 'nota' => 9.75, 'criterios_evaluados' => null, 'puntajes' => null,
         'valor_medible' => null, 'aspectos_fallidos' => null, 'observaciones' => null, 'recomendaciones' => null, 'aprobado' => true],
        [1, '1000000101', 'formas'], ['nota' => 1.0, 'aprobado' => false], ['nota', 9.75, 1]],
    'entrega_cinturon' => ['EntregaCinturonRepository', 'EntregaCinturonModel', 0,
        ['sesion_examen_id' => 1, 'deportista_documento' => '1000000101', 'cinturon_entregado' => true, 'fecha_entrega' => '2026-05-03',
         'confirmado_por' => 'deportista'],
        [1, '1000000101'], ['confirmado_por' => 'administrativo'], ['confirmado_por', 'deportista', 1]],
    'intento_acceso' => ['IntentoAccesoRepository', 'IntentoAccesoModel', 0,
        ['ambito' => 'publica', 'identificador' => '1000000101', 'intentos_fallidos' => 4, 'bloqueado_hasta' => '2026-10-16 15:30:00'],
        ['publica', '1000000101'], ['intentos_fallidos' => 0, 'bloqueado_hasta' => null], ['intentos_fallidos', 4, 1]],
];

foreach ($tablas as $tabla => [$claseRepo, $claseModelo, $filasSemilla, $datos, $llave, $cambios, $filtro]) {
    echo "\n[{$tabla}]\n";
    $repo = new $claseRepo();

    $filas = $repo->listar();
    comprobar("{$tabla}: listar() devuelve las {$filasSemilla} filas de la semilla", count($filas) === $filasSemilla, (string) count($filas));
    comprobar("{$tabla}: contar() = {$filasSemilla}", $repo->contar() === $filasSemilla);
    $tiposOk = true;
    foreach ($filas as $fila) { try { $claseModelo::fromArray($fila); } catch (TypeError $e) { $tiposOk = false; } }
    comprobar("{$tabla}: fromArray() acepta las filas reales (sin TypeError)", $tiposOk);

    $modelo = $claseModelo::fromArray($datos);
    if (!method_exists($repo, 'crear')) {
        // Retirado por código muerto (revisión 23/10): se inserta con SQL directo.
        comprobar("{$tabla}: crear() retirado (sin uso en el código)", true);
        $fila = $modelo->toArray();
        $bd->prepare("INSERT INTO {$tabla} (" . implode(', ', array_keys($fila)) . ') VALUES (' . implode(', ', array_map(fn ($c) => ":{$c}", array_keys($fila))) . ')')
            ->execute(array_map(fn ($v) => is_bool($v) ? (int) $v : $v, $fila));
        $resultado = true;
    } else {
        $resultado = $repo->crear($modelo);
    }
    if ($llave === null) {
        comprobar("{$tabla}: crear() devuelve el id nuevo", is_int($resultado) && $resultado > 0);
        $llave = [$resultado];
        $datos['id'] = $resultado;
    } else {
        comprobar("{$tabla}: crear() devuelve true", $resultado === true);
    }

    $leida = $repo->obtenerPorId(...$llave);
    $esperado = $claseModelo::fromArray($datos)->toArray();
    if ($tabla === 'usuario_administrativo') {
        // Las lecturas normales no traen credenciales: se compara con la lectura de autenticacion.
        $leida = $repo->obtenerParaAutenticacion($llave[0]);
    }
    comprobar("{$tabla}: obtenerPorId() devuelve exactamente lo creado (columnas nuevas incluidas)",
        $leida !== null && $claseModelo::fromArray($leida)->toArray() == $esperado,
        json_encode($leida));

    [$columnaFiltro, $valorFiltro, $esperadas] = $filtro;
    comprobar("{$tabla}: listar() filtra por {$columnaFiltro}", count($repo->listar([$columnaFiltro => $valorFiltro])) === $esperadas);

    if (method_exists($repo, 'actualizar')) {
        $modificado = $claseModelo::fromArray(array_merge($datos, $cambios));
        $repo->actualizar($modificado);
        $leida = $tabla === 'usuario_administrativo' ? $repo->obtenerParaAutenticacion($llave[0]) : $repo->obtenerPorId(...$llave);
        comprobar("{$tabla}: actualizar() guarda los cambios", $claseModelo::fromArray($leida)->toArray() == $modificado->toArray(), json_encode($leida));
    } else {
        comprobar("{$tabla}: actualizar() retirado (sin uso en el código)", true);
    }

    if (method_exists($repo, 'eliminar')) {
        $repo->eliminar(...$llave);
    } else {
        comprobar("{$tabla}: eliminar() retirado (contradice las reglas: no se borra)", true);
        $columnaLlave = ['usuario_administrativo' => 'correo', 'competencia' => 'evento_id'][$tabla];
        $bd->prepare("DELETE FROM {$tabla} WHERE {$columnaLlave} = ?")->execute([$llave[0]]);
    }
    comprobar("{$tabla}: eliminar() borra la fila", $repo->obtenerPorId(...$llave) === null && $repo->contar() === $filasSemilla);
}

// ------------------------------------------------------------------
// 2. Modo estricto en la conexion (punto 2)
// ------------------------------------------------------------------
echo "\n[modo estricto]\n";
$modo = $bd->query('SELECT @@SESSION.sql_mode')->fetchColumn();
comprobar('La sesion PDO tiene STRICT_ALL_TABLES', str_contains($modo, 'STRICT_ALL_TABLES'), $modo);

$detalles = new DetalleEvaluacionRepository();
$fueraDeRango = fn (array $c) => DetalleEvaluacionModel::fromArray(array_merge(
    ['sesion_examen_id' => 1, 'deportista_documento' => '1000000101', 'modalidad' => 'rompimiento', 'grado_evaluado' => 'amarillo', 'estado' => 'pendiente'], $c));
comprobar('nota 100 (DECIMAL 4,2) lanza PDOException 22003 en vez de guardar 99.99',
    lanza(fn () => $detalles->crear($fueraDeRango(['nota' => 100.0])), PDOException::class, '22003'));
comprobar('nota 0.5 viola el CHECK 1-10 y lanza PDOException',
    lanza(fn () => $detalles->crear($fueraDeRango(['nota' => 0.5])), PDOException::class));
comprobar('No quedo ninguna fila de esas pruebas', $detalles->obtenerPorId(1, '1000000101', 'rompimiento') === null);

$participaciones = new ParticipacionCompetenciaRepository();
comprobar('puesto -1 (TINYINT UNSIGNED) lanza PDOException en vez de guardar 0',
    lanza(fn () => $participaciones->crear(ParticipacionCompetenciaModel::fromArray(['deportista_documento' => '1000000103',
        'competencia_evento_id' => 1, 'modalidad' => 'formas', 'categoria_edad' => 'mayores', 'puesto' => -1])), PDOException::class, '22003'));

$eventos = new EventoCalendarioRepository();
comprobar('ENUM desconocido (tipo "fiesta") lanza PDOException en vez de guardar ""',
    lanza(fn () => $eventos->crear(EventoCalendarioModel::fromArray(['nombre' => 'X', 'tipo' => 'fiesta', 'fecha' => '2026-12-01',
        'lugar' => 'Sede', 'visibilidad' => 'publica'])), PDOException::class, '01000'));
comprobar('Texto mas largo que la columna lanza PDOException en vez de recortarlo',
    lanza(fn () => $eventos->crear(EventoCalendarioModel::fromArray(['nombre' => str_repeat('a', 151), 'tipo' => 'grado',
        'fecha' => '2026-12-01', 'lugar' => 'Sede', 'visibilidad' => 'publica'])), PDOException::class, '22001'));

$pagos = new PagoRepository();
$pago = fn (array $c) => PagoModel::fromArray(array_merge(['deportista_documento' => '1000000103', 'tipo' => 'mensualidad', 'anio' => 2026], $c));
comprobar('pago mes 13 viola el CHECK 0-12', lanza(fn () => $pagos->crear($pago(['mes' => 13, 'semana' => 1])), PDOException::class));
comprobar('pago semana 5 viola el CHECK 1-4', lanza(fn () => $pagos->crear($pago(['mes' => 5, 'semana' => 5])), PDOException::class));
comprobar('pago repetido (deportista, tipo, anio, mes) viola la llave unica (23000)',
    lanza(fn () => $pagos->crear(PagoModel::fromArray(['deportista_documento' => '1000000101', 'tipo' => 'mensualidad', 'anio' => 2026, 'mes' => 3, 'semana' => 4])), PDOException::class, '23000'));

// Contraste: la misma insercion en una conexion SIN el modo de la aplicacion (como venia el MariaDB de XAMPP).
$sinEstricto = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sinEstricto->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
$sinEstricto->exec("INSERT INTO participacion_competencia (deportista_documento, competencia_evento_id, modalidad, categoria_edad, puesto) VALUES ('1000000103', 1, 'formas', 'mayores', -1)");
$puestoGuardado = $sinEstricto->query("SELECT puesto FROM participacion_competencia WHERE deportista_documento = '1000000103' AND modalidad = 'formas'")->fetchColumn();
comprobar('Contraste sin modo estricto: puesto -1 se guarda en silencio como 0', (string) $puestoGuardado === '0', (string) $puestoGuardado);
$sinEstricto->exec("DELETE FROM participacion_competencia WHERE deportista_documento = '1000000103' AND modalidad = 'formas'");
comprobar('Contraste sin modo estricto: nota 100 la frena igual el CHECK de MariaDB (no queda en 99.99)',
    lanza(fn () => $sinEstricto->exec("INSERT INTO detalle_evaluacion (sesion_examen_id, deportista_documento, modalidad, grado_evaluado, estado, nota) VALUES (1, '1000000101', 'contraste', 'amarillo', 'pendiente', 100)"), PDOException::class, '23000'));

// ------------------------------------------------------------------
// 3. Ajustes de BaseRepository y UsuarioAdministrativo (punto 3)
// ------------------------------------------------------------------
echo "\n[BaseRepository]\n";
$deportistas = new DeportistaRepository();

// (a) ORDEN con desempate: dos deportistas con el mismo nombre.
foreach (['9000000202', '9000000201'] as $doc) {
    $deportistas->crear(DeportistaModel::fromArray(['documento' => $doc, 'nombre' => 'Nombre Repetido', 'fecha_nacimiento' => '2000-01-01',
        'sexo' => 'masculino', 'estatura' => 1.7, 'peso' => 70, 'autorizacion_datos' => true, 'fecha_ingreso' => '2026-01-01',
        'estado' => 'activo', 'estado_registro' => 'validado']));
}
$total = $deportistas->contar();
$paginado = [];
for ($i = 0; $i < $total; $i++) {
    foreach ($deportistas->listar([], 1, $i) as $fila) { $paginado[] = $fila['documento']; }
}
$completo = array_column($deportistas->listar(), 'documento');
comprobar('(a) Paginar de 1 en 1 da las mismas filas, sin repetir ni saltar', $paginado === $completo && count(array_unique($paginado)) === $total);
$repetidos = array_values(array_filter($completo, fn ($d) => str_starts_with($d, '90000002')));
comprobar('(a) Empate por nombre se resuelve por documento (llave primaria)', $repetidos === ['9000000201', '9000000202'], json_encode($repetidos));

// (b) Filtro null -> IS NULL.
$sinAcudienteSql = (int) $bd->query('SELECT COUNT(*) FROM deportista WHERE acudiente_documento IS NULL')->fetchColumn();
comprobar('(b) listar([acudiente_documento => null]) usa IS NULL', count($deportistas->listar(['acudiente_documento' => null])) === $sinAcudienteSql && $sinAcudienteSql === 4);
comprobar('(b) contar([acudiente_documento => null]) usa IS NULL', $deportistas->contar(['acudiente_documento' => null]) === 4);
comprobar('(b) null combinado con otro filtro', $deportistas->contar(['acudiente_documento' => null, 'estado' => 'inactivo']) === 1);

// (c) existePorId.
$acudientes = new AcudienteTutorRepository();
comprobar('(c) DeportistaRepository::existePorId true para uno existente', $deportistas->existePorId('1000000101') === true);
comprobar('(c) DeportistaRepository::existePorId false para uno inexistente', $deportistas->existePorId('0000000000') === false);
comprobar('(c) AcudienteTutorRepository::existePorId true/false', $acudientes->existePorId('1000000001') === true && $acudientes->existePorId('0') === false);

foreach (['9000000201', '9000000202'] as $doc) { $deportistas->eliminar($doc); }

// Limite / desplazamiento negativos.
comprobar('limite negativo lanza InvalidArgumentException', lanza(fn () => $deportistas->listar([], -1), InvalidArgumentException::class));
comprobar('desplazamiento negativo lanza InvalidArgumentException', lanza(fn () => $deportistas->listar([], 10, -5), InvalidArgumentException::class));
comprobar('limite 0 y desplazamiento 0 son validos', $deportistas->listar([], 0, 0) === []);
comprobar('columna fuera de COLUMNAS sigue lanzando LogicException', lanza(fn () => $deportistas->listar(['1=1 OR documento' => 'x']), LogicException::class));

echo "\n[UsuarioAdministrativo sin credenciales]\n";
$usuarios = new UsuarioAdministrativoRepository();
$sensibles = ['contrasena', 'token_recuperacion', 'token_recuperacion_expira'];
$sinCredenciales = fn (array $fila) => array_intersect($sensibles, array_keys($fila)) === [];
comprobar('listar() no trae contrasena ni token', array_filter($usuarios->listar(), fn ($f) => !$sinCredenciales($f)) === [] && count($usuarios->listar()) === 3);
$fila = $usuarios->obtenerPorId('admin.fundador@hwanghonficticio.test');
comprobar('obtenerPorId() no trae contrasena ni token', $fila !== null && $sinCredenciales($fila) && array_key_exists('activa', $fila));
$auth = $usuarios->obtenerParaAutenticacion('admin.fundador@hwanghonficticio.test');
comprobar('obtenerParaAutenticacion() si trae contrasena y token', $auth !== null && array_intersect($sensibles, array_keys($auth)) === $sensibles);
comprobar('No se puede filtrar por contrasena (LogicException)', lanza(fn () => $usuarios->listar(['contrasena' => 'x']), LogicException::class));

$hashAntes = $auth['contrasena'];
$modelo = UsuarioAdministrativoModel::fromArray($fila); // leido SIN contrasena: el modelo queda con contrasena ''
$modelo->establecerNombre('Carlos Editado');
$usuarios->actualizar($modelo);
$despues = $usuarios->obtenerParaAutenticacion('admin.fundador@hwanghonficticio.test');
comprobar('actualizar() con un modelo leido sin credenciales NO borra la contrasena', $despues['contrasena'] === $hashAntes && $despues['nombre'] === 'Carlos Editado');

$credenciales = UsuarioAdministrativoModel::fromArray($despues);
$credenciales->establecerTokenRecuperacion(hash('sha256', 'token-ficticio'));
$credenciales->establecerTokenRecuperacionExpira('2026-10-16 18:00:00');
$usuarios->actualizarCredenciales($credenciales);
$conToken = $usuarios->obtenerParaAutenticacion('admin.fundador@hwanghonficticio.test');
comprobar('actualizarCredenciales() guarda el token y conserva la contrasena', $conToken['token_recuperacion'] === hash('sha256', 'token-ficticio') && $conToken['contrasena'] === $hashAntes);

echo "\n=== RESULTADO: " . $ok . ' OK, ' . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
