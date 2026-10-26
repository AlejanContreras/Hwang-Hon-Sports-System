<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `intento_acceso`.
 * Contador de inicios de sesión fallidos por ámbito e identificador (RN-T.16).
 * CRUD genérico únicamente — sin reglas de negocio (el conteo y el bloqueo
 * viven en AuthService).
 */
class IntentoAccesoRepository extends BaseRepository
{
    protected const TABLA = 'intento_acceso';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     */
    protected const COLUMNAS = [
        'ambito', 'identificador', 'intentos_fallidos', 'bloqueado_hasta',
    ];

    protected const ORDEN = 'ambito, identificador';

    /**
     * Obtiene una fila de `intento_acceso` por su llave primaria compuesta (ambito, identificador).
     */
    public function obtenerPorId(string $ambito, string $identificador): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM intento_acceso WHERE ambito = :ambito AND identificador = :identificador',
            [
                'ambito' => $ambito,
                'identificador' => $identificador,
            ]
        );
    }

    /**
     * Reserva un intento de inicio de sesión ANTES de verificar la clave, en
     * UNA sola sentencia atómica (así peticiones simultáneas no pueden leer
     * el mismo contador y saltarse el límite):
     *   - con un bloqueo vigente no cambia nada (el intento no se reserva);
     *   - si el bloqueo anterior ya venció, el conteo vuelve a 1 (B.5);
     *   - si no, suma 1, y al llegar a $umbral fija el bloqueo hasta $bloqueo.
     * Devuelve true si la petición obtuvo su intento. Con MYSQL_ATTR_FOUND_ROWS
     * apagado (el valor por defecto), una fila que no cambia cuenta 0.
     * MySQL evalúa las asignaciones de izquierda a derecha: la de
     * bloqueado_hasta ve el intentos_fallidos ya sumado y su propio valor
     * anterior.
     */
    public function reservarIntento(string $ambito, string $identificador, string $ahora, string $bloqueo, int $umbral): bool
    {
        return $this->ejecutarYContarFilas(
            'INSERT INTO intento_acceso (ambito, identificador, intentos_fallidos, bloqueado_hasta) VALUES (:ambito, :identificador, 1, NULL) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'intentos_fallidos = IF(bloqueado_hasta > :ahora, intentos_fallidos, IF(bloqueado_hasta IS NULL, intentos_fallidos + 1, 1)), '
            . 'bloqueado_hasta = IF(bloqueado_hasta > :ahora_bloqueo, bloqueado_hasta, IF(intentos_fallidos >= :umbral, :bloqueo, NULL))',
            [
                'ambito' => $ambito,
                'identificador' => $identificador,
                'ahora' => $ahora,
                'ahora_bloqueo' => $ahora,
                'umbral' => $umbral,
                'bloqueo' => $bloqueo,
            ]
        ) > 0;
    }

    /**
     * Limpieza ocasional: llama a limpiarViejos una de cada 100 veces.
     */
    public function limpiarDeVezEnCuando(string $limite): void
    {
        // Una de cada 100 llamadas (inicios de sesión y autorregistros): en
        // promedio, muchas veces al día con uso real y sin costo en las demás.
        if (random_int(1, 100) === 1) {
            $this->limpiarViejos($limite);
        }
    }

    /**
     * Limpieza de filas viejas (la tabla crecía sin límite): las que tienen
     * fecha (bloqueo o ventana de autorregistro) vencida antes de $limite.
     * Nunca toca un bloqueo vigente. Las filas sin bloqueo (1 a 3 fallos) no
     * guardan fecha, así que no se puede saber si son viejas: se dejan (se
     * borran solas al entrar bien o al llegar al bloqueo y vencerse).
     * Devuelve cuántas borró.
     */
    public function limpiarViejos(string $limite): int
    {
        return $this->ejecutarYContarFilas(
            'DELETE FROM intento_acceso WHERE bloqueado_hasta IS NOT NULL AND bloqueado_hasta < :limite',
            ['limite' => $limite]
        );
    }

    /**
     * Contador por ventana fija (límite de registros por IP del
     * autorregistro), reutilizando la tabla sin cambiarla: intentos_fallidos
     * = eventos en la ventana y bloqueado_hasta = fin de la ventana. Suma uno
     * en una sola sentencia atómica (si la ventana ya terminó, empieza otra
     * con 1) y devuelve el total de la ventana actual. MySQL evalúa las
     * asignaciones de izquierda a derecha: intentos_fallidos se calcula con
     * el bloqueado_hasta anterior.
     */
    public function contarEnVentana(string $ambito, string $identificador, string $ahora, string $finVentana): int
    {
        $this->ejecutar(
            'INSERT INTO intento_acceso (ambito, identificador, intentos_fallidos, bloqueado_hasta) VALUES (:ambito, :identificador, 1, :fin) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'intentos_fallidos = IF(bloqueado_hasta IS NULL OR bloqueado_hasta <= :ahora, 1, intentos_fallidos + 1), '
            . 'bloqueado_hasta = IF(bloqueado_hasta IS NULL OR bloqueado_hasta <= :ahora_ventana, :fin_nueva, bloqueado_hasta)',
            [
                'ambito' => $ambito,
                'identificador' => $identificador,
                'fin' => $finVentana,
                'ahora' => $ahora,
                'ahora_ventana' => $ahora,
                'fin_nueva' => $finVentana,
            ]
        );
        return (int) ($this->obtenerPorId($ambito, $identificador)['intentos_fallidos'] ?? 0);
    }

    /**
     * Actualiza una fila existente de `intento_acceso` a partir de un IntentoAccesoModel.
     */
    public function actualizar(IntentoAccesoModel $modelo): bool
    {
        return $this->ejecutar(
            'UPDATE intento_acceso SET intentos_fallidos = :intentos_fallidos, bloqueado_hasta = :bloqueado_hasta WHERE ambito = :ambito AND identificador = :identificador',
            $modelo->toArray()
        );
    }

    /**
     * Elimina una fila de `intento_acceso` por su llave primaria compuesta (ambito, identificador).
     */
    public function eliminar(string $ambito, string $identificador): bool
    {
        return $this->ejecutar(
            'DELETE FROM intento_acceso WHERE ambito = :ambito AND identificador = :identificador',
            [
                'ambito' => $ambito,
                'identificador' => $identificador,
            ]
        );
    }
}
