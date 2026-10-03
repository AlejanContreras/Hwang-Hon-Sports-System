<?php

declare(strict_types=1);

/**
 * Conexión a la base de datos (patrón singleton sobre PDO).
 * Lee la configuración de variables de entorno; con valores por defecto
 * razonables para desarrollo local (XAMPP/MariaDB).
 */
class Database
{
    private static ?PDO $conexion = null;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public static function obtenerConexion(): PDO
    {
        if (self::$conexion === null) {
            self::$conexion = self::crearConexion();
        }

        return self::$conexion;
    }

    private static function crearConexion(): PDO
    {
        $servidor = self::variableEntorno('DB_HOST', 'localhost');
        $puerto = self::variableEntorno('DB_PORT', '3306');
        $nombreBaseDatos = self::variableEntorno('DB_NAME', 'hwanghon');
        $usuario = self::variableEntorno('DB_USER', 'root');
        $contrasena = self::variableEntorno('DB_PASS', '');

        $dsn = "mysql:host={$servidor};port={$puerto};dbname={$nombreBaseDatos};charset=utf8mb4";

        return new PDO($dsn, $usuario, $contrasena, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function variableEntorno(string $nombre, string $porDefecto): string
    {
        $valor = getenv($nombre);
        return $valor === false ? $porDefecto : $valor;
    }
}
