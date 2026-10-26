<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `deportista`.
 * Deportista inscrito en el club, con sus datos personales, deportivos y de estado (RN-1.x).
 * Solo acceso a datos — sin reglas de negocio (esas viven en DeportistaService).
 */
class DeportistaRepository extends BaseRepository
{
    protected const TABLA = 'deportista';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'documento', 'nombre', 'fecha_nacimiento', 'lugar_nacimiento', 'sexo', 'estatura',
        'peso', 'telefono', 'direccion', 'correo', 'discapacidad', 'experiencia_previa',
        'estado_animico', 'sede_entrenamiento', 'autorizacion_datos', 'grado_actual',
        'fecha_ingreso', 'estado', 'estado_registro', 'acudiente_documento',
        'validado_por_correo',
    ];

    protected const ORDEN = 'nombre, documento';

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
     * Autocompletado: documento que empieza por $texto o nombre que lo
     * contiene. La collation utf8mb4_unicode_ci ya ignora mayusculas y
     * tildes. Los comodines de LIKE (%, _ y la barra invertida, que es el
     * caracter de escape) se escapan para que el texto sea literal. Devuelve
     * solo las columnas de identificacion, ordenadas por nombre y documento.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscar(string $texto, int $limite): array
    {
        $literal = addcslashes($texto, '\\%_');
        return $this->consultar(
            "SELECT documento, nombre, estado, estado_registro, grado_actual FROM deportista "
            . "WHERE documento LIKE :prefijo ESCAPE '\\\\' OR nombre LIKE :contiene ESCAPE '\\\\' "
            . 'ORDER BY nombre, documento LIMIT :limite',
            [
                'prefijo' => $literal . '%',
                'contiene' => '%' . $literal . '%',
                'limite' => $limite,
            ]
        );
    }

    /**
     * Cambia solo el estado activo/inactivo (RN-1.11), sin reescribir el
     * resto de la fila.
     */
    public function actualizarEstado(string $documento, string $estado): void
    {
        $this->ejecutar(
            'UPDATE deportista SET estado = :estado WHERE documento = :documento',
            ['estado' => $estado, 'documento' => $documento]
        );
    }

    /**
     * Cambia solo grado_actual (tras confirmar la entrega del cinturón,
     * RN-5.7), sin reescribir el resto de la fila.
     */
    public function actualizarGradoActual(string $documento, string $grado): void
    {
        $this->ejecutar(
            'UPDATE deportista SET grado_actual = :grado WHERE documento = :documento',
            ['grado' => $grado, 'documento' => $documento]
        );
    }

    /**
     * Filas de los deportistas con esos documentos, en una sola consulta
     * (carga masiva: se precargan en bloque en vez de consultar por fila).
     * Devuelve documento => fila.
     *
     * @param array<int, string> $documentos
     * @return array<string, array<string, mixed>>
     */
    public function listarPorDocumentos(array $documentos): array
    {
        return $this->indexarPorDocumento($this->consultarEnLista('SELECT * FROM deportista WHERE documento IN (%s)', $documentos));
    }

    /**
     * Documento, correo y fecha de nacimiento de los deportistas con alguno
     * de esos correos (conflictos de correo de la carga masiva, RN-1.10). La
     * collation de la columna ignora mayúsculas.
     *
     * @param array<int, string> $correos
     * @return array<int, array{documento: string, correo: string, fecha_nacimiento: string}>
     */
    public function listarPorCorreos(array $correos): array
    {
        return $this->consultarEnLista('SELECT documento, correo, fecha_nacimiento FROM deportista WHERE correo IN (%s)', $correos);
    }

    /**
     * Ejecuta $sql (con un %s donde va la lista) con un marcador por valor.
     * Lista vacía: no consulta.
     *
     * @param array<int, string> $valores
     * @return array<int, array<string, mixed>>
     */
    private function consultarEnLista(string $sql, array $valores): array
    {
        $valores = array_values(array_unique($valores));
        if ($valores === []) {
            return [];
        }
        $marcadores = [];
        $parametros = [];
        foreach ($valores as $i => $valor) {
            $marcadores[] = ":v{$i}";
            $parametros["v{$i}"] = $valor;
        }
        return $this->consultar(sprintf($sql, implode(', ', $marcadores)), $parametros);
    }

    /**
     * @param array<int, array<string, mixed>> $filas
     * @return array<string, array<string, mixed>>
     */
    private function indexarPorDocumento(array $filas): array
    {
        $resultado = [];
        foreach ($filas as $fila) {
            $resultado[(string) $fila['documento']] = $fila;
        }
        return $resultado;
    }

    /**
     * Indica si existe un deportista con ese documento (sin leer la fila).
     */
    public function existePorId(string $documento): bool
    {
        return $this->existe(
            'SELECT 1 FROM deportista WHERE documento = :documento LIMIT 1',
            ['documento' => $documento]
        );
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
            'INSERT INTO deportista (documento, nombre, fecha_nacimiento, lugar_nacimiento, sexo, estatura, peso, telefono, direccion, correo, discapacidad, experiencia_previa, estado_animico, sede_entrenamiento, autorizacion_datos, grado_actual, fecha_ingreso, estado, estado_registro, acudiente_documento, validado_por_correo) VALUES (:documento, :nombre, :fecha_nacimiento, :lugar_nacimiento, :sexo, :estatura, :peso, :telefono, :direccion, :correo, :discapacidad, :experiencia_previa, :estado_animico, :sede_entrenamiento, :autorizacion_datos, :grado_actual, :fecha_ingreso, :estado, :estado_registro, :acudiente_documento, :validado_por_correo)',
            $modelo->toArray()
        );
    }

    /**
     * Actualiza una fila existente de `deportista` a partir de un DeportistaModel.
     */
    public function actualizar(DeportistaModel $modelo): bool
    {
        return $this->ejecutar(
            'UPDATE deportista SET nombre = :nombre, fecha_nacimiento = :fecha_nacimiento, lugar_nacimiento = :lugar_nacimiento, sexo = :sexo, estatura = :estatura, peso = :peso, telefono = :telefono, direccion = :direccion, correo = :correo, discapacidad = :discapacidad, experiencia_previa = :experiencia_previa, estado_animico = :estado_animico, sede_entrenamiento = :sede_entrenamiento, autorizacion_datos = :autorizacion_datos, grado_actual = :grado_actual, fecha_ingreso = :fecha_ingreso, estado = :estado, estado_registro = :estado_registro, acudiente_documento = :acudiente_documento, validado_por_correo = :validado_por_correo WHERE documento = :documento',
            $modelo->toArray()
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
