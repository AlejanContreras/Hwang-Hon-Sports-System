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
    protected const TABLA = 'pago';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'id', 'deportista_documento', 'tipo', 'anio', 'mes', 'semana',
    ];

    protected const ORDEN = 'anio, mes, id';

    /**
     * Semana del ultimo pago de mensualidad de cada deportista, en una sola
     * consulta. "Ultimo" = mayor (anio, mes), no mayor id (un pago adelantado
     * registrado antes puede tener un mes posterior).
     *
     * @return array<string, int> documento => semana
     */
    public function semanasDelUltimoPago(): array
    {
        $resultado = [];
        $filas = $this->consultar(
            "SELECT p.deportista_documento, p.semana FROM pago p "
            . "JOIN (SELECT deportista_documento, MAX(anio * 100 + mes) AS periodo FROM pago WHERE tipo = 'mensualidad' GROUP BY deportista_documento) u "
            . "ON u.deportista_documento = p.deportista_documento AND p.anio * 100 + p.mes = u.periodo "
            . "WHERE p.tipo = 'mensualidad'"
        );
        foreach ($filas as $fila) {
            $resultado[(string) $fila['deportista_documento']] = (int) $fila['semana'];
        }
        return $resultado;
    }

    /**
     * Permanencia por deportista (historial): meses de mensualidad pagados
     * (pares año-mes distintos; la matrícula no cuenta) y el primer y último
     * periodo pagado como AAAA*100+MM. La agregación va en una tabla derivada
     * agrupada por documento y se une con LEFT JOIN, así quien no ha pagado
     * sale con 0 y es válida con ONLY_FULL_GROUP_BY. Con $documento, ese
     * deportista (exista o no validado); sin él, todos los validados.
     *
     * @return array<int, array<string, mixed>>
     */
    public function permanencia(?string $documento, ?string $estado): array
    {
        $condiciones = [];
        $parametros = [];
        if ($documento !== null) {
            $condiciones[] = 'd.documento = :documento';
            $parametros['documento'] = $documento;
        } else {
            $condiciones[] = "d.estado_registro = 'validado'";
        }
        if ($estado !== null) {
            $condiciones[] = 'd.estado = :estado';
            $parametros['estado'] = $estado;
        }
        return $this->consultar(
            'SELECT d.documento, d.nombre, d.estado, d.fecha_ingreso, COALESCE(m.meses, 0) AS meses_pagados, m.primero, m.ultimo '
            . 'FROM deportista d '
            . "LEFT JOIN (SELECT deportista_documento, COUNT(DISTINCT anio, mes) AS meses, MIN(anio * 100 + mes) AS primero, MAX(anio * 100 + mes) AS ultimo "
            . "FROM pago WHERE tipo = 'mensualidad' GROUP BY deportista_documento) m ON m.deportista_documento = d.documento "
            . 'WHERE ' . implode(' AND ', $condiciones)
            . ' ORDER BY meses_pagados DESC, d.nombre, d.documento',
            $parametros
        );
    }

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
     * Inserta una nueva fila en `pago` a partir de un PagoModel y devuelve el id
     * generado (columna AUTO_INCREMENT). El id del modelo no se envia: lo
     * asigna la base de datos.
     */
    public function crear(PagoModel $modelo): int
    {
        $datos = $modelo->toArray();
        unset($datos['id']);
        return $this->insertarYObtenerId(
            'INSERT INTO pago (deportista_documento, tipo, anio, mes, semana) VALUES (:deportista_documento, :tipo, :anio, :mes, :semana)',
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
