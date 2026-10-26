<?php

declare(strict_types=1);

/**
 * Configuración de seguridad HTTP. No lleva secretos: se versiona.
 *
 * ORIGENES_PERMITIDOS: orígenes extra que pueden enviar peticiones que
 *   modifican datos (protección CSRF de Solicitud::exigirMismoOrigen), además
 *   del propio host. Separados por coma, como origen completo o host:puerto:
 *   "https://club.example.com, localhost:5173". Se puede fijar con la
 *   variable de entorno del mismo nombre. Vacío por defecto.
 * TRUST_PROXY: true SOLO si el sitio está detrás de un proxy de confianza que
 *   termina HTTPS y envía X-Forwarded-Proto (entonces la cookie de sesión se
 *   marca secure). Con false, esa cabecera se ignora: cualquiera podría
 *   enviarla.
 */
define('ORIGENES_PERMITIDOS', (string) (getenv('ORIGENES_PERMITIDOS') ?: ''));
define('TRUST_PROXY', false);
