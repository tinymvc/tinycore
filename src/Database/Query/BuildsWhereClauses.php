<?php

namespace Spark\Database\Query;

use Closure;
use Spark\Contracts\Support\Arrayable;
use Spark\Database\Exceptions\QueryBuilderInvalidWhereClauseException;
use Spark\Database\QueryBuilder;
use function func_num_args;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Builds and binds WHERE clauses while preserving the QueryBuilder fluent API.
 *
 * @internal Composed into \Spark\Database\QueryBuilder.
 */
trait BuildsWhereClauses
{
    /**
     * Add a where clause to the query.
     *
     * @param null|string|array|Arrayable|Closure $column
     *   The column name to query, or an array of column names.
     * @param string|null $operator
     *   The operator to use. If null, the operator will be determined
     *   based on the value given.
     * @param mixed $value
     *   The value to query. If null, the value will be determined
     *   based on the operator given.
     * @param null|string $andOr
     *   The type of where clause to add. May be 'AND' or 'OR'.
     * @param bool $not
     *   If true, the where clause will be negated.
     *
     * @return self
     */
    public function where(null|string|array|Arrayable|Closure $column = null, mixed $operator = null, $value = null, null|string $andOr = null, bool $not = false): QueryBuilder
    {
        if ($column instanceof Arrayable) {
            $column = $column->toArray();
        }

        $argumentCount = func_num_args();

        if ($column === null || $column === '' || $column === []) {
            return $this;
        } elseif ($column instanceof Closure) {
            return $this->grouped($column);
        }

        $hasExplicitBoolean = $andOr !== null;
        $andOr = $this->normalizeBoolean($andOr ?? 'AND');

        // Holds a conditional clause for database.
        $command = '';

        if (is_string($column) && ($operator !== null || $value !== null || ($argumentCount === 2 && !$hasExplicitBoolean))) {
            // Create a where clause from column, operator, and value.
            // for example: "title like :title"
            if ($argumentCount === 2) {
                $value = $operator;
                $operator = '=';
            }

            $operator = strtoupper((string) ($operator ?? '='));

            if ($value === null && in_array($operator, ['=', 'IS'], true)) {
                return $this->whereNull($column, false, $andOr);
            }

            if ($value === null && in_array($operator, ['!=', '<>', 'IS NOT', 'NOT'], true)) {
                return $this->whereNull($column, true, $andOr);
            }

            if (is_array($value) && in_array($operator, ['IN', 'NOT IN'], true)) {
                return $this->whereInValues($column, $value, $andOr, $operator === 'NOT IN' || $not);
            }

            $columnPlaceholder = $this->getWhereSqlColumn($column);
            $comparison = sprintf(
                "%s %s :%s",
                $this->wrapper->wrapColumn($column),
                $operator,
                $columnPlaceholder
            );

            $command = sprintf(
                "%s %s",
                $andOr,
                $not ? "NOT ($comparison)" : $comparison
            );

            $this->bindings[$columnPlaceholder] = $value;
        }
        // Associative Array where clause.
        elseif (is_array($column) && !array_is_list($column) && $operator === null && $value === null) {
            // Create a where clause from array conditions.
            $keys = array_keys($column);
            $values = array_values($column);

            if (is_string($keys[0])) {
                $command = sprintf(
                    "%s %s",
                    $andOr,
                    implode(
                        " {$andOr} ",
                        array_map(
                            function ($attr, $value) use ($not) {

                                $columnPlaceholder = $this->getWhereSqlColumn($attr); // Get the column placeholder for binding.
                                if ($value === null) {
                                    return $this->wrapper->wrapColumn($attr) . ' IS ' . ($not ? 'NOT ' : '') . 'NULL';
                                }

                                if (!is_array($value)) {
                                    $this->bindings[$columnPlaceholder] = $value; // Bind the value to the placeholder.
                                }

                                if (is_array($value) && !empty($value)) {
                                    $this->bindings[$columnPlaceholder] = array_values($value);
                                }

                                return is_array($value) ?
                                    $this->compileWhereIn($attr, $value, $columnPlaceholder, $not)
                                    // Create a where close to match is equal, Ex. "id = :id_0"
                                    : $this->wrapper->wrapColumn($attr) . ($not ? ' !=' : ' =') . " :" . $columnPlaceholder;
                            },
                            $keys,
                            $values
                        )
                    )
                );
            } else {
                if (isset($values[0]) && is_string($values[0])) {
                    return $this->where($values[0], $values[1] ?? null, $values[2] ?? null, $values[3] ?? $andOr, $not);
                }

                foreach ($values as $value) {
                    if (isset($value[0]) && is_string($value[0])) {
                        $this->where($value[0], $value[1] ?? null, $value[2] ?? null, $value[3] ?? $andOr, $not);
                    } else {
                        $this->where($value, null, null, $andOr, $not);
                    }
                }

                return $this; // Return early as where clauses are already added.
            }

        }
        // List of where clauses.
        elseif (is_array($column) && array_is_list($column) && $operator === null && $value === null) {
            foreach ($column as $item) {
                if (isset($item[0]) && is_string($item[0])) {
                    $this->where($item[0], $item[1] ?? null, $item[2] ?? null, $item[3] ?? $andOr, $not);
                } elseif (isset($item[0]) && is_array($item[0])) {
                    $this->where($item[0], null, null, $item[3] ?? $andOr, $not);
                } else {
                    $this->where($item, null, null, $andOr, $not);
                }
            }
        }
        // Single String where clause.
        elseif (is_string($column) && $operator === null && $value === null) {
            // Simply add a where clause from string.
            $command = "{$andOr} {$column}";
        } else {
            throw new QueryBuilderInvalidWhereClauseException('Invalid where clause');
        }

        // Grouped where clauses.
        if ($this->where['grouped']) {
            $command = "$andOr (" . $this->stripBooleanPrefix($command, $andOr);
            $this->where['grouped'] = false;
        }

        // Register the where clause into current query builder.
        $this->where['sql'] .= sprintf(
            ' %s ',
            empty($this->where['sql']) ? $this->stripBooleanPrefix($command, $andOr) : $command
        );

        // Returns the current instance for method chaining.
        return $this;
    }

