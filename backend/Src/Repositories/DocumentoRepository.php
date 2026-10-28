<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `documento`.
 * Documento cargado en el expediente de un deportista (RN-6.1).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class DocumentoRepository extends BaseRepository
{
    protected const TABLA = 'documento';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'id', 'deportista_documento', 'tipo_documento', 'archivo', 'nombre_original',
        'fecha_carga',
    ];

    protected const ORDEN = 'tipo_documento, id';

    /**
     * Tipos de documento ya cargados por cada deportista con ese
     * estado_registro, en UNA sola consulta (indicador de faltantes de la
     * lista de pendientes de validacion, sin una consulta por deportista).
     *
     * @return array<int, array{deportista_documento: string, tipo_documento: string}>
     */
    public function tiposPorEstadoRegistro(string $estadoRegistro): array
    {
        return $this->consultar(
            'SELECT DISTINCT d.deportista_documento, d.tipo_documento FROM documento d '
            . 'JOIN deportista p ON p.documento = d.deportista_documento '
            . 'WHERE p.estado_registro = :estado_registro ORDER BY d.deportista_documento, d.tipo_documento',
            ['estado_registro' => $estadoRegistro]
        );
    }

    /**
     * Obtiene una fila de `documento` por su llave primaria `id`.
     */
    public function obtenerPorId(string|int $id): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM documento WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Inserta una nueva fila en `documento` a partir de un DocumentoModel y devuelve el id
     * generado (columna AUTO_INCREMENT). El id del modelo no se envia: lo
     * asigna la base de datos.
     */
    public function crear(DocumentoModel $modelo): int
    {
        $datos = $modelo->toArray();
        unset($datos['id']);
        return $this->insertarYObtenerId(
            'INSERT INTO documento (deportista_documento, tipo_documento, archivo, nombre_original, fecha_carga) VALUES (:deportista_documento, :tipo_documento, :archivo, :nombre_original, :fecha_carga)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `documento` a partir de un DocumentoModel.
     */
    public function actualizar(DocumentoModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE documento SET deportista_documento = :deportista_documento, tipo_documento = :tipo_documento, archivo = :archivo, nombre_original = :nombre_original, fecha_carga = :fecha_carga WHERE id = :id',
            $datos
        );
    }

    /**
     * Borra todas las filas de documento de un deportista y devuelve cuántas
     * borró (rechazo de un registro pendiente; va dentro de su transacción).
     */
    public function eliminarDeDeportista(string $deportistaDocumento): int
    {
        return $this->ejecutarYContarFilas(
            'DELETE FROM documento WHERE deportista_documento = :documento',
            ['documento' => $deportistaDocumento]
        );
    }

    /**
     * Elimina una fila de `documento` por su llave primaria `id`.
     */
    public function eliminar(string|int $id): bool
    {
        return $this->ejecutar(
            'DELETE FROM documento WHERE id = :id',
            ['id' => $id]
        );
    }
}
