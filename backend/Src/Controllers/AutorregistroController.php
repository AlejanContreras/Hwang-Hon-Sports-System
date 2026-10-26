<?php

declare(strict_types=1);

/**
 * Controller HTTP del autorregistro (RN-T.14). Politica 'publica' en
 * Public/api.php: no exige sesion. Solo expone estas acciones; cualquier
 * otra responde 404. Toda la logica (anti-spam, permiso temporal de
 * documentos) vive en AutorregistroService.
 *
 * Acciones: registrar (POST, JSON); listarDocumentos (GET); subirDocumento
 * (POST multipart: tipo_documento, archivo); reemplazarDocumento (POST ?id=,
 * multipart: archivo); eliminarDocumento (DELETE ?id=); confirmar (POST).
 */
class AutorregistroController
{
    private ?AutorregistroService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): AutorregistroService
    {
        return $this->servicio ??= new AutorregistroService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'registrar' => $this->responder('POST', $metodo, fn () => $this->servicio()->registrar()),
            'listarDocumentos' => $this->responder('GET', $metodo, fn () => $this->servicio()->listarDocumentos()),
            'subirDocumento' => $this->responder('POST', $metodo, fn () => $this->servicio()->subirDocumento($_POST), 201),
            'reemplazarDocumento' => $this->responder('POST', $metodo, fn () => $this->servicio()->reemplazarDocumento($parametros)),
            'eliminarDocumento' => $this->responder('DELETE', $metodo, function () use ($parametros): array {
                $this->servicio()->eliminarDocumento($parametros);
                return ['eliminado' => true];
            }),
            'confirmar' => $this->responder('POST', $metodo, fn () => $this->servicio()->confirmar()),
            default => Respuesta::error(404, "Accion no reconocida: {$accion}"),
        };
    }

    /**
     * Exige el metodo HTTP y responde con lo que devuelva el Service.
     */
    private function responder(string $metodoEsperado, string $metodo, callable $operacion, int $codigo = 200): void
    {
        Solicitud::exigirMetodo($metodoEsperado, $metodo);
        Respuesta::exito($operacion(), $codigo);
    }
}
