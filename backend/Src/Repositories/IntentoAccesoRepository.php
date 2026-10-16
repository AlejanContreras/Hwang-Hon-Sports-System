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
