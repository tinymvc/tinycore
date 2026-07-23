<?php

namespace Spark\Database\Query;

use Closure;
use PDO;
use Spark\Database\QueryBuilder;
use function func_get_args;
use function func_num_args;
use function is_array;
use function is_object;
use function is_string;

/**
 * Builds SELECT, JOIN, ordering, grouping, pagination limit, and union SQL fragments.
 *
 * @internal Composed into \Spark\Database\QueryBuilder.
 */
trait BuildsSelectQueries
{
    /**
     * Specify the fields to include in the SELECT clause.
     *
     * @param array|string $fields A string or an array of column names to select.
     * @return self The current instance for method chaining.
     */
    public function select(array|string $fields = '*'): QueryBuilder
    {
        // Handle multiple arguments as an array
        if (func_num_args() > 1) {
            $fields = func_get_args();
        }
        // Convert array of fields to a comma-separated string if necessary
        if (is_array($fields)) {
            $fields = array_filter(array_map('trim', $fields)); // Trim whitespace and remove empty values
            $fields = implode(',', array_unique($fields)); // Remove duplicates and join with commas
        }

        // Remove any leading "SELECT " from the fields string
        $fields = preg_replace('/^\s*select\s+/i', '', $fields);

        // Build the initial SELECT SQL query
        $this->query['select'] = $this->wrapAndEscapeColumns($fields);

        // Returns the current instance for method chaining.
        return $this;
    }

    /**
     * Select a single column from the database.
     * 
     * @param string $column The name of the column to select.
     * @return self The current instance for method chaining.
     */
    public function column(string $column): QueryBuilder
    {
        $this->query['select'] = $this->wrapAndEscapeColumns($column);
        return $this->fetchColumn();
    }

    /**
     * Get a single column's value from the first result.
     *
     * @param string $column The column name to retrieve.
     * @return mixed
     */
    public function value(string $column): mixed
    {
        $result = $this->first($column);

        if ($result === false) {
            return null;
        }

        return is_object($result) ? $result->$column : $result[$column];
    }

    /**
     * Get a single column's values from all matching rows.
     *
     * @param string $column The column name to retrieve.
     * @param string|null $key Optional column name to use as keys in the returned array.
     * @return array
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $fields = array_filter([$column, $key]);
        $results = $this->select($fields)->all();

        if ($key === null) {
            return array_map(fn($row) => is_object($row) ? $row->$column : $row[$column], $results);
        }

        $values = [];
        foreach ($results as $row) {
            $itemKey = is_object($row) ? $row->$key : $row[$key];
            $values[$itemKey] = is_object($row) ? $row->$column : $row[$column];
        }

        return $values;
    }

    /**
     * Adds a raw SQL expression to the SELECT clause.
     *
     * @param string $sql The raw SQL expression to add.
     * @param array $bindings Optional bindings for the SQL expression.
     * @return self The current instance for method chaining.
     */
    public function selectRaw(string $sql, array $bindings = []): QueryBuilder
    {
        $this->query['select'] = preg_replace('/^\s*select\s+/i', '', $sql);

        $this->addBindings($sql, $bindings);

        return $this;
    }

    /**
     * Sets the table to select from.
     * 
     * This method allows you to specify the table from which to select data.
     * If the table is already set in the query, it will replace the existing FROM clause
     * with the new table.
     * 
     * @param string $table The name of the table to select from.
     * @param string|null $alias Optional alias for the table.
     * @return self The current instance for method chaining.
     */
    public function from(string $table, string|null $alias = null): QueryBuilder
    {
        $this->table ??= $table;
        $this->query['from'] = $table;

        // Set alias if provided
        !empty($alias) && $this->as($alias);

        return $this;
    }

    /**
     * Calculates the maximum value of a specified field.
     * 
     * @param string $field
     * @return float
     */
    public function max(string $field): float
    {
        $column = 'MAX(' . $this->wrapper->wrapColumn($field) . ')';
        $this->select($column);

        return $this->fetchColumn()->first();
    }

    /**
     * Calculates the minimum value of a specified field.
     * 
     * @param string $field
     * @return float
     */
    public function min(string $field): float
    {
        $column = 'MIN(' . $this->wrapper->wrapColumn($field) . ')';
        $this->select($column);

        return $this->fetchColumn()->first();
    }

