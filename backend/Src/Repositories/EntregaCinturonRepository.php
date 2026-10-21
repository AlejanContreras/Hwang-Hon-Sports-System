<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `entrega_cinturon`.
 * Confirmación de entrega física del cinturón tras aprobar un examen (RN-5.7).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class EntregaCinturonRepository extends BaseRepository
{
    protected const TABLA = 'entrega_cinturon';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'sesion_examen_id', 'deportista_documento', 'cinturon_entregado',
        'fecha_entrega', 'confirmado_por',
    ];

    protected const ORDEN = 'sesion_examen_id, deportista_documento';

    /**
     * Lee la fila BLOQUEÁNDOLA hasta el fin de la transacción (SELECT ... FOR
     * UPDATE): una segunda confirmación simultánea espera y después ve la
     * entrega ya hecha. Solo tiene efecto dentro de enTransaccion().
     */
    public function obtenerParaActualizar(int $sesionExamenId, string $deportistaDocumento): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM entrega_cinturon WHERE sesion_examen_id = :sesion AND deportista_documento = :documento FOR UPDATE',
            ['sesion' => $sesionExamenId, 'documento' => $deportistaDocumento]
        );
    }

    /**
     * Fecha de la última entrega confirmada de cada deportista (fecha del
     * último ascenso), en una sola consulta.
     *
     * @return array<string, string> documento => fecha
     */
    public function ultimasEntregas(): array
    {
        $resultado = [];
        foreach ($this->consultar('SELECT deportista_documento, MAX(fecha_entrega) AS ultima FROM entrega_cinturon WHERE cinturon_entregado = 1 GROUP BY deportista_documento') as $fila) {
            $resultado[(string) $fila['deportista_documento']] = $fila['ultima'];
        }
        return $resultado;
    }

    /**
     * Borra todas las filas de una sesión.
     */
    public function eliminarDeSesion(int $sesionExamenId): void
    {
        $this->ejecutar('DELETE FROM entrega_cinturon WHERE sesion_examen_id = :sesion', ['sesion' => $sesionExamenId]);
    }

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
     * Inserta una nueva fila en `entrega_cinturon` a partir de un EntregaCinturonModel.
     */
    public function crear(EntregaCinturonModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO entrega_cinturon (sesion_examen_id, deportista_documento, cinturon_entregado, fecha_entrega, confirmado_por) VALUES (:sesion_examen_id, :deportista_documento, :cinturon_entregado, :fecha_entrega, :confirmado_por)',
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
            'UPDATE entrega_cinturon SET cinturon_entregado = :cinturon_entregado, fecha_entrega = :fecha_entrega, confirmado_por = :confirmado_por WHERE sesion_examen_id = :sesion_examen_id AND deportista_documento = :deportista_documento',
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