    /**
     * Adds a WHERE binding to the query.
     * This method allows you to add additional bindings to the WHERE clause.
     *
     * @param string|array $args
     * @return QueryBuilder
     */
    public function bind(string|array $args, bool $named = true): QueryBuilder
    {
        if ($named && is_array($args)) {
            $this->bindings = [...$this->bindings, ...$args];
        } else {
            $this->param($args);
        }

        return $this;
    }

    /**
     * Adds a raw WHERE clause to the query.
     *
     * @param string $sql
     *   The raw SQL condition to add.
     * @param string|array $bindings
     *   The bindings for the raw SQL condition.
     * @param string $andOr
     *   The type of where clause to add. May be 'AND' or 'OR'.
     * @return self
     */
    public function whereRaw(string $sql, string|array $bindings = [], string $andOr = 'AND'): QueryBuilder
    {
        $andOr = $this->normalizeBoolean($andOr);

        $this->where['sql'] .= sprintf(
            ' %s (%s)',
            !empty($this->where['sql']) ? $andOr : '',
            $sql
        );

        $this->addBindings($sql, $bindings);

        return $this;
    }

    /**
     * Adds an OR raw WHERE clause to the query.
     *
     * @param string $sql
     *   The raw SQL condition to add.
     * @param string|array $bindings
     *   The bindings for the raw SQL condition.
     * @return self
     */
    public function orWhereRaw(string $sql, string|array $bindings = []): QueryBuilder
    {
        return $this->whereRaw($sql, $bindings, 'OR');
    }

