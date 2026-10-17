<?php

declare(strict_types=1);

/**
 * Crea las dos cuentas administrativas iniciales (RN-T.11, sección 35.16):
 *   - la cuenta fundadora (Aldrin), y
 *   - la cuenta «Desarrollador».
 * Ambas con es_administrador_principal = 1, activa = 1 y creado_por_correo = NULL.
 *
 * Solo corre por línea de comandos. Pide correo, nombre y contraseña por
 * consola (la contraseña dos veces y sin mostrarla cuando la consola lo
 * permite); nunca por argumento. No imprime contraseñas ni las guarda en
 * archivos. Si una cuenta ya existe, no la toca.
 *
 * Uso:
 *   php crear_cuentas_iniciales.php         crea las cuentas en la base de datos
 *   php crear_cuentas_iniciales.php --sql   solo imprime los INSERT (con el hash,
 *                                           nunca la clave) para pegarlos en
 *                                           phpMyAdmin, en un hosting sin consola
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('America/Bogota');

const MINIMO_CONTRASENA = 8;
const MAXIMO_BYTES_CONTRASENA = 72;

$soloSql = in_array('--sql', $argv, true);

// Se decide antes de leer nada: preguntar por la consola con datos ya leídos
// del búfer de STDIN los descarta.
define('ENTRADA_ES_CONSOLA', function_exists('stream_isatty') && stream_isatty(STDIN));

$cuentas = [
    ['titulo' => 'Cuenta fundadora (Aldrin)', 'nombre' => null, 'rol' => 'administrador'],
    ['titulo' => 'Cuenta «Desarrollador»', 'nombre' => 'Desarrollador', 'rol' => 'desarrollador'],
];

$repositorio = null;
if (!$soloSql) {
    $src = dirname(__DIR__) . '/Src';
    require $src . '/Config/database.php';
    require $src . '/Repositories/BaseRepository.php';
    require $src . '/Models/UsuarioAdministrativoModel.php';
    require $src . '/Repositories/UsuarioAdministrativoRepository.php';
    $repositorio = new UsuarioAdministrativoRepository();
}

$sentencias = [];
foreach ($cuentas as $cuenta) {
    escribir("\n== {$cuenta['titulo']} ==");

    $correo = pedirCorreo();
    if ($repositorio !== null && $repositorio->obtenerPorId($correo) !== null) {
        escribir("Ya existe una cuenta con ese correo: no se modifica.");
        continue;
    }

    $nombre = pedirNombre($cuenta['nombre']);
    $hash = password_hash(pedirContrasena(), PASSWORD_DEFAULT);

    if ($soloSql) {
        $sentencias[] = sprintf(
            "INSERT INTO usuario_administrativo (correo, contrasena, nombre, rol, es_administrador_principal, activa, creado_por_correo, token_recuperacion, token_recuperacion_expira) VALUES ('%s', '%s', '%s', '%s', 1, 1, NULL, NULL, NULL);",
            textoSql($correo),
            textoSql($hash),
            textoSql($nombre),
            textoSql($cuenta['rol'])
        );
        continue;
    }

    $repositorio->crear(new UsuarioAdministrativoModel(
        correo: $correo,
        contrasena: $hash,
        nombre: $nombre,
        rol: $cuenta['rol'],
        esAdministradorPrincipal: true,
        activa: true,
        creadoPorCorreo: null,
    ));
    escribir("Cuenta creada.");
}

if ($soloSql && $sentencias !== []) {
    escribir("\n-- Pegar en phpMyAdmin (pestaña SQL) sobre la base del sistema.");
    escribir("-- Si una cuenta ya existe, su INSERT falla y no la modifica.");
    foreach ($sentencias as $sentencia) {
        escribir($sentencia);
    }
}

// ----------------------------------------------------------------------

function escribir(string $texto): void
{
    fwrite(STDOUT, $texto . PHP_EOL);
}

function leerLinea(string $pregunta): string
{
    fwrite(STDOUT, $pregunta);
    $linea = fgets(STDIN);
    if ($linea === false) {
        terminarPorFinDeEntrada();
    }
    return trim($linea);
}

/**
 * La entrada se acabó (EOF: tubería vacía o Ctrl+Z / Ctrl+D) mientras se
 * pedía un dato: se termina sin crear nada más, en vez de seguir preguntando.
 */
