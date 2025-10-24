<?php

namespace Spark\Facades;

use Spark\Database\DB as Database;
use Spark\Database\QueryBuilder;
use PDO;
use PDOStatement;

/**
 * Facade DB
 *
 * This class provides a simple facade for the Database class, allowing for easy
 * access to database operations.
 *
 * @method static Database resetConfig(array $config)
 * @method static Database resetPdo()
 * @method static false|PDOStatement query(string $query, ...$args)
 * @method static false|PDOStatement prepare(string $statement, array $options = [])
 * @method static PDO getPdo()
 * @method static string getDriver()
 * @method static bool isMySQL()
 * @method static bool isSQLite()
 * @method static bool isPostgreSQL()
 * @method static bool isDriver(string $driver)
 * @method static mixed getConfig(string $key, $default = null)
 * @method static bool|int exec(string $statement)
 *  
 * @package Spark\Http
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class DB extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string The registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return Database::class;
    }

    /**
     * Begin a query on a specific table.
     *
     * @param string $table The name of the table to query.
     * @return QueryBuilder The query builder instance for the specified table.
     */
    public static function table(string $table): QueryBuilder
    {
        return Database::table($table);
    }

    /**
     * Perform a select query.
     *
     * @param string $fields The fields to select.
     * @param mixed ...$args Additional arguments for the select query.
     * @return QueryBuilder The query builder instance for the select query.
     */
    public static function select(string $fields = '*', ...$args): QueryBuilder
    {
        return Database::select(...func_get_args());
    }

    /**
     * Get the database connection instance.
     *
     * @return Database The database connection instance.
     */
    public static function connection(): Database
    {
        return app(self::getFacadeAccessor());
    }

    /**
     * Get the ID of the last inserted row.
     *
     * @return bool|string The ID of the last inserted row, or false on failure.
     */
    public static function lastInsertId(): bool|string
    {
        return self::connection()->lastInsertId();
    }

    /**
     * Quote a string for use in a query.
     *
     * @param string $string The string to quote.
     * @param int $type The data type of the parameter (default is PDO::PARAM_STR).
     * @return string The quoted string.
     */
    public static function quote(string $string, int $type = PDO::PARAM_STR): string
    {
        return self::connection()->quote($string, $type);
    }

    /* Transaction Methods */

    /**
     * Commit the current transaction.
     *
     * @return bool True on success, false on failure.
     */
    public static function commit(): bool
    {
        return self::connection()->commit();
    }

    /**
     * Roll back the current transaction.
     *
     * @return bool True on success, false on failure.
     */
    public static function rollBack(): bool
    {
        return self::connection()->rollBack();
    }

    /**
     * Check if a transaction is currently active.
     *
     * @return bool True if a transaction is active, false otherwise.
     */
    public static function inTransaction(): bool
    {
        return self::connection()->inTransaction();
    }

    /**
     * Begin a new transaction.
     *
     * @return bool True on success, false on failure.
     */
    public static function beginTransaction(): bool
    {
        return self::connection()->beginTransaction();
    }

    /**
     * Execute a callback within a transaction.
     *
     * @param callable $callback The callback to execute.
     * @return void
     *
     * @throws \Throwable Rethrows any exception thrown within the transaction.
     */
    public static function transaction(callable $callback)
    {
        try {
            self::connection()->beginTransaction();
            $callback();
            self::connection()->commit();
        } catch (\Throwable $e) {
            self::connection()->rollBack();
            throw $e;
        }
    }
}
