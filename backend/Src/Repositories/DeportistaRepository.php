<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `deportista`.
 * Deportista inscrito en el club, con sus datos personales, deportivos y de estado (RN-1.x).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class DeportistaRepository extends BaseRepository
{
    /**
     * Obtiene una fila de `deportista` por su llave primaria `documento`.
     */
    public function obtenerPorId(string|int $documento): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM deportista WHERE documento = :documento',
            ['documento' => $documento]
        );
    }

    /**
     * Lista filas de `deportista`. Filtro genérico por columna=valor (sin lógica de negocio).
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

        $sql = 'SELECT * FROM deportista';
        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $sql .= ' LIMIT :limite OFFSET :desplazamiento';
        $parametros['limite'] = $limite;
        $parametros['desplazamiento'] = $desplazamiento;

        return $this->consultar($sql, $parametros);
    }

    /**
     * Inserta una nueva fila en `deportista` a partir de un DeportistaModel.
     */
    public function crear(DeportistaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO deportista (documento, nombre, fecha_nacimiento, estatura, peso, discapacidad, estado_animico, sede_entrenamiento, autorizacion_datos, grado_actual, fecha_ingreso, estado, estado_registro, acudiente_documento, validado_por_correo) VALUES (:documento, :nombre, :fecha_nacimiento, :estatura, :peso, :discapacidad, :estado_animico, :sede_entrenamiento, :autorizacion_datos, :grado_actual, :fecha_ingreso, :estado, :estado_registro, :acudiente_documento, :validado_por_correo)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `deportista` a partir de un DeportistaModel.
     */
    public function actualizar(DeportistaModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE deportista SET nombre = :nombre, fecha_nacimiento = :fecha_nacimiento, estatura = :estatura, peso = :peso, discapacidad = :discapacidad, estado_animico = :estado_animico, sede_entrenamiento = :sede_entrenamiento, autorizacion_datos = :autorizacion_datos, grado_actual = :grado_actual, fecha_ingreso = :fecha_ingreso, estado = :estado, estado_registro = :estado_registro, acudiente_documento = :acudiente_documento, validado_por_correo = :validado_por_correo WHERE documento = :documento',
            $datos
        );
    }

    /**
     * Elimina una fila de `deportista` por su llave primaria `documento`.
     */
    public function eliminar(string|int $documento): bool
    {
        return $this->ejecutar(
            'DELETE FROM deportista WHERE documento = :documento',
            ['documento' => $documento]
        );
    }
}
