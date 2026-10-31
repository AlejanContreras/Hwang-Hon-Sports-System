<?php

declare(strict_types=1);

// Router para "php -S" del entorno AISLADO de pruebas (Postman, Selenium y baterías).
// Solo pruebas: lo arranca pruebas/herramientas/entorno.py con la raíz en pruebas/entorno/app,
// una COPIA de backend/ y frontend/ con su propio Almacenamiento y la base hwanghon_pruebas.
//
// Imita lo que hace Apache en XAMPP para que front y API compartan origen:
//   /frontend/...            archivos estáticos del front (index.html en carpetas)
//   /backend/Public/...      api.php (forma oficial y forma por ruta, como mod_rewrite)
//   resto de carpetas        403 (como los .htaccess "Require all denied")
// No modifica nada de la aplicación.

$raiz = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT']));
$ruta = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($ruta === '/' || $ruta === '') {
    header('Location: /frontend/index.html', true, 302);
    return true;
}

// ---------------------------------------------------------------- API
if (preg_match('#^/backend/Public(/|$)#i', $ruta) === 1) {
    $publico = $raiz . '/backend/Public';
    $archivo = realpath($raiz . $ruta);
    // Como Apache: un archivo real distinto de api.php (ej. .htaccess) no se sirve.
    if ($archivo !== false && is_file($archivo) && basename($archivo) !== 'api.php') {
        http_response_code(403);
        return true;
    }
    $_SERVER['SCRIPT_NAME'] = '/backend/Public/api.php';
    $_SERVER['SCRIPT_FILENAME'] = $publico . '/api.php';
    $_SERVER['PHP_SELF'] = '/backend/Public/api.php';
    chdir($publico);
    require $publico . '/api.php';
    return true;
}

// ---------------------------------------------------------------- FRONT
if (preg_match('#^/frontend(/|$)#i', $ruta) === 1) {
    $archivo = realpath($raiz . $ruta);
    if ($archivo !== false && is_dir($archivo)) {
        $archivo = realpath($archivo . '/index.html');
    }
    $base = (string) realpath($raiz . '/frontend');
    if ($archivo === false || !is_file($archivo) || strncmp(str_replace('\\', '/', $archivo), str_replace('\\', '/', $base), strlen($base)) !== 0
        || str_starts_with(basename($archivo), '.')) {
        http_response_code(404);
        echo 'No encontrado';
        return true;
    }
    $tipos = [
        'html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2',
        'txt' => 'text/plain; charset=utf-8',
    ];
    $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: no-cache');
    header('Content-Length: ' . filesize($archivo));
    readfile($archivo);
    return true;
}

// ---------------------------------------------------------------- RESTO
// backend/Src, backend/Almacenamiento, base_datos, pruebas...: sin acceso web.
http_response_code(file_exists($raiz . $ruta) ? 403 : 404);
return true;
