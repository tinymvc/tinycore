<?php

namespace Spark\Database\Schema\Contracts;

/**
 * Interface GrammarContract
 *
 * This interface defines the methods that must be implemented by a grammar instance.
 * A grammar instance is used to generate SQL code for a given database connection.
 *
 * @package Spark\Database\Schema\Contracts
 */
interface WrapperContract
{
    /**
     * Wrap a value with database-specific characters.
     *
     * This method is used to wrap a value so that it can be used as a literal in an SQL query.
     *
     * @param string $value The value to be wrapped.
     * @return string The wrapped value.
     */
    public function wrap(string $value): string;

    /**
     * Wrap a table name.
     *
     * This method is used to wrap a table name so that it can be used in an SQL query.
     *
     * @param string $table The name of the table.
     * @return string The wrapped table name.
     */
    public function wrapTable(string $table): string;

    /**
     * Wrap a column name.
     *
     * This method is used to wrap a column name so that it can be used in an SQL query.
     *
     * @param string $column The name of the column.
     * @return string The wrapped column name.
     */
    public function wrapColumn(string $column): string;


    /**
     * Quote enum values for SQL statements.
     *
     * This method is used to quote enum values so that they can be safely used in SQL queries.
     *
     * @param array $values The values to quote.
     * @return string The quoted values as a comma-separated string.
     */
    public function quoteEnumValues(array $values): string;

    /**
     * Columnize an array of column names.
     *
     * This method is used to convert an array of column names into a comma-separated string,
     * with each column name properly wrapped for SQL queries.
     *
     * @param array $columns The array of column names.
     * @return string The columnized string.
     */
    public function columnize(array $columns): string;
}
