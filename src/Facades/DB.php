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
 * @method static bool beginTransaction()
 * @method static bool commit()
 * @method static bool rollBack()
 * @method static bool inTransaction()
 * @method static bool|string lastInsertId()
 * @method static bool|string quote()
 * @method static bool|int exec(string $statement)
 * @method static array raw(string $sql, array $bindings = [])
 * @method static QueryBuilder where(null|string|array|Closure $column = null, ?string $operator = null, mixed $value = null, ?string $andOr = null, bool $not = false)
 * @method static QueryBuilder whereRaw(string $sql, string|array $bindings = [], string $andOr = 'AND')
 * @method static QueryBuilder when(mixed $value, callable $callback)
 * @method static QueryBuilder unless(mixed $value, callable $callback)
 * @method static QueryBuilder table(string $table)
 * @method static QueryBuilder select(array|string $fields = '*', ...$args)
 * @method static QueryBuilder selectRaw(string $sql, array $bindings = [])
 * @method static QueryBuilder from(string $table, ?string $alias = null)
 * @method static QueryBuilder max($field, $name = null)
 * @method static QueryBuilder min($field, $name = null)
 * @method static QueryBuilder sum($field, $name = null)
 * @method static QueryBuilder avg($field, $name = null)
 *  
 * @package Spark\Http
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class DB extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Database::class;
    }
}
