<?php

namespace Spark\Database\Schema\Contracts;

use Closure;
use Spark\Database\Schema\Grammar;

/**
 * Interface SchemaContract
 *
 * This interface defines the methods that must be implemented by any class
 * that implements it. It provides methods for creating and dropping tables
 * in the database.
 *
 * @package Spark\Database\Schema\Contracts
 */
interface SchemaContract
{
    /**
     * Creates a new table in the database.
     *
     * @param string   $table  The name of the table to be created.
     * @param Closure  $callback
     */
    public static function create(string $table, Closure $callback): void;

    /**
     * Alters an existing table in the database.
     *
     * @param string $table The name of the table to be altered.
     * @param Closure $callback
     */
    public static function table(string $table, Closure $callback): void;

    /**
     * Drops a table from the database if it exists.
     *
     * @param string $table The name of the table to be dropped.
     */
    public static function dropIfExists(string $table): void;

    /**
     * Rename a table.
     *
     * @param string $from The current table name.
     * @param string $to The new table name.
     * @return void
     */
    public static function rename(string $from, string $to): void;

    /**
     * Determine if a table exists.
     *
     * @param string $table The table name.
     * @return bool
     */
    public static function hasTable(string $table): bool;

    /**
     * Determine if a table has a column.
     *
     * @param string $table The table name.
     * @param string $column The column name.
     * @return bool
     */
    public static function hasColumn(string $table, string $column): bool;

    /**
     * Determine if a table has all given columns.
     *
     * @param string $table The table name.
     * @param array $columns The column names.
     * @return bool
     */
    public static function hasColumns(string $table, array $columns): bool;

    /**
     * Get the column listing for a table.
     *
     * @param string $table The table name.
     * @return array
     */
    public static function getColumnListing(string $table): array;

    /**
     * Enable foreign key constraints.
     *
     * @return bool
     */
    public static function enableForeignKeyConstraints(): bool;

    /**
     * Disable foreign key constraints.
     *
     * @return bool
     */
    public static function disableForeignKeyConstraints(): bool;

    /**
     * Run a callback with foreign key constraints disabled.
     *
     * @param Closure $callback
     * @return mixed
     */
    public static function withoutForeignKeyConstraints(Closure $callback): mixed;

    /**
     * Retrieves the database grammar instance.
     *
     * This method returns the existing Grammar instance if available, or
     * initializes it by resolving the PDO connection and obtaining its
     * driver name.
     *
     * @return Grammar The database grammar instance.
     */
    public static function getGrammar(): Grammar;
}