    /**
     * Add an OR where clause to the query.
     *
     * @param string|array $column
     *   The column name to query, or an array of column names.
     * @param string|null $operator
     *   The operator to use. If null, the operator will be determined
     *   based on the value given.
     * @param mixed $value
     *   The value to query. If null, the value will be determined
     *   based on the operator given.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orWhere(null|string|array|Arrayable|Closure $column = null, mixed $operator = null, $value = null): QueryBuilder
    {
        if (func_num_args() === 2) {
            return $this->where($column, '=', $operator, 'OR');
        }

        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * Add an AND NOT where clause to the query.
     *
     * @param string|array $column
     *   The column name to query, or an array of column names.
     * @param string|null $operator
     *   The operator to use. If null, the operator will be determined
     *   based on the value given.
     * @param mixed $value
     *   The value to query. If null, the value will be determined
     *   based on the operator given.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function notWhere(null|string|array|Arrayable|Closure $column = null, mixed $operator = null, $value = null): QueryBuilder
    {
        if (func_num_args() === 2) {
            return $this->where($column, '=', $operator, 'AND', true);
        }

        return $this->where($column, $operator, $value, 'AND', true);
    }

    /**
     * Add an OR NOT where clause to the query.
     *
     * @param string|array $column
     *   The column name to query, or an array of column names.
     * @param string|null $operator
     *   The operator to use. If null, the operator will be determined
     *   based on the value given.
     * @param mixed $value
     *   The value to query. If null, the value will be determined
     *   based on the operator given.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orNotWhere(null|string|array|Arrayable|Closure $column = null, mixed $operator = null, $value = null): QueryBuilder
    {
        if (func_num_args() === 2) {
            return $this->where($column, '=', $operator, 'OR', true);
        }

        return $this->where($column, $operator, $value, 'OR', true);
    }

    /**
     * Adds a WHERE condition that the given column is null.
     *
     * @param string $field
     *   The column name to query.
     * @param bool $not
     *   Whether to use IS NOT NULL instead of IS NULL.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function whereNull(string $field, bool $not = false, string $andOr = 'AND'): QueryBuilder
    {
        $field = $this->wrapper->wrapColumn($field) . ' IS ' . ($not ? 'NOT' : '') . ' NULL';

        return $this->where($field, andOr: $andOr);
    }

    /**
     * Adds a WHERE condition that the given column is not null.
     *
     * @param string $field
     *   The column name to query.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function whereNotNull(string $field): QueryBuilder
    {
        return $this->whereNull($field, true);
    }

    /**
     * Adds an OR WHERE condition that the given column is null.
     *
     * @param string $field
     * @return self
     */
    public function orWhereNull(string $field): QueryBuilder
    {
        return $this->whereNull($field, false, 'OR');
    }

    /**
     * Adds an OR WHERE condition that the given column is not null.
     *
     * @param string $field
     * @return self
     */
    public function orWhereNotNull(string $field): QueryBuilder
    {
        return $this->whereNull($field, true, 'OR');
    }

