<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Competencias (RN-3.x).
 *
 * Recibe la solicitud ya autenticada (politica 'autenticada' de
 * Public/api.php), despacha segun la accion y delega toda logica de negocio
 * a CompetenciaService, que decide que ve y que puede hacer cada ambito.
 *
 * Acciones: listar, consultar (?id=), proximo y sugerencia (GET); crear e
 * inscribir (POST); actualizar (PUT ?id=); actualizarParticipacion (PUT) y
 * quitarParticipante (DELETE), ambas con ?deportista_documento=
 * &competencia_evento_id=&modalidad=. No existe "eliminar torneo" (RN-3.9).
 */
class CompetenciaController
{
    private ?CompetenciaService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): CompetenciaService
    {
        return $this->servicio ??= new CompetenciaService();
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
            'proximo' => $this->manejarProximo($metodo, $parametros),
            'crear' => $this->manejarCrear($metodo, $parametros),
            'actualizar' => $this->manejarActualizar($metodo, $parametros),
            'inscribir' => $this->manejarInscribir($metodo, $parametros),
            'sugerencia' => $this->manejarSugerencia($metodo, $parametros),
            'actualizarParticipacion' => $this->manejarActualizarParticipacion($metodo, $parametros),
            'quitarParticipante' => $this->manejarQuitarParticipante($metodo, $parametros),
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

    private function manejarProximo(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->proximo());
    }

    private function manejarCrear(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($this->servicio()->crear(Solicitud::cuerpoJson()), 201);
    }

    private function manejarActualizar(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('PUT', $metodo);
        Respuesta::exito($this->servicio()->actualizar($parametros, Solicitud::cuerpoJson()));
    }

    private function manejarInscribir(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($this->servicio()->inscribir(Solicitud::cuerpoJson()), 201);
    }

    private function manejarSugerencia(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('GET', $metodo);
        Respuesta::exito($this->servicio()->sugerencia($parametros));
    }

    private function manejarActualizarParticipacion(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('PUT', $metodo);
        Respuesta::exito($this->servicio()->actualizarParticipacion($parametros, Solicitud::cuerpoJson()));
    }

    private function manejarQuitarParticipante(string $metodo, array $parametros): void
    {
        Solicitud::exigirMetodo('DELETE', $metodo);
        $this->servicio()->quitarParticipante($parametros);
        Respuesta::exito(['eliminado' => true]);
    }
}
