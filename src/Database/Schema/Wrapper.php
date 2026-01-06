<?php

namespace Spark\Database\Schema;

use Spark\Database\Schema\Contracts\WrapperContract;
use function strlen;

class Wrapper implements WrapperContract
{
    /**
     * @var array $wrapper Character wrappers for SQL identifiers.
     */
    public array $wrapper;

    /**
     * @var string $driver The database driver.
     */
    public function __construct(string $driver)
    {
        $this->wrapper = ['mysql' => ['`', '`'], 'sqlite' => ['"', '"'], 'pgsql' => ['"', '"']][$driver];
    }

    /**
     * Quote enum values for SQL statements.
     *
     * @param array $values The values to quote.
     * @return string The quoted values as a comma-separated string.
     */
    public function quoteEnumValues(array $values): string
    {
        $escaped = array_map(fn($v) => "'" . addslashes($v) . "'", $values);
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
        return $this->wrapColumn($table); // Reuse wrapColumn for table names as well
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
        if (strlen($value) > 63) {
            throw new \InvalidArgumentException(
                "Identifier '{$value}' exceeds maximum length of 63 characters"
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