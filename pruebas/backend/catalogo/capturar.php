<?php

declare(strict_types=1);

// Captura llamadas REALES contra la semilla para el catálogo de la API. Fuera del repositorio.
// Uso: php capturar.php <url base> <archivo salida json>
// Requiere: esquema v4 + semilla + cuentas_prueba_locales recién cargados. Nunca dispara un correo real
// (la recuperación de contraseña solo se pide con un correo inexistente).

[$_, $base, $salida] = $argv;
date_default_timezone_set('America/Bogota');
$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$capturas = [];

final class Cliente
{
    public array $cookies = [];
    public function __construct(private string $base) {}
    public function cookie(): string { return implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($this->cookies), $this->cookies)); }
    public function llamar(string $metodo, string $ruta, mixed $cuerpo = null, ?array $multipart = null): array
    {
        $h = $this->cookies ? 'Cookie: ' . $this->cookie() . "\r\n" : '';
        $contenido = '';
        if ($multipart !== null) {
            $lim = '----catalogo' . bin2hex(random_bytes(6));
            foreach ($multipart['campos'] ?? [] as $k => $v) { $contenido .= "--{$lim}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n"; }
            foreach ($multipart['archivos'] ?? [] as $k => [$n, $c]) { $contenido .= "--{$lim}\r\nContent-Disposition: form-data; name=\"{$k}\"; filename=\"{$n}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$c}\r\n"; }
            $contenido .= "--{$lim}--\r\n";
            $h .= "Content-Type: multipart/form-data; boundary={$lim}\r\n";
        } elseif (is_string($cuerpo)) {
            $contenido = $cuerpo;
            $h .= "Content-Type: text/plain\r\n";
        } elseif ($cuerpo !== null) {
            $contenido = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
            $h .= "Content-Type: application/json\r\n";
        }
        $ctx = stream_context_create(['http' => ['method' => $metodo, 'header' => $h, 'content' => $contenido, 'ignore_errors' => true, 'timeout' => 60]]);
        $texto = (string) file_get_contents($this->base . '/api.php?' . $ruta, false, $ctx);
        preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $m);
        $cab = [];
        foreach (array_slice($http_response_header, 1) as $l) {
            [$k, $v] = array_map('trim', explode(':', $l, 2)) + [1 => ''];
            $cab[strtolower($k)] = $v;
            if (strtolower($k) === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', $v, $mc)) {
                if ($mc[2] === '' || $mc[2] === 'deleted') { unset($this->cookies[$mc[1]]); } else { $this->cookies[$mc[1]] = $mc[2]; }
            }
        }
        return [(int) $m[1], $texto, $cab];
    }
}

function capturar(string $clave, Cliente $cliente, string $metodo, string $ruta, mixed $cuerpo = null, ?array $multipart = null, string $quien = ''): ?array
{
    global $capturas;
    [$codigo, $texto, $cab] = $cliente->llamar($metodo, $ruta, $cuerpo, $multipart);
    $json = json_decode($texto, true);
    $esArchivo = !str_starts_with($cab['content-type'] ?? '', 'application/json');
    $capturas[$clave] = [
        'quien' => $quien, 'metodo' => $metodo, 'ruta' => 'api.php?' . $ruta,
        'cuerpo' => $multipart !== null ? ['multipart' => array_merge($multipart['campos'] ?? [], array_map(fn ($a) => "(archivo {$a[0]}, " . strlen($a[1]) . ' bytes)', $multipart['archivos'] ?? []))] : $cuerpo,
        'codigo' => $codigo,
        'respuesta' => $esArchivo ? null : $json,
        'cabeceras' => $esArchivo ? array_intersect_key($cab, array_flip(['content-type', 'content-disposition', 'content-length', 'x-content-type-options'])) : null,
    ];
    printf("%-55s %d\n", $clave, $codigo);
    return $json;
}

$PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$hoy = new DateTimeImmutable('today');
$sabado = $hoy->modify('-1 day');
while ($sabado->format('N') !== '6') { $sabado = $sabado->modify('-1 day'); }
$sab1 = $sabado->format('Y-m-d');
$sab2 = $sabado->modify('-7 days')->format('Y-m-d');
$anio = (int) $hoy->format('Y');

$anon = new Cliente($base);
$admin = new Cliente($base);
$pub = new Cliente($base);
$staff = new Cliente($base);

