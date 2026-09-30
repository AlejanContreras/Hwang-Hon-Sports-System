<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Evaluacion.
 * Examenes de ascenso de grado (RN-5.x). La regla exacta de aprobacion general (RN-5.3) sigue PENDIENTE DE RESPUESTA (RN-10.3/RN-5.7) y no se implementa en esta etapa.
 *
 * Recibe la solicitud ya autenticada (segun la politica de acceso de
 * Public/api.php), despacha segun la accion solicitada y delega toda
 * logica de negocio a EvaluacionService.
 *
 * Patron adaptado de AttendQR (ver Src/Controllers/*Controller.php), con
 * nomenclatura en espanol (regla 22 del proyecto).
 *
 * Ubicacion: backend/Src/Controllers/EvaluacionController.php
 */
class EvaluacionController
{
    private EvaluacionService $servicio;

    public function __construct()
    {
        $this->servicio = new EvaluacionService();
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
            'confirmarEntregaCinturon' => $this->manejarConfirmarEntregaCinturon($metodo, $parametros),
            default => $this->responderError(404, "Accion no reconocida: {$accion}"),
        };
    }

    private function manejarListar(string $metodo, array $parametros): void
    {
        $metodoEsperado = 'GET';
        $this->despacharConMetodo($metodoEsperado, $metodo, function () use ($parametros) {
            $filtros = $parametros;
            $resultado = $this->servicio->listar($filtros);
            $this->responderExito($resultado);
        });
    }

    private function manejarConsultar(string $metodo, array $parametros): void
    {
        $metodoEsperado = 'GET';
        $this->despacharConMetodo($metodoEsperado, $metodo, function () use ($parametros) {
            $resultado = $this->servicio->consultar($parametros);
            if ($resultado === null) {
                $this->responderError(404, 'No encontrado.');
            }
            $this->responderExito($resultado);
        });
    }

    private function manejarCrear(string $metodo, array $parametros): void
    {
        $metodoEsperado = 'POST';
        $this->despacharConMetodo($metodoEsperado, $metodo, function () {
            $datos = $this->leerCuerpoJson();
            $resultado = $this->servicio->crear($datos);
            $this->responderExito($resultado, 201);
        });
    }

    private function manejarActualizar(string $metodo, array $parametros): void
    {
        $metodoEsperado = 'PUT';
        $this->despacharConMetodo($metodoEsperado, $metodo, function () use ($parametros) {
            $datos = $this->leerCuerpoJson();
            $resultado = $this->servicio->actualizar($parametros, $datos);
            $this->responderExito($resultado);
        });
    }

    private function manejarEliminar(string $metodo, array $parametros): void
    {
        $metodoEsperado = 'DELETE';
        $this->despacharConMetodo($metodoEsperado, $metodo, function () use ($parametros) {
            $this->servicio->eliminar($parametros);
            $this->responderExito(['eliminado' => true]);
        });
    }

    private function manejarConfirmarEntregaCinturon(string $metodo, array $parametros): void
    {
        $metodoEsperado = 'PUT';
        $this->despacharConMetodo($metodoEsperado, $metodo, function () use ($parametros) {
            $datos = $this->leerCuerpoJson();
            $resultado = $this->servicio->confirmarEntregaCinturon($parametros, $datos);
            $this->responderExito($resultado);
        });
    }

    /**
     * Verifica que el metodo HTTP recibido sea el esperado para la accion;
     * si coincide, ejecuta $funcion; si no, responde 405.
     */
    private function despacharConMetodo(string $metodoEsperado, string $metodoActual, callable $funcion): void
    {
        if ($metodoActual !== $metodoEsperado) {
            $this->responderError(405, "Metodo no permitido. Se esperaba {$metodoEsperado}.");
            return;
        }
        $funcion();
    }

    /**
     * Lee y decodifica el cuerpo JSON de la solicitud.
     *
     * @return array<string, mixed>
     */
    private function leerCuerpoJson(): array
    {
        $crudo = file_get_contents('php://input');
        if ($crudo === false || $crudo === '') {
            return [];
        }
        $datos = json_decode($crudo, true);
        if (!is_array($datos)) {
            $this->responderError(400, 'Cuerpo de la solicitud invalido: se esperaba JSON.');
        }
        return $datos;
    }

    /**
     * Responde con exito en formato JSON estandar y termina la ejecucion.
     */
    private function responderExito(mixed $datos, int $codigoHttp = 200): never
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['exito' => true, 'datos' => $datos], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Responde con error en formato JSON estandar y termina la ejecucion.
     */
    private function responderError(int $codigoHttp, string $mensaje): never
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['exito' => false, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
