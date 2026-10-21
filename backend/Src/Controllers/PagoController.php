<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Pagos (RN-8.x).
 *
 * Recibe la solicitud ya autenticada (politica 'autenticada' de
 * Public/api.php), despacha segun la accion y delega toda logica de negocio
 * a PagoService, que decide que puede hacer cada ambito.
 *
 * Acciones: registrar (POST), anular (DELETE ?id=), estado, deudores,
 * listar y consultar (GET). No existe "actualizar": un pago no se edita, se
 * anula y se vuelve a registrar.
 */
class PagoController
{
    private ?PagoService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): PagoService
    {
        return $this->servicio ??= new PagoService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'registrar' => $this->manejarRegistrar($metodo, $parametros),
            'anular' => $this->manejarAnular($metodo, $parametros),
            'estado' => $this->manejarEstado($metodo, $parametros),
            'deudores' => $this->manejarDeudores($metodo, $parametros),
            'listar' => $this->manejarListar($metodo, $parametros),
            'consultar' => $this->manejarConsultar($metodo, $parametros),
            default => Respuesta::error(404, "Accion no reconocida: {$accion}"),
        };
    }

    private function manejarRegistrar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($this->servicio()->registrar(Solicitud::cuerpoJson()), 201);
    }

    private function manejarAnular(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('DELETE', $metodo);
        $this->servicio()->anular($parametros);
        Respuesta::exito(['eliminado' => true]);
    }

    private function manejarEstado(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->estado($parametros));
    }

    private function manejarDeudores(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->deudores());
    }

    private function manejarListar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->listar($parametros));
    }

    private function manejarConsultar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        $resultado = $this->servicio()->consultar($parametros);
        if ($resultado === null) {
            Respuesta::error(404, 'No encontrado.');
        }
        Respuesta::exito($resultado);
    }
}
