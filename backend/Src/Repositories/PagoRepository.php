<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `pago`.
 * Registro de pago (mensualidad o matrícula) de un deportista (RN-8.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class PagoRepository extends BaseRepository
{
    /**
     * Obtiene una fila de `pago` por su llave primaria `id`.
     */
    public function obtenerPorId(string|int $id): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM pago WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Lista filas de `pago`. Filtro genérico por columna=valor (sin lógica de negocio).
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

        $sql = 'SELECT * FROM pago';
        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $sql .= ' LIMIT :limite OFFSET :desplazamiento';
        $parametros['limite'] = $limite;
        $parametros['desplazamiento'] = $desplazamiento;

        return $this->consultar($sql, $parametros);
    }

    /**
     * Inserta una nueva fila en `pago` a partir de un PagoModel.
     */
    public function crear(PagoModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO pago (id, deportista_documento, tipo, anio, mes, semana, estado, consecuencia_mora) VALUES (:id, :deportista_documento, :tipo, :anio, :mes, :semana, :estado, :consecuencia_mora)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `pago` a partir de un PagoModel.
     */
    public function actualizar(PagoModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE pago SET deportista_documento = :deportista_documento, tipo = :tipo, anio = :anio, mes = :mes, semana = :semana, estado = :estado, consecuencia_mora = :consecuencia_mora WHERE id = :id',
            $datos
        );
    }

    /**
     * Elimina una fila de `pago` por su llave primaria `id`.
     */
    public function eliminar(string|int $id): bool
    {
        return $this->ejecutar(
            'DELETE FROM pago WHERE id = :id',
            ['id' => $id]
        );
    }
}
