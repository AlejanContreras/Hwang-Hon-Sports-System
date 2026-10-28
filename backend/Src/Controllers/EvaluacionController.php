<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Evaluaciones y ascensos (RN-5.x).
 *
 * Recibe la solicitud ya autenticada (politica 'autenticada' de
 * Public/api.php), despacha segun la accion y delega toda logica de negocio
 * a EvaluacionService, que decide que ve y que puede hacer cada ambito.
 *
 * Acciones: candidatos, listar y consultar (GET); crear (POST); inscribir
 * (POST ?sesion_id=); actualizar, cerrar y reabrir (PUT ?id=); eliminar
 * (DELETE ?id=); actualizarParticipante y quitarParticipante (PUT / DELETE
 * ?sesion_id=&deportista_documento=); calificar (PUT ?sesion_id=
 * &deportista_documento=&modalidad=); confirmarEntregaCinturon (PUT
 * ?sesion_id=&deportista_documento=, tambien para el ambito publico).
 */
class EvaluacionController
{
    private ?EvaluacionService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): EvaluacionService
    {
        return $this->servicio ??= new EvaluacionService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'candidatos' => $this->leer('GET', $metodo, fn () => $this->servicio()->candidatos($parametros)),
            'listar' => $this->leer('GET', $metodo, fn () => $this->servicio()->listar($parametros)),
            'consultar' => $this->manejarConsultar($metodo, $parametros),
            'crear' => $this->manejarCrear($metodo),
            'inscribir' => $this->leer('POST', $metodo, fn () => $this->servicio()->inscribir($parametros, Solicitud::cuerpoJson()), 201),
            'actualizar' => $this->leer('PUT', $metodo, fn () => $this->servicio()->actualizar($parametros, Solicitud::cuerpoJson())),
            'actualizarParticipante' => $this->leer('PUT', $metodo, fn () => $this->servicio()->actualizarParticipante($parametros, Solicitud::cuerpoJson())),
            'calificar' => $this->leer('PUT', $metodo, fn () => $this->servicio()->calificar($parametros, Solicitud::cuerpoJson())),
            'cerrar' => $this->leer('PUT', $metodo, fn () => $this->servicio()->cerrar($parametros)),
            'reabrir' => $this->leer('PUT', $metodo, fn () => $this->servicio()->reabrir($parametros)),
            'confirmarEntregaCinturon' => $this->leer('PUT', $metodo, fn () => $this->servicio()->confirmarEntregaCinturon($parametros, Solicitud::cuerpoJson())),
            'quitarParticipante' => $this->manejarBorrado($metodo, fn () => $this->servicio()->quitarParticipante($parametros)),
            'eliminar' => $this->manejarBorrado($metodo, fn () => $this->servicio()->eliminar($parametros)),
            default => Respuesta::error(404, "Acción no reconocida: {$accion}"),
        };
    }

    /**
     * Exige el metodo HTTP y responde con lo que devuelva el Service.
     */
    private function leer(string $metodoEsperado, string $metodo, callable $operacion, int $codigo = 200): void
    {
        Solicitud::exigirMetodo($metodoEsperado, $metodo);
        Respuesta::exito($operacion(), $codigo);
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

    private function manejarCrear(string $metodo): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($this->servicio()->crear(Solicitud::cuerpoJson()), 201);
    }

    private function manejarBorrado(string $metodo, callable $operacion): void
    {
        Solicitud::exigirMetodo('DELETE', $metodo);
        $operacion();
        Respuesta::exito(['eliminado' => true]);
    }
}
