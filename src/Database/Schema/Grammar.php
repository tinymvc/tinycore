<?php

namespace Spark\Database\Schema;

use Spark\Database\Schema\Contracts\GrammarContract;
use Spark\Database\Schema\Contracts\WrapperContract;
use Spark\Database\Schema\Exceptions\SqliteAlterFailedException;
use Spark\Database\Schema\Exceptions\InvalidForeignKeyException;
use Spark\Database\Schema\Exceptions\UnsupportedDatabaseDriverException;
use Spark\Support\Traits\Macroable;
use function func_get_args;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;

/**
 * Class Grammar
 * Implements database schema grammar for various SQL drivers.
 * 
 * @package Spark\Database\Schema
 */
class Grammar implements GrammarContract
{
    use Macroable;

    /**
     * @var WrapperContract $wrapper The wrapper instance for SQL identifiers.
     */
    private WrapperContract $wrapper;

    /**
     * Constructor.
     *
     * @param string $driver The database driver in use.
     * @throws UnsupportedDatabaseDriverException If the driver is not supported.
     */
    public function __construct(private string $driver)
    {
        if (!in_array(strtolower($driver), ['mysql', 'sqlite', 'pgsql'])) {
            throw new UnsupportedDatabaseDriverException(
                'Unsupported database driver. Supported drivers: mysql, sqlite, pgsql.'
            );
        }

        $this->wrapper = new Wrapper($driver);
    }

    /**
     * Determine if the database driver is SQLite.
     *
     * @return bool True if SQLite, false otherwise.
     */
    public function isSQLite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /**
     * Determine if the database driver is PostgreSQL.
     *
     * @return bool True if PostgreSQL, false otherwise.
     */
    public function isPostgreSQL(): bool
    {
        return $this->driver === 'pgsql';
    }

    /**
     * Determine if the database driver is MySQL.
     *
     * @return bool True if MySQL, false otherwise.
     */

    public function isMySQL(): bool
    {
        return $this->driver === 'mysql';
    }

