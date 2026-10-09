<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `deportista`.
 * Deportista inscrito en el club, con sus datos personales, deportivos y de estado (RN-1.x).
 * Solo acceso a datos — sin reglas de negocio (esas viven en DeportistaService).
 */
class DeportistaRepository extends BaseRepository
{
    /**
     * Columnas reales de la tabla. listar() solo acepta filtros por estas
     * columnas: el nombre de columna se escribe dentro del SQL, asi que nunca
     * puede venir libre desde la peticion (evita inyeccion SQL).
     */
    private const COLUMNAS = [
        'documento', 'nombre', 'fecha_nacimiento', 'sexo', 'estatura', 'peso',
        'discapacidad', 'estado_animico', 'sede_entrenamiento', 'autorizacion_datos',
        'grado_actual', 'fecha_ingreso', 'estado', 'estado_registro',
        'acudiente_documento', 'validado_por_correo',
    ];

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
     * Lista filas de `deportista`, con filtro columna = valor (sin logica de negocio).
     * Lanza LogicException si un filtro no es una columna de la tabla.
     * $limite null = sin limite (todas las filas).
     *
     * @param array<string, mixed> $filtros
     */
    public function listar(array $filtros = [], ?int $limite = 100, int $desplazamiento = 0): array
    {
        $condiciones = [];
        $parametros = [];
        foreach ($filtros as $columna => $valor) {
            if (!in_array($columna, self::COLUMNAS, true)) {
                throw new LogicException("Columna no valida para filtrar deportista: {$columna}");
            }
            $condiciones[] = "{$columna} = :{$columna}";
            $parametros[$columna] = $valor;
        }

        $sql = 'SELECT * FROM deportista';
        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $sql .= ' ORDER BY nombre';
        if ($limite !== null) {
            $sql .= ' LIMIT :limite OFFSET :desplazamiento';
            $parametros['limite'] = $limite;
            $parametros['desplazamiento'] = $desplazamiento;
        }

        return $this->consultar($sql, $parametros);
    }

    /**
     * Inserta una nueva fila en `deportista` a partir de un DeportistaModel.
     * Envia siempre todas las columnas, incluidos sexo, estado y
     * estado_registro: son ENUM NOT NULL y, si se omiten, MySQL los completaria
     * en silencio con su primer valor.
     */
    public function crear(DeportistaModel $modelo): bool
    {
        return $this->insertar(
            'INSERT INTO deportista (documento, nombre, fecha_nacimiento, sexo, estatura, peso, discapacidad, estado_animico, sede_entrenamiento, autorizacion_datos, grado_actual, fecha_ingreso, estado, estado_registro, acudiente_documento, validado_por_correo) VALUES (:documento, :nombre, :fecha_nacimiento, :sexo, :estatura, :peso, :discapacidad, :estado_animico, :sede_entrenamiento, :autorizacion_datos, :grado_actual, :fecha_ingreso, :estado, :estado_registro, :acudiente_documento, :validado_por_correo)',
            self::parametros($modelo)
        );
    }

    /**
     * Actualiza una fila existente de `deportista` a partir de un DeportistaModel.
     */
    public function actualizar(DeportistaModel $modelo): bool
    {
        return $this->ejecutar(
            'UPDATE deportista SET nombre = :nombre, fecha_nacimiento = :fecha_nacimiento, sexo = :sexo, estatura = :estatura, peso = :peso, discapacidad = :discapacidad, estado_animico = :estado_animico, sede_entrenamiento = :sede_entrenamiento, autorizacion_datos = :autorizacion_datos, grado_actual = :grado_actual, fecha_ingreso = :fecha_ingreso, estado = :estado, estado_registro = :estado_registro, acudiente_documento = :acudiente_documento, validado_por_correo = :validado_por_correo WHERE documento = :documento',
            self::parametros($modelo)
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

    /**
     * Arreglo de parametros para INSERT/UPDATE. autorizacion_datos va como
     * entero (0/1): un booleano false se enviaria como texto vacio y MySQL lo
     * rechaza en una columna TINYINT.
     *
     * @return array<string, mixed>
     */
    private static function parametros(DeportistaModel $modelo): array
    {
        $datos = $modelo->toArray();
        $datos['autorizacion_datos'] = (int) $datos['autorizacion_datos'];
        return $datos;
    }
}
