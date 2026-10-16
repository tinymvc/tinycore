<?php
namespace Spark\Database\Contracts;

use Closure;
use Spark\Contracts\Support\Arrayable;
use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\Relation;
use Spark\Utils\Paginator;

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
     * @param string|null $alias Optional alias for the table.
     * @return QueryBuilderContract Returns the query builder instance.
     */
    public function table(string $table, ?string $alias = null): self;

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
    public function where(null|string|array|Arrayable|Closure $column = null, mixed $operator = null, $value = null, null|string $type = null): self;

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

    /**
     * Find a single record by its primary key.
     *
     * @param string|int $value
     * @return Model|null
     */
    public function find(string|int $value): ?Model;

    /**
     * Get a single column's value from the first result of a query.
     *
     * @param string $column
     * @return mixed
     */
    public function value(string $column): mixed;

    /**
     * Get an array with the values of a given column.
     *
     * @param string $column
     * @param string|null $key
     * @return array
     */
    public function pluck(string $column, ?string $key = null): array;

    /**
     * Determine if any rows exist for the current query.
     *
     * @return bool
     */
    public function exists(): bool;

    /**
     * Set the "limit" and optional "offset" for the query.
     *
     * @param string|int $from
     * @param int|null $to
     * @return self
     */
    public function limit(string|int $from, ?int $to = null): self;

    /**
     * Set the "offset" value of the query.
     *
     * @param int $offset
     * @return self
     */
    public function offset(int $offset): self;

    /**
     * Add an "order by" clause to the query.
     *
     * @param string $field
     * @param string $sort
     * @return self
     */
    public function orderBy(string $field, string $sort = 'ASC'): self;

    /**
     * Add a "group by" clause to the query.
     *
     * @param array|string $field
     * @return self
     */
    public function groupBy(array|string $field): self;

    /**
     * Add a "having" clause to the query.
     *
     * @param string $having
     * @return self
     */
    public function having(string $having): self;

    /**
     * Add an "or where" clause to the query.
     *
     * @param Arrayable|Closure|array|string|null $column
     * @param mixed $operator
     * @param mixed $value
     * @return self
     */
    public function orWhere(Arrayable|Closure|array|string|null $column = null, mixed $operator = null, $value = null): self;

    /**
     * Add a "where in" clause to the query.
     *
     * @param string $column
     * @param Relation|QueryBuilder|Closure|array|string $values
     * @return self
     */
    public function whereIn(string $column, Relation|QueryBuilder|Closure|array|string $values): self;

    /**
     * Add a "where not in" clause to the query.
     *
     * @param string $column
     * @param Relation|QueryBuilder|Closure|array|string $values
     * @return self
     */
    public function whereNotIn(string $column, Relation|QueryBuilder|Closure|array|string $values): self;

    /**
     * Add a "where null" clause to the query.
     *
     * @param string $field
     * @param bool $not
     * @param string $boolean
     * @return self
     */
    public function whereNull(string $field, bool $not = false, string $boolean = 'AND'): self;

    /**
     * Add a "where not null" clause to the query.
     *
     * @param string $field
     * @return self
     */
    public function whereNotNull(string $field): self;

    /**
     * Add a "where between" clause to the query.
     *
     * @param string $field
     * @param array $values
     * @param string $boolean
     * @param bool $not
     * @return self
     */
    public function whereBetween(string $field, array $values, string $boolean = 'AND', bool $not = false): self;

    /**
     * Force the query to only return distinct results.
     *
     * @param string|null $column
     * @return self
     */
    public function distinct(?string $column = null): self;

    /**
     * Paginate the given query into a Paginator instance.
     *
     * @param int $limit
     * @param string $keyword
     * @param array|string|null $fields
     * @return Paginator
     */
    public function paginate(int $limit = 10, string $keyword = 'page', array|string|null $fields = null): Paginator;

    /**
     * Truncate the table.
     *
     * @return int
     */
    public function truncate(): int;

    /**
     * Get the raw SQL representation of the query.
     *
     * @return string
     */
    public function toSql(): string;

    /**
     * Get the current query value bindings.
     *
     * @return array
     */
    public function getBindings(): array;
}
