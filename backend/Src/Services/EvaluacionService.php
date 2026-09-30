<?php

declare(strict_types=1);

/**
 * Capa de logica de negocio del modulo Evaluacion.
 * Examenes de ascenso de grado (RN-5.x). La regla exacta de aprobacion general (RN-5.3) sigue PENDIENTE DE RESPUESTA (RN-10.3/RN-5.7) y no se implementa en esta etapa.
 *
 * Los metodos de este archivo son firmas (contratos) definidas en la Etapa 4
 * (Diseno de arquitectura backend, fase "Contratos por modulo"); la
 * implementacion real de las reglas de negocio corresponde a la Etapa 5
 * (Desarrollo backend), segun la Especificacion de Logica de Negocio.
 *
 * Ubicacion: backend/Src/Services/EvaluacionService.php
 */
class EvaluacionService
{
    private SesionExamenRepository $sesionExamen;
    private DetalleEvaluacionRepository $detalleEvaluacion;
    private EntregaCinturonRepository $entregaCinturon;
    private DeportistaRepository $deportista;

    public function __construct()
    {
        $this->sesionExamen = new SesionExamenRepository();
        $this->detalleEvaluacion = new DetalleEvaluacionRepository();
        $this->entregaCinturon = new EntregaCinturonRepository();
        $this->deportista = new DeportistaRepository();
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function listar(array $filtros = []): array
    {
        throw new RuntimeException(
            'EvaluacionService::listar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function consultar(array $identificador): ?array
    {
        throw new RuntimeException(
            'EvaluacionService::consultar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function crear(array $datos): array
    {
        throw new RuntimeException(
            'EvaluacionService::crear() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function actualizar(array $identificador, array $datos): array
    {
        throw new RuntimeException(
            'EvaluacionService::actualizar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function eliminar(array $identificador): bool
    {
        throw new RuntimeException(
            'EvaluacionService::eliminar() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function confirmarEntregaCinturon(array $identificador, array $datos): array
    {
        throw new RuntimeException(
            'EvaluacionService::confirmarEntregaCinturon() pendiente de implementar en Etapa 5.'
        );
    }
}
