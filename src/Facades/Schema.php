<?php

namespace Spark\Facades;

use PDO;
use Spark\Database\Schema\Grammar;
use Spark\Database\Schema\Schema as BaseSchema;

/**
 * Facade Schema
 *
 * Static interface to the database schema builder.
 *
 * @method static void create(string $table, Closure $callback)
 * @method static void table(string $table, Closure $callback)
 * @method static void drop(string $table)
 * @method static void dropIfExists(string $table)
 * @method static void rename(string $from, string $to)
 * @method static bool hasTable(string $table)
 * @method static bool hasColumn(string $table, string $column)
 * @method static bool hasColumns(string $table, array $columns)
 * @method static array getColumnListing(string $table)
 * @method static bool enableForeignKeyConstraints()
 * @method static bool disableForeignKeyConstraints()
 * @method static mixed withoutForeignKeyConstraints(Closure $callback)
 * @method static Grammar getGrammar()
 * @method static PDO getConnection()
 * @method static void rawSql(string $sql)
 * @method static void execute(string|array $sql)
 *
 * @package Spark\Facades
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Schema extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseSchema::class;
    }
}
