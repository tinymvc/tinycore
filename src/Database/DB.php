<?php

namespace Spark\Database;

use PDO;
use PDOStatement;
use Spark\Database\Contracts\DBContract;
use Spark\Database\Exceptions\InvalidDatabaseConfigException;
use Spark\Support\Traits\Macroable;

/**
 * Class Database
 * 
 * Manages database connections and provides query execution and statement preparation.
 * 
 * @method bool beginTransaction()
 * @method bool commit()
 * @method bool rollBack()
 * @method bool inTransaction()
 * @method bool|string lastInsertId()
 * @method bool|string quote(string $string, int $type = PDO::PARAM_STR)
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
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class DB implements DBContract
{
    use Macroable {
        __call as macroCall;
        __callStatic as macroCallStatic;
    }

    /**
     * Store the PDO connection of database.
     * 
     * @var PDO
     */
    private PDO $pdo;

    /**
     * Database configuration.
     *
     * @var array
     */
    private array $config = [];

    /**
     * Initializes the database connection.
     *
     * @param array $config Database configuration.
     */
    public function __construct(array $config = [])
    {
        // If no configuration is provided, use the default configuration.
        if (empty($config)) {
            $config = config('database');
        }

        $this->config = $config;
    }

    /**
     * Retrieves a configuration value by key.
     *
     * @param string $key The configuration key.
     * @param mixed $default The default value if the configuration key is not found.
     * @return mixed The configuration value.
     */
    public function getConfig(string $key, $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Retrieves the database driver name.
     *
     * If the driver is not specified in the configuration, it defaults to 'mysql'.
     *
     * @return string The database driver name.
     */
    public function getDriver(): string
    {
        return $this->config['driver'] ?? 'mysql';
    }

    /**
     * Checks if the current database driver is PostgreSQL.
     *
     * @return bool True if the current driver is PostgreSQL, false otherwise.
     */
    public function isMySQL(): bool
    {
        return $this->isDriver('mysql');
    }

    /**
     * Checks if the current database driver is SQLite.
     *
     * @return bool True if the current driver is SQLite, false otherwise.
     */
    public function isSQLite(): bool
    {
        return $this->isDriver('sqlite');
    }

    /**
     * Checks if the current database driver is PostgreSQL.
     *
     * @return bool True if the current driver is PostgreSQL, false otherwise.
     */
    public function isPostgreSQL(): bool
    {
        return $this->isDriver('pgsql');
    }

    /**
     * Checks if the current database driver matches the specified driver.
     *
     * @param array|string ...$drivers The driver to check against the current driver.
     * @return bool True if the current driver matches the specified driver, false otherwise.
     */
    public function isDriver(array|string ...$drivers): bool
    {
        $drivers = is_array($drivers[0]) ? $drivers[0] : $drivers;
        return in_array($this->getDriver(), $drivers);
    }

    /**
     * Resets the database configuration.
     *
     * This method resets the database configuration with the given configuration
     * and unsets the PDO connection. It is useful when you want to change the
     * database configuration at runtime.
     *
     * @param array $config The new database configuration.
     * @return static The database instance.
     */
    public function resetConfig(array $config): self
    {
        unset($this->pdo);
        $this->config = $config;
        return $this;
    }

    /**
     * Retrieves or initializes the PDO instance.
     *
     * @return PDO The PDO connection instance.
     */
    public function getPdo(): PDO
    {
        if (!isset($this->pdo)) {
            $this->resetPdo();
        }

        return $this->pdo;
    }

    /**
     * Executes a raw SQL query with optional arguments.
     *
     * @param string $query The SQL query.
     * @param mixed ...$args Additional arguments for query execution.
     * @return PDOStatement|false The resulting statement or false on failure.
     */
    public function query(string $query, ...$args): false|PDOStatement
    {
        $started = microtime(true);
        $startedMemory = memory_get_usage(true);

        $result = $this->getPdo()->query($query, ...$args);

        $this->log($started, $query, $startedMemory);

        return $result;
    }

    /**
     * Prepares an SQL statement for execution with optional options.
     *
     * @param string $statement The SQL query to prepare.
     * @param array $options Options for statement preparation.
     * @return PDOStatement|false The prepared statement or false on failure.
     */
    public function prepare(string $statement, array $options = []): false|PDOStatement
    {
        return $this->getPdo()->prepare($statement, $options);
    }

    /**
     * Executes an SQL statement and returns the number of affected rows.
     *
     * @param string $statement The SQL statement to execute.
     * @return int|false The number of affected rows or false on failure.
     */
    public function exec(string $statement): int|false
    {
        $started = microtime(true);
        $startedMemory = memory_get_usage(true);

        $result = $this->getPdo()->exec($statement);

        $this->log($started, $statement, $startedMemory);

        return $result;
    }

    /**
     * Handles dynamic method calls, allowing direct PDO method calls on this class.
     *
     * @param string $name The name of the method to call.
     * @param array $args The arguments for the method call.
     * @return mixed The result of the PDO method call.
     */
    public function __call(string $name, array $args)
    {
        // call the macro if it exists.
        if (static::hasMacro($name)) {
            return $this->macroCall($name, $args);
        }

        return $this->getPdo()->$name(...$args);
    }

    /**
     * Handles dynamic static method calls, allowing direct QueryBuilder method calls on this class.
     *
     * @param string $name The name of the method to call.
     * @param array $arguments The arguments for the method call.
     * @return mixed The result of the QueryBuilder method call.
     */
    public static function __callStatic($name, $arguments)
    {
        // call the macro if it exists.
        if (static::hasMacro($name)) {
            return static::macroCallStatic($name, $arguments);
        }

        // Create a new QueryBuilder instance with the current context.
        $query = app(QueryBuilder::class);

        // Dynamically call the method on the QueryBuilder instance and return the result.
        return $query->$name(...$arguments);
    }

    /**
     * Initializes or resets the PDO connection using the provided configuration.
     *
     * @return self
     */
    public function resetPdo(): self
    {
        // Clear previous PDO connection if exists.
        unset($this->pdo);

        // Check if config is empty.
        if (empty($this->config)) {
            throw new InvalidDatabaseConfigException('Database configuration is empty.');
        }

        // Check if config has a default DSN else. create a new one. 
        $dsn = $this->config['dsn'] ?? $this->buildDsn();

        // Merge PDO default options with config.
        $options = $this->config['options'] ?? [];
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

        /** 
         * Create a new databse (PHP Data Object) connection.
         * 
         * learn more about pdo and drivers from:
         * @link https://www.php.net/manual/en/book.pdo.php
         */
        $this->pdo = new PDO(
            $dsn,
            $this->config['user'] ?? null,
            $this->config['password'] ?? null,
            $options
        );

        // If the driver is SQLite, enable foreign key support.
        if ($this->isSQLite()) {
            $this->pdo->exec('PRAGMA foreign_keys = ON;');
        }

        // Set the default collation for the database connection.
        if (isset($this->config['charset'], $this->config['collation']) && $this->isMySQL()) {
            $this->pdo->exec(
                sprintf("SET NAMES '%s' COLLATE '%s';", $this->config['charset'], $this->config['collation'])
            );
        }

        return $this;
    }

    /**
     * Builds the DSN (Data Source Name) string based on the configuration settings.
     *
     * @return string The constructed DSN string.
     */
    private function buildDsn(): string
    {
        return match ($this->getDriver()) {
            // create a sqlite data source name, sqlite.db filepath.
            'sqlite' => sprintf('sqlite:%s', $this->config['file']),

            /** create a server side data source name.
             * 
             * supported drivers: mysql, pgsql, cubrid, dblib, firebird, ibm, informix, sqlsrv, oci, odbc
             * @see https://www.php.net/manual/en/pdo.drivers.php
             **/
            default => sprintf(
                "%s:%s%s%s%s",
                $this->config['driver'],
                isset($this->config['host']) ?
                sprintf('host=%s;', $this->config['host']) : '',
                isset($this->config['port']) ?
                sprintf('port=%s;', $this->config['port']) : '',
                isset($this->config['name']) ?
                sprintf('dbname=%s;', $this->config['name']) : '',
                isset($this->config['charset']) ?
                sprintf('charset=%s;', $this->config['charset']) : '',
            ),
        };
    }

    /**
     * Logs the execution time of a SQL query.
     *
     * @param float $started The start time of the query execution.
     * @param string $sql The SQL query that was executed.
     * @param int $startedMemory The memory usage before the query execution.
     * @return void
     */
    private function log(float $started, string $sql, int $startedMemory): void
    {
        if (!env('debug')) {
            return; // Skip in non-debug mode.
        }

        $ended = microtime(true);
        $time = round(($ended - $started) * 1000, 6);

        event('app:db.queryExecuted', ['query' => $sql, 'time' => $time, 'memory_before' => $startedMemory]);
    }
}
