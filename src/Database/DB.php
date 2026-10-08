<?php

namespace Spark\Database;

use PDO;
use PDOStatement;
use Spark\Database\Contracts\DBContract;
use Spark\Database\Exceptions\InvalidDatabaseConfigException;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function dirname;
use function func_get_args;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function mkdir;
use function sprintf;

/**
 * Class Database
 * 
 * Manages database connections and provides query execution and statement preparation.
 * 
 * @mixin QueryBuilder
 * 
 * @method bool beginTransaction()
 * @method bool commit()
 * @method bool rollBack()
 * @method bool inTransaction()
 * @method bool|string lastInsertId()
 * @method bool|string quote(string $string, int $type = PDO::PARAM_STR)
 * 
 * @method static array raw(string $sql, array $bindings = [])
 * @method static QueryBuilder where(null|string|array|Closure $column = null, mixed $operator = null, mixed $value = null, ?string $boolean = null, bool $not = false)
 * @method static QueryBuilder whereRaw(string $sql, string|array $bindings = [], string $boolean = 'AND')
 * @method static QueryBuilder whereIn(string $column, array $values)
 * @method static QueryBuilder when(mixed $value, callable $callback)
 * @method static QueryBuilder unless(mixed $value, callable $callback)
 * @method static QueryBuilder table(string $table, ?string $alias = null)
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
    use Conditionable, Macroable {
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

    /** @var string The default database driver. */
    public const DEFAULT_DRIVER = 'mysql';

    /**
     * PDO driver names that may double as connection names.
     *
     * When a connection entry omits `driver`, a connection name from this list
     * (for example `DB_CONNECTION=pgsql`) is used as the driver.
     *
     * @var string[]
     */
    private const KNOWN_DRIVERS = [
        'mysql',
        'pgsql',
        'sqlite',
        'sqlsrv',
        'oci',
        'odbc',
        'firebird',
        'ibm',
        'informix',
        'dblib',
        'cubrid',
    ];

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

        $this->config = $this->resolveConnectionConfig((array) $config);
    }

    /**
     * Creates a new database connection instance.
     *
     * @param string|array $config A connection name from `database.connections` or a complete configuration array.
     * @return self A new instance of the DB class.
     */
    public static function connection(string|array $config = []): self
    {
        if (empty($config)) {
            return app(DB::class); // Return the default connection instance if no config is provided.
        }

        if (is_string($config)) {
            $name = $config;
            $default = self::defaultConnectionName($databaseConfig = (array) config('database', []));

            if ($name === $default) {
                return app(DB::class); // Return the default connection instance if the name matches the default.
            }

            $config = config("database.connections.$name");
            if (!is_array($config)) {
                throw new InvalidDatabaseConfigException("Undefined database connection: $name");
            }

            // Aliases inherit the resolved driver, not the default connection's name.
            if (!isset($config['driver'])) {
                $config['driver'] = in_array(strtolower($name), self::KNOWN_DRIVERS, true)
                    ? strtolower($name)
                    : (new self($databaseConfig))->getDriver();
            }
        }

        return new self($config);
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
        return strtolower((string) ($this->config['driver'] ??= self::DEFAULT_DRIVER));
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
     * @param array|string $driver The driver to check against the current driver.
     * @return bool True if the current driver matches the specified driver, false otherwise.
     */
    public function isDriver(array|string $driver): bool
    {
        $driver = is_array($driver) ? $driver : func_get_args();
        return in_array($this->getDriver(), $driver);
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
    public function reset(array $config = []): self
    {
        if (empty($config)) {
            $config = config('database');
        }

        unset($this->pdo);
        $this->config = $this->resolveConnectionConfig($config);
        return $this;
    }

    /**
     * Resolves config/database.php connection shapes into a single PDO config.
     *
     * `default` names the connection. If no entry has that name and the name is a
     * PDO driver (for example `DB_CONNECTION=mysql`), the `default` entry is used
     * with that driver. The selected entry is merged over any top-level options.
     */
    private function resolveConnectionConfig(array $config): array
    {
        if (is_array($config['connections'] ?? null)) {
            $connections = $config['connections'];
            $name = self::defaultConnectionName($config);

            if (is_array($connections[$name] ?? null)) {
                $connection = $connections[$name];
            } elseif (in_array(strtolower($name), self::KNOWN_DRIVERS, true)) {
                $connection = is_array($connections['default'] ?? null) ? $connections['default'] : [];
            } else {
                throw new InvalidDatabaseConfigException("Undefined database connection: $name");
            }

            $base = $config;
            unset($base['connections'], $base['default']);

            $config = [...$base, ...$connection];
            $config['driver'] = $connection['driver'] ?? self::guessDriver($name, (string) ($base['driver'] ?? ''));
        }

        $config['driver'] ??= self::DEFAULT_DRIVER; // Set default driver if not provided.
        $config['driver'] = strtolower((string) $config['driver']);

        if (isset($config['username']) && !isset($config['user'])) {
            $config['user'] = $config['username'];
        }

        if (isset($config['database']) && !isset($config['name']) && ($config['driver'] ?? null) !== 'sqlite') {
            $config['name'] = $config['database'];
        }

        if (isset($config['file']) && !isset($config['database']) && ($config['driver'] ?? null) === 'sqlite') {
            $config['database'] = $config['file'];
        }

        if (isset($config['path']) && !isset($config['database']) && ($config['driver'] ?? null) === 'sqlite') {
            $config['database'] = $config['path'];
        }

        return $config;
    }

    /**
     * Returns the default connection name from a config/database.php array.
     */
    private static function defaultConnectionName(array $config): string
    {
        foreach (['default', 'default_connection', 'driver'] as $key) {
            if (is_string($config[$key] ?? null) && $config[$key] !== '') {
                return $config[$key];
            }
        }

        return self::DEFAULT_DRIVER;
    }

    /**
     * Returns the first candidate that is a known PDO driver name, or the default driver.
     */
    private static function guessDriver(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = strtolower($candidate);
            if (in_array($candidate, self::KNOWN_DRIVERS, true)) {
                return $candidate;
            }
        }

        return self::DEFAULT_DRIVER;
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
     * Prepares and executes an SQL statement with optional parameters and options.
     *
     * @param string $statement The SQL statement to execute.
     * @param array $params Parameters to bind to the SQL statement.
     * @param array $options Options for statement preparation.
     * @return bool True on success, false on failure.
     */
    public function statement(string $statement, array $params = [], array $options = []): bool
    {
        $started = microtime(true);
        $startedMemory = memory_get_usage(true);

        $result = $this->getPdo()
            ->prepare($statement, $options)
            ->execute($params);

        $this->log($started, $statement, $startedMemory);

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
     * Execute a callback within a transaction.
     *
     * @param callable $callback The callback, receiving this connection as its argument.
     * @return mixed The result of the callback.
     *
     * @throws \Throwable Rethrows any exception thrown within the transaction.
     */
    public function transaction(callable $callback): mixed
    {
        $savepoint = 'spark_' . bin2hex(random_bytes(8));

        $pdo = $this->getPdo(); // Get the PDO instance for transaction management.

        $nested = $pdo->inTransaction();
        $nested ? $pdo->exec("SAVEPOINT $savepoint") : $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $nested ? $pdo->exec("RELEASE SAVEPOINT $savepoint") : $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            // A callback or DDL may already have ended the transaction. Preserve its error.
            try {
                if ($pdo->inTransaction()) {
                    if ($nested) {
                        $pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");
                        $pdo->exec("RELEASE SAVEPOINT $savepoint");
                    } else {
                        $pdo->rollBack();
                    }
                }
            } catch (\Throwable) {
                // The original failure remains the actionable exception.
            }
            throw $e;
        }
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

        $query = new QueryBuilder($this);

        if (method_exists($query, $name)) {
            return $query->$name(...$args);
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

        if ($this->isSQLite()) {
            $this->ensureSqliteDirectory();
        }

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

        // Set the default timezone for the database connection.
        if (isset($this->config['timezone']) && $this->isMySQL()) {
            $this->pdo->exec(sprintf("SET time_zone = '%s';", $this->config['timezone']));
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
        return match ($driver = $this->getDriver()) {
            // create a sqlite data source name, sqlite.db filepath.
            'sqlite' => sprintf('sqlite:%s', $this->config['database'] ?? $this->config['path'] ?? $this->config['file'] ?? ':memory:'),

            /** create a server side data source name.
             * 
             * supported drivers: mysql, pgsql, cubrid, dblib, firebird, ibm, informix, sqlsrv, oci, odbc
             * @see https://www.php.net/manual/en/pdo.drivers.php
             **/
            default => sprintf(
                "$driver:%s%s%s%s",
                isset($this->config['host']) ?
                sprintf('host=%s;', $this->config['host']) : '',
                isset($this->config['port']) ?
                sprintf('port=%s;', $this->config['port']) : '',
                isset($this->config['name']) ?
                sprintf('dbname=%s;', $this->config['name']) : '',
                isset($this->config['charset']) && $driver === 'mysql' ?
                sprintf('charset=%s;', $this->config['charset']) : '',
            ),
        };
    }

    /**
     * Creates the parent directory for file-backed SQLite connections.
     */
    private function ensureSqliteDirectory(): void
    {
        $database = (string) ($this->config['database'] ?? $this->config['path'] ?? $this->config['file'] ?? '');
        if ($database === '' || $database === ':memory:') {
            return;
        }

        $directory = dirname($database);
        if ($directory !== '' && !is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
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
        if (!is_debug_mode()) {
            return; // Skip in non-debug mode.
        }

        $ended = microtime(true);
        $time = round(($ended - $started) * 1000, 6);

        event('app:db.queryExecuted', ['query' => $sql, 'time' => $time, 'memory_before' => $startedMemory]);
    }
}
