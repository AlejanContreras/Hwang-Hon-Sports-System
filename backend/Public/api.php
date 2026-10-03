<?php

declare(strict_types=1);

/**
 * Punto de entrada unico (front controller) de la API de HwangHon-Sports-System.
 *
 * Usa una tabla de rutas ($rutas) que asocia cada recurso con su
 * Controller, y una tabla de politicas de acceso ($politicasAcceso) que
 * decide que middleware aplica antes de despachar la solicitud.
 *
 * El acceso se basa en el modelo simplificado de 2 ambitos (RN-T.1/
 * RN-T.2/RN-T.3): 'publica' (sin autenticacion), 'autenticada' (cualquier
 * sesion activa, administrativa o publica — el Service filtra que se
 * expone) y 'solo_administrativa' (unicamente sesion del ambito
 * administrativo). No existe 'solo_publica': el ambito administrativo
 * siempre tiene acceso completo (RN-T.2).
 *
 * Las politicas asignadas por recurso son un punto de partida razonable,
 * no definitivo -- se revisan si la Etapa 5 revela una necesidad distinta
 * (p. ej. una accion de un recurso "autenticada" que en realidad deba
 * restringirse a "solo_administrativa").
 *
 * Solicitudes esperadas: /api.php?recurso=<recurso>&accion=<accion>[&...parametros]
 * con el cuerpo (si aplica) en JSON.
 */

date_default_timezone_set('America/Bogota');

define('RUTA_RAIZ', dirname(__DIR__));
define('RUTA_SRC', RUTA_RAIZ . '/Src');

/*
 * Carga de archivos (bootstrap). El orden importa, porque cada capa
 * necesita que la anterior ya este cargada:
 *   1. Utilidades compartidas: Respuesta (respuestas JSON y descargas),
 *      Solicitud (metodo HTTP y cuerpo JSON de entrada), ExcepcionNegocio
 *      (errores esperados) y ArchivoSubido (archivos recibidos), que usan
 *      las demas capas.
 *   2. Configuracion de la base de datos (database.php).
 *   3. Los Middleware (se usan mas abajo, segun la politica de acceso).
 *   4. BaseRepository, que usa la conexion a la base de datos.
 *   5. Los Models (estructuras de datos que usan los Repositories).
 *   6. Los Repositories, que extienden BaseRepository.
 *   7. Los Services, que usan los Repositories.
 *   8. El Controller del recurso solicitado, que usa su Service
 *      (se carga mas abajo, solo el que se necesita).
 */
require_once RUTA_SRC . '/Utils/Respuesta.php';
require_once RUTA_SRC . '/Utils/Solicitud.php';
require_once RUTA_SRC . '/Utils/ExcepcionNegocio.php';
require_once RUTA_SRC . '/Utils/ArchivoSubido.php';
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
    Respuesta::exito(['mensaje' => 'HwangHon-Sports-System API activa.']);
}

if ($accion === null) {
    Respuesta::error(400, 'Falta el parametro "accion".');
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
    Respuesta::error(404, "Recurso no reconocido: {$recurso}");
}

[$archivoControlador, $nombreClaseControlador] = $rutas[$recurso];
require_once RUTA_SRC . '/Controllers/' . $archivoControlador;

if (!class_exists($nombreClaseControlador)) {
    Respuesta::error(500, "Controller no encontrado para el recurso: {$recurso}");
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
        Respuesta::error(500, "Politica de acceso no reconocida: {$politica}");
}

/*
 * Parametros de la solicitud. La "query string" es la parte de la URL que
 * va despues del signo "?", con forma clave=valor separada por "&".
 * Ejemplo: api.php?recurso=deportistas&accion=consultar&documento=1001
 * PHP la entrega ya separada en el arreglo $_GET. Se copian todos los
 * valores y se quitan "recurso" y "accion" (ya se usaron arriba para
 * elegir el Controller); lo que queda (ej. documento=1001) se pasa al
 * Controller como $parametros.
 */
$parametros = $_GET;
unset($parametros['recurso'], $parametros['accion']);

$metodo = $_SERVER['REQUEST_METHOD'];

/*
 * Manejo de errores centralizado. Cualquier error que ocurra dentro del
 * Controller (o del Service y Repository que este llama) se atrapa aqui y
 * se responde siempre en el formato JSON estandar:
 *   - ExcepcionNegocio: error esperado (dato invalido, no encontrado...),
 *     se responde con su propio codigo HTTP y mensaje.
 *   - Cualquier otro error (Throwable: fallo de la base de datos, error de
 *     programacion): se guarda el detalle en el log del servidor y al
 *     cliente solo se le responde un 500 generico, sin exponer detalles
 *     tecnicos.
 */
try {
    $controlador = new $nombreClaseControlador();
    $controlador->manejar($metodo, $accion, $parametros);
} catch (ExcepcionNegocio $excepcion) {
    Respuesta::error($excepcion->obtenerCodigoHttp(), $excepcion->getMessage());
} catch (Throwable $excepcion) {
    error_log((string) $excepcion);
    Respuesta::error(500, 'Error interno del servidor.');
}
