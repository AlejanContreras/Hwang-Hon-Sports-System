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
     * Suma un fallo en UNA sola sentencia (atómica): crea la fila con 1, o
     * suma 1 a la existente. Si el bloqueo anterior ya venció
     * (bloqueado_hasta <= ahora), el fallo cuenta como el primero y el
     * bloqueo se borra. Así dos peticiones simultáneas no pueden leer el mismo
     * contador y saltarse el límite. Decidir cuándo bloquear queda en el Service.
     * MySQL evalúa las asignaciones de izquierda a derecha: la de
     * intentos_fallidos todavía ve el bloqueado_hasta original.
     */
    public function registrarFallo(string $ambito, string $identificador, string $ahora): void
    {
        $this->ejecutar(
            'INSERT INTO intento_acceso (ambito, identificador, intentos_fallidos, bloqueado_hasta) VALUES (:ambito, :identificador, 1, NULL) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'intentos_fallidos = IF(bloqueado_hasta IS NOT NULL AND bloqueado_hasta <= :ahora, 1, intentos_fallidos + 1), '
            . 'bloqueado_hasta = IF(bloqueado_hasta IS NOT NULL AND bloqueado_hasta <= :ahora_bloqueo, NULL, bloqueado_hasta)',
            [
                'ambito' => $ambito,
                'identificador' => $identificador,
                'ahora' => $ahora,
                'ahora_bloqueo' => $ahora,
            ]
        );
    }

    /**
     * Inserta una nueva fila en `intento_acceso` a partir de un IntentoAccesoModel.
     * Envia siempre ambito: es ENUM NOT NULL.
     */
    public function crear(IntentoAccesoModel $modelo): bool
    {
        return $this->insertar(
            'INSERT INTO intento_acceso (ambito, identificador, intentos_fallidos, bloqueado_hasta) VALUES (:ambito, :identificador, :intentos_fallidos, :bloqueado_hasta)',
            $modelo->toArray()
        );
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
