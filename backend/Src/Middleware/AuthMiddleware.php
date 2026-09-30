<?php

declare(strict_types=1);

/**
 * Middleware de autenticación: verifica que exista una sesión activa
 * (administrativa o pública) antes de continuar hacia el Controller.
 *
 * La sesión se identifica por $_SESSION['ambito'] ('administrativa' | 'publica')
 * más el identificador correspondiente ($_SESSION['correo'] o $_SESSION['documento']),
 * establecidos por AuthController al iniciar sesión (ver Etapa 4, fase 3).
 *
 * Patrón adaptado de AttendQR (ver Src/Middleware/AuthMiddleware.php), con
 * nomenclatura en español (regla 22) y adaptado al modelo de 2 ámbitos de
 * HwangHon (RN-T.1/RN-T.2/RN-T.3) en lugar de los 5 roles de AttendQR.
 *
 * Ubicación: backend/Src/Middleware/AuthMiddleware.php
 */
class AuthMiddleware
{
    /**
     * Exige una sesión activa (de cualquier ámbito). Responde 401 y detiene
     * la ejecución si no hay sesión.
     *
     * @return array{ambito: string, identificador: string}
     */
    public static function verificar(): array
    {
        $usuario = self::obtenerUsuario();

        if ($usuario === null) {
            self::responderError(401, 'No autenticado. Inicie sesión para continuar.');
        }

        return $usuario;
    }

    /**
     * Obtiene los datos de la sesión activa sin bloquear la ejecución.
     * Devuelve null si no hay sesión.
     *
     * @return array{ambito: string, identificador: string}|null
     */
    public static function obtenerUsuario(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $ambito = $_SESSION['ambito'] ?? null;
        $identificador = $_SESSION['identificador'] ?? null;

        if ($ambito === null || $identificador === null) {
            return null;
        }

        return [
            'ambito' => $ambito,
            'identificador' => $identificador,
        ];
    }

    private static function responderError(int $codigoHttp, string $mensaje): never
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['exito' => false, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
