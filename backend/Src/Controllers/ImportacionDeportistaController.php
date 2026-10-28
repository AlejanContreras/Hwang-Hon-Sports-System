<?php

declare(strict_types=1);

/**
 * Controller HTTP de la carga masiva de deportistas por CSV (RN-1.7, RN-1.9,
 * RN-1.10). Politica 'solo_administrativa' de Public/api.php.
 *
 * Acciones (POST multipart, campo "archivo" con el CSV):
 *   previsualizar: clasifica cada fila sin escribir nada.
 *   aplicar: mismo CSV + confirmar_actualizacion (documentos cuya
 *   actualizacion aprobo el administrativo); devuelve el resultado por fila.
 */
class ImportacionDeportistaController
{
    private ?ImportacionDeportistaService $servicio = null;

    /**
     * Devuelve el Service, creandolo solo la primera vez que se necesita
     * (instanciacion perezosa). Asi una accion no reconocida o un metodo
     * HTTP incorrecto se responden (404/405) sin abrir la conexion a la
     * base de datos.
     */
    private function servicio(): ImportacionDeportistaService
    {
        return $this->servicio ??= new ImportacionDeportistaService();
    }

    /**
     * Punto de entrada llamado desde Public/api.php.
     *
     * @param array<string, mixed> $parametros
     */
    public function manejar(string $metodo, string $accion, array $parametros): void
    {
        match ($accion) {
            'previsualizar' => $this->responder($metodo, fn () => $this->servicio()->previsualizar()),
            'aplicar' => $this->responder($metodo, fn () => $this->servicio()->aplicar($_POST)),
            default => Respuesta::error(404, "Acción no reconocida: {$accion}"),
        };
    }

    /**
     * Exige POST y responde con lo que devuelva el Service.
     */
    private function responder(string $metodo, callable $operacion): void
    {
        Solicitud::exigirMetodo('POST', $metodo);
        Respuesta::exito($operacion());
    }
}