    /**
     * Calculates the sum of a specified field.
     * 
     * @param string $field
     * @return float
     */
    public function sum(string $field): float
    {
        $column = 'SUM(' . $this->wrapper->wrapColumn($field) . ')';
        $this->select($column);

        return $this->fetchColumn()->first();
    }

    /**
     * Calculates the average value of a specified field.
     * 
     * @param string $field
     * @return float
     */
    public function avg(string $field): float
    {
        $column = 'AVG(' . $this->wrapper->wrapColumn($field) . ')';
        $this->select($column);

        return $this->fetchColumn()->first();
    }

    /**
     * Sets an alias for the current table in the FROM clause.
     *
     * If the given alias does not contain the 'AS ' keyword, it will be prepended.
     *
     * @param string $alias The alias for the table.
     *
     * @return self The current instance for method chaining.
     */
    public function as(string $alias): QueryBuilder
    {
        if (stripos($alias, 'AS ') === false) {
            $alias = "AS " . $this->wrapper->wrapColumn($alias);
        }

        $this->query['alias'] = " $alias ";
        return $this;
    }

    /**
     * Adds a JOIN clause to the query.
     *
     * @param string $table The table to join.
     * @param string|null $field1 The first field to join on.
     * @param string|null $operator The operator to use for the join.
     * @param string|null $field2 The second field to join on.
     * @param string      $type The type of the join (e.g., LEFT, RIGHT, INNER).
     *
     * @return self The current instance for method chaining.
     */
    public function join(string $table, string|null $field1 = null, string|null $operator = null, string|null $field2 = null, string $type = ''): QueryBuilder
    {
        $on = $field1;
        $table = $this->prefix . $table;

        if ($operator !== null) {
            if (empty($field2)) {
                $field2 = $operator;
                $operator = '=';
            }

            $on = $this->wrapOrValue($field1) . " $operator " . $this->wrapOrValue($field2);
        } elseif (!empty($on)) {
            $on = $this->wrapJoinOn($on);
        }

        $this->query['joins'] .= " {$type}JOIN " . $this->wrapAndEscapeColumns($table) . ($on ? " ON $on" : "");

        return $this;
    }

    /**
     * Adds a raw JOIN clause to the query.
     *
     * @param string $sql The raw SQL join clause.
     * @param array $bindings The bindings for the raw SQL.
     *
     * @return self The current instance for method chaining.
     */
    public function joinRaw(string $sql, array $bindings = []): QueryBuilder
    {
        $this->query['joins'] .= " $sql";

        $this->addBindings($sql, $bindings);

        return $this;
    }

    /**
     * Adds an ON clause to the query.
     *
     * @param string $field1 The field to join on.
     * @param null|string $operator The operator to use for the join.
     * @param string $field2 The value to join with.
     * @param string|array|null $parameters The parameters to bind to the query.
     *
     * @return self The current instance for method chaining.
     */
    public function on(string $field1, null|string $operator = null, null|string $field2 = null, null|string|array $parameters = null, string $orOn = 'ON', ): QueryBuilder
    {
        if ($operator !== null) {
            if (empty($field2)) {
                $field2 = $operator;
                $operator = '=';
            }

            $this->query['joins'] .= " " . $orOn . " " . $this->wrapper->wrapColumn($field1) . " $operator " . $this->wrapOrValue($field2);
        } else {
            $this->query['joins'] .= " " . $orOn . " " . $this->wrapJoinOn($field1);
        }

        if ($parameters) {
            $this->param($parameters);
        }

        return $this;
    }

    /**
     * Adds an OR clause to the current join condition.
     *
     * @param string $field1 The first field to join on.
     * @param string|null $operator The operator to use for the join.
     * @param string|null $field2 The second field to join on.
     * @param string|array|null $parameters The parameters to bind to the query.
     *
     * @return self The current instance for method chaining.
     */
    public function orOn(string $field1, null|string $operator = null, null|string $field2 = null, null|string|array $parameters = null): QueryBuilder
    {
        return $this->on($field1, $operator, $field2, $parameters, 'OR');
    }

    /**
     * Adds an AND clause to the current join condition.
     *
     * @param string $field1 The first field to join on.
     * @param string|null $operator The operator to use for the join.
     * @param string|null $field2 The second field to join on.
     * @param string|array|null $parameters The parameters to bind to the query.
     *
     * @return self The current instance for method chaining.
     */
    public function andOn(string $field1, null|string $operator = null, null|string $field2 = null, null|string|array $parameters = null): QueryBuilder
    {
        return $this->on($field1, $operator, $field2, $parameters, 'AND');
    }