    /**
     * Add a WHERE condition that the given column is in the given array of values.
     *
     * @param string $column
     *   The column name to query.
     * @param array $values
     *   The array of values to query.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function whereIn(string $column, array $values): QueryBuilder
    {
        return $this->whereInValues($column, $values);
    }

    /**
     * Add a WHERE condition that the given column is not in the given array of values.
     *
     * @param string $column
     *   The column name to query.
     * @param array $values
     *   The array of values to query.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function whereNotIn(string $column, array $values): QueryBuilder
    {
        return $this->whereInValues($column, $values, not: true);
    }

    /**
     * Add an OR WHERE condition that the given column is in the given array of values.
     *
     * @param string $column
     *   The column name to query.
     * @param array $values
     *   The array of values to query.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orWhereIn(string $column, array $values): QueryBuilder
    {
        return $this->whereInValues($column, $values, 'OR');
    }

    /**
     * Add an OR WHERE condition that the given column is not in the given array of values.
     *
     * @param string $column
     *   The column name to query.
     * @param array $values
     *   The array of values to query.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orWhereNotIn(string $column, array $values): QueryBuilder
    {
        return $this->whereInValues($column, $values, 'OR', true);
    }

    /**
     * Add a WHERE condition that the given column is in the given array of values.
     *
     * @param string $column
     *   The column name to query.
     * @param array $values
     *   The array of values to query.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function in(string $column, array $values): QueryBuilder
    {
        return $this->whereIn($column, $values);
    }

    /**
     * Add a WHERE condition that the given column is not in the given array of values.
     *
     * @param string $column
     *   The column name to query.
     * @param array $values
     *   The array of values to query.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function notIn(string $column, array $values): QueryBuilder
    {
        return $this->whereNotIn($column, $values);
    }

    /**
     * Add an OR WHERE condition that the given column is in the given array of values.
     *
     * @param string $column
     *   The column name to query.
     * @param array $values
     *   The array of values to query.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orIn(string $column, array $values): QueryBuilder
    {
        return $this->orWhereIn($column, $values);
    }

    /**
     * Add an OR WHERE condition that the given column is not in the given array of values.
     *
     * @param string $column
     *   The column name to query.
     * @param array $values
     *   The array of values to query.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orNotIn(string $column, array $values): QueryBuilder
    {
        return $this->orWhereNotIn($column, $values);
    }

    /**
     * Add a WHERE condition using FIND_IN_SET function.
     *
     * @param string $field
     *   The field name to search within.
     * @param mixed $key
     *   The key to find in the set.
     * @param string $type
     *   Optional type to prepend to the FIND_IN_SET clause (e.g., 'NOT').
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function findInSet(string $field, mixed $key, string $type = '', string $andOr = 'AND'): QueryBuilder
    {
        $type = $this->normalizeOperatorPrefix($type);

        // Get the SQL column placeholder for binding.
        $columnPlaceholder = $this->getWhereSqlColumn($field);

        // Construct the FIND_IN_SET condition
        if ($this->database->isDriver('sqlite')) {
            $where = $this->wrapper->wrapColumn($field) . " {$type}LIKE :$columnPlaceholder";
            $key = "%$key%"; // SQLite uses LIKE for partial matches.
        } else {
            $where = "{$type}FIND_IN_SET (:$columnPlaceholder, {$this->wrapper->wrapColumn($field)})";
        }

        // Bind the key to the placeholder
        $this->bindings[$columnPlaceholder] = $key;

        // Add the condition to the query's WHERE clause
        return $this->where($where, andOr: $andOr);
    }

    /**
     * Add a WHERE condition using FIND_IN_SET function, negated.
     *
     * @param string $field
     *   The field name to search within.
     * @param mixed $key
     *   The key to find in the set.
     *
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function notFindInSet(string $field, mixed $key): QueryBuilder
    {
        return $this->findInSet($field, $key, 'NOT ');
    }

    /**
     * Add an OR WHERE condition using the FIND_IN_SET function.
     *
     * @param string $field
     *   The field name to search within.
     * @param mixed $key
     *   The key to find in the set.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orFindInSet(string $field, mixed $key): QueryBuilder
    {
        return $this->findInSet($field, $key, '', 'OR');
    }

    /**
     * Add an OR WHERE condition using the FIND_IN_SET function, negated.
     *
     * @param string $field
     *   The field name to search within.
     * @param mixed $key
     *   The key to find in the set.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orNotFindInSet(string $field, mixed $key): QueryBuilder
    {
        return $this->findInSet($field, $key, 'NOT ', 'OR');
    }

    /**
     * Add a WHERE condition using JSON_EXTRACT function.
     *
     * @param string $field
     *   The field name to search within.
     * @param string $key
     *   The key to find in the JSON object.
     * @param mixed $value
     *   The value to match against the extracted JSON value.
     * @param string $type
     *   Optional type to prepend to the LIKE clause (e.g., 'NOT').
     * @param string $andOr
     *   The logical operator to combine with previous conditions, e.g., 'AND' or 'OR'.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function findInJson(string $field, string $key, mixed $value, string $type = '', string $andOr = 'AND'): QueryBuilder
    {
        $type = $this->normalizeOperatorPrefix($type);

        // Get the SQL column placeholder for binding.
        $columnPlaceholder = $this->getWhereSqlColumn("{$field}_{$key}");

        // Construct the JSON condition
        $where = "JSON_EXTRACT({$this->wrapper->wrapColumn($field)}, '$.{$key}') {$type}LIKE :$columnPlaceholder";

        $this->bindings[$columnPlaceholder] = "%$value%";

        return $this->where($where, andOr: $andOr);
    }

    /**
     * Add a WHERE condition using JSON_EXTRACT function, negated.
     *
     * @param string $field
     *   The field name to search within.
     * @param string $key
     *   The key to find in the JSON object.
     * @param mixed $value
     *   The value to match against the extracted JSON value.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function notFindInJson(string $field, string $key, mixed $value): QueryBuilder
    {
        return $this->findInJson($field, $key, $value, 'NOT ');
    }

    /**
     * Add an OR WHERE condition using JSON_EXTRACT function.
     *
     * @param string $field
     *   The field name to search within.
     * @param string $key
     *   The key to find in the JSON object.
     * @param mixed $value
     *   The value to match against the extracted JSON value.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orFindInJson(string $field, string $key, mixed $value): QueryBuilder
    {
        return $this->findInJson($field, $key, $value, '', 'OR');
    }

    /**
     * Add an OR WHERE condition using JSON_EXTRACT function, negated.
     *
     * @param string $field
     *   The field name to search within.
     * @param string $key
     *   The key to find in the JSON object.
     * @param mixed $value
     *   The value to match against the extracted JSON value.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orNotFindInJson(string $field, string $key, mixed $value): QueryBuilder
    {
        return $this->findInJson($field, $key, $value, 'NOT ', 'OR');
    }

    /**
     * Add a WHERE condition that checks if a JSON field contains a specific value.
     *
     * @param string $field
     *   The field name to query.
     * @param string $key
     *   The key within the JSON object to check.
     * @param mixed $value
     *   The value to check for within the JSON array.
     * @param string $type
     *   The type of comparison, e.g., 'NOT'.
     * @param string $andOr
     *   The logical operator to combine with previous conditions, e.g., 'AND' or 'OR'.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function whereJsonContains(string $field, string $key, mixed $value, string $type = '', string $andOr = 'AND'): QueryBuilder
    {
        return $this->findInJson($field, $key, $value, $type, $andOr);
    }

    /**
     * Add a WHERE condition that checks if a JSON field does not contain a specific value.
     *
     * @param string $field
     *   The field name to query.
     * @param string $key
     *   The key within the JSON object to check.
     * @param mixed $value
     *   The value to check for within the JSON array.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function whereJsonNotContains(string $field, string $key, mixed $value): QueryBuilder
    {
        return $this->whereJsonContains($field, $key, $value, 'NOT ');
    }

    /**
     * Add an OR WHERE condition that checks if a JSON field contains a specific value.
     *
     * @param string $field
     *   The field name to query.
     * @param string $key
     *   The key within the JSON object to check.
     * @param mixed $value
     *   The value to check for within the JSON array.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orWhereJsonContains(string $field, string $key, mixed $value): QueryBuilder
    {
        return $this->whereJsonContains($field, $key, $value, '', 'OR');
    }

    /**
     * Add an OR WHERE condition that checks if a JSON field does not contain a specific value.
     *
     * @param string $field
     *   The field name to query.
     * @param string $key
     *   The key within the JSON object to check.
     * @param mixed $value
     *   The value to check for within the JSON array.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orWhereJsonNotContains(string $field, string $key, mixed $value): QueryBuilder
    {
        return $this->whereJsonContains($field, $key, $value, 'NOT ', 'OR');
    }

    /**
     * Add a WHERE condition that checks if the field is between two values.
     *
     * @param string $field
     *   The field name to query.
     * @param mixed $value1
     *   The first value of the range.
     * @param mixed $value2
     *   The second value of the range.
     * @param string $type
     *   The type of comparison, e.g., 'NOT'.
     * @param string $andOr
     *   The logical operator to combine with previous conditions, e.g., 'AND' or 'OR'.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function between(string $field, mixed $value1, mixed $value2, string $type = '', string $andOr = 'AND'): QueryBuilder
    {
        $type = $this->normalizeOperatorPrefix($type);

        $columnPlaceholder1 = $this->getWhereSqlColumn("{$field}1");
        $columnPlaceholder2 = $this->getWhereSqlColumn("{$field}2");

        $where = '(' . $this->wrapper->wrapColumn($field) . ' ' . $type . 'BETWEEN '
            . (":$columnPlaceholder1 AND :$columnPlaceholder2") . ')';

        $this->bindings[$columnPlaceholder1] = $value1;
        $this->bindings[$columnPlaceholder2] = $value2;

        return $this->where($where, andOr: $andOr);
    }

    /**
     * Laravel-style alias for adding a BETWEEN condition.
     *
     * @param string $field
     * @param array $values
     * @param string $andOr
     * @param bool $not
     * @return self
     */
    public function whereBetween(string $field, array $values, string $andOr = 'AND', bool $not = false): QueryBuilder
    {
        return $this->between($field, $values[0] ?? null, $values[1] ?? null, $not ? 'NOT ' : '', $andOr);
    }

