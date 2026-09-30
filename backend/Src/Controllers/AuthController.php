<?php

declare(strict_types=1);

/**
 * Controller HTTP del modulo Auth.
 * Modulo transversal de autenticacion (RN-T.10/RN-T.11). No tiene Model/Repository propio: usa UsuarioAdministrativoRepository y DeportistaRepository para validar credenciales de cada ambito.
 *
 * Recibe la solicitud ya autenticada (segun la politica de acceso de
 * Public/api.php), despacha segun la accion solicitada y delega toda
 * logica de negocio a AuthService.
 *
 * Patron adaptado de AttendQR (ver Src/Controllers/*Controller.php), con
 * nomenclatura en espanol (regla 22 del proyecto).
 *
 * Ubicacion: backend/Src/Controllers/AuthController.php
 */
class AuthController
{
    private AuthService $servicio;

    public function __construct()
    {
        $this->servicio = new AuthService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'iniciarSesionAdministrativa' => $this->manejarIniciarSesionAdministrativa($metodo, $parametros),
            'iniciarSesionPublica' => $this->manejarIniciarSesionPublica($metodo, $parametros),
            'cerrarSesion' => $this->manejarCerrarSesion($metodo, $parametros),
            'sesionActual' => $this->manejarSesionActual($metodo, $parametros),
            default => $this->responderError(404, "Accion no reconocida: {$accion}"),
        };
    }

    private function manejarIniciarSesionAdministrativa(string $metodo, array $parametros): void
    {
        $metodoEsperado = 'POST';
        $this->despacharConMetodo($metodoEsperado, $metodo, function () {
            $credenciales = $this->leerCuerpoJson();
            $resultado = $this->servicio->iniciarSesionAdministrativa($credenciales);
            $this->responderExito($resultado);
        });
    }

    private function manejarIniciarSesionPublica(string $metodo, array $parametros): void
    {
        $metodoEsperado = 'POST';
        $this->despacharConMetodo($metodoEsperado, $metodo, function () {
            $credenciales = $this->leerCuerpoJson();
            $resultado = $this->servicio->iniciarSesionPublica($credenciales);
            $this->responderExito($resultado);
        });
    }

    private function manejarCerrarSesion(string $metodo, array $parametros): void
    {
        $metodoEsperado = 'POST';
        $this->despacharConMetodo($metodoEsperado, $metodo, function () {
            $this->servicio->cerrarSesion();
            $this->responderExito(['sesionCerrada' => true]);
        });
    }

    private function manejarSesionActual(string $metodo, array $parametros): void
    {
        $metodoEsperado = 'GET';
        $this->despacharConMetodo($metodoEsperado, $metodo, function () {
            $resultado = $this->servicio->sesionActual();
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
