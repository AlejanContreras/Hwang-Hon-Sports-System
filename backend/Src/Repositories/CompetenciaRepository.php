<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `competencia`.
 * Datos específicos de un evento de tipo competencia (RN-3.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class CompetenciaRepository extends BaseRepository
{
    protected const TABLA = 'competencia';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'evento_id', 'plataforma', 'tipo_torneo',
    ];

    protected const ORDEN = 'evento_id';

    /**
     * Obtiene una fila de `competencia` por su llave primaria `evento_id`.
     */
    public function obtenerPorId(string|int $eventoId): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM competencia WHERE evento_id = :evento_id',
            ['evento_id' => $eventoId]
        );
    }

    /**
     * Inserta una nueva fila en `competencia` a partir de un CompetenciaModel.
     */
    public function crear(CompetenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO competencia (evento_id, plataforma, tipo_torneo) VALUES (:evento_id, :plataforma, :tipo_torneo)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `competencia` a partir de un CompetenciaModel.
     */
    public function actualizar(CompetenciaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE competencia SET plataforma = :plataforma, tipo_torneo = :tipo_torneo WHERE evento_id = :evento_id',
            $datos
        );
    }

    /**
     * Elimina una fila de `competencia` por su llave primaria `evento_id`.
     */
    public function eliminar(string|int $eventoId): bool
    {
        return $this->ejecutar(
            'DELETE FROM competencia WHERE evento_id = :evento_id',
            ['evento_id' => $eventoId]
        );
    }
}
