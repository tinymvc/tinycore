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
     * Get the table prefix.
     *
     * @return string The current table prefix.
     */
    public function getTablePrefix(): string;

    /**
     * Get the table name with the prefix applied.
     *
     * @param string $table The name of the table.
     * @return string The full table name with prefix.
     */
    public function wrapTable(string $table): string;

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

    /**
     * Execute an SQL statement with parameters.
     *
     * @param string $statement
     * @param array $params
     * @param array $options
     * @return bool
     */
    public function statement(string $statement, array $params = [], array $options = []): bool;

    /**
     * Execute a raw SQL statement and return the number of affected rows.
     *
     * @param string $statement
     * @return int|false
     */
    public function exec(string $statement): int|false;

    /**
     * Execute a Closure within a database transaction.
     *
     * @param callable $callback
     * @return mixed
     */
    public function transaction(callable $callback): mixed;

    /**
     * Get the PDO driver name for the connection.
     *
     * @return string
     */
    public function getDriver(): string;

    /**
     * Check if the database driver matches the given driver(s).
     *
     * @param array|string $driver
     * @return bool
     */
    public function isDriver(array|string $driver): bool;

    /**
     * Get a configuration value for the database connection.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getConfig(string $key, $default = null): mixed;
}