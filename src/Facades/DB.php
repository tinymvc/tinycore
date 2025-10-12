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
    protected static function getFacadeAccessor(): string
    {
        return Database::class;
    }
}
