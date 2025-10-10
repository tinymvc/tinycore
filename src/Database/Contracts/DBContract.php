<?php

namespace Spark\Database\Contracts;

use PDO;
use PDOStatement;

/**
 * Interface DBContract
 * 
 * This interface provides the contract for the Database class.
 */
interface DBContract
{
    /**
     * Retrieves or initializes the PDO instance.
     * 
     * @return PDO The PDO connection instance.
     */
    public function getPdo(): PDO;

    /**
     * Executes a raw SQL query with optional arguments.
     * 
     * @param string $query The SQL query.
     * @param mixed ...$args Additional arguments for query execution.
     * @return PDOStatement|false The resulting statement or false on failure.
     */
    public function query(string $query, ...$args): false|PDOStatement;

    /**
     * Prepares an SQL statement for execution with optional options.
     * 
     * @param string $statement The SQL query to prepare.
     * @param array $options Options for statement preparation.
     * @return PDOStatement|false The prepared statement or false on failure.
     */
    public function prepare(string $statement, array $options = []): false|PDOStatement;
}