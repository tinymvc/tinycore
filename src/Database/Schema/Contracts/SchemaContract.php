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
     * Drops a table from the database if it exists.
     *
     * @param string $table The name of the table to be dropped.
     */
    public static function dropIfExists(string $table): void;

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
