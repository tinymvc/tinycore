<?php

namespace Spark\Contracts\Database;

/**
 * Interface for the query builder contract.
 *
 * This interface defines the minimum requirements for a query builder instance.
 * It provides methods for constructing and executing SQL queries.
 */
interface QueryBuilderContract
{
    /**
     * Set the table name to be used for the query.
     *
     * @param string $table The table name to use.
     * @return QueryBuilderContract Returns the query builder instance.
     */
    public function table(string $table): self;

    /**
     * Insert data into the database.
     *
     * @param array $data The data to insert.
     * @param array $config Optional configuration for the query.
     * @return int The number of rows inserted.
     */
    public function insert(array $data, array $config = []): int;

    /**
     * Add a where clause to the query.
     *
     * @param string|array $column The column name to query, or an array of column names.
     * @param ?string $operator The operator to use. If null, the operator will be determined
     *   based on the value given.
     * @param mixed $value The value to query. If null, the value will be determined
     *   based on the operator given.
     * @param ?string $type The type of the value given.
     * @return QueryBuilderContract Returns the query builder instance.
     */
    public function where(string|array $column = null, ?string $operator = null, mixed $value = null, ?string $type = null): self;

    /**
     * Update data in the database.
     *
     * @param array $data The data to update.
     * @param mixed $where The condition to use for the update.
     * @return bool True if the update was successful, false otherwise.
     */
    public function update(array $data, mixed $where = null): bool;

    /**
     * Delete data from the database.
     *
     * @param mixed $where The condition to use for the delete.
     * @return bool True if the delete was successful, false otherwise.
     */
    public function delete(mixed $where = null): bool;

    /**
     * Set the fields to select from the database.
     *
     * @param array|string $fields The fields to select.
     * @return QueryBuilderContract Returns the query builder instance.
     */
    public function select(array|string $fields = '*'): self;

    /**
     * Add a join to the query.
     *
     * @param string $table The table to join.
     * @param string $condition The condition for the join.
     * @return QueryBuilderContract Returns the query builder instance.
     */
    public function join(string $table, string $condition): self;

    /**
     * Get the first row of the result set.
     *
     * @return mixed The first row of the result set.
     */
    public function first(): mixed;

    /**
     * Get the last row of the result set.
     *
     * @return mixed The last row of the result set.
     */
    public function last(): mixed;

    /**
     * Get the result set.
     *
     * @return array The result set.
     */
    public function result(): array;

    /**
     * Get the number of rows in the result set.
     *
     * @return int The number of rows in the result set.
     */
    public function count(): int;
}