// ---------------------------------------------------------------- AUTH
capturar('auth.iniciarSesionAdministrativa', $admin, 'POST', 'recurso=auth&accion=iniciarSesionAdministrativa', ['correo' => 'maestro@hwanghon.local', 'contrasena' => '12345678'], null, 'sin sesión');
capturar('auth.iniciarSesionAdministrativa.error401', $anon, 'POST', 'recurso=auth&accion=iniciarSesionAdministrativa', ['correo' => 'maestro@hwanghon.local', 'contrasena' => 'equivocada'], null, 'sin sesión');
capturar('auth.iniciarSesionPublica', $pub, 'POST', 'recurso=auth&accion=iniciarSesionPublica', ['documento' => '1000000101', 'fecha_nacimiento' => '2014-03-12'], null, 'sin sesión');
capturar('auth.iniciarSesionPublica.error403', $anon, 'POST', 'recurso=auth&accion=iniciarSesionPublica', ['documento' => '1000000102', 'fecha_nacimiento' => '2005-07-20'], null, 'sin sesión');
capturar('auth.sesionActual.admin', $admin, 'GET', 'recurso=auth&accion=sesionActual', null, null, 'administrativo');
capturar('auth.sesionActual.publico', $pub, 'GET', 'recurso=auth&accion=sesionActual', null, null, 'público');
capturar('auth.sesionActual.error401', $anon, 'GET', 'recurso=auth&accion=sesionActual', null, null, 'sin sesión');
capturar('login.staff', $staff, 'POST', 'recurso=auth&accion=iniciarSesionAdministrativa', ['correo' => 'administrativo1@hwanghon.local', 'contrasena' => '12345678'], null, 'sin sesión');
capturar('auth.cambiarContrasena.error400', $staff, 'POST', 'recurso=auth&accion=cambiarContrasena', ['contrasena_actual' => 'otra', 'contrasena_nueva' => 'NuevaClave2026'], null, 'administrativo');
capturar('auth.cambiarContrasena', $staff, 'POST', 'recurso=auth&accion=cambiarContrasena', ['contrasena_actual' => '12345678', 'contrasena_nueva' => 'NuevaClave2026'], null, 'administrativo');
capturar('auth.cambiarContrasena.error403', $pub, 'POST', 'recurso=auth&accion=cambiarContrasena', ['contrasena_actual' => 'x', 'contrasena_nueva' => 'NuevaClave2026'], null, 'público');
capturar('auth.solicitarRecuperacionContrasena', $anon, 'POST', 'recurso=auth&accion=solicitarRecuperacionContrasena', ['correo' => 'no.registrado@hwanghon.local'], null, 'sin sesión');
$token = str_repeat('7', 64);
$bd->prepare("UPDATE usuario_administrativo SET token_recuperacion = ?, token_recuperacion_expira = ? WHERE correo = 'administrativo1@hwanghon.local'")
    ->execute([hash('sha256', $token), $hoy->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
capturar('auth.restablecerContrasena', $anon, 'POST', 'recurso=auth&accion=restablecerContrasena', ['token' => $token, 'contrasena_nueva' => '12345678'], null, 'sin sesión');
capturar('auth.restablecerContrasena.error400', $anon, 'POST', 'recurso=auth&accion=restablecerContrasena', ['token' => $token, 'contrasena_nueva' => '12345678'], null, 'sin sesión');
capturar('login.staff2', $staff, 'POST', 'recurso=auth&accion=iniciarSesionAdministrativa', ['correo' => 'administrativo1@hwanghon.local', 'contrasena' => '12345678'], null, 'sin sesión');

// ---------------------------------------------------------------- USUARIOS
capturar('usuarios-administrativos.listar', $admin, 'GET', 'recurso=usuarios-administrativos&accion=listar', null, null, 'cuenta principal');
capturar('usuarios-administrativos.listar.error403', $staff, 'GET', 'recurso=usuarios-administrativos&accion=listar', null, null, 'administrativo no principal');
capturar('usuarios-administrativos.consultar', $admin, 'GET', 'recurso=usuarios-administrativos&accion=consultar&correo=administrativo1@hwanghon.local', null, null, 'cuenta principal');
capturar('usuarios-administrativos.crear', $admin, 'POST', 'recurso=usuarios-administrativos&accion=crear', ['correo' => 'secretaria.prueba@hwanghon.local', 'nombre' => 'Secretaria de Prueba', 'rol' => 'secretaria', 'contrasena' => 'ClaveInicial1'], null, 'cuenta principal');
capturar('usuarios-administrativos.crear.error409', $admin, 'POST', 'recurso=usuarios-administrativos&accion=crear', ['correo' => 'secretaria.prueba@hwanghon.local', 'nombre' => 'Otra', 'contrasena' => 'ClaveInicial1'], null, 'cuenta principal');
capturar('usuarios-administrativos.actualizar', $admin, 'PUT', 'recurso=usuarios-administrativos&accion=actualizar&correo=secretaria.prueba@hwanghon.local', ['nombre' => 'Secretaria Prueba Editada', 'rol' => 'tesoreria'], null, 'cuenta principal');
capturar('usuarios-administrativos.desactivar', $admin, 'PUT', 'recurso=usuarios-administrativos&accion=desactivar&correo=secretaria.prueba@hwanghon.local', null, null, 'cuenta principal');
capturar('usuarios-administrativos.desactivar.error409', $admin, 'PUT', 'recurso=usuarios-administrativos&accion=desactivar&correo=maestro@hwanghon.local', null, null, 'cuenta principal');
capturar('usuarios-administrativos.reactivar', $admin, 'PUT', 'recurso=usuarios-administrativos&accion=reactivar&correo=secretaria.prueba@hwanghon.local', null, null, 'cuenta principal');
capturar('usuarios-administrativos.restablecerContrasena', $admin, 'PUT', 'recurso=usuarios-administrativos&accion=restablecerContrasena&correo=secretaria.prueba@hwanghon.local', ['contrasena_nueva' => 'ClaveNueva2026'], null, 'cuenta principal');
capturar('usuarios-administrativos.restablecerContrasena.error409', $admin, 'PUT', 'recurso=usuarios-administrativos&accion=restablecerContrasena&correo=maestro@hwanghon.local', ['contrasena_nueva' => 'ClaveNueva2026'], null, 'cuenta principal');

// ---------------------------------------------------------------- DEPORTISTAS
capturar('deportistas.listar.admin', $admin, 'GET', 'recurso=deportistas&accion=listar', null, null, 'administrativo');
capturar('deportistas.listar.pendientes', $admin, 'GET', 'recurso=deportistas&accion=listar&estado_registro=pendiente_validacion', null, null, 'administrativo');
capturar('deportistas.listar.publico', $pub, 'GET', 'recurso=deportistas&accion=listar', null, null, 'público');
capturar('deportistas.listar.error401', $anon, 'GET', 'recurso=deportistas&accion=listar', null, null, 'sin sesión');
capturar('deportistas.consultar.admin', $admin, 'GET', 'recurso=deportistas&accion=consultar&documento=1000000101', null, null, 'administrativo');
capturar('deportistas.consultar.publico', $pub, 'GET', 'recurso=deportistas&accion=consultar&documento=1000000101', null, null, 'público');
capturar('deportistas.consultar.error404', $pub, 'GET', 'recurso=deportistas&accion=consultar&documento=1000000103', null, null, 'público');
capturar('deportistas.buscar', $admin, 'GET', 'recurso=deportistas&accion=buscar&texto=rey', null, null, 'administrativo');
capturar('deportistas.buscar.error400', $admin, 'GET', 'recurso=deportistas&accion=buscar&texto=r', null, null, 'administrativo');
$menor = ['documento' => '1000000150', 'nombre' => 'Valentina Gómez Ficticia', 'fecha_nacimiento' => '2013-06-15', 'lugar_nacimiento' => 'Soacha', 'sexo' => 'femenino',
    'estatura' => 1.48, 'peso' => 39.5, 'telefono' => '3000000150', 'direccion' => 'Calle Ficticia 15', 'correo' => 'familia.gomez@correoficticio.test',
    'discapacidad' => null, 'experiencia_previa' => null, 'sede_entrenamiento' => 'Club Hwang Hon', 'autorizacion_datos' => true,
    'acudiente' => ['documento' => '1000000950', 'nombre' => 'Carolina Gómez Ficticia', 'relacion' => 'madre', 'telefono' => '3000000950', 'correo' => 'familia.gomez@correoficticio.test']];
capturar('deportistas.crear', $admin, 'POST', 'recurso=deportistas&accion=crear', $menor, null, 'administrativo');
capturar('deportistas.crear.error409', $admin, 'POST', 'recurso=deportistas&accion=crear', $menor, null, 'administrativo');
$sinAcudiente = $menor; unset($sinAcudiente['acudiente']); $sinAcudiente['documento'] = '1000000151';
capturar('deportistas.crear.error400', $admin, 'POST', 'recurso=deportistas&accion=crear', $sinAcudiente, null, 'administrativo');
capturar('deportistas.actualizar.admin', $admin, 'PUT', 'recurso=deportistas&accion=actualizar&documento=1000000150', ['peso' => 41.2, 'grado_actual' => 'Blanco'], null, 'administrativo');
capturar('deportistas.actualizar.validar', $admin, 'PUT', 'recurso=deportistas&accion=actualizar&documento=1000000102', ['estado_registro' => 'validado'], null, 'administrativo');
capturar('deportistas.actualizar.publico', $pub, 'PUT', 'recurso=deportistas&accion=actualizar&documento=1000000101', ['peso' => 46.0, 'telefono' => '3000000002'], null, 'público');
capturar('deportistas.actualizar.error403', $pub, 'PUT', 'recurso=deportistas&accion=actualizar&documento=1000000101', ['nombre' => 'Otro'], null, 'público');

// ---------------------------------------------------------------- AUTORREGISTRO
$reg = new Cliente($base);
capturar('autorregistro.registrar', $reg, 'POST', 'recurso=autorregistro&accion=registrar', ['documento' => '1000000160', 'nombre' => 'Andrés Registro Ficticio', 'fecha_nacimiento' => '1999-02-10',
    'sexo' => 'masculino', 'estatura' => 1.75, 'peso' => 72, 'telefono' => '3000000160', 'correo' => 'andres.registro@correoficticio.test', 'autorizacion_datos' => true, 'sitio_web' => ''], null, 'sin sesión');
capturar('autorregistro.registrar.error400', $anon, 'POST', 'recurso=autorregistro&accion=registrar', ['documento' => '1000000161', 'nombre' => 'Sin Autorización', 'fecha_nacimiento' => '1999-02-10', 'sexo' => 'masculino', 'estatura' => 1.7, 'peso' => 70, 'autorizacion_datos' => false], null, 'sin sesión');
$sub = capturar('autorregistro.subirDocumento', $reg, 'POST', 'recurso=autorregistro&accion=subirDocumento', null, ['campos' => ['tipo_documento' => 'documento_identidad'], 'archivos' => ['archivo' => ['cedula.pdf', $PDF]]], 'permiso de autorregistro');
$idReg = $sub['datos']['id'] ?? 0;
capturar('autorregistro.reemplazarDocumento', $reg, 'POST', "recurso=autorregistro&accion=reemplazarDocumento&id={$idReg}", null, ['archivos' => ['archivo' => ['cedula_legible.pdf', $PDF]]], 'permiso de autorregistro');
$sub2 = capturar('autorregistro.subirDocumento.foto', $reg, 'POST', 'recurso=autorregistro&accion=subirDocumento', null, ['campos' => ['tipo_documento' => 'fotografia'], 'archivos' => ['archivo' => ['foto.pdf', $PDF]]], 'permiso de autorregistro');
capturar('autorregistro.eliminarDocumento', $reg, 'DELETE', 'recurso=autorregistro&accion=eliminarDocumento&id=' . ($sub2['datos']['id'] ?? 0), null, null, 'permiso de autorregistro');
capturar('autorregistro.listarDocumentos', $reg, 'GET', 'recurso=autorregistro&accion=listarDocumentos', null, null, 'permiso de autorregistro');
capturar('autorregistro.confirmar', $reg, 'POST', 'recurso=autorregistro&accion=confirmar', null, null, 'permiso de autorregistro');
capturar('autorregistro.subirDocumento.error403', $reg, 'POST', 'recurso=autorregistro&accion=subirDocumento', null, ['campos' => ['tipo_documento' => 'fotografia'], 'archivos' => ['archivo' => ['foto.pdf', $PDF]]], 'permiso ya cerrado');
capturar('deportistas.eliminar.conDocumentos', $admin, 'DELETE', 'recurso=deportistas&accion=eliminar&documento=1000000160', null, null, 'administrativo');
capturar('autorregistro.registrar.otro', new Cliente($base), 'POST', 'recurso=autorregistro&accion=registrar', ['documento' => '1000000162', 'nombre' => 'Registro Sin Documentos', 'fecha_nacimiento' => '2000-08-08', 'sexo' => 'femenino', 'estatura' => 1.6, 'peso' => 54, 'autorizacion_datos' => true], null, 'sin sesión');
capturar('deportistas.eliminar', $admin, 'DELETE', 'recurso=deportistas&accion=eliminar&documento=1000000162', null, null, 'administrativo');
capturar('deportistas.eliminar.error409', $admin, 'DELETE', 'recurso=deportistas&accion=eliminar&documento=1000000103', null, null, 'administrativo');
capturar('autorregistro.registrar.conAsistencia', new Cliente($base), 'POST', 'recurso=autorregistro&accion=registrar', ['documento' => '1000000163', 'nombre' => 'Registro Con Asistencia', 'fecha_nacimiento' => '2001-04-04', 'sexo' => 'masculino', 'estatura' => 1.7, 'peso' => 66, 'autorizacion_datos' => true], null, 'sin sesión');
$bd->exec("INSERT INTO asistencia (deportista_documento, fecha, asistio) VALUES ('1000000163', '" . $hoy->format('Y-m-d') . "', 1)");
capturar('deportistas.eliminar.error409modulos', $admin, 'DELETE', 'recurso=deportistas&accion=eliminar&documento=1000000163', null, null, 'administrativo');

// ---------------------------------------------------------------- DOCUMENTOS
$doc = capturar('documentos.subir', $admin, 'POST', 'recurso=documentos&accion=subir', null, ['campos' => ['documento' => '1000000150', 'tipo_documento' => 'formulario_inscripcion'], 'archivos' => ['archivo' => ['formulario_valentina.pdf', $PDF]]], 'administrativo');
$idDoc = $doc['datos']['id'] ?? 0;
capturar('documentos.subir.error409', $admin, 'POST', 'recurso=documentos&accion=subir', null, ['campos' => ['documento' => '1000000150', 'tipo_documento' => 'formulario_inscripcion'], 'archivos' => ['archivo' => ['otro.pdf', $PDF]]], 'administrativo');
capturar('documentos.listar.admin', $admin, 'GET', 'recurso=documentos&accion=listar&documento=1000000150', null, null, 'administrativo');
capturar('documentos.listar.publico', $pub, 'GET', 'recurso=documentos&accion=listar', null, null, 'público');
capturar('documentos.consultar', $admin, 'GET', "recurso=documentos&accion=consultar&id={$idDoc}", null, null, 'administrativo');
capturar('documentos.reemplazar', $admin, 'POST', "recurso=documentos&accion=reemplazar&id={$idDoc}", null, ['archivos' => ['archivo' => ['formulario_valentina_firmado.pdf', $PDF]]], 'administrativo');
capturar('documentos.descargar', $admin, 'GET', "recurso=documentos&accion=descargar&id={$idDoc}", null, null, 'administrativo');
capturar('documentos.descargar.error404', $admin, 'GET', 'recurso=documentos&accion=descargar&id=1', null, null, 'administrativo');
capturar('documentos.faltantes.uno', $admin, 'GET', 'recurso=documentos&accion=faltantes&documento=1000000150', null, null, 'administrativo');
capturar('documentos.faltantes.pendientes', $admin, 'GET', 'recurso=documentos&accion=faltantes', null, null, 'administrativo');
capturar('documentos.eliminar', $admin, 'DELETE', "recurso=documentos&accion=eliminar&id={$idDoc}", null, null, 'administrativo');
capturar('documentos.eliminar.error403', $pub, 'DELETE', 'recurso=documentos&accion=eliminar&id=1', null, null, 'público');
$gen = capturar('documentos.subirGeneral', $admin, 'POST', 'recurso=documentos&accion=subirGeneral', null, ['campos' => ['titulo' => 'Teoría para cinturón amarillo'], 'archivos' => ['archivo' => ['teoria_amarillo.pdf', $PDF]]], 'administrativo');
$idGen = $gen['datos']['id'] ?? 0;
capturar('documentos.listarGenerales', $pub, 'GET', 'recurso=documentos&accion=listarGenerales', null, null, 'público');
capturar('documentos.descargarGeneral', $pub, 'GET', "recurso=documentos&accion=descargarGeneral&id={$idGen}", null, null, 'público');
capturar('documentos.reemplazarGeneral', $admin, 'POST', "recurso=documentos&accion=reemplazarGeneral&id={$idGen}", null, ['campos' => ['titulo' => 'Teoría para cinturón amarillo (2026)'], 'archivos' => ['archivo' => ['teoria_amarillo_v2.pdf', $PDF]]], 'administrativo');
capturar('documentos.eliminarGeneral', $admin, 'DELETE', "recurso=documentos&accion=eliminarGeneral&id={$idGen}", null, null, 'administrativo');

// ---------------------------------------------------------------- ASISTENCIA
capturar('asistencia.listarPantalla', $admin, 'GET', "recurso=asistencia&accion=listarPantalla&fecha={$sab1}", null, null, 'administrativo');
capturar('asistencia.tomar', $admin, 'POST', 'recurso=asistencia&accion=tomar', ['fecha' => $sab1, 'presentes' => ['1000000101', '1000000103']], null, 'administrativo');
capturar('asistencia.tomar.error409', $admin, 'POST', 'recurso=asistencia&accion=tomar', ['fecha' => $sab1, 'presentes' => []], null, 'administrativo');
capturar('asistencia.tomar.error400', $admin, 'POST', 'recurso=asistencia&accion=tomar', ['fecha' => $hoy->modify('next monday')->modify('-7 days')->format('Y-m-d'), 'presentes' => []], null, 'administrativo');
capturar('asistencia.listar.fecha', $admin, 'GET', "recurso=asistencia&accion=listar&fecha={$sab1}", null, null, 'administrativo');
capturar('asistencia.listar.publico', $pub, 'GET', 'recurso=asistencia&accion=listar', null, null, 'público');
capturar('asistencia.consultar', $admin, 'GET', "recurso=asistencia&accion=consultar&documento=1000000101&fecha={$sab1}", null, null, 'administrativo');
capturar('asistencia.resumenDia', $admin, 'GET', 'recurso=asistencia&accion=resumenDia', null, null, 'administrativo');
capturar('asistencia.corregir', $admin, 'PUT', "recurso=asistencia&accion=corregir&documento=1000000102&fecha={$sab1}", ['asistio' => true], null, 'administrativo');
capturar('asistencia.porcentaje.admin', $admin, 'GET', 'recurso=asistencia&accion=porcentaje&documento=1000000101&ventana=todo', null, null, 'administrativo');
capturar('asistencia.porcentaje.publico', $pub, 'GET', 'recurso=asistencia&accion=porcentaje', null, null, 'público');
capturar('asistencia.eliminarMarca', $admin, 'DELETE', "recurso=asistencia&accion=eliminarMarca&documento=1000000102&fecha={$sab1}", null, null, 'administrativo');
capturar('asistencia.tomar.otra', $admin, 'POST', 'recurso=asistencia&accion=tomar', ['fecha' => $sab2, 'presentes' => []], null, 'administrativo');
capturar('asistencia.eliminarFecha', $admin, 'DELETE', "recurso=asistencia&accion=eliminarFecha&fecha={$sab2}", null, null, 'administrativo');
capturar('asistencia.eliminarFecha.error404', $admin, 'DELETE', "recurso=asistencia&accion=eliminarFecha&fecha={$sab2}", null, null, 'administrativo');

// ---------------------------------------------------------------- PAGOS
$pag = capturar('pagos.registrar.mensualidad', $admin, 'POST', 'recurso=pagos&accion=registrar', ['documento' => '1000000150', 'tipo' => 'mensualidad', 'anio' => $anio, 'pagos' => [['mes' => 10, 'semana' => 2], ['mes' => 11, 'semana' => 2]]], null, 'administrativo');
capturar('pagos.registrar.matricula', $admin, 'POST', 'recurso=pagos&accion=registrar', ['documento' => '1000000150', 'tipo' => 'matricula', 'anio' => $anio], null, 'administrativo');
capturar('pagos.registrar.error409', $admin, 'POST', 'recurso=pagos&accion=registrar', ['documento' => '1000000150', 'tipo' => 'mensualidad', 'anio' => $anio, 'pagos' => [['mes' => 10, 'semana' => 1]]], null, 'administrativo');
capturar('pagos.estado.admin', $admin, 'GET', "recurso=pagos&accion=estado&documento=1000000101&anio={$anio}", null, null, 'administrativo');
capturar('pagos.estado.publico', $pub, 'GET', 'recurso=pagos&accion=estado', null, null, 'público');
capturar('pagos.deudores', $admin, 'GET', 'recurso=pagos&accion=deudores', null, null, 'administrativo');
capturar('pagos.listar', $admin, 'GET', "recurso=pagos&accion=listar&documento=1000000150&anio={$anio}", null, null, 'administrativo');
$idPago = $pag['datos'][1]['id'] ?? 0;
capturar('pagos.consultar', $admin, 'GET', "recurso=pagos&accion=consultar&id={$idPago}", null, null, 'administrativo');
capturar('pagos.anular', $admin, 'DELETE', "recurso=pagos&accion=anular&id={$idPago}", null, null, 'administrativo');
capturar('pagos.anular.error404', $admin, 'DELETE', "recurso=pagos&accion=anular&id={$idPago}", null, null, 'administrativo');

// ---------------------------------------------------------------- CALENDARIO
capturar('calendario.listar', $pub, 'GET', "recurso=calendario&accion=listar&desde={$anio}-04-01&hasta={$anio}-06-30", null, null, 'público');
capturar('calendario.consultar', $admin, 'GET', 'recurso=calendario&accion=consultar&id=3', null, null, 'administrativo');
capturar('calendario.consultar.error404', $pub, 'GET', 'recurso=calendario&accion=consultar&id=3', null, null, 'público');
$ev = capturar('calendario.crear', $admin, 'POST', 'recurso=calendario&accion=crear', ['nombre' => 'Clausura de fin de año', 'tipo' => 'evento_extra', 'fecha' => "{$anio}-12-12", 'lugar' => 'Sede principal', 'visibilidad' => 'publica', 'observaciones' => 'Traer uniforme'], null, 'administrativo');
capturar('calendario.crear.error400', $admin, 'POST', 'recurso=calendario&accion=crear', ['nombre' => 'Reunión de junta', 'tipo' => 'reunion_administrativa', 'fecha' => "{$anio}-11-20", 'lugar' => 'Oficina'], null, 'administrativo');
capturar('calendario.actualizar', $admin, 'PUT', 'recurso=calendario&accion=actualizar&id=' . ($ev['datos']['id'] ?? 0), ['fecha' => "{$anio}-12-13", 'observaciones' => 'Traer uniforme completo'], null, 'administrativo');
capturar('calendario.actualizar.error409', $admin, 'PUT', 'recurso=calendario&accion=actualizar&id=1', ['fecha' => "{$anio}-05-17"], null, 'administrativo');
capturar('calendario.vista.admin', $admin, 'GET', "recurso=calendario&accion=vista&desde={$anio}-03-01&hasta={$anio}-03-31", null, null, 'administrativo');
capturar('calendario.vista.publico', $pub, 'GET', "recurso=calendario&accion=vista&desde={$anio}-03-01&hasta={$anio}-03-31", null, null, 'público');
capturar('calendario.importar', $admin, 'POST', 'recurso=calendario&accion=importar', null, ['archivos' => ['archivo' => ['eventos.csv', "nombre;tipo;fecha;lugar;visibilidad;observaciones\nTorneo interclubes;competencia;14/11/{$anio};Coliseo municipal;publica;\nClausura de fin de año;evento_extra;{$anio}-12-13;Sede principal;publica;\nTaller de árbitros;evento_extra;31/02/{$anio};Sede;publica;\n"]]], 'administrativo');

// ---------------------------------------------------------------- COMPETENCIAS
capturar('competencias.listar.admin', $admin, 'GET', 'recurso=competencias&accion=listar', null, null, 'administrativo');
capturar('competencias.listar.publico', $pub, 'GET', 'recurso=competencias&accion=listar', null, null, 'público');
capturar('competencias.consultar.admin', $admin, 'GET', 'recurso=competencias&accion=consultar&id=1', null, null, 'administrativo');
capturar('competencias.consultar.publico', $pub, 'GET', 'recurso=competencias&accion=consultar&id=1', null, null, 'público');
$tor = capturar('competencias.crear', $admin, 'POST', 'recurso=competencias&accion=crear', ['tipo_torneo' => 'departamental', 'plataforma' => 'Fitofan',
    'evento' => ['nombre' => 'Copa Departamental Ficticia', 'fecha' => $hoy->modify('+40 days')->format('Y-m-d'), 'lugar' => 'Coliseo de Soacha', 'visibilidad' => 'publica', 'observaciones' => null]], null, 'administrativo');
$idTor = $tor['datos']['evento_id'] ?? 0;
capturar('competencias.crear.error422', $admin, 'POST', 'recurso=competencias&accion=crear', ['tipo_torneo' => 'nacional', 'evento_id' => 4], null, 'administrativo');
capturar('competencias.proximo', $admin, 'GET', 'recurso=competencias&accion=proximo', null, null, 'administrativo');
capturar('competencias.proximo.error403', $pub, 'GET', 'recurso=competencias&accion=proximo', null, null, 'público');
capturar('competencias.actualizar', $admin, 'PUT', "recurso=competencias&accion=actualizar&id={$idTor}", ['tipo_torneo' => 'nacional'], null, 'administrativo');
capturar('competencias.actualizar.evento', $admin, 'PUT', "recurso=competencias&accion=actualizar&id={$idTor}", ['fecha' => $hoy->modify('+45 days')->format('Y-m-d'), 'lugar' => 'Coliseo Municipal de Soacha', 'visibilidad' => 'administrativa'], null, 'administrativo');
capturar('competencias.actualizar.error400', $admin, 'PUT', "recurso=competencias&accion=actualizar&id={$idTor}", ['nombre' => 'Otro nombre'], null, 'administrativo');
capturar('competencias.sugerencia', $admin, 'GET', "recurso=competencias&accion=sugerencia&deportista_documento=1000000150&competencia_evento_id={$idTor}&modalidad=combate_individual", null, null, 'administrativo');
capturar('competencias.inscribir', $admin, 'POST', 'recurso=competencias&accion=inscribir', ['competencia_evento_id' => $idTor, 'participantes' => [['deportista_documento' => '1000000150', 'modalidad' => 'combate_individual'], ['deportista_documento' => '1000000101', 'modalidad' => 'formas']]], null, 'administrativo');
capturar('competencias.inscribir.error409', $admin, 'POST', 'recurso=competencias&accion=inscribir', ['competencia_evento_id' => $idTor, 'participantes' => [['deportista_documento' => '1000000150', 'modalidad' => 'combate_individual']]], null, 'administrativo');
capturar('competencias.actualizarParticipacion', $admin, 'PUT', 'recurso=competencias&accion=actualizarParticipacion&deportista_documento=1000000101&competencia_evento_id=1&modalidad=formas', ['resultado' => 'Primer lugar en formas', 'notas_oponente' => 'Sin oponente directo'], null, 'administrativo');
capturar('competencias.actualizar.error422', $admin, 'PUT', 'recurso=competencias&accion=actualizar&id=1', ['fecha' => $hoy->modify('+10 days')->format('Y-m-d')], null, 'administrativo');
capturar('competencias.actualizarParticipacion.error422', $admin, 'PUT', "recurso=competencias&accion=actualizarParticipacion&deportista_documento=1000000150&competencia_evento_id={$idTor}&modalidad=combate_individual", ['puesto' => 1], null, 'administrativo');
capturar('competencias.quitarParticipante', $admin, 'DELETE', "recurso=competencias&accion=quitarParticipante&deportista_documento=1000000101&competencia_evento_id={$idTor}&modalidad=formas", null, null, 'administrativo');

// ---------------------------------------------------------------- EVALUACIONES
$fIni = $hoy->modify('-3 days')->format('Y-m-d');
capturar('evaluaciones.candidatos', $admin, 'GET', "recurso=evaluaciones&accion=candidatos&fecha={$fIni}", null, null, 'administrativo');
$ses = capturar('evaluaciones.crear', $admin, 'POST', 'recurso=evaluaciones&accion=crear', ['fecha' => $fIni, 'fecha_grado' => $hoy->format('Y-m-d'), 'lugar' => 'Sede principal', 'participantes' => [['deportista_documento' => '1000000150']]], null, 'administrativo');
$idSes = $ses['datos']['id'] ?? 0;
capturar('evaluaciones.inscribir', $admin, 'POST', "recurso=evaluaciones&accion=inscribir&sesion_id={$idSes}", ['participantes' => [['deportista_documento' => '1000000103', 'grado_evaluado' => 'Verde']]], null, 'administrativo');
capturar('evaluaciones.actualizarParticipante', $admin, 'PUT', "recurso=evaluaciones&accion=actualizarParticipante&sesion_id={$idSes}&deportista_documento=1000000103", ['grado_evaluado' => 'Azul'], null, 'administrativo');
capturar('evaluaciones.quitarParticipante', $admin, 'DELETE', "recurso=evaluaciones&accion=quitarParticipante&sesion_id={$idSes}&deportista_documento=1000000103", null, null, 'administrativo');
capturar('evaluaciones.actualizar', $admin, 'PUT', "recurso=evaluaciones&accion=actualizar&id={$idSes}", ['lugar' => 'Coliseo municipal'], null, 'administrativo');
capturar('evaluaciones.cerrar.error409', $admin, 'PUT', "recurso=evaluaciones&accion=cerrar&id={$idSes}", null, null, 'administrativo');
foreach (['teorico' => 8.5, 'fisico' => 7, 'combate' => 6.5, 'rompimiento' => 9, 'formas' => 8, 'llaves' => 7.5] as $m => $n) {
    $cuerpo = ['nota' => $n] + ($m === 'fisico' ? ['valor_medible' => 42, 'observaciones' => 'Buena resistencia', 'recomendaciones' => 'Trabajar flexibilidad'] : []);
    capturar("evaluaciones.calificar.{$m}", $admin, 'PUT', "recurso=evaluaciones&accion=calificar&sesion_id={$idSes}&deportista_documento=1000000150&modalidad={$m}", $cuerpo, null, 'administrativo');
}
capturar('evaluaciones.calificar.error400', $admin, 'PUT', "recurso=evaluaciones&accion=calificar&sesion_id={$idSes}&deportista_documento=1000000150&modalidad=teorico", ['nota' => 11], null, 'administrativo');
capturar('evaluaciones.listar.admin', $admin, 'GET', 'recurso=evaluaciones&accion=listar', null, null, 'administrativo');
capturar('evaluaciones.listar.publico', $pub, 'GET', 'recurso=evaluaciones&accion=listar', null, null, 'público');
capturar('evaluaciones.cerrar', $admin, 'PUT', "recurso=evaluaciones&accion=cerrar&id={$idSes}", null, null, 'administrativo');
capturar('evaluaciones.consultar', $admin, 'GET', "recurso=evaluaciones&accion=consultar&id={$idSes}", null, null, 'administrativo');
capturar('evaluaciones.confirmarEntregaCinturon', $admin, 'PUT', "recurso=evaluaciones&accion=confirmarEntregaCinturon&sesion_id={$idSes}&deportista_documento=1000000150", [], null, 'administrativo');
capturar('evaluaciones.confirmarEntregaCinturon.error409', $admin, 'PUT', "recurso=evaluaciones&accion=confirmarEntregaCinturon&sesion_id={$idSes}&deportista_documento=1000000150", [], null, 'administrativo');
capturar('evaluaciones.reabrir', $admin, 'PUT', "recurso=evaluaciones&accion=reabrir&id={$idSes}", null, null, 'administrativo');
capturar('evaluaciones.cerrar.otra', $admin, 'PUT', "recurso=evaluaciones&accion=cerrar&id={$idSes}", null, null, 'administrativo');
$vacia = capturar('evaluaciones.crear.vacia', $admin, 'POST', 'recurso=evaluaciones&accion=crear', ['fecha' => $hoy->modify('+20 days')->format('Y-m-d'), 'fecha_grado' => $hoy->modify('+27 days')->format('Y-m-d'), 'lugar' => 'Sede principal'], null, 'administrativo');
capturar('evaluaciones.eliminar', $admin, 'DELETE', 'recurso=evaluaciones&accion=eliminar&id=' . ($vacia['datos']['id'] ?? 0), null, null, 'administrativo');
capturar('evaluaciones.eliminar.error409', $admin, 'DELETE', "recurso=evaluaciones&accion=eliminar&id={$idSes}", null, null, 'administrativo');

// ---------------------------------------------------------------- HISTORIAL Y ESTADÍSTICAS
capturar('historial.torneos.admin', $admin, 'GET', 'recurso=historial&accion=torneos', null, null, 'administrativo');
capturar('historial.torneos.publico', $pub, 'GET', 'recurso=historial&accion=torneos', null, null, 'público');
capturar('historial.ascensos', $admin, 'GET', 'recurso=historial&accion=ascensos&deportista=1000000150', null, null, 'administrativo');
capturar('historial.permanencia', $admin, 'GET', 'recurso=historial&accion=permanencia', null, null, 'administrativo');
capturar('historial.torneos.error400', $admin, 'GET', 'recurso=historial&accion=torneos&desde=2026-05-01&hasta=2026-04-01', null, null, 'administrativo');
capturar('estadisticas.deportista', $admin, 'GET', 'recurso=estadisticas&accion=deportista&deportista=1000000150', null, null, 'administrativo');
capturar('estadisticas.deportista.publico', $pub, 'GET', 'recurso=estadisticas&accion=deportista', null, null, 'público');
capturar('estadisticas.torneo', $admin, 'GET', 'recurso=estadisticas&accion=torneo&id=1', null, null, 'administrativo');
capturar('estadisticas.torneo.error403', $pub, 'GET', 'recurso=estadisticas&accion=torneo&id=1', null, null, 'público');

// ---------------------------------------------------------------- REPORTES
capturar('reportes.deportistaFicha', $admin, 'GET', 'recurso=reportes&accion=deportistaFicha&deportista=1000000150', null, null, 'administrativo');
capturar('reportes.deportistas', $admin, 'GET', 'recurso=reportes&accion=deportistas&estado=activo', null, null, 'administrativo');
capturar('reportes.asistencia', $admin, 'GET', "recurso=reportes&accion=asistencia&desde={$anio}-01-01&hasta=" . $hoy->format('Y-m-d'), null, null, 'administrativo');
capturar('reportes.asistencia.error422', $admin, 'GET', "recurso=reportes&accion=asistencia&desde=" . ($anio - 2) . "-01-01&hasta=" . $hoy->format('Y-m-d'), null, null, 'administrativo');
capturar('reportes.pagos', $admin, 'GET', "recurso=reportes&accion=pagos&anio={$anio}", null, null, 'administrativo');
capturar('reportes.torneos', $admin, 'GET', 'recurso=reportes&accion=torneos', null, null, 'administrativo');
capturar('reportes.sesionExamen', $admin, 'GET', "recurso=reportes&accion=sesionExamen&id={$idSes}", null, null, 'administrativo');
capturar('reportes.deportistas.error403', $pub, 'GET', 'recurso=reportes&accion=deportistas', null, null, 'público');

// ---------------------------------------------------------------- IMPORTACIÓN
$csv = "\xEF\xBB\xBFdocumento;nombre;fecha_nacimiento;sexo;estatura;peso;correo;acudiente_documento;acudiente_nombre;acudiente_relacion\n"
    . "1000000170;Mateo Importado Ficticio;10/05/2012;M;1,45;38;;1000000970;Laura Importada Ficticia;madre\n"
    . "1000000103;Fabián Esteban Rincón Peña;;;;76,5;;;;\n"
    . "1000000171;Sin Peso Ficticio;01/01/1990;F;1,6;;;;;\n";
capturar('importacion-deportistas.previsualizar', $admin, 'POST', 'recurso=importacion-deportistas&accion=previsualizar', null, ['archivos' => ['archivo' => ['carga.csv', $csv]]], 'administrativo');
capturar('importacion-deportistas.aplicar', $admin, 'POST', 'recurso=importacion-deportistas&accion=aplicar', null, ['campos' => ['confirmar_actualizacion' => '1000000103'], 'archivos' => ['archivo' => ['carga.csv', $csv]]], 'administrativo');
capturar('importacion-deportistas.previsualizar.error400', $admin, 'POST', 'recurso=importacion-deportistas&accion=previsualizar', null, ['archivos' => ['archivo' => ['vacio.csv', "nombre;peso\nAna;50\n"]]], 'administrativo');

// ---------------------------------------------------------------- AL FINAL (cambian estados)
capturar('asistencia.revisarInactivos', $admin, 'POST', 'recurso=asistencia&accion=revisarInactivos', null, null, 'administrativo');
capturar('auth.cerrarSesion', $pub, 'POST', 'recurso=auth&accion=cerrarSesion', null, null, 'público');
capturar('comun.error405', $admin, 'POST', 'recurso=deportistas&accion=listar', null, null, 'administrativo');
capturar('comun.error404accion', $admin, 'GET', 'recurso=deportistas&accion=noExiste', null, null, 'administrativo');
capturar('comun.error415', $admin, 'POST', 'recurso=calendario&accion=crear', '{"nombre":"x"}', null, 'administrativo');

file_put_contents($salida, json_encode($capturas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo count($capturas) . " capturas\n";
