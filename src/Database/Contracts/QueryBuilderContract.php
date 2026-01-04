<?php
namespace Spark\Database\Contracts;

use Closure;
use Spark\Contracts\Support\Arrayable;

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
     * @param array|Arrayable $data The data to insert.
     * @param array $config Optional configuration for the query.
     * @return int Returns last insert ID.
     */
    public function insert(array|Arrayable $data, array $config = []): int;

    /**
     * Add a where clause to the query.
     *
     * @param null|string|array|Arrayable|Closure $column The column name to query, or an array of column names.
     * @param null|string $operator The operator to use. If null, the operator will be determined
     *   based on the value given.
     * @param mixed $value The value to query. If null, the value will be determined
     *   based on the operator given.
     * @param null|string $type The type of the value given.
     * @return QueryBuilderContract Returns the query builder instance.
     */
    public function where(null|string|array|Arrayable|Closure $column = null, null|string $operator = null, $value = null, null|string $type = null): self;

    /**
     * Update data in the database.
     *
     * @param array|Arrayable $data The data to update.
     * @param null|string|array|Arrayable|Closure $where The condition to use for the update.
     * @return int The number of affected rows.
     */
    public function update(array|Arrayable $data, null|string|array|Arrayable|Closure $where = null): int;

    /**
     * Delete data from the database.
     *
     * @param null|string|array|Arrayable|Closure $where The condition to use for the delete.
     * @return int The number of affected rows.
     */
    public function delete(null|string|array|Arrayable|Closure $where = null): int;

    /**
     * Set the fields to select from the database.
     *
     * @param array|string $fields The fields to select.
     * @return QueryBuilderContract Returns the query builder instance.
     */
    public function select(array|string $fields = '*'): self;

    /**
     * Adds a JOIN clause to the query.
     *
     * @param string $table    The table to join.
     * @param string|null $field1   The first field to join on.
     * @param string|null $operator The operator to use for the join.
     * @param string|null $field2   The second field to join on.
     * @param string      $type     The type of the join (e.g., LEFT, RIGHT, INNER).
     *
     * @return self Returns the query builder instance.
     */
    public function join(string $table, string|null $field1 = null, string|null $operator = null, string|null $field2 = null, string $type = ''): self;

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
     * Get the all the rows.
     *
     * @return array The result array.
     */
    public function all(): array;

    /**
     * Get the result set as a collection.
     *
     * @return \Spark\Support\Collection The result set as a collection.
     */
    public function get(): \Spark\Support\Collection;

    /**
     * Get the number of rows in the result set.
     *
     * @return int The number of rows in the result set.
     */
    public function count(): int;
}
