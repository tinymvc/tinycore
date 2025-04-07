<?php

namespace Spark\Database\Schema;

use Closure;
use PDO;
use Spark\Database\DB;
use Spark\Database\Schema\Contracts\SchemaContract;

/**
 * Class Schema
 *
 * This class provides methods for creating and dropping tables in the database.
 * It implements the SchemaContract interface.
 *
 * @package Spark\Database\Schema
 */
class Schema implements SchemaContract
{
    /** @var PDO The PDO connection.*/
    private static PDO $connection;

    /** @var Grammar The database grammar. */
    private static Grammar $grammar;

    /**
     * Creates a new table in the database.
     *
     * @param string   $table  The name of the table to be created.
     * @param Closure  $callback
     */
    public static function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $sql = $blueprint->compileCreate();

        self::execute($sql);
    }

    /**
     * Executes a raw SQL query.
     *
     * This method allows executing any raw SQL query provided as a string
     * using the current PDO connection. It is useful for running custom
     * SQL statements that may not be covered by the existing schema methods.
     *
     * @param string $sql The raw SQL query to execute.
     */
    public static function rawSql(string $sql): void
    {
        self::execute($sql);
    }

    /**
     * Alters an existing table in the database.
     *
     * This method allows modification of an existing table structure by applying
     * the changes defined in the provided callback function. The callback function
     * receives a Blueprint instance, which can be used to define the alterations 
     * to be made to the table.
     *
     * @param string $table The name of the table to be altered.
     * @param Closure $callback A callback function that defines the alterations.
     */
    public static function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table, true);
        $callback($blueprint);

        $sql = $blueprint->compileAlter();

        self::execute($sql);
    }

    /**
     * Drops a table from the database.
     *
     * @param string $table The name of the table to be dropped.
     */
    public static function drop(string $table)
    {
        $sql = "DROP TABLE " . self::getGrammar()->wrapTable($table);
        self::execute($sql);
    }

    /**
     * Drops a table from the database if it exists.
     *
     * @param string $table The name of the table to be dropped.
     */
    public static function dropIfExists(string $table): void
    {
        $sql = "DROP TABLE IF EXISTS " . self::getGrammar()->wrapTable($table);

        self::execute($sql);
    }

    /**
     * Executes a raw SQL statement against the database.
     *
     * This method runs the given SQL command using the current PDO connection.
     * It is typically used for executing DDL statements such as CREATE, DROP, or ALTER.
     *
     * @param string $sql The raw SQL statement to execute.
     */
    public static function execute(string $sql): void
    {
        $pdo = self::getConnection();
        $pdo->exec($sql);
    }

    /**
     * Retrieves or initializes the PDO connection.
     *
     * This method returns the existing PDO connection if available, 
     * or initializes it by resolving the DB class from the application 
     * container and obtaining its PDO instance.
     *
     * @return PDO The PDO connection instance.
     */
    public static function getConnection(): PDO
    {
        return self::$connection ??= get(DB::class)->getPdo();
    }

    /**
     * Retrieves the database grammar instance.
     *
     * This method returns the existing Grammar instance if available, 
     * or initializes it by resolving the PDO connection and obtaining 
     * its driver name.
     *
     * @return Grammar The database grammar instance.
     */
    public static function getGrammar(): Grammar
    {
        return self::$grammar ??= new Grammar(
            self::getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME)
        );
    }
}