<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Documento.
 * Documentos del expediente de un deportista (RN-6.x).
 *
 * Recibe la solicitud ya autenticada (segun la politica de acceso de
 * Public/api.php), despacha segun la accion solicitada y delega toda
 * logica de negocio a DocumentoService.
 */
class DocumentoController
{
    private ?DocumentoService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): DocumentoService
    {
        return $this->servicio ??= new DocumentoService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'listar' => $this->manejarListar($metodo, $parametros),
            'consultar' => $this->manejarConsultar($metodo, $parametros),
            'crear' => $this->manejarCrear($metodo, $parametros),
            'actualizar' => $this->manejarActualizar($metodo, $parametros),
            'eliminar' => $this->manejarEliminar($metodo, $parametros),
            default => Respuesta::error(404, "Accion no reconocida: {$accion}"),
        };
    }

    private function manejarListar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        $resultado = $this->servicio()->listar($parametros);
        Respuesta::exito($resultado);
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

    private function manejarCrear(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        $datos = Solicitud::cuerpoJson();
        $resultado = $this->servicio()->crear($datos);
        Respuesta::exito($resultado, 201);
    }

    private function manejarActualizar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('PUT', $metodo);
        $datos = Solicitud::cuerpoJson();
        $resultado = $this->servicio()->actualizar($parametros, $datos);
        Respuesta::exito($resultado);
    }

    private function manejarEliminar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('DELETE', $metodo);
        $this->servicio()->eliminar($parametros);
        Respuesta::exito(['eliminado' => true]);
    }
}
