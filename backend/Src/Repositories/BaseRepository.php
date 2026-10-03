<?php

declare(strict_types=1);

/**
 * Clase base abstracta para todos los repositorios del sistema.
 * Provee acceso a la conexión PDO y helpers genéricos de consulta,
 * todos usando sentencias preparadas (sin concatenar valores en SQL).
 */
abstract class BaseRepository
{
    protected PDO $bd;

    public function __construct()
    {
        $this->bd = Database::obtenerConexion();
    }

    /**
     * Ejecuta una consulta SELECT y devuelve todas las filas.
     *
     * @param array<string, mixed> $parametros
     * @return array<int, array<string, mixed>>
     */
    protected function consultar(string $sql, array $parametros = []): array
    {
        $sentencia = $this->bd->prepare($sql);
        $sentencia->execute($parametros);
        return $sentencia->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ejecuta una consulta SELECT y devuelve una sola fila (o null si no hay resultado).
     *
     * @param array<string, mixed> $parametros
     * @return array<string, mixed>|null
     */
    protected function consultarUno(string $sql, array $parametros = []): ?array
    {
        $sentencia = $this->bd->prepare($sql);
        $sentencia->execute($parametros);
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);
        return $fila === false ? null : $fila;
    }

    /**
     * Ejecuta un INSERT.
     *
     * @param array<string, mixed> $parametros
     */
    protected function insertar(string $sql, array $parametros = []): bool
    {
        $sentencia = $this->bd->prepare($sql);
        return $sentencia->execute($parametros);
    }

    /**
     * Ejecuta un UPDATE o DELETE.
     *
     * @param array<string, mixed> $parametros
     */
    protected function ejecutar(string $sql, array $parametros = []): bool
    {
        $sentencia = $this->bd->prepare($sql);
        return $sentencia->execute($parametros);
    }

    /**
     * Cuenta filas que cumplen una condición.
     *
     * @param array<string, mixed> $parametros
     */
    protected function contar(string $sql, array $parametros = []): int
    {
        $sentencia = $this->bd->prepare($sql);
        $sentencia->execute($parametros);
        return (int) $sentencia->fetchColumn();
    }

    /**
     * Verifica si existe al menos una fila que cumpla una condición.
     *
     * @param array<string, mixed> $parametros
     */
    protected function existe(string $sql, array $parametros = []): bool
    {
        $sentencia = $this->bd->prepare($sql);
        $sentencia->execute($parametros);
        return $sentencia->fetchColumn() !== false;
    }
}