    /**
     * Add a WHERE condition that checks if the field is not between two values.
     *
     * @param string $field
     *   The field name to query.
     * @param mixed $value1
     *   The first value of the range.
     * @param mixed $value2
     *   The second value of the range.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function notBetween(string $field, mixed $value1, mixed $value2): QueryBuilder
    {
        return $this->between($field, $value1, $value2, 'NOT ');
    }

    /**
     * Laravel-style alias for adding a NOT BETWEEN condition.
     *
     * @param string $field
     * @param array $values
     * @return self
     */
    public function whereNotBetween(string $field, array $values): QueryBuilder
    {
        return $this->whereBetween($field, $values, not: true);
    }

    /**
     * Add an OR WHERE condition that checks if the field is between two values.
     *
     * @param string $field
     *   The field name to query.
     * @param mixed $value1
     *   The first value of the range.
     * @param mixed $value2
     *   The second value of the range.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orBetween(string $field, mixed $value1, mixed $value2): QueryBuilder
    {
        return $this->between($field, $value1, $value2, '', 'OR');
    }

    /**
     * Laravel-style alias for adding an OR BETWEEN condition.
     *
     * @param string $field
     * @param array $values
     * @return self
     */
    public function orWhereBetween(string $field, array $values): QueryBuilder
    {
        return $this->whereBetween($field, $values, 'OR');
    }