    /**
     * Adds an INNER JOIN clause to the query.
     *
     * @param string      $table The table to join.
     * @param string|null $field1 The first field to join on.
     * @param string|null $operator The operator to use for the join.
     * @param string|null $field2 The second field to join on.
     *
     * @return self The current instance for method chaining.
     */
    public function innerJoin(string $table, string|null $field1 = null, string|null $operator = null, string|null $field2 = null)
    {
        return $this->join($table, $field1, $operator, $field2, 'INNER ');
    }

    /**
     * Adds a LEFT JOIN clause to the query.
     *
     * @param string      $table The table to join.
     * @param string|null $field1 The first field to join on.
     * @param string|null $operator The operator to use for the join.
     * @param string|null $field2 The second field to join on.
     *
     * @return self The current instance for method chaining.
     */
    public function leftJoin(string $table, string|null $field1 = null, string|null $operator = null, string|null $field2 = null)
    {
        return $this->join($table, $field1, $operator, $field2, 'LEFT ');
    }

    /**
     * Adds a RIGHT JOIN clause to the query.
     *
     * @param string      $table The table to join.
     * @param string|null $field1 The first field to join on.
     * @param string|null $operator The operator to use for the join.
     * @param string|null $field2 The second field to join on.
     *
     * @return self The current instance for method chaining.
     */
    public function rightJoin(string $table, string|null $field1 = null, string|null $operator = null, string|null $field2 = null)
    {
        return $this->join($table, $field1, $operator, $field2, 'RIGHT ');
    }

    /**
     * Adds a FULL OUTER JOIN clause to the query.
     *
     * @param string      $table The table to join.
     * @param string|null $field1 The first field to join on.
     * @param string|null $operator The operator to use for the join.
     * @param string|null $field2 The second field to join on.
     *
     * @return self The current instance for method chaining.
     */

    public function fullOuterJoin(string $table, string|null $field1 = null, string|null $operator = null, string|null $field2 = null)
    {
        return $this->join($table, $field1, $operator, $field2, 'FULL OUTER ');
    }

    /**
     * Adds a LEFT OUTER JOIN clause to the query.
     *
     * @param string      $table The table to join.
     * @param string|null $field1 The first field to join on.
     * @param string|null $operator The operator to use for the join.
     * @param string|null $field2 The second field to join on.
     *
     * @return self The current instance for method chaining.
     */
    public function leftOuterJoin(string $table, string|null $field1 = null, string|null $operator = null, string|null $field2 = null)
    {
        return $this->join($table, $field1, $operator, $field2, 'LEFT OUTER ');
    }

    /**
     * Adds a RIGHT OUTER JOIN clause to the query.
     *
     * @param string      $table The table to join.
     * @param string|null $field1 The first field to join on.
     * @param string|null $operator The operator to use for the join.
     * @param string|null $field2 The second field to join on.
     *
     * @return self The current instance for method chaining.
     */
    public function rightOuterJoin(string $table, string|null $field1 = null, string|null $operator = null, string|null $field2 = null)
    {
        return $this->join($table, $field1, $operator, $field2, 'RIGHT OUTER ');
    }

    /**
     * Sets the ordering clause for the query.
     *
     * @param null|string $sort Order by clause as a string (e.g., 'field ASC').
     * @return self
     */
    public function order(null|string $sort = null): QueryBuilder
    {
        if ($sort !== null) {
            $this->query['order'] = $sort;
        }

        return $this;
    }

    /**
     * Sets the ordering clause for the query.
     *
     * @param string $field The field to order by.
     * @param string $sort The sorting direction, defaults to 'ASC'.
     * @return self
     */
    public function orderBy(string $field, string $sort = 'ASC'): QueryBuilder
    {
        $this->query['order'] = $this->wrapper->wrapColumn($field) . " $sort";
        return $this;
    }

    /**
     * Sets a raw ordering clause for the query.
     *
     * @param string $sql The raw SQL for the ORDER BY clause.
     * @param array $bindings Optional bindings for the raw SQL.
     * @return self
     */
    public function orderByRaw(string $sql, array $bindings = []): QueryBuilder
    {
        $this->query['order'] = $sql;

        $this->addBindings($sql, $bindings);

        return $this;
    }

