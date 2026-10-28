<?php

declare(strict_types=1);

// Recuperación de contraseña diferida (M5) con un Correo FALSO: nunca envía nada.
// Uso: php prueba_recuperacion_diferida.php <ruta backend/Src> [hijo]
// El modo "hijo" emite la respuesta con Respuesta::exitoSinTerminar y después ejecuta el envío (para ver el orden).

$src = rtrim($argv[1], '/');

/** Correo falso: tarda 1,5 s y anota la llamada (sustituye a Utils/Correo.php). */
final class Correo
{
    public static array $enviados = [];
    public static function enviar(string $destino, string $asunto, string $cuerpo): bool
    {
        usleep(1500000);
        self::$enviados[] = [$destino, $asunto, $cuerpo];
        echo "\n<<CORREO-FALSO-ENVIADO>>";
        return true;
    }
    public static function configuracion(): array
    {
        return ['url_restablecimiento' => 'http://ficticio.test/restablecer'];
    }
}
date_default_timezone_set('America/Bogota');
foreach (['Respuesta', 'Solicitud', 'Sesion', 'ExcepcionNegocio', 'Validador'] as $u) { require $src . "/Utils/{$u}.php"; }
require $src . '/Config/database.php';
require $src . '/Repositories/BaseRepository.php';
foreach (glob($src . '/Models/*.php') as $f) { require_once $f; }
foreach (glob($src . '/Repositories/*.php') as $f) { require_once $f; }
require $src . '/Services/AuthService.php';

$bd = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'hwanghon') . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$limpiar = fn () => $bd->exec("UPDATE usuario_administrativo SET token_recuperacion = NULL, token_recuperacion_expira = NULL WHERE correo = 'maestro@hwanghon.local'");

if (($argv[2] ?? '') === 'hijo') {
    $limpiar();
    $envio = (new AuthService())->solicitarRecuperacionContrasena(['correo' => 'maestro@hwanghon.local']);
    Respuesta::exitoSinTerminar(['mensaje' => AuthService::MENSAJE_RECUPERACION]);
    if ($envio !== null) { $envio(); }
    exit;
}

$ok = 0; $fallos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}
$token = fn () => $bd->query("SELECT token_recuperacion, token_recuperacion_expira FROM usuario_administrativo WHERE correo = 'maestro@hwanghon.local'")->fetch(PDO::FETCH_ASSOC);
$servicio = new AuthService();

$limpiar();
$t0 = microtime(true);
$envio = $servicio->solicitarRecuperacionContrasena(['correo' => 'maestro@hwanghon.local']);
$tExiste = microtime(true) - $t0;
$t0 = microtime(true);
$nada = $servicio->solicitarRecuperacionContrasena(['correo' => 'no.existe@hwanghon.local']);
$tNoExiste = microtime(true) - $t0;
comprobar('cuenta existente: devuelve la tarea diferida; inexistente: null', $envio instanceof Closure && $nada === null);
comprobar('antes de responder no se genera el token ni se envía nada', $token()['token_recuperacion'] === null && Correo::$enviados === []);
comprobar(sprintf('el trabajo previo a la respuesta tarda lo mismo exista o no (%.1f ms vs %.1f ms)', $tExiste * 1000, $tNoExiste * 1000), abs($tExiste - $tNoExiste) < 0.05);
$envio();
$fila = $token();
comprobar('al ejecutar la tarea: token generado (SHA-256), vence en 1 hora y se "envía" el correo', strlen((string) $fila['token_recuperacion']) === 64 && abs(strtotime($fila['token_recuperacion_expira']) - (time() + 3600)) < 10 && count(Correo::$enviados) === 1);
comprobar('el correo lleva el enlace con el token', str_contains(Correo::$enviados[0][2], 'http://ficticio.test/restablecer?token='));
$segunda = $servicio->solicitarRecuperacionContrasena(['correo' => 'maestro@hwanghon.local']);
comprobar('(b) con ese enlace vigente, una nueva solicitud no genera otro', $segunda === null && $token() === $fila);

// Orden real de salida: la respuesta completa antes del envío lento.
$t0 = microtime(true);
$salida = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($src) . ' hijo');
$posJson = strpos($salida, '{"exito":true');
$posCorreo = strpos($salida, '<<CORREO-FALSO-ENVIADO>>');
comprobar('Respuesta::exitoSinTerminar entrega el JSON antes de que empiece el envío', $posJson !== false && $posCorreo !== false && $posJson < $posCorreo, $salida);
$limpiar();

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
foreach ($fallos as $f) { echo " - {$f}\n"; }
exit($fallos === [] ? 0 : 1);
