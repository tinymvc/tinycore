<?php

namespace Spark\Database\Schema;

use Closure;
use PDO;
use Spark\Database\DB;
use Spark\Database\Schema\Contracts\SchemaContract;
use Spark\Support\Traits\Macroable;
use function count;
use function in_array;
use function sprintf;

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
    use Macroable;

    /** @var PDO The PDO connection.*/
    private static PDO $connection;

    /** @var Grammar The database grammar. */
    private static ?Grammar $grammar = null;

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

        self::execute($blueprint->compileCreateStatements());
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
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        self::execute($blueprint->compileAlterStatements());
    }

    /**
     * Drops a table from the database.
     *
     * @param string $table The name of the table to be dropped.
     */
    public static function drop(string $table)
    {
        $sql = "DROP TABLE " . self::getGrammar()->getWrapper()->wrapTable($table);
        self::execute($sql);
    }

    /**
     * Drops a table from the database if it exists.
     *
     * @param string $table The name of the table to be dropped.
     */
    public static function dropIfExists(string $table): void
    {
        $sql = "DROP TABLE IF EXISTS " . self::getGrammar()->getWrapper()->wrapTable($table);
        self::execute($sql);
    }

    /**
     * Rename a table.
     *
     * @param string $from The current table name.
     * @param string $to The new table name.
     * @return void
     */
    public static function rename(string $from, string $to): void
    {
        $grammar = self::getGrammar();
        $wrapper = $grammar->getWrapper();
        if (!$grammar->isMySQL() && str_contains($to, '.')) {
            [$toSchema] = self::tableMetadata($to);
            [$fromSchema] = self::tableMetadata($from);
            $fromSchema ??= $grammar->isSQLite() ? 'main' : self::getConnection()->query('SELECT current_schema()')->fetchColumn();
            if ($toSchema !== $fromSchema) {
                throw new \InvalidArgumentException('Renaming a table cannot move it to another schema.');
            }
            $to = substr($to, strrpos($to, '.') + 1);
        }

        self::execute(sprintf(
            'ALTER TABLE %s RENAME TO %s',
            $wrapper->wrapTable($from),
            $wrapper->wrapTable($to)
        ));
    }

    /**
     * Determine if a table exists.
     *
     * @param string $table The table name.
     * @return bool
     */
    public static function hasTable(string $table): bool
    {
        $grammar = self::getGrammar();
        $pdo = self::getConnection();
        [$schema, $table] = self::tableMetadata($table);

        $sql = match ($grammar->getDriver()) {
            DB::DRIVER_SQLITE => 'SELECT 1 FROM ' . $grammar->getWrapper()->wrap($schema ?? 'main') . ".sqlite_master WHERE type = 'table' AND name = ?",
            DB::DRIVER_PGSQL => 'SELECT 1 FROM information_schema.tables WHERE table_schema = ' . ($schema === null ? 'current_schema()' : '?') . ' AND table_name = ?',
            default => 'SELECT 1 FROM information_schema.tables WHERE table_schema = ' . ($schema === null ? 'DATABASE()' : '?') . ' AND table_name = ?',
        };

        $statement = $pdo->prepare($sql);
        $statement->execute($schema !== null && !$grammar->isSQLite() ? [$schema, $table] : [$table]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * Determine if a table contains a column.
     *
     * @param string $table The table name.
     * @param string $column The column name.
     * @return bool
     */
    public static function hasColumn(string $table, string $column): bool
    {
        return in_array($column, self::getColumnListing($table), true);
    }

    /**
     * Determine if a table contains all of the given columns.
     *
     * @param string $table The table name.
     * @param array $columns The column names.
     * @return bool
     */
    public static function hasColumns(string $table, array $columns): bool
    {
        $existing = self::getColumnListing($table);

        foreach ($columns as $column) {
            if (!in_array($column, $existing, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the column listing for a table.
     *
     * @param string $table The table name.
     * @return array
     */
    public static function getColumnListing(string $table): array
    {
        $grammar = self::getGrammar();
        $pdo = self::getConnection();
        [$schema, $table] = self::tableMetadata($table);

        if ($grammar->isSQLite()) {
            $wrapper = $grammar->getWrapper();
            $statement = $pdo->query('PRAGMA ' . $wrapper->wrap($schema ?? 'main') . '.table_info(' . $wrapper->wrap($table) . ')');
            return array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'name');
        }

        $sql = match ($grammar->getDriver()) {
            DB::DRIVER_PGSQL => 'SELECT column_name FROM information_schema.columns WHERE table_schema = ' . ($schema === null ? 'current_schema()' : '?') . ' AND table_name = ? ORDER BY ordinal_position',
            default => 'SELECT column_name FROM information_schema.columns WHERE table_schema = ' . ($schema === null ? 'DATABASE()' : '?') . ' AND table_name = ? ORDER BY ordinal_position',
        };

        $statement = $pdo->prepare($sql);
        $statement->execute($schema === null ? [$table] : [$schema, $table]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Resolve the optional namespace and physical name used by metadata queries. */
    private static function tableMetadata(string $table): array
    {
        $wrapper = self::getGrammar()->getWrapper();
        $parts = explode('.', $wrapper->tableName($table));
        foreach ($parts as $part) {
            $wrapper->wrap($part);
        }
        if (count($parts) > 2) {
            throw new \InvalidArgumentException('Expected a table name or schema.table.');
        }
        return count($parts) === 2 ? $parts : [null, $parts[0]];
    }

    /**
     * Enable foreign key constraints.
     *
     * @return bool
     */
    public static function enableForeignKeyConstraints(): bool
    {
        return self::setForeignKeyConstraints(true);
    }

    /**
     * Disable foreign key constraints.
     *
     * @return bool
     */
    public static function disableForeignKeyConstraints(): bool
    {
        return self::setForeignKeyConstraints(false);
    }

    /**
     * Run a callback with foreign key constraints disabled.
     *
     * @param Closure $callback
     * @return mixed
     */
    public static function withoutForeignKeyConstraints(Closure $callback): mixed
    {
        $pdo = self::getConnection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === DB::DRIVER_PGSQL) {
            throw new \LogicException('PostgreSQL cannot disable foreign keys this way. Use explicitly DEFERRABLE constraints inside a transaction.');
        }
        $enabled = (bool) $pdo->query($driver === DB::DRIVER_SQLITE ? 'PRAGMA foreign_keys' : 'SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
        self::disableForeignKeyConstraints();
        try {
            return $callback();
        } finally {
            self::setForeignKeyConstraints($enabled);
        }
    }

    /**
     * Executes a raw SQL statement against the database.
     *
     * This method runs the given SQL command using the current PDO connection.
     * It is typically used for executing DDL statements such as CREATE, DROP, or ALTER.
     *
     * @param string $sql The raw SQL statement to execute.
     */
    public static function execute(string|array $sql): void
    {
        $pdo = self::getConnection();

        foreach ((array) $sql as $statement) {
            $statement = trim($statement);

            if ($statement === '') {
                continue;
            }

            $pdo->exec($statement);
        }
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
        $connection = get(DB::class)->getPdo();
        if (!isset(self::$connection) || self::$connection !== $connection) {
            self::$connection = $connection;
            self::$grammar = null;
        }
        return $connection;
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
        self::getConnection();
        return self::$grammar ??= new Grammar(
            self::getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME),
            get(DB::class)->getTablePrefix()
        );
    }

    /**
     * Toggle foreign key constraints for the current connection.
     *
     * @param bool $enabled
     * @return bool
     */
    private static function setForeignKeyConstraints(bool $enabled): bool
    {
        $grammar = self::getGrammar();

        if ($grammar->getDriver() === DB::DRIVER_PGSQL) {
            throw new \LogicException('PostgreSQL does not support toggling all foreign keys. Use explicitly DEFERRABLE constraints inside a transaction.');
        }
        if ($grammar->isSQLite() && self::getConnection()->inTransaction()) {
            throw new \LogicException('SQLite foreign keys must be toggled before starting a transaction.');
        }
        $sql = match ($grammar->getDriver()) {
            DB::DRIVER_SQLITE => 'PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF'),
            default => 'SET FOREIGN_KEY_CHECKS=' . ($enabled ? '1' : '0'),
        };

        return self::getConnection()->exec($sql) !== false;
    }
}
