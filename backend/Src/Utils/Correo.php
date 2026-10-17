<?php

declare(strict_types=1);

/**
 * Envío de correos del sistema (hoy: el enlace de recuperación de
 * contraseña, RN-T.12).
 *
 * La configuración vive en Src/Config/correo.php (ignorado por Git: lleva la
 * clave de aplicación de Gmail). La plantilla versionada es
 * Src/Config/correo.example.php. Dos modos:
 *   - smtp: envía de verdad con PHPMailer (Gmail, puerto 587, STARTTLS, UTF-8).
 *   - archivo: no envía nada; deja el correo completo en
 *     backend/Almacenamiento/correos/, para probar sin servidor de correo.
 *
 * Un fallo nunca interrumpe la petición: se registra en el log del servidor
 * y enviar() devuelve false.
 */
class Correo
{
    private const ARCHIVO_CONFIGURACION = __DIR__ . '/../Config/correo.php';
    private const CARPETA_CORREOS = __DIR__ . '/../../Almacenamiento/correos';
    private const CARPETA_PHPMAILER = __DIR__ . '/../Lib/PHPMailer';

    private static ?array $configuracion = null;

    /**
     * Envía un correo de texto plano. Devuelve true si se envió (o se guardó,
     * en modo archivo) y false si falló; el detalle queda en el log.
     */
    public static function enviar(string $destino, string $asunto, string $cuerpo): bool
    {
        try {
            $configuracion = self::configuracion();
            return match ($configuracion['modo'] ?? null) {
                'smtp' => self::enviarPorSmtp($configuracion, $destino, $asunto, $cuerpo),
                'archivo' => self::guardarEnArchivo($configuracion, $destino, $asunto, $cuerpo),
                default => throw new RuntimeException('Modo de correo no reconocido en Config/correo.php.'),
            };
        } catch (Throwable $error) {
            error_log('Correo: no se pudo enviar el correo: ' . $error->getMessage());
            return false;
        }
    }

    /**
     * Lee la configuración una sola vez por petición. Lanza RuntimeException
     * si falta el archivo local.
     *
     * @return array<string, mixed>
     */
    public static function configuracion(): array
    {
        if (self::$configuracion === null) {
            if (!is_file(self::ARCHIVO_CONFIGURACION)) {
                throw new RuntimeException('Falta Src/Config/correo.php (copiar de correo.example.php).');
            }
            $configuracion = require self::ARCHIVO_CONFIGURACION;
            if (!is_array($configuracion)) {
                throw new RuntimeException('Src/Config/correo.php debe devolver un arreglo.');
            }
            self::$configuracion = $configuracion;
        }

        return self::$configuracion;
    }

    /**
     * @param array<string, mixed> $configuracion
     */
    private static function enviarPorSmtp(array $configuracion, string $destino, string $asunto, string $cuerpo): bool
    {
        // La biblioteca solo se carga cuando de verdad se va a enviar.
        require_once self::CARPETA_PHPMAILER . '/Exception.php';
        require_once self::CARPETA_PHPMAILER . '/PHPMailer.php';
        require_once self::CARPETA_PHPMAILER . '/SMTP.php';

        $correo = new PHPMailer\PHPMailer\PHPMailer(true);
        $correo->isSMTP();
        $correo->Host = (string) $configuracion['servidor'];
        $correo->Port = (int) $configuracion['puerto'];
        $correo->SMTPAuth = true;
        $correo->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $correo->Username = (string) $configuracion['usuario'];
        $correo->Password = (string) $configuracion['clave_aplicacion'];
        $correo->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $correo->setFrom((string) $configuracion['remitente_correo'], (string) $configuracion['remitente_nombre']);
        $correo->addAddress($destino);
        $correo->Subject = $asunto;
        $correo->Body = $cuerpo;
        $correo->isHTML(false);

        return $correo->send();
    }

    /**
     * Modo de prueba: escribe el correo completo (cabeceras y cuerpo) en un
     * archivo .eml con nombre aleatorio.
     *
     * @param array<string, mixed> $configuracion
     */
    private static function guardarEnArchivo(array $configuracion, string $destino, string $asunto, string $cuerpo): bool
    {
        if (!is_dir(self::CARPETA_CORREOS) && !@mkdir(self::CARPETA_CORREOS, 0700, true) && !is_dir(self::CARPETA_CORREOS)) {
            throw new RuntimeException('No se pudo crear la carpeta Almacenamiento/correos.');
        }

        $remitente = ($configuracion['remitente_nombre'] ?? '') . ' <' . ($configuracion['remitente_correo'] ?? '') . '>';
        $contenido = "From: {$remitente}\r\n"
            . "To: {$destino}\r\n"
            . 'Subject: ' . $asunto . "\r\n"
            . 'Date: ' . date(DATE_RFC2822) . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n"
            . $cuerpo . "\r\n";

        $archivo = self::CARPETA_CORREOS . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.eml';
        if (file_put_contents($archivo, $contenido) === false) {
            throw new RuntimeException('No se pudo escribir el correo en Almacenamiento/correos.');
        }
        return true;
    }
}