    /**
     * Add an OR WHERE condition that checks if the field is not between two values.
     *
     * @param string $field
     *   The field name to query.
     * @param mixed $value1
     *   The first value of the range.
     * @param mixed $value2
     *   The second value of the range.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orNotBetween(string $field, mixed $value1, mixed $value2): QueryBuilder
    {
        return $this->between($field, $value1, $value2, 'NOT ', 'OR');
    }

    /**
     * Laravel-style alias for adding an OR NOT BETWEEN condition.
     *
     * @param string $field
     * @param array $values
     * @return self
     */
    public function orWhereNotBetween(string $field, array $values): QueryBuilder
    {
        return $this->whereBetween($field, $values, 'OR', true);
    }

    /**
     * Add a WHERE condition using the LIKE operator.
     *
     * @param string $field
     *   The field name to query.
     * @param mixed $data
     *   The data to match against using the LIKE operator.
     * @param string $type
     *   Optional type to prepend to the LIKE clause (e.g., 'NOT').
     * @param string $andOr
     *   The type of where clause to add. May be 'AND' or 'OR'.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function like(string $field, mixed $data, string $type = '', string $andOr = 'AND'): QueryBuilder
    {
        $type = $this->normalizeOperatorPrefix($type);

        $columnPlaceholder = $this->getWhereSqlColumn($field);
        $where = $this->wrapper->wrapColumn($field) . " {$type}LIKE :$columnPlaceholder";

        $this->bindings[$columnPlaceholder] = $data;

        return $this->where(column: $where, andOr: $andOr);
    }

    /**
     * Add an OR WHERE condition that checks if the field matches the given data
     * using the LIKE operator.
     *
     * @param string $field
     *   The field name to query.
     * @param mixed $data
     *   The data to match against using the LIKE operator.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orLike(string $field, mixed $data): QueryBuilder
    {
        return $this->like($field, $data, '', 'OR');
    }

    /**
     * Add an AND WHERE condition that checks if the field does not match
     * the given data using the LIKE operator.
     *
     * @param string $field
     *   The field name to query.
     * @param mixed $data
     *   The data to match against using the NOT LIKE operator.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function notLike(string $field, mixed $data): QueryBuilder
    {
        return $this->like($field, $data, 'NOT ', 'AND');
    }

    /**
     * Add an OR WHERE condition that checks if the field does not match
     * the given data using the NOT LIKE operator.
     *
     * @param string $field
     *   The field name to query.
     * @param mixed $data
     *   The data to match against using the NOT LIKE operator.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orNotLike(string $field, mixed $data): QueryBuilder
    {
        return $this->like($field, $data, 'NOT ', 'OR');
    }

    /**
     * Add a WHERE clause that checks if a column contains a value.
     *
     * @param string $column
     * @param mixed $value
     * @param string $type
     * @param string $andOr
     * @return self
     */
    public function whereContains(string $column, mixed $value, string $type = '', string $andOr = 'AND'): QueryBuilder
    {
        return $this->like($column, "%{$value}%", $type, $andOr);
    }

    /**
     * Add an OR WHERE clause that checks if a column contains a value.
     *
     * @param string $column
     * @param mixed $value
     * @return self
     */
    public function orWhereContains(string $column, mixed $value): QueryBuilder
    {
        return $this->whereContains($column, $value, '', 'OR');
    }

    /**
     * Add a WHERE clause that checks if a column does not contain a value.
     *
     * @param string $column
     * @param mixed $value
     * @return self
     */
    public function whereNotContains(string $column, mixed $value): QueryBuilder
    {
        return $this->like($column, "%{$value}%", 'NOT');
    }

