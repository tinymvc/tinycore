<?php

namespace Spark\Database\Schema;

use Spark\Database\Contracts\DatabaseDriver;
use Spark\Database\Expression;
use Spark\Database\Schema\Contracts\WrapperContract;
use function array_slice;
use function is_string;
use function strlen;

class Wrapper implements WrapperContract, DatabaseDriver
{
    private array $aliases = [];

    private array $qualifiedTables = [];

    /**
     * @var array $wrapper Character wrappers for SQL identifiers.
     */
    public array $wrapper;

    /**
     * @var string $driver The database driver.
     */
    public function __construct(private string $driver, private string $tablePrefix = '')
    {
        self::validateTablePrefix($tablePrefix);
        $this->wrapper = [self::DRIVER_MYSQL => ['`', '`'], self::DRIVER_SQLITE => ['"', '"'], self::DRIVER_PGSQL => ['"', '"']][$driver];
    }

    /**
     * Quote enum values for SQL statements.
     *
     * @param array $values The values to quote.
     * @return string The quoted values as a comma-separated string.
     */
    public function quoteEnumValues(array $values): string
    {
        $escaped = array_map(fn($v) => "'" . str_replace("'", "''", $this->driver === self::DRIVER_MYSQL ? str_replace('\\', '\\\\', $v) : $v) . "'", $values);
        return implode(',', $escaped);
    }

    /**
     * Wrap a table name.
     *
     * @param string $table The table name.
     * @return string The wrapped table name.
     */
    public function wrapTable(string $table): string
    {
        $parts = preg_split('/\s+as\s+/i', trim($table), 2);
        $sql = implode('.', array_map([$this, 'wrap'], explode('.', $this->tableName($parts[0]))));

        if (isset($parts[1])) {
            $this->registerAlias($parts[1]);
            $sql .= ' AS ' . $this->wrap($parts[1]);
        }

        return $sql;
    }

    /** Resolve a logical table name, prefixing only its final segment. */
    public function tableName(string $table): string
    {
        $parts = array_map('trim', explode('.', $table));
        foreach ($parts as $part) {
            if ($part === '' || $part === '*') {
                throw new \InvalidArgumentException('A table name must contain non-empty identifiers.');
            }
            $this->wrap($part);
        }
        $parts[array_key_last($parts)] = $this->tablePrefix . end($parts);
        $this->wrap(end($parts));
        return implode('.', $parts);
    }

    public static function validateTablePrefix(mixed $prefix): void
    {
        if (!is_string($prefix) || preg_match('/[^A-Za-z0-9_]/', $prefix)) {
            throw new \InvalidArgumentException('A table prefix must be a string containing only letters, digits, and underscores.');
        }
    }

    public function setTablePrefix(string $prefix): void
    {
        self::validateTablePrefix($prefix);
        $this->tablePrefix = $prefix;
    }

    public function registerAlias(string $alias): void
    {
        $alias = trim($alias);
        $this->wrap($alias);
        if ($this->tablePrefix !== '' && isset($this->qualifiedTables[$alias]) && !isset($this->aliases[$alias])) {
            throw new \LogicException('Declare table aliases before referencing them in query clauses.');
        }
        $this->aliases[$alias] = true;
    }

    /** Remove an alias when its FROM scope is replaced or shadowed by an inner table. */
    public function unregisterAlias(string $alias): void
    {
        unset($this->aliases[$alias]);
    }

    /** Inherit outer aliases for correlation without inheriting clause-order checks. */
    public function forSubquery(): self
    {
        $wrapper = clone $this;
        $wrapper->qualifiedTables = [];
        return $wrapper;
    }

    /** Prefix a qualified column while keeping aliases and bare columns intact. */
    public function qualifyColumn(string $column): string
    {
        if (!str_contains($column, '.')) {
            return $column;
        }

        $parts = array_map('trim', explode('.', $column));
        $name = array_pop($parts);
        $table = implode('.', $parts);
        $this->qualifiedTables[$table] = true;
        if (!isset($this->aliases[$table])) {
            $table = $this->tableName($table);
        }

        return "$table.$name";
    }

    /**
     * Wrap a column name.
     *
     * @param string $column The column name.
     * @return string The wrapped column name.
     */
    public function wrapColumn(string|Expression $column): string
    {
        if ($column instanceof Expression) {
            return $column->getValue();
        }
        if (str_contains($column, '.')) {
            $parts = explode('.', $this->qualifyColumn($column));
            if ($this->driver === self::DRIVER_SQLITE && end($parts) === '*' && count($parts) > 2) {
                $parts = array_slice($parts, -2);
            }
            return implode('.', array_map([$this, 'wrap'], $parts));
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
     * Wrap a value with database-specific characters.
     *
     * @param string $value The value to wrap.
     * @return string The wrapped value.
     */
    public function wrap(string $value): string
    {
        $value = trim($value); // Trim whitespace

        // Check for wildcard
        if ($value === '*') {
            return $value;
        }

        // Validate identifier length (PostgreSQL limit is 63, MySQL is 64)
        $limit = match ($this->driver) { self::DRIVER_MYSQL => 64, self::DRIVER_PGSQL => 63, default => 255};
        if (strlen($value) > $limit) {
            throw new \InvalidArgumentException(
                "Identifier '{$value}' exceeds maximum length of $limit characters"
            );
        }

        // Prevent SQL injection attempts in identifier names
        if (preg_match('/[^\w_]/', $value)) {
            throw new \InvalidArgumentException(
                "Invalid characters in identifier: '{$value}'"
            );
        }

        return $this->wrapper[0] . $value . $this->wrapper[1];
    }
}
