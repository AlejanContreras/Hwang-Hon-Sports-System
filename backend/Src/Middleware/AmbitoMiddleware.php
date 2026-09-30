<?php

declare(strict_types=1);

/**
 * Middleware de ámbito: exige que la sesión activa sea del ámbito
 * administrativo. A diferencia de AttendQR (5 roles con permisos
 * granulares), HwangHon solo distingue 2 ámbitos y la vista administrativa
 * siempre tiene acceso completo (RN-T.2) — por eso no existe un equivalente
 * a "solo_publica": lo público siempre es además accesible por lo
 * administrativo.
 *
 * Ubicación: backend/Src/Middleware/AmbitoMiddleware.php
 */
class AmbitoMiddleware
{
    /**
     * Exige que la sesión activa sea del ámbito administrativo.
     * Responde 401 si no hay sesión, o 403 si la sesión es del ámbito público.
     *
     * @return array{ambito: string, identificador: string}
     */
    public static function verificarAdministrativa(): array
    {
        $usuario = AuthMiddleware::verificar();

        if ($usuario['ambito'] !== 'administrativa') {
            self::responderError(403, 'Acceso restringido al ámbito administrativo.');
        }

        return $usuario;
    }

    private static function responderError(int $codigoHttp, string $mensaje): never
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['exito' => false, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
