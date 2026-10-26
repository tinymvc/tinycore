<?php

namespace Spark\Facades;

use Spark\Database\Contracts\DatabaseDriver;
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
 * @method static Database reset()
 * @method static Database connection(string|array|null $config = null)
 * @method static false|PDOStatement query(string $query, ...$args)
 * @method static false|PDOStatement prepare(string $statement, array $options = [])
 * @method static PDO getPdo()
 * @method static PDO pdo()
 * @method static string getDriver()
 * @method static bool isMySQL()
 * @method static bool isSQLite()
 * @method static bool isPostgreSQL()
 * @method static bool isDriver(string $driver)
 * @method static mixed getConfig(string $key, $default = null)
 * @method static bool|int exec(string $statement)
 * @method static bool statement(string $statement, array $params = [], array $options = [])
 * @method static \Spark\Database\Expression raw(string $sql)
 * @method static array run(string $sql, array $bindings = [])
 * @method static string getTablePrefix()
 * @method static string wrapTable(string $table)
 * @method static QueryBuilder table(string $table, ?string $alias = null)
 * @method static QueryBuilder select(array|string|\Spark\Database\Expression $fields = '*', ...$args)
 * @method static QueryBuilder selectRaw(string $sql, array $bindings = [])
 * @method static QueryBuilder from(string $table, ?string $alias = null)
 * @method static QueryBuilder where(null|string|array|\Closure $column = null, mixed $operator = null, mixed $value = null, ?string $boolean = null, bool $not = false)
 * @method static QueryBuilder whereRaw(string $sql, string|array $bindings = [], string $boolean = 'AND')
 * @method static QueryBuilder whereIn(string $column, array $values)
 * @method static QueryBuilder when(mixed $value, callable $callback)
 * @method static QueryBuilder unless(mixed $value, callable $callback)
 * @method static QueryBuilder max(string $field, ?string $name = null)
 * @method static QueryBuilder min(string $field, ?string $name = null)
 * @method static QueryBuilder sum(string $field, ?string $name = null)
 * @method static QueryBuilder avg(string $field, ?string $name = null)
 * @method static bool|string insert(array $values)
 * @method static int update(array $values)
 * @method static int delete()
 * @method static mixed find(int|string $id, array|string $fields = '*')
 * @method static mixed first(array|string $fields = '*')
 * @method static array|\Spark\Support\Collection get(array|string $fields = '*')
 * @method static int count(string $field = '*')
 * @method static bool exists()
 * @method static bool|string lastInsertId()
 * @method static string quote(string $string, int $type = PDO::PARAM_STR)
 * @method static bool beginTransaction()
 * @method static bool setAttribute(int $attribute, mixed $value)
 * @method static mixed getAttribute(int $attribute)
 * @method static array getAvailableDrivers()
 * @method static array errorInfo()
 * @method static ?string errorCode()
 * @method static bool commit()
 * @method static bool rollBack()
 * @method static bool inTransaction()
 * @method static mixed transaction(callable $callback)
 *  
 * @package Spark\Database
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class DB extends Facade implements DatabaseDriver
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
}
