<?php

namespace Spark\Database\Schema;

use Spark\Database\Schema\Contracts\GrammarContract;
use Spark\Database\Schema\Exceptions\SqliteAlterFailedException;
use Spark\Database\Schema\Exceptions\InvalidForeignKeyException;
use Spark\Database\Schema\Exceptions\UnsupportedDatabaseDriverException;

/**
 * Class Grammar
 * Implements database schema grammar for various SQL drivers.
 * 
 * @package Spark\Database\Schema
 */
class Grammar implements GrammarContract
{
    /**
     * @var array $wrapper Character wrappers for SQL identifiers.
     */
    private array $wrapper;

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

        $this->wrapper = $this->getWrapper();
    }

    /**
     * Determine if the database driver is SQLite.
     *
     * @return bool True if SQLite, false otherwise.
     */
    public function isSQLite(): bool
    {
        return $this->isSQLite();
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
     * @param string|array $driver The driver(s) to check.
     * @return bool True if the current driver matches one of the given drivers, false otherwise.
     */
    public function isDriver(string|array $driver): bool
    {
        foreach ((array) $driver as $d) {
            if ($d === $this->driver) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wrap a value with database-specific characters.
     *
     * @param string $value The value to wrap.
     * @return string The wrapped value.
     */
    public function wrap(string $value): string
    {
        $maxLength = match ($this->driver) {
            'mysql' => 64,
            'pgsql' => 63,
            default => null
        };

        $value = $maxLength ? substr($value, 0, $maxLength) : $value;
        return $this->wrapper[0] . $value . $this->wrapper[1];
    }

    /**
     * Wrap a table name.
     *
     * @param string $table The table name.
     * @return string The wrapped table name.
     */
    public function wrapTable(string $table): string
    {
        if (str_contains($table, '.')) {
            return implode('.', array_map([$this, 'wrap'], explode('.', $table)));
        }

        return $this->wrap($table);
    }

    /**
     * Wrap a column name.
     *
     * @param string $column The column name.
     * @return string The wrapped column name.
     */
    public function wrapColumn(string $column): string
    {
        if (str_contains($column, '.')) {
            return implode('.', array_map([$this, 'wrap'], explode('.', $column)));
        }

        return $this->wrap($column);
    }

    /**
     * Convert an array of column names to a string.
     *
     * @param array $columns The column names.
     * @return string The column names as a comma-separated string.
     */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map([$this, 'wrapColumn'], $columns));
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
                'sqlite' => 'TEXT',
                'pgsql' => "VARCHAR({$parameters['length']})"
            ],
            'integer' => ['mysql' => 'INT', 'sqlite' => 'INTEGER', 'pgsql' => 'INTEGER'],
            'text' => ['mysql' => 'TEXT', 'sqlite' => 'TEXT', 'pgsql' => 'TEXT'],
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
                'mysql' => 'ENUM(' . $this->quoteEnumValues($parameters['allowed']) . ')',
                'sqlite' => 'TEXT CHECK(' . $this->wrapColumn($parameters['name']) .
                    ' IN (' . $this->quoteEnumValues($parameters['allowed']) . '))',
                'pgsql' => 'TEXT CHECK(' . $this->wrapColumn($parameters['name']) .
                    ' IN (' . $this->quoteEnumValues($parameters['allowed']) . '))',
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
            'after' => $this->isMySQL() ? "AFTER " . $this->wrapColumn($value) : '',
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
            $sql .= 'CONSTRAINT ' . $this->wrap($constraintName) . ' ';
        }

        $sql .= 'FOREIGN KEY (' . $this->columnize($fk->columns) . ') ';
        $sql .= 'REFERENCES ' . $this->wrapTable($fk->onTable);
        $sql .= ' (' . $this->columnize($fk->references) . ')';

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
        $columns = $this->columnize($index['columns']);
        $indexName = $this->generateIndexName($table, $index);
        $typePrefix = $type === 'UNIQUE' ? 'UNIQUE ' : '';

        // Database-specific syntax adjustments
        return match ($this->driver) {
            'pgsql' => "CREATE {$typePrefix}INDEX {$this->wrap($indexName)} ON "
            . $this->wrapTable($table) . " USING btree ({$columns});",
            default => "CREATE {$typePrefix}INDEX {$this->wrap($indexName)} ON "
            . $this->wrapTable($table) . " ({$columns});"
        };
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

        return "ALTER TABLE {$this->wrapTable($table)} " .
            implode(', ', array_map(fn($col) => "DROP COLUMN {$this->wrapColumn($col)}", $columns));
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

        return "ALTER TABLE {$this->wrapTable($table)} " .
            "DROP INDEX {$this->wrap($indexName)}";
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

        return "ALTER TABLE {$this->wrapTable($table)} " .
            "DROP CONSTRAINT {$this->wrap($constraintName)}";
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
        return match ($this->driver) {
            'mysql', 'pgsql' => "ALTER TABLE {$this->wrapTable($table)} " .
            "RENAME COLUMN {$this->wrapColumn($from)} TO {$this->wrapColumn($to)}",
            'sqlite' => $this->compileSQLiteRename($table, $from, $to),
            default => ''
        };
    }

    /**
     * Compile a column rename operation for SQLite.
     *
     * SQLite does not support renaming columns directly, so
     * this method throws an exception to indicate that the
     * operation is not supported.
     *
     * @param string $table
     * @param string $from
     * @param string $to
     * @return string
     * @throws SqliteAlterFailedException
     */
    private function compileSQLiteRename(string $table, string $from, string $to): string
    {
        // SQLite only supports renaming through table recreation
        // This would require more complex implementation
        throw new SqliteAlterFailedException('SQLite requires special handling for column renames');
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

    /**
     * Quote enum values for SQL statements.
     *
     * @param array $values The values to quote.
     * @return string The quoted values as a comma-separated string.
     */
    private function quoteEnumValues(array $values): string
    {
        $escaped = array_map(fn($v) => "'" . addslashes($v) . "'", $values);
        return implode(',', $escaped);
    }

    /**
     * Get character wrappers for the current database driver.
     *
     * @return array The character wrappers.
     */
    private function getWrapper(): array
    {
        return ['mysql' => ['`', '`'], 'sqlite' => ['"', '"'], 'pgsql' => ['"', '"']][$this->driver];
    }
}
