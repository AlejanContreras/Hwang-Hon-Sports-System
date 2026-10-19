<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Documentos (RN-6.x).
 *
 * Recibe la solicitud ya autenticada (politica 'autenticada' de
 * Public/api.php), despacha segun la accion y delega toda logica de negocio
 * a DocumentoService, que decide que puede hacer cada ambito.
 *
 * Documentos del deportista: listar, consultar, descargar y faltantes (GET);
 * subir y reemplazar (POST multipart: PHP solo llena $_FILES con POST);
 * eliminar (DELETE). Documentos generales: listarGenerales y
 * descargarGeneral (GET); subirGeneral y reemplazarGeneral (POST multipart);
 * eliminarGeneral (DELETE). Los campos del formulario multipart llegan en
 * $_POST y el archivo en $_FILES (lo lee ArchivoSubido).
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
            'subir' => $this->manejarSubir($metodo, $parametros),
            'reemplazar' => $this->manejarReemplazar($metodo, $parametros),
            'eliminar' => $this->manejarEliminar($metodo, $parametros),
            'descargar' => $this->manejarDescargar($metodo, $parametros),
            'faltantes' => $this->manejarFaltantes($metodo, $parametros),
            'listarGenerales' => $this->manejarListarGenerales($metodo, $parametros),
            'descargarGeneral' => $this->manejarDescargarGeneral($metodo, $parametros),
            'subirGeneral' => $this->manejarSubirGeneral($metodo, $parametros),
            'reemplazarGeneral' => $this->manejarReemplazarGeneral($metodo, $parametros),
            'eliminarGeneral' => $this->manejarEliminarGeneral($metodo, $parametros),
            default => Respuesta::error(404, "Accion no reconocida: {$accion}"),
        };
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

    private function manejarSubir(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($this->servicio()->subir($_POST), 201);
    }

    private function manejarReemplazar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($this->servicio()->reemplazar($parametros));
    }

    private function manejarEliminar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('DELETE', $metodo);
        $this->servicio()->eliminar($parametros);
        Respuesta::exito(['eliminado' => true]);
    }

    private function manejarDescargar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        $archivo = $this->servicio()->descargar($parametros);
        Respuesta::archivo($archivo['contenido'], $archivo['nombre'], $archivo['tipo_mime']);
    }

    private function manejarFaltantes(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->faltantes($parametros));
    }

    private function manejarListarGenerales(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->listarGenerales());
    }

    private function manejarDescargarGeneral(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        $archivo = $this->servicio()->descargarGeneral($parametros);
        Respuesta::archivo($archivo['contenido'], $archivo['nombre'], $archivo['tipo_mime']);
    }

    private function manejarSubirGeneral(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($this->servicio()->subirGeneral($_POST), 201);
    }

    private function manejarReemplazarGeneral(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($this->servicio()->reemplazarGeneral($parametros, $_POST));
    }

    private function manejarEliminarGeneral(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('DELETE', $metodo);
        $this->servicio()->eliminarGeneral($parametros);
        Respuesta::exito(['eliminado' => true]);
    }
}