function terminarPorFinDeEntrada(): never
{
    fwrite(STDERR, "\nEntrada terminada: no se creó nada más.\n");
    exit(1);
}

function pedirCorreo(): string
{
    while (true) {
        $correo = mb_strtolower(leerLinea('Correo: '));
        if ($correo !== '' && mb_strlen($correo) <= 150 && filter_var($correo, FILTER_VALIDATE_EMAIL) !== false) {
            return $correo;
        }
        escribir('Correo no válido (máximo 150 caracteres).');
    }
}

function pedirNombre(?string $porDefecto): string
{
    while (true) {
        $pregunta = $porDefecto === null ? 'Nombre: ' : "Nombre [{$porDefecto}]: ";
        $nombre = leerLinea($pregunta);
        if ($nombre === '' && $porDefecto !== null) {
            return $porDefecto;
        }
        if ($nombre !== '' && mb_strlen($nombre) <= 150) {
            return $nombre;
        }
        escribir('El nombre es obligatorio (máximo 150 caracteres).');
    }
}

function pedirContrasena(): string
{
    while (true) {
        $contrasena = leerOculto('Contraseña (mínimo 8 caracteres): ');
        if (mb_strlen($contrasena) < MINIMO_CONTRASENA) {
            escribir('La contraseña debe tener al menos 8 caracteres.');
            continue;
        }
        if (strlen($contrasena) > MAXIMO_BYTES_CONTRASENA) {
            escribir('La contraseña no puede superar 72 caracteres.');
            continue;
        }
        if (leerOculto('Repite la contraseña: ') !== $contrasena) {
            escribir('Las contraseñas no coinciden.');
            continue;
        }
        return $contrasena;
    }
}

/**
 * Lee una línea sin mostrarla. La clave nunca viaja como argumento de un
 * proceso: en Windows la lee PowerShell (Read-Host -AsSecureString) y la
 * devuelve por su salida estándar (una tubería); en Linux/Mac se apaga el eco
 * con stty. Si la entrada no es una consola (ej. una tubería) o nada de eso
 * funciona, se lee visible avisando. Si la entrada se acaba (EOF), termina.
 *
 * PowerShell va SIN -NonInteractive: con esa bandera Read-Host falla. El
 * prompt lo escribe PHP una sola vez; Read-Host se llama sin -Prompt.
 */
function leerOculto(string $pregunta): string
{
    if (ENTRADA_ES_CONSOLA) {
        if (PHP_OS_FAMILY === 'Windows') {
            fwrite(STDOUT, $pregunta);
            $comando = 'powershell -NoProfile -Command "'
                . '$s = Read-Host -AsSecureString; '
                . '$b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); '
                . '[Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)); '
                . '[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b)"';
            $proceso = proc_open($comando, [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $tuberias);
            if (is_resource($proceso)) {
                $contrasena = stream_get_contents($tuberias[1]);
                fclose($tuberias[1]);
                if (proc_close($proceso) === 0 && is_string($contrasena)) {
                    return $contrasena;
                }
            }
        } elseif (exec('stty -echo 2>/dev/null', $salida, $codigo) !== false && $codigo === 0) {
            fwrite(STDOUT, $pregunta);
            $linea = fgets(STDIN);
            exec('stty echo 2>/dev/null');
            fwrite(STDOUT, PHP_EOL);
            if ($linea === false) {
                terminarPorFinDeEntrada();
            }
            return rtrim($linea, "\r\n");
        }
        // PowerShell no pudo leer la clave: se sigue en una línea nueva, avisando.
        fwrite(STDOUT, PHP_EOL);
    }

    escribir('(Aviso: la contraseña se verá en pantalla.)');
    fwrite(STDOUT, $pregunta);
    $linea = fgets(STDIN);
    if ($linea === false) {
        terminarPorFinDeEntrada();
    }
    return rtrim($linea, "\r\n");
}

/**
 * Escapa un texto para un literal SQL entre comillas simples.
 */
function textoSql(string $texto): string
{
    return str_replace(["\\", "'"], ["\\\\", "''"], $texto);
}