    /**
     * Sets ascending order for a specified field.
     *
     * @param null|string $field Field to order by in ascending order, defaults to 'id'.
     * @return self
     */
    public function orderAsc(null|string $field = null): QueryBuilder
    {
        $field ??= $this->withAlias('id');

        $this->query['order'] = "$field ASC";
        return $this;
    }

    /**
     * Sets descending order for a specified field.
     *
     * @param null|string $field Field to order by in descending order, defaults to 'id'.
     * @return self
     */
    public function orderDesc(null|string $field = null): QueryBuilder
    {
        $field ??= $this->withAlias('id');

        $this->query['order'] = "$field DESC";
        return $this;
    }

    /**
     * Sets the GROUP BY clause for the query.
     *
     * @param string|array $field Group by clause as a string or array.
     * @return self
     */
    public function groupBy(string|array $field): QueryBuilder
    {
        $field = is_array($field) ? $field : func_get_args();

        $this->query['group'] = $this->wrapper->columnize($field);
        return $this;
    }

    /**
     * Sets a raw GROUP BY clause for the query.
     *
     * @param string $sql The raw SQL for the GROUP BY clause.
     * @param array $bindings Optional bindings for the raw SQL.
     * @return self
     */
    public function groupByRaw(string $sql, array $bindings = []): QueryBuilder
    {
        $this->query['group'] = $sql;

        $this->addBindings($sql, $bindings);

        return $this;
    }

    /**
     * Sets the HAVING clause for the query.
     *
     * @param string $having Having clause as a string.
     * @return self
     */
    public function having(string $having): QueryBuilder
    {
        $this->query['having'] = $having;
        return $this;
    }

    /**
     * Sets a limit and optional offset for the query.
     *
     * @param string|int $from Starting point for the query.
     * @param int|null $to Ending point for the query, if specified.
     * @return self
     */
    public function limit(string|int $from, null|int $to = null): QueryBuilder
    {
        if ($to === null) {
            $this->query['limit'] = $from;
        } else {
            $this->query['offset'] = $from;
            $this->query['limit'] = $to;
        }

        return $this;
    }

    /**
     * Sets the offset for the query.
     *
     * @param int $offset The offset for the query.
     * @return self
     */
    public function offset(int $offset): QueryBuilder
    {
        $this->query['offset'] = $offset;
        return $this;
    }

    /**
     * Specifies the number of records to fetch.
     *
     * @param int $limit Number of records to fetch.
     * @return self
     */
    public function take(int $limit): QueryBuilder
    {
        $this->query['limit'] = $limit;
        return $this;
    }

    /**
     * Skip a number of records.
     *
     * @param int $count Number of records to skip.
     * @return self
     */
    public function skip(int $count): QueryBuilder
    {
        $this->query['offset'] = $count;
        return $this;
    }

    /**
     * Specifies the fetch mode(s) for the query results.
     *
     * @param mixed ...$fetch PDO fetch styles (e.g., PDO::FETCH_ASSOC).
     * @return self
     */
    public function fetch(...$fetch): QueryBuilder
    {
        $this->query['fetch'] = $fetch;
        return $this;
    }