    /**
     * Add an OR WHERE clause that checks if a column does not contain a value.
     *
     * @param string $column
     * @param mixed $value
     * @return self
     */
    public function orWhereNotContains(string $column, mixed $value): QueryBuilder
    {
        return $this->like($column, "%{$value}%", 'NOT', 'OR');
    }

    /**
     * Add a WHERE clause that checks if a column starts with a value.
     *
     * @param string $column
     * @param mixed $value
     * @param string $type
     * @param string $andOr
     * @return self
     */
    public function whereStartsWith(string $column, mixed $value, string $type = '', string $andOr = 'AND'): QueryBuilder
    {
        return $this->like($column, "{$value}%", $type, $andOr);
    }

    /**
     * Add an OR WHERE clause that checks if a column starts with a value.
     *
     * @param string $column
     * @param mixed $value
     * @return self
     */
    public function orWhereStartsWith(string $column, mixed $value): QueryBuilder
    {
        return $this->whereStartsWith($column, $value, '', 'OR');
    }

    /**
     * Add a WHERE clause that checks if a column ends with a value.
     *
     * @param string $column
     * @param mixed $value
     * @param string $type
     * @param string $andOr
     * @return self
     */
    public function whereEndsWith(string $column, mixed $value, string $type = '', string $andOr = 'AND'): QueryBuilder
    {
        return $this->like($column, "%{$value}", $type, $andOr);
    }

    /**
     * Add an OR WHERE clause that checks if a column ends with a value.
     *
     * @param string $column
     * @param mixed $value
     * @return self
     */
    public function orWhereEndsWith(string $column, mixed $value): QueryBuilder
    {
        return $this->whereEndsWith($column, $value, '', 'OR');
    }

    /**
     * Add a WHERE clause for date comparison.
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @param string $andOr
     * @return self
     */
    public function whereDate(string $column, string $operator, $value = null, string $andOr = 'AND'): QueryBuilder
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $placeholder = $this->getWhereSqlColumn($column);

