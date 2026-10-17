<?php

declare(strict_types=1);

/**
 * Plantilla de configuración del correo (Utils/Correo.php).
 *
 * Copiar este archivo como correo.php en esta misma carpeta y completar los
 * datos. correo.php está ignorado por Git: la clave de aplicación de Gmail
 * NUNCA se escribe aquí ni en ningún archivo versionado.
 *
 * modo:
 *   - 'archivo': no envía; guarda cada correo en backend/Almacenamiento/correos/ (pruebas).
 *   - 'smtp': envía con Gmail. Requiere la verificación en dos pasos activa en la
 *     cuenta y una "contraseña de aplicación" (Cuenta de Google > Seguridad).
 *
 * url_restablecimiento: dirección de la pantalla "restablecer contraseña" del
 * front. El sistema le agrega ?token=... (o &token=... si ya trae parámetros).
 */
return [
    'modo' => 'archivo',
    'servidor' => 'smtp.gmail.com',
    'puerto' => 587,
    'usuario' => 'cuenta.del.club@gmail.com',
    'clave_aplicacion' => '',
    'remitente_correo' => 'cuenta.del.club@gmail.com',
    'remitente_nombre' => 'HwangHon Sports System',
    'url_restablecimiento' => 'http://localhost/HwangHon-Sports-System/restablecer-contrasena',
];