    /**
     * Specifies that results should be fetched as associative arrays.
     *
     * @return self
     */
    public function fetchAssoc(): QueryBuilder
    {
        return $this->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Specifies that results should be fetched as numeric arrays.
     *
     * @return self
     */
    public function fetchColumn(): QueryBuilder
    {
        return $this->fetch(PDO::FETCH_COLUMN);
    }

    /**
     * Specifies that results should be fetched as objects.
     *
     * @return self
     */
    public function fetchClass(string $className, array $ctorArgs = []): QueryBuilder
    {
        return $this->fetch(PDO::FETCH_CLASS, $className, $ctorArgs);
    }

    /**
     * Get distinct values for a column.
     *
     * @param null|string $column
     * @return self
     */
    public function distinct(null|string $column = null): QueryBuilder
    {
        if ($column) {
            $this->query['select'] = "DISTINCT {$this->wrapAndEscapeColumns($column)}";
        } else {
            $this->query['select'] = str_replace('SELECT ', 'SELECT DISTINCT ', $this->query['select'] ?? '*');
        }

        return $this;
    }

    /**
     * Get the SQL representation of the query.
     *
     * @return string
     */
    public function toSql(): string
    {
        if (empty($this->query['select'])) {
            $this->select();
        }

        $table = $this->getTableName();

        return "SELECT {$this->query['select']} FROM $table"
            . $this->query['alias']
            . $this->query['sql']
            . $this->query['joins']
            . $this->getWhereSql()
            . (isset($this->query['group']) ? ' GROUP BY ' . trim($this->query['group']) : '')
            . (isset($this->query['having']) ? ' HAVING ' . trim($this->query['having']) : '')
            . (isset($this->query['order']) ? ' ORDER BY ' . trim($this->query['order']) : '')
            . $this->buildLimitOffset()
            . ($this->query['unions'] ?? '');
    }

    /**
     * Add a UNION clause to the query.
     *
     * @param QueryBuilder|Closure $query
     * @param bool $all
     * @return self
     */
    public function union(QueryBuilder|Closure $query, bool $all = false): QueryBuilder
    {
        $unionType = $all ? 'UNION ALL' : 'UNION';

        if ($query instanceof Closure) {
            $newQuery = new static($this->database);
            $newQuery->table($this->table);
            $query($newQuery);
            $query = $newQuery;
        }

        // Build the union query
        $unionSql = $query->toSql();

        if (!isset($this->query['unions'])) {
            $this->query['unions'] = '';
        }

        $this->query['unions'] .= " {$unionType} ({$unionSql})";

        // Merge bindings
        $this->bindings = [...$this->bindings, ...$query->getBindings()];

        return $this;
    }

    /**
     * Wraps and escapes column names for use in SQL queries.
     *
     * @param array|string $columns The column names to wrap and escape.
     * @return string The wrapped and escaped column names.
     */
    private function wrapAndEscapeColumns(array|string $columns): string
    {
        if (is_string($columns) && $columns === '*') {
            return '*';
        }

        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }

        $columns = array_map(function ($column) {
            $columns = explode(' as ', str_ireplace(' As ', ' as ', $column));
            return implode(' as ', array_map([$this, 'wrapOrValue'], $columns));
        }, $columns);

        return implode(', ', $columns);
    }

    /**
     * Wraps and escapes a value for use in SQL queries.
     *
     * @param string $value The value to wrap and escape.
     * @return string The wrapped and escaped value.
     */
    private function wrapOrValue(string $value): string
    {
        if (
            str_contains($value, '?') ||
            preg_match('/:\w+/', $value) ||
            preg_match('/\([^)]*\)/', $value)
        ) {
            return $value;
        }

        return $this->wrapper->wrapColumn($value);
    }

    /**
     * Wraps and escapes the ON clause for use in SQL queries.
     *
     * @param string $on The ON clause to wrap and escape.
     * @return string The wrapped and escaped ON clause.
     */
    private function wrapJoinOn(string $on): string
    {
        // Determine the operator used in the ON clause
        $operator = str_contains($on, '=') ? '=' :
            (str_contains($on, '!=') ? '!=' : null);

        if ($operator) {
            // Split the ON clause into its components
            [$field1, $field2] = array_map(
                'trim',
                explode($operator, $on, 2)
            );

            // Wrap the values for the ON clause
            $on = $this->wrapOrValue($field1) . " $operator " . $this->wrapOrValue($field2);
        }

        return $on;
    }

    /**
     * Adds bindings for a SQL query.
     *
     * @param string $sql The SQL query string.
     * @param string|array $bindings The bindings to add.
     * @return void
     */
    private function addBindings(string $sql, string|array $bindings = []): void
    {
        if (is_array($bindings) && !str_contains($sql, '?') && preg_match('/\:(\w+)/', $sql)) {
            $this->bindings = [...$this->bindings, ...$bindings];
        } else {
            $this->param($bindings);
        }
    }

    /**
     * Builds the LIMIT and OFFSET clause for the SQL query.
     *
     * @return string The LIMIT and OFFSET clause.
     */
    private function buildLimitOffset(): string
    {
        if (isset($this->query['offset']) && isset($this->query['limit'])) {
            return ' LIMIT ' . $this->query['offset'] . ", " . $this->query['limit'];
        } elseif (isset($this->query['limit'])) {
            return ' LIMIT ' . $this->query['limit'];
        }

        return '';
    }
}