        if ($this->database->isMySQL()) {
            return $this->whereRaw("DATE({$this->wrapper->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
        } elseif ($this->database->isSQLite()) {
            return $this->whereRaw("date({$this->wrapper->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
        }

        return $this->where($column, $operator, $value, $andOr);
    }

    /**
     * Add an OR WHERE clause for date comparison.
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @return self
     */
    public function orWhereDate(string $column, string $operator, mixed $value = null): QueryBuilder
    {
        return $this->whereDate($column, $operator, $value, 'OR');
    }

    /**
     * Add a WHERE clause for year comparison.
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @param string $andOr
     * @return self
     */
    public function whereYear(string $column, string $operator, $value = null, string $andOr = 'AND'): QueryBuilder
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $placeholder = $this->getWhereSqlColumn($column);

        if ($this->database->isMySQL()) {
            return $this->whereRaw("YEAR({$this->wrapper->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
        } elseif ($this->database->isSQLite()) {
            return $this->whereRaw("strftime('%Y', {$this->wrapper->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
        }

        return $this->where($column, $operator, $value, $andOr);
    }

    /**
     * Add an OR WHERE clause for year comparison.
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @return self
     */
    public function orWhereYear(string $column, string $operator, mixed $value = null): QueryBuilder
    {
        return $this->whereYear($column, $operator, $value, 'OR');
    }

    /**
     * Add a WHERE clause for month comparison.
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @param string $andOr
     * @return self
     */
    public function whereMonth(string $column, string $operator, mixed $value = null, string $andOr = 'AND'): QueryBuilder
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $placeholder = $this->getWhereSqlColumn($column);

        if ($this->database->isMySQL()) {
            return $this->whereRaw("MONTH({$this->wrapper->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
        } elseif ($this->database->isSQLite()) {
            return $this->whereRaw("strftime('%m', {$this->wrapper->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
        }

        return $this->where($column, $operator, $value, $andOr);
    }

    /**
     * Add an OR WHERE clause for month comparison.
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @return self
     */
    public function orWhereMonth(string $column, string $operator, mixed $value = null): QueryBuilder
    {
        return $this->whereMonth($column, $operator, $value, 'OR');
    }

    /**
     * Add a grouped WHERE condition.
     *
     * This method will execute the given callback with the current instance
     * as the first parameter. The callback should call any of the where,
     * orWhere, whereNull, or orWhereNull methods to add the desired
     * conditions.
     *
     * The callback should not return anything, but the conditions will be
     * added to the query.
     *
     * @param Closure $callback
     *   The callback to call to add the conditions.
     *
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function grouped(Closure $callback)
    {
        $this->where['grouped'] = true;
        $callback($this);
        $this->where['sql'] .= ')';
        return $this;
    }

    /**
     * Checks if any conditions have been set in the WHERE clause.
     *
     * @return bool
     */
    private function hasWhere(): bool
    {
        // Returns true if conditions are set, otherwise false.
        return !empty(trim($this->where['sql']));
    }

    /**
     * Generates the SQL string for the WHERE clause based on conditions added.
     *
     * @return string
     */
    private function getWhereSql(): string
    {
        // Returns the SQL string for the WHERE clause.
        return $this->hasWhere() ? ' WHERE ' . trim($this->where['sql']) . ' ' : '';
    }

    /**
     * Add a WHERE IN/NOT IN clause and handle empty values safely.
     *
     * @param string $column
     * @param array $values
     * @param string $andOr
     * @param bool $not
     * @return self
     */
    private function whereInValues(string $column, array $values, string $andOr = 'AND', bool $not = false): QueryBuilder
    {
        $andOr = $this->normalizeBoolean($andOr);
        $placeholder = $this->getWhereSqlColumn($column);
        $command = $this->compileWhereIn($column, $values, $placeholder, $not);

        if (!empty($values)) {
            $this->bindings[$placeholder] = array_values($values);
        }

        $this->where['sql'] .= sprintf(
            ' %s ',
            empty($this->where['sql']) ? $this->stripBooleanPrefix($command, $andOr) : "$andOr $command"
        );

        return $this;
    }

    /**
     * Compile a WHERE IN condition.
     *
     * @param string $column
     * @param array $values
     * @param string $placeholder
     * @param bool $not
     * @return string
     */
    private function compileWhereIn(string $column, array $values, string $placeholder, bool $not = false): string
    {
        if (empty($values)) {
            return $not ? '1 = 1' : '0 = 1';
        }

        $parameters = join(
            ',',
            array_map(fn($index) => ":{$placeholder}_$index", array_keys(array_values($values)))
        );

        return sprintf(
            '%s %sIN (%s)',
            $this->wrapper->wrapColumn($column),
            $not ? 'NOT ' : '',
            $parameters
        );
    }

    /**
     * Normalize a boolean connector for SQL clauses.
     *
     * @param string $boolean
     * @return string
     */
    private function normalizeBoolean(string $boolean): string
    {
        return strtoupper(trim($boolean)) === 'OR' ? 'OR' : 'AND';
    }

    /**
     * Normalize optional SQL operator prefixes such as NOT.
     *
     * @param string $prefix
     * @return string
     */
    private function normalizeOperatorPrefix(string $prefix): string
    {
        $prefix = trim($prefix);

        return $prefix === '' ? '' : "$prefix ";
    }

    /**
     * Remove the leading boolean connector from a generated condition.
     *
     * @param string $sql
     * @param string $boolean
     * @return string
     */
    private function stripBooleanPrefix(string $sql, string $boolean): string
    {
        return preg_replace('/^\s*' . preg_quote($boolean, '/') . '\s+/i', '', $sql) ?? $sql;
    }

    /**
     * Get the Where SQL column name.
     * This method ensures that the column name is unique by appending an index if necessary.
     *
     * @param string $column The column name to be used in the WHERE clause.
     * @return string
     *   Returns a unique column name for the WHERE clause.
     */
    private function getWhereSqlColumn(string $column): string
    {
        $column = $this->makeParameterName($column);

        $index = 0;
        $x_column = $column;
        do {
            $x_column = $index === 0 ? $column : "$column$index";
            $index++;
        } while (isset($this->bindings[$x_column]));

        return $x_column;
    }

    /**
     * Create a safe placeholder name from a column or binding key.
     *
     * @param string $name
     * @return string
     */
    private function makeParameterName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        $name = trim($name, '_');

        return $name !== '' ? $name : 'value';
    }

    /**
     * Normalize named PDO bindings to the ":name" form.
     *
     * @param string $key
     * @return string
     */
    private function normalizeNamedBinding(string $key): string
    {
        return str_starts_with($key, ':') ? $key : ":$key";
    }
}
