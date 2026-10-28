<?php

declare(strict_types=1);

// Router para "php -S" (solo pruebas, fuera del repositorio).
// /__sesion?ambito=...&identificador=... crea una sesion con Sesion::iniciar();
// cualquier otra ruta se despacha a backend/Public/api.php.

$publico = getenv('RUTA_PUBLIC');
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($ruta === '/__sesion') {
    require $publico . '/../Src/Utils/Sesion.php';
    $huella = null;
    if ($_GET['ambito'] === 'administrativa') {
        // Igual que AuthService: huella de la contraseña de la cuenta.
        $bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '');
        $consulta = $bd->prepare('SELECT contrasena FROM usuario_administrativo WHERE correo = ?');
        $consulta->execute([$_GET['identificador']]);
        $hash = $consulta->fetchColumn();
        $huella = is_string($hash) ? Sesion::huellaCredencial($hash) : null;
    }
    Sesion::iniciar($_GET['ambito'], $_GET['identificador'], $huella);
    header('Content-Type: application/json');
    echo json_encode(['sesion' => 'iniciada']);
    return true;
}

if ($ruta === '/__ini') {
    // Arranca una sesion de prueba y devuelve la configuracion que dejo Sesion.
    require $publico . '/../Src/Utils/Sesion.php';
    Sesion::iniciar('publica', 'prueba-ini');
    header('Content-Type: application/json');
    echo json_encode(['save_path' => ini_get('session.save_path'), 'gc_maxlifetime' => ini_get('session.gc_maxlifetime')]);
    Sesion::cerrar();
    return true;
}

// Con MEMORIA_LOG, anota el pico de memoria de cada peticion (solo pruebas;
// usa una ruta fuera del repositorio o el memoria.log ignorado por Git).
$registroMemoria = getenv('MEMORIA_LOG');
if ($registroMemoria) {
    register_shutdown_function(static function () use ($registroMemoria): void {
        file_put_contents($registroMemoria, $_SERVER['REQUEST_URI'] . "\t" . memory_get_peak_usage(true) . "\n", FILE_APPEND);
    });
}

chdir($publico);
require $publico . '/api.php';
