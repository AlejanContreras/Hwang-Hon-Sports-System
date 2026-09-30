<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `entrega_cinturon`.
 * Confirmación de entrega física del cinturón tras aprobar un examen (RN-5.7).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 *
 * Ubicación: backend/Src/Repositories/EntregaCinturonRepository.php
 */
class EntregaCinturonRepository extends BaseRepository
{
    /**
     * Obtiene una fila de `entrega_cinturon` por su llave primaria compuesta (sesion_examen_id, deportista_documento).
     */
    public function obtenerPorId(string|int $sesionExamenId, string|int $deportistaDocumento): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM entrega_cinturon WHERE sesion_examen_id = :sesion_examen_id AND deportista_documento = :deportista_documento',
            [
                'sesion_examen_id' => $sesionExamenId,
                'deportista_documento' => $deportistaDocumento,
            ]
        );
    }

    /**
     * Lista filas de `entrega_cinturon`. Filtro genérico por columna=valor (sin lógica de negocio).
     *
     * @param array<string, mixed> $filtros
     */
    public function listar(array $filtros = [], int $limite = 100, int $desplazamiento = 0): array
    {
        $condiciones = [];
        $parametros = [];
        foreach ($filtros as $columna => $valor) {
            $condiciones[] = "{$columna} = :{$columna}";
            $parametros[$columna] = $valor;
        }

        $sql = 'SELECT * FROM entrega_cinturon';
        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $sql .= ' LIMIT :limite OFFSET :desplazamiento';
        $parametros['limite'] = $limite;
        $parametros['desplazamiento'] = $desplazamiento;

        return $this->consultar($sql, $parametros);
    }

    /**
     * Inserta una nueva fila en `entrega_cinturon` a partir de un EntregaCinturonModel.
     */
    public function crear(EntregaCinturonModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO entrega_cinturon (sesion_examen_id, deportista_documento, cinturon_entregado, fecha_entrega) VALUES (:sesion_examen_id, :deportista_documento, :cinturon_entregado, :fecha_entrega)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `entrega_cinturon` a partir de un EntregaCinturonModel.
     */
    public function actualizar(EntregaCinturonModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE entrega_cinturon SET cinturon_entregado = :cinturon_entregado, fecha_entrega = :fecha_entrega WHERE sesion_examen_id = :sesion_examen_id AND deportista_documento = :deportista_documento',
            $datos
        );
    }

    /**
     * Elimina una fila de `entrega_cinturon` por su llave primaria compuesta (sesion_examen_id, deportista_documento).
     */
    public function eliminar(string|int $sesionExamenId, string|int $deportistaDocumento): bool
    {
        return $this->ejecutar(
            'DELETE FROM entrega_cinturon WHERE sesion_examen_id = :sesion_examen_id AND deportista_documento = :deportista_documento',
            [
                'sesion_examen_id' => $sesionExamenId,
                'deportista_documento' => $deportistaDocumento,
            ]
        );
    }
}