    /**
     * Retrieves the database driver name.
     *
     * @return string The database driver name.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Checks if the database driver is one of the given drivers.
     *
     * @param string|array $drivers The driver(s) to check.
     * @return bool True if the current driver matches one of the given drivers, false otherwise.
     */
    public function isDriver(string|array $drivers): bool
    {
        $drivers = is_array($drivers) ? $drivers : func_get_args();
        foreach ($drivers as $d) {
            if ($d === $this->driver) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieves the wrapper instance.
     *
     * @return WrapperContract The wrapper instance.
     */
    public function getWrapper(): WrapperContract
    {
        return $this->wrapper;
    }

    /**
     * Map a column type to a database-specific type.
     *
     * @param string $type The column type.
     * @param array $parameters Additional parameters for the type.
     * @return string The database-specific column type.
     */
    public function mapColumnType(string $type, array $parameters): string
    {
        $map = match ($type) {
            // Mapping of general types to specific SQL types per driver.
            'id' => ['mysql' => 'INT AUTO_INCREMENT', 'sqlite' => 'INTEGER', 'pgsql' => 'SERIAL'],
            'string' => [
                'mysql' => "VARCHAR({$parameters['length']})",
                'sqlite' => 'TEXT COLLATE NOCASE',
                'pgsql' => "VARCHAR({$parameters['length']})"
            ],
            'integer' => ['mysql' => 'INT', 'sqlite' => 'INTEGER', 'pgsql' => 'INTEGER'],
            'tinyInteger' => ['mysql' => 'TINYINT', 'sqlite' => 'INTEGER', 'pgsql' => 'SMALLINT'],
            'text' => ['mysql' => 'TEXT', 'sqlite' => 'TEXT COLLATE NOCASE', 'pgsql' => 'TEXT'],
            'timestamp' => ['mysql' => 'TIMESTAMP', 'sqlite' => 'DATETIME', 'pgsql' => 'TIMESTAMP'],
            'boolean' => ['mysql' => 'TINYINT(1)', 'sqlite' => 'INTEGER', 'pgsql' => 'BOOLEAN'],
            'bigIncrements' => [
                'mysql' => 'BIGINT UNSIGNED AUTO_INCREMENT',
                'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'pgsql' => 'BIGSERIAL'
            ],
            'bigInteger' => ['mysql' => 'BIGINT', 'sqlite' => 'INTEGER', 'pgsql' => 'BIGINT'],
            'decimal' => [
                'mysql' => "DECIMAL({$parameters['precision']}, {$parameters['scale']})",
                'sqlite' => 'NUMERIC',
                'pgsql' => "DECIMAL({$parameters['precision']}, {$parameters['scale']})"
            ],
            'double' => [
                'mysql' => "DOUBLE({$parameters['precision']}, {$parameters['scale']})",
                'sqlite' => 'REAL',
                'pgsql' => 'DOUBLE PRECISION'
            ],
            'float' => ['mysql' => "FLOAT({$parameters['precision']})", 'sqlite' => 'REAL', 'pgsql' => 'REAL'],
            'char' => ['mysql' => "CHAR({$parameters['length']})", 'sqlite' => 'TEXT', 'pgsql' => "CHAR({$parameters['length']})"],
            'enum' => [
                'mysql' => 'ENUM(' . $this->wrapper->quoteEnumValues($parameters['allowed']) . ')',
                'sqlite' => 'TEXT CHECK(' . $this->wrapper->wrapColumn($parameters['name']) .
                    ' IN (' . $this->wrapper->quoteEnumValues($parameters['allowed']) . '))',
                'pgsql' => 'TEXT CHECK(' . $this->wrapper->wrapColumn($parameters['name']) .
                    ' IN (' . $this->wrapper->quoteEnumValues($parameters['allowed']) . '))',
            ],
            'longText' => ['mysql' => 'LONGTEXT', 'sqlite' => 'TEXT', 'pgsql' => 'TEXT'],
            'json' => ['mysql' => 'JSON', 'sqlite' => 'TEXT', 'pgsql' => 'JSON'],
            'date' => ['mysql' => 'DATE', 'sqlite' => 'TEXT', 'pgsql' => 'DATE'],
            'dateTime' => [
                'mysql' => "DATETIME(" . ($parameters['precision'] ?? 0) . ")",
                'sqlite' => 'TEXT',
                'pgsql' => 'TIMESTAMP' . (isset($parameters['precision']) ? "({$parameters['precision']})" : '')
            ],
            'time' => [
                'mysql' => "TIME({$parameters['precision']})",
                'sqlite' => 'TEXT',
                'pgsql' => 'TIME' . ($parameters['precision'] ? "({$parameters['precision']})" : '')
            ],
            'binary' => ['mysql' => 'BLOB', 'sqlite' => 'BLOB', 'pgsql' => 'BYTEA'],
            'uuid' => ['mysql' => 'CHAR(36)', 'sqlite' => 'TEXT', 'pgsql' => 'UUID'],
            default => ['mysql' => 'TEXT', 'sqlite' => 'TEXT', 'pgsql' => 'TEXT'],
        };

        return $map[$this->driver] ?? 'TEXT';
    }

    /**
     * Map a column modifier to a database-specific clause.
     *
     * @param string $modifier The modifier name.
     * @param mixed $value The value associated with the modifier.
     * @return string The database-specific clause.
     */
    public function mapModifier(string $modifier, $value = null): string
    {
        return match ($modifier) {
            'nullable' => 'NULL',
            'required' => 'NOT NULL',
            'unique' => 'UNIQUE',
            'unsigned' => $this->isMySQL() ? 'UNSIGNED' : '',
            'auto_increment' => match ($this->driver) {
                    'mysql' => 'AUTO_INCREMENT',
                    'sqlite' => 'AUTOINCREMENT',
                    default => ''
                },
            'default' => match (true) {
                    is_string($value) => "DEFAULT '$value'",
                    is_bool($value) && $this->isPostgreSQL() => "DEFAULT " . ($value ? 'TRUE' : 'FALSE'),
                    is_bool($value) => "DEFAULT " . ($value ? 1 : 0),
                    default => "DEFAULT $value"
                },
            'after' => $this->isMySQL() ? "AFTER " . $this->wrapper->wrapColumn($value) : '',
            'charset' => $this->isMySQL() ? "CHARACTER SET $value" : '',
            'collation' => $this->isMySQL() ? "COLLATE $value" : '',
            'comment' => $this->isMySQL() ? "COMMENT '$value'" : '',
            'on_update_current_timestamp' => $this->isMySQL() ? 'ON UPDATE CURRENT_TIMESTAMP' : '',
            'default_current_timestamp' => 'DEFAULT CURRENT_TIMESTAMP',
            default => ''
        };
    }

    /**
     * Compile a foreign key constraint.
     *
     * @param ForeignKeyConstraint $fk The foreign key constraint object.
     * @return string The SQL for the foreign key constraint.
     */
    public function compileForeignKey(ForeignKeyConstraint $fk): string
    {
        // Validate required parameters
        if (!isset($fk->onTable, $fk->columns, $fk->references)) {
            throw new InvalidForeignKeyException(
                'Foreign key constraint requires onTable, columns, and references properties'
            );
        }

        $sql = '';

        // MySQL/PostgreSQL: Use named constraints
        if (!$this->isMySQL()) {
            $constraintName = "fk_{$fk->onTable}_" . implode('_', $fk->columns);
            $sql .= 'CONSTRAINT ' . $this->wrapper->wrap($constraintName) . ' ';
        }

        $sql .= 'FOREIGN KEY (' . $this->wrapper->columnize($fk->columns) . ') ';
        $sql .= 'REFERENCES ' . $this->wrapper->wrapTable($fk->onTable);
        $sql .= ' (' . $this->wrapper->columnize($fk->references) . ')';

        // SQLite only supports RESTRICT, NO ACTION, SET NULL, CASCADE
        $allowedActions = ['RESTRICT', 'NO ACTION', 'SET NULL', 'CASCADE'];

        if (isset($fk->onDelete)) {
            $action = strtoupper($fk->onDelete);
            $sql .= " ON DELETE " . ($this->isSQLite() && !in_array($action, $allowedActions)
                ? 'NO ACTION' : $action
            );
        }

        if (isset($fk->onUpdate)) {
            $action = strtoupper($fk->onUpdate);
            $sql .= " ON UPDATE " . ($this->isSQLite() && !in_array($action, $allowedActions)
                ? 'NO ACTION' : $action
            );
        }

        return $sql;
    }

    /**
     * Compile an index statement.
     *
     * @param string $table The table name.
     * @param array $index The index details.
     * @return string The SQL for the index statement.
     */
    public function compileIndex(string $table, array $index): string
    {
        $type = strtoupper($index['type']);
        $columns = $this->wrapper->columnize($index['columns']);
        $indexName = $this->generateIndexName($table, $index);
        $typePrefix = $type === 'UNIQUE' ? 'UNIQUE ' : '';

        // Database-specific syntax adjustments
        return match ($this->driver) {
            'pgsql' => "CREATE {$typePrefix}INDEX {$this->wrapper->wrap($indexName)} ON "
            . $this->wrapper->wrapTable($table) . " USING btree ({$columns});",
            default => "CREATE {$typePrefix}INDEX {$this->wrapper->wrap($indexName)} ON "
            . $this->wrapper->wrapTable($table) . " ({$columns});"
        };
    }

    /**
     * Compile an ADD COLUMN statement.
     *
     * @param string $table The table name.
     * @param Column $column The column to add.
     * @return string The SQL for the ADD COLUMN statement.
     */
    public function compileAddColumn(string $table, Column $column): string
    {
        return sprintf("ALTER TABLE %s ADD COLUMN %s", $this->wrapper->wrapTable($table), $column->toSql());
    }

    /**
     * Compile an ADD FOREIGN KEY statement for ALTER TABLE.
     *
     * @param string $table The table name.
     * @param ForeignKeyConstraint $fk The foreign key constraint object.
     * @return string The SQL for the ADD FOREIGN KEY statement.
     */
    public function compileAddForeignKey(string $table, ForeignKeyConstraint $fk): string
    {
        // SQLite doesn't support adding foreign keys to existing tables
        if ($this->isSQLite()) {
            throw new SqliteAlterFailedException('SQLite does not support adding foreign keys to existing tables');
        }

        // Validate required parameters
        if (!isset($fk->onTable, $fk->columns, $fk->references)) {
            throw new InvalidForeignKeyException(
                'Foreign key constraint requires onTable, columns, and references properties'
            );
        }

        $constraintName = "fk_{$fk->onTable}_" . implode('_', $fk->columns);
        $sql = "ALTER TABLE " . $this->wrapper->wrapTable($table) . " ADD CONSTRAINT ";
        $sql .= $this->wrapper->wrap($constraintName) . ' ';
        $sql .= 'FOREIGN KEY (' . $this->wrapper->columnize($fk->columns) . ') ';
        $sql .= 'REFERENCES ' . $this->wrapper->wrapTable($fk->onTable);
        $sql .= ' (' . $this->wrapper->columnize($fk->references) . ')';

        if (isset($fk->onDelete)) {
            $sql .= " ON DELETE " . strtoupper($fk->onDelete);
        }

        if (isset($fk->onUpdate)) {
            $sql .= " ON UPDATE " . strtoupper($fk->onUpdate);
        }

        return $sql;
    }

    /**
     * Compile a drop statement.
     *
     * @param string $table The table name.
     * @param array $drop The drop details.
     * @return string The SQL for the drop statement.
     */
    public function compileDrop(string $table, array $drop): string
    {
        return match ($drop['type']) {
            'column' => $this->compileDropColumn($table, $drop['names']),
            'index', 'unique' => $this->compileDropIndex($table, $drop),
            'foreign' => $this->compileDropForeign($table, $drop),
            default => '',
        };
    }

    /**
     * Compile a DROP COLUMN statement.
     *
     * @param string $table The table name.
     * @param array $columns The column names.
     * @return string The SQL for the DROP COLUMN statement.
     *
     * @throws SqliteAlterFailedException
     */
    public function compileDropColumn(string $table, array $columns): string
    {
        // SQLite doesn't support DROP COLUMN
        if ($this->isSQLite()) {
            throw new SqliteAlterFailedException('SQLite does not support dropping columns');
        }

        return "ALTER TABLE {$this->wrapper->wrapTable($table)} " .
            implode(', ', array_map(fn($col) => "DROP COLUMN {$this->wrapper->wrapColumn($col)}", $columns));
    }

    /**
     * Compile a DROP INDEX statement.
     *
     * @param string $table The table name.
     * @param array $index The index details.
     * @return string The compiled SQL statement.
     */
    public function compileDropIndex(string $table, array $index): string
    {
        $indexName = $this->generateIndexName($table, ['type' => $index['type'], 'columns' => $index['columns']]);

        return "ALTER TABLE {$this->wrapper->wrapTable($table)} " .
            "DROP INDEX {$this->wrapper->wrap($indexName)}";
    }

    /**
     * Compile a DROP FOREIGN KEY statement.
     *
     * @param string $table The table name.
     * @param array $fk The foreign key details.
     * @return string The compiled SQL statement.
     * @throws SqliteAlterFailedException If the database driver is SQLite.
     */
    public function compileDropForeign(string $table, array $fk): string
    {
        if ($this->isSQLite()) {
            throw new SqliteAlterFailedException('SQLite does not support foreign key operations');
        }

        $constraintName = "fk_{$fk['table']}_" . implode('_', $fk['columns']);

        return "ALTER TABLE {$this->wrapper->wrapTable($table)} " .
            "DROP CONSTRAINT {$this->wrapper->wrap($constraintName)}";
    }

    /**
     * Compile a column rename operation.
     *
     * This method will compile a column rename operation for the
     * given table. The operation will be adapted according to the
     * database driver.
     *
     * @param string $table
     *   The table name.
     * @param string $from
     *   The old column name.
     * @param string $to
     *   The new column name.
     *
     * @return string
     *   The SQL for the column rename operation.
     */
    public function compileRenameColumn(string $table, string $from, string $to): string
    {
        if ($this->isSQLite()) {
            throw new SqliteAlterFailedException('SQLite requires special handling for column renames');
        }

        return "ALTER TABLE {$this->wrapper->wrapTable($table)} " .
            "RENAME COLUMN {$this->wrapper->wrapColumn($from)} TO {$this->wrapper->wrapColumn($to)}";
    }

    /**
     * Generate an index name for the given table and index.
     *
     * Generates an index name in the format of "table_type_column1_column2_..."
     * for MySQL and PostgreSQL, and "idx_table_column1_column2_..." for SQLite.
     *
     * @param string $table The table name.
     * @param array $index The index details.
     * @return string The generated index name.
     */
    private function generateIndexName(string $table, array $index): string
    {
        $prefix = match ($this->driver) {
            'pgsql', 'mysql' => "{$table}_{$index['type']}_",
            'sqlite' => "idx_{$table}_"
        };

        $columnPart = implode('_', $index['columns']);
        $name = "$prefix$columnPart";

        // Trim to database length limits
        return substr($name, 0, match ($this->driver) {
            'mysql' => 64,
            'pgsql' => 63,
            default => 255
        });
    }
}
