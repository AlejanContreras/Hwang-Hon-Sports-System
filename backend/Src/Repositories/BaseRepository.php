<?php

declare(strict_types=1);

/**
 * Clase base abstracta para todos los repositorios del sistema.
 * Provee acceso a la conexión PDO y helpers genéricos de consulta, todos con
 * sentencias preparadas (los valores nunca se concatenan en el SQL).
 *
 * Cada Repository hijo declara tres constantes, usadas por listar() y contar():
 *   - TABLA: nombre de la tabla.
 *   - COLUMNAS: lista blanca de columnas por las que se puede filtrar. El
 *     nombre de columna se escribe dentro del SQL, así que nunca puede venir
 *     libre de la petición (evita inyección SQL por nombre de columna).
 *   - ORDEN: cláusula ORDER BY fija, escrita en el código, nunca tomada de
 *     datos del usuario. Termina siempre en la llave primaria, para que el
 *     orden sea único y la paginación estable (sin filas repetidas ni
 *     saltadas entre páginas).
 * Opcional:
 *   - COLUMNAS_LECTURA: columnas que devuelve listar(). Por defecto todas; un
 *     hijo la redefine para no leer columnas sensibles (ej. contrasena).
 */
abstract class BaseRepository
{
    protected const TABLA = '';
    protected const COLUMNAS = [];
    protected const ORDEN = '';
    protected const COLUMNAS_LECTURA = '*';

    protected PDO $bd;

    public function __construct()
    {
        $this->bd = Database::obtenerConexion();
    }

    /**
     * Lista filas de la tabla con filtro columna = valor (sin lógica de negocio);
     * un filtro con valor null busca columna IS NULL.
     * $limite null = sin límite (todas las filas). No hay límite por defecto.
     * Lanza LogicException si un filtro no es una columna de COLUMNAS, y
     * InvalidArgumentException si el límite o el desplazamiento son negativos
     * (el Service valida antes lo que venga de la petición).
     *
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public function listar(array $filtros = [], ?int $limite = null, int $desplazamiento = 0): array
    {
        if (($limite !== null && $limite < 0) || $desplazamiento < 0) {
            throw new InvalidArgumentException('El limite y el desplazamiento no pueden ser negativos.');
        }

        [$donde, $parametros] = $this->construirFiltro($filtros);

        $sql = 'SELECT ' . static::COLUMNAS_LECTURA . ' FROM ' . static::TABLA . $donde . ' ORDER BY ' . static::ORDEN;
        if ($limite !== null) {
            $sql .= ' LIMIT :limite OFFSET :desplazamiento';
            $parametros['limite'] = $limite;
            $parametros['desplazamiento'] = $desplazamiento;
        }

        return $this->consultar($sql, $parametros);
    }

    /**
     * Cuenta las filas que cumplen el filtro (mismas reglas que listar()).
     * Junto con listar(limite, desplazamiento) permite paginar: el Service de
     * cada módulo arma la respuesta paginada.
     *
     * @param array<string, mixed> $filtros
     */
    public function contar(array $filtros = []): int
    {
        [$donde, $parametros] = $this->construirFiltro($filtros);
        return $this->contarConsulta('SELECT COUNT(*) FROM ' . static::TABLA . $donde, $parametros);
    }

    /**
     * Ejecuta $operacion dentro de una transacción: si todo sale bien confirma
     * (commit); si algo lanza una excepción, deshace todo (rollBack) y la vuelve
     * a lanzar. Como todos los Repositories comparten la misma conexión
     * (Database es singleton), la transacción cubre las operaciones de varios
     * Repositories a la vez (ej. deportista + acudiente). Así el Service agrupa
     * operaciones sin tocar PDO.
     */
    public function enTransaccion(callable $operacion): mixed
    {
        $this->bd->beginTransaction();
        try {
            $resultado = $operacion();
            $this->bd->commit();
            return $resultado;
        } catch (Throwable $error) {
            $this->bd->rollBack();
            throw $error;
        }
    }

