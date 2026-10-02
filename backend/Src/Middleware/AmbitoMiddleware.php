<?php

declare(strict_types=1);

/**
 * Middleware de ámbito: exige que la sesión activa sea del ámbito
 * administrativo. No hay permisos granulares por rol: el sistema solo
 * distingue 2 ámbitos y la vista administrativa siempre tiene acceso
 * completo (RN-T.2) — por eso no existe un equivalente
 * a "solo_publica": lo público siempre es además accesible por lo
 * administrativo.
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
            Respuesta::error(403, 'Acceso restringido al ámbito administrativo.');
        }

        return $usuario;
    }
}
