<?php

declare(strict_types=1);

/**
 * Punto de entrada unico (front controller) de la API de HwangHon-Sports-System.
 *
 * Patron adaptado de AttendQR (ver Public/api.php): una tabla de rutas
 * ($rutas) que asocia cada recurso con su Controller, y una tabla de
 * politicas de acceso ($politicasAcceso) que decide que middleware aplica
 * antes de despachar la solicitud.
 *
 * A diferencia de AttendQR (5 politicas basadas en rol), HwangHon usa el
 * modelo simplificado de 2 ambitos acordado para este proyecto (RN-T.1/
 * RN-T.2/RN-T.3): 'publica' (sin autenticacion), 'autenticada' (cualquier
 * sesion activa, administrativa o publica — el Service filtra que se
 * expone) y 'solo_administrativa' (unicamente sesion del ambito
 * administrativo). No existe 'solo_publica': el ambito administrativo
 * siempre tiene acceso completo (RN-T.2).
 *
 * Las politicas asignadas aqui son un punto de partida para la fase
 * "Enrutamiento y politica de acceso" (Etapa 4, fase 20.2) — se revisan y
 * ajustan en esa fase, no son definitivas todavia.
 *
 * Solicitudes esperadas: /api.php?recurso=<recurso>&accion=<accion>[&...parametros]
 * con el cuerpo (si aplica) en JSON.
 *
 * Ubicacion: backend/Public/api.php
 */

date_default_timezone_set('America/Bogota');

define('RUTA_RAIZ', dirname(__DIR__));
define('RUTA_SRC', RUTA_RAIZ . '/Src');

function respuestaJson(array $payload, int $codigoHttp = 200): never
{
    http_response_code($codigoHttp);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function respuestaError(int $codigoHttp, string $mensaje): never
{
    respuestaJson(['exito' => false, 'mensaje' => $mensaje], $codigoHttp);
}

// Bootstrap: orden importa (BaseRepository depende de Database, los
// Repositories dependen de BaseRepository, los Controllers dependen de los
// Services que dependen de los Repositories)
require_once RUTA_SRC . '/Config/database.php';
require_once RUTA_SRC . '/Middleware/AuthMiddleware.php';
require_once RUTA_SRC . '/Middleware/AmbitoMiddleware.php';
require_once RUTA_SRC . '/Repositories/BaseRepository.php';

foreach (glob(RUTA_SRC . '/Models/*.php') as $archivoModelo) {
    require_once $archivoModelo;
}
foreach (glob(RUTA_SRC . '/Repositories/*.php') as $archivoRepositorio) {
    if (basename($archivoRepositorio) !== 'BaseRepository.php') {
        require_once $archivoRepositorio;
    }
}
foreach (glob(RUTA_SRC . '/Services/*.php') as $archivoServicio) {
    require_once $archivoServicio;
}

// Health check en la raiz
$recurso = $_GET['recurso'] ?? null;
$accion = $_GET['accion'] ?? null;

if ($recurso === null) {
    respuestaJson(['exito' => true, 'mensaje' => 'HwangHon-Sports-System API activa.']);
}

if ($accion === null) {
    respuestaError(400, 'Falta el parametro "accion".');
}

// Tabla de rutas: recurso => [archivo del Controller, nombre de la clase]
$rutas = [
    'auth' => ['AuthController.php', 'AuthController'],
    'usuarios-administrativos' => ['UsuarioAdministrativoController.php', 'UsuarioAdministrativoController'],
    'deportistas' => ['DeportistaController.php', 'DeportistaController'],
    'historial' => ['HistorialController.php', 'HistorialController'],
    'competencias' => ['CompetenciaController.php', 'CompetenciaController'],
    'estadisticas' => ['EstadisticaController.php', 'EstadisticaController'],
    'evaluaciones' => ['EvaluacionController.php', 'EvaluacionController'],
    'documentos' => ['DocumentoController.php', 'DocumentoController'],
    'asistencia' => ['AsistenciaController.php', 'AsistenciaController'],
    'pagos' => ['PagoController.php', 'PagoController'],
    'calendario' => ['CalendarioController.php', 'CalendarioController'],
    'reportes' => ['ReporteController.php', 'ReporteController'],
];

// Tabla de politicas de acceso: recurso => politica
$politicasAcceso = [
    'auth' => 'publica',
    'usuarios-administrativos' => 'solo_administrativa',
    'deportistas' => 'autenticada',
    'historial' => 'autenticada',
    'competencias' => 'autenticada',
    'estadisticas' => 'autenticada',
    'evaluaciones' => 'autenticada',
    'documentos' => 'autenticada',
    'asistencia' => 'autenticada',
    'pagos' => 'autenticada',
    'calendario' => 'autenticada',
    'reportes' => 'solo_administrativa',
];

if (!array_key_exists($recurso, $rutas)) {
    respuestaError(404, "Recurso no reconocido: {$recurso}");
}

[$archivoControlador, $nombreClaseControlador] = $rutas[$recurso];
require_once RUTA_SRC . '/Controllers/' . $archivoControlador;

if (!class_exists($nombreClaseControlador)) {
    respuestaError(500, "Controller no encontrado para el recurso: {$recurso}");
}

// Middleware segun la politica de acceso del recurso
$politica = $politicasAcceso[$recurso] ?? 'solo_administrativa';

switch ($politica) {
    case 'publica':
        // Sin autenticacion requerida.
        break;
    case 'autenticada':
        AuthMiddleware::verificar();
        break;
    case 'solo_administrativa':
        AmbitoMiddleware::verificarAdministrativa();
        break;
    default:
        respuestaError(500, "Politica de acceso no reconocida: {$politica}");
}

// Parametros: el resto de la query string, sin recurso/accion
$parametros = $_GET;
unset($parametros['recurso'], $parametros['accion']);

$metodo = $_SERVER['REQUEST_METHOD'];

$controlador = new $nombreClaseControlador();
$controlador->manejar($metodo, $accion, $parametros);