    /**
     * Ejecuta una consulta SELECT y devuelve todas las filas.
     *
     * @param array<string, mixed> $parametros
     * @return array<int, array<string, mixed>>
     */
    protected function consultar(string $sql, array $parametros = []): array
    {
        return $this->preparar($sql, $parametros)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ejecuta una consulta SELECT y devuelve una sola fila (o null si no hay resultado).
     *
     * @param array<string, mixed> $parametros
     * @return array<string, mixed>|null
     */
    protected function consultarUno(string $sql, array $parametros = []): ?array
    {
        $fila = $this->preparar($sql, $parametros)->fetch(PDO::FETCH_ASSOC);
        return $fila === false ? null : $fila;
    }

    /**
     * Ejecuta un INSERT.
     *
     * @param array<string, mixed> $parametros
     */
    protected function insertar(string $sql, array $parametros = []): bool
    {
        $this->preparar($sql, $parametros);
        return true;
    }

    /**
     * Ejecuta un INSERT en una tabla con id AUTO_INCREMENT y devuelve el id
     * generado por la base de datos.
     *
     * @param array<string, mixed> $parametros
     */
    protected function insertarYObtenerId(string $sql, array $parametros = []): int
    {
        $this->preparar($sql, $parametros);
        return (int) $this->bd->lastInsertId();
    }

    /**
     * Ejecuta un UPDATE o DELETE.
     *
     * @param array<string, mixed> $parametros
     */
    protected function ejecutar(string $sql, array $parametros = []): bool
    {
        $this->preparar($sql, $parametros);
        return true;
    }

    /**
     * Ejecuta un UPDATE o DELETE y devuelve cuántas filas cambió. Sirve
     * cuando el resultado importa (ej. un UPDATE condicionado que solo debe
     * aplicarse una vez). Ojo: sin la opción FOUND_ROWS de la conexión, un
     * UPDATE que deja los mismos valores cuenta 0 filas.
     *
     * @param array<string, mixed> $parametros
     */
    protected function ejecutarYContarFilas(string $sql, array $parametros = []): int
    {
        return $this->preparar($sql, $parametros)->rowCount();
    }

    /**
     * Ejecuta una consulta de conteo escrita a mano (SELECT COUNT(*) ...) y
     * devuelve el número. Para contar por filtros simples usar contar().
     *
     * @param array<string, mixed> $parametros
     */
    protected function contarConsulta(string $sql, array $parametros = []): int
    {
        return (int) $this->preparar($sql, $parametros)->fetchColumn();
    }

    /**
     * Verifica si existe al menos una fila que cumpla una condición. Sirve con
     * SELECT COUNT(*) ... o con SELECT 1 ... LIMIT 1: sin filas, fetchColumn()
     * devuelve false, que como entero es 0.
     *
     * @param array<string, mixed> $parametros
     */
    protected function existe(string $sql, array $parametros = []): bool
    {
        return (int) $this->preparar($sql, $parametros)->fetchColumn() > 0;
    }

    /**
     * Prepara y ejecuta la sentencia. Los booleanos se envían como 0/1: PDO
     * enviaría false como texto vacío y MySQL lo rechaza en una columna
     * TINYINT(1) ("Incorrect integer value: ''").
     *
     * @param array<string, mixed> $parametros
     */
    private function preparar(string $sql, array $parametros): PDOStatement
    {
        foreach ($parametros as $nombre => $valor) {
            if (is_bool($valor)) {
                $parametros[$nombre] = (int) $valor;
            }
        }

        $sentencia = $this->bd->prepare($sql);
        $sentencia->execute($parametros);
        return $sentencia;
    }

    /**
     * Arma la cláusula WHERE de listar()/contar() validando cada nombre de
     * columna contra COLUMNAS. Un valor null se compara con IS NULL: en SQL
     * "columna = NULL" nunca es verdadero y no encontraría ninguna fila.
     *
     * @param array<string, mixed> $filtros
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function construirFiltro(array $filtros): array
    {
        $condiciones = [];
        $parametros = [];
        foreach ($filtros as $columna => $valor) {
            if (!in_array($columna, static::COLUMNAS, true)) {
                throw new LogicException('Columna no valida para filtrar ' . static::TABLA . ": {$columna}");
            }
            if ($valor === null) {
                $condiciones[] = "{$columna} IS NULL";
                continue;
            }
            $condiciones[] = "{$columna} = :{$columna}";
            $parametros[$columna] = $valor;
        }

        $donde = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);
        return [$donde, $parametros];
    }
}
