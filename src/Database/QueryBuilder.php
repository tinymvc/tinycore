<?php

namespace Spark\Database;

use Closure;
use Spark\Contracts\Database\QueryBuilderContract;
use Spark\Database\Exceptions\QueryBuilderException;
use Spark\Database\Exceptions\QueryBuilderInvalidWhereClauseException;
use Spark\Database\Schema\Grammar;
use Spark\Support\Collection;
use Spark\Support\Traits\Macroable;
use Spark\Utils\Paginator;
use PDO;
use PDOStatement;

/**
 * Class Query
 *
 * This class provides methods to build and execute SQL queries for CRUD operations and 
 * joins in a structured and dynamic way.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class QueryBuilder implements QueryBuilderContract
{
    use Macroable;

    /**
     * Holds the SQL and bind parameters for the WHERE clause.
     * 
     * @var array
     */
    private array $where = ['sql' => '', 'bind' => [], 'grouped' => false];

    /**
     * Holds the SQL structure, join conditions, and join count.
     * 
     * @var array
     */
    private array $query = ['sql' => '', 'alias' => '', 'joins' => ''];

    /**
     * Array to store data mappers for processing retrieved data.
     * 
     * @var array
     */
    private array $dataMapper = [];

    /**
     * Holds the table name to be used for the query.
     * 
     * @var string
     */
    private string $table;

    /**
     * Holds the table prefix to be used for the query.
     * 
     * @var string $prefix
     */
    private string $prefix = '';

    /**
     * Holds the database schema grammar for the query.
     * 
     * @var \Spark\Database\Schema\Grammar
     */
    private Grammar $grammar;

    /**
     * Constructor for the query class.
     *
     * Initializes the query object with a database instance, 
     * which is used for executing SQL queries.
     *
     * @param DB $database The database instance to be used for query execution.
     */
    public function __construct(private DB $database)
    {
        $this->grammar = new Grammar($this->database->getDriver());
    }

    /**
     * Sets the table name to be used for the query.
     * 
     * @param string $table The table name to set.
     * @return self
     */
    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Sets the table prefix to be used for the query.
     * 
     * @param string $prefix The table prefix to set.
     * @return self
     */
    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Adds a data mapper callback to process query results.
     * 
     * @param callable $callback The callback function to process data.
     * @return self Returns the query object.
     */
    public function addMapper(callable $callback): self
    {
        $this->dataMapper[] = $callback;
        return $this;
    }

    /**
     * Inserts data into the database with optional configurations.
     * 
     * @param array $data The data to insert (single record or multiple records)
     * @param array $config Optional configurations [
     *     'ignore' => bool,      // Skip errors on duplicate
     *     'replace' => bool,     // Replace existing records
     *     'conflict' => array,   // Conflict target columns (for ON CONFLICT)
     *     'update' => array,     // Columns to update on conflict
     *     'returning' => mixed   // Columns to return (PostgreSQL)
     * ]
     * @return int|array Returns last insert ID or array of returned data (PostgreSQL with returning)
     * @throws QueryBuilderException
     */
    public function insert(array $data, array $config = []): int|array
    {
        if (empty($data)) {
            return 0;
        }

        // Normalize data to always be an array of records
        $data = !(isset($data[0]) && is_array($data[0])) ? [$data] : $data;

        $fields = array_keys($data[0]);

        // Generate the SQL statement
        $sql = $this->compileInsert($data, $config);

        // Prepare the statement
        $statement = $this->database->prepare($sql);
        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind all values
        $this->bindInsertValues($statement, $data, $fields);

        // Execute the statement
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        // Handle PostgreSQL RETURNING clause
        if ($this->grammar->isPostgreSQL() && isset($config['returning'])) {
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->database->getPdo()->lastInsertId();
    }

    /**
     * Update multiple records into the database with optional configurations.
     * 
     * @param array $data 
     * @param array $config 
     * @return int 
     */
    public function bulkUpdate(array $data, array $config = []): int
    {
        // Transform single records into multiple.
        if (!(isset($data[0]) && is_array($data[0]))) {
            $data = [$data];
        }

        // Add default update close, if provided none.
        if (!isset($config['conflict'])) {
            $config['conflict'] = ['id'];
        }

        // Add default update fields, if provided none.
        if (!isset($config['update'])) {
            // Extract all fields except those are in $config['conflict'].
            $fields = array_filter(
                array_keys($data[0]),
                fn($field) => !in_array($field, $config['conflict'])
            );

            // Add extracted fields to be updated on conflict.
            $config['update'] = array_merge(...array_map(fn($field) => [$field => $field], $fields));
        }

        // Returns to base insert method. integer on success else, 0 on fails. 
        return $this->insert($data, $config);
    }

    /**
     * Add a where clause to the query.
     *
     * @param string|array|Closure $column 
     *   The column name to query, or an array of column names.
     * @param string|null $operator 
     *   The operator to use. If null, the operator will be determined
     *   based on the value given.
     * @param mixed $value 
     *   The value to query. If null, the value will be determined
     *   based on the operator given.
     * @param ?string $andOr 
     *   The type of where clause to add. May be 'AND' or 'OR'.
     * @param bool $not
     *   If true, the where clause will be negated.
     * 
     * @return self
     */
    public function where(string|array|Closure $column = null, ?string $operator = null, mixed $value = null, ?string $andOr = null, bool $not = false): self
    {
        if ($column !== null) {
            return $this;
        } elseif ($column instanceof Closure) {
            return $column($this);
        }

        $andOr ??= 'AND';

        // Holds a conditional clause for database.
        $command = '';

        if (is_string($column) && is_string($operator)) {
            // Create a where clause from column, operator, and value.
            // for example: "title like :title"
            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $command = sprintf(
                "%s %s %s :%s",
                $andOr,
                $column,
                $operator,
                str_replace('.', '', $column)
            );
            $this->where['bind'] = array_merge($this->where['bind'], [$column => $value]);
        } elseif (is_array($column) && $operator === null && $value === null) {
            // Create a where clause from array conditions.
            $command = sprintf(
                "%s %s",
                $andOr,
                implode(
                    " {$andOr} ",
                    array_map(
                        fn($attr, $value) => $attr . (is_array($value) ?
                            // Create a where clause to match IN(), Ex: "id IN(:id_0, :id_1, :id_2, :id_3)" .
                            sprintf(
                                ($not ? ' IS NOT' : '') . " IN (%s)",
                                join(",", array_map(fn($index) => ':' . str_replace('.', '', $attr) . '_' . $index, array_keys($value)))
                            )
                            // Create a where close to match is equal, Ex. "id = :id_0"
                            : ($not ? ' !=' : ' =') . " :" . str_replace('.', '', $attr)
                        ),
                        array_keys($column),
                        array_values($column)
                    )
                )
            );

            // Append where clause binding values, safe & GOOD PDO practice.
            $this->where['bind'] = array_merge($this->where['bind'], $column);
        } elseif (is_string($column) && $operator === null && $value === null) {
            // Simply add a where clause from string.
            $command = "{$andOr} {$column}";
        } else {
            throw new QueryBuilderInvalidWhereClauseException('Invalid where clause');
        }

        // Grouped where clauses.
        if ($this->where['grouped']) {
            $command = "($command";
            $this->where['grouped'] = false;
        }

        // Register the where clause into current query builder.
        $this->where['sql'] .= sprintf(
            ' %s ',
            empty($this->where['sql']) ? ltrim($command, "$andOr ") : $command
        );

        // Returns the current instance for method chaining.
        return $this;
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
    public function orWhere(string|array $column = null, ?string $operator = null, $value = null): self
    {
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
    public function notWhere(string|array $column = null, ?string $operator = null, $value = null): self
    {
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
    public function orNotWhere(string|array $column = null, ?string $operator = null, $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR', true);
    }

    /**
     * Adds a WHERE condition that the given column is null.
     *
     * @param string $where
     *   The column name to query.
     * @param bool $not
     *   Whether to use IS NOT NULL instead of IS NULL.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function whereNull($where, $not = false): self
    {
        $where = $where . ' IS ' . ($not ? 'NOT' : '') . ' NULL';

        return $this->where($where);
    }

    /**
     * Adds a WHERE condition that the given column is not null.
     *
     * @param string $where
     *   The column name to query.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function whereNotNull($where): self
    {
        return $this->whereNull($where, true);
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
    public function in(string $column, array $values): self
    {
        return $this->where([$column => $values]);
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
    public function notIn(string $column, array $values): self
    {
        return $this->where(column: [$column => $values], not: true);
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
    public function orIn($column, array $values): self
    {
        return $this->where(column: [$column => $values], andOr: 'OR');
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
    public function orNotIn($column, array $values): self
    {
        return $this->where(column: [$column => $values], andOr: 'OR ', not: true);
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
    public function findInSet($field, $key, $type = '', $andOr = 'AND'): self
    {
        // If the key is not numeric, wrap it with grammar-specific quotes
        $key = is_numeric($key) ? $key : $this->grammar->wrap($key);

        // Construct the FIND_IN_SET condition
        $where = "{$type}FIND_IN_SET ($key, $field)";

        // Add the condition to the query's WHERE clause
        return $this->where(column: $where, andOr: $andOr);
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
    public function notFindInSet($field, $key): self
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
    public function orFindInSet($field, $key): self
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
    public function orNotFindInSet($field, $key): self
    {
        return $this->findInSet($field, $key, 'NOT ', 'OR');
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
    public function between($field, $value1, $value2, $type = '', $andOr = 'AND'): self
    {
        $where = '(' . $field . ' ' . $type . 'BETWEEN '
            . ($this->grammar->wrap($value1) . ' AND ' . $this->grammar->wrap($value2)) . ')';

        return $this->where(column: $where, andOr: $andOr);
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
    public function notBetween($field, $value1, $value2): self
    {
        return $this->between($field, $value1, $value2, 'NOT ');
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
    public function orBetween($field, $value1, $value2): self
    {
        return $this->between($field, $value1, $value2, '', 'OR');
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
    public function orNotBetween($field, $value1, $value2): self
    {
        return $this->between($field, $value1, $value2, 'NOT ', 'OR');
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
    public function like($field, $data, $type = '', $andOr = 'AND'): self
    {
        $like = $this->grammar->wrap($data);
        $where = "$field {$type}LIKE $like";

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
    public function orLike($field, $data): self
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
    public function notLike($field, $data): self
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
    public function orNotLike($field, $data): self
    {
        return $this->like($field, $data, 'NOT ', 'OR');
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
     * @param \Closure $callback
     *   The callback to call to add the conditions.
     *
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function grouped(Closure $callback)
    {
        $this->where['grouped'] = true;
        call_user_func($callback, $this);
        $this->where['sql'] .= ')';

        return $this;
    }

    /**
     * Updates records in the database based on specified data and conditions.
     *
     * @param array $data  Key-value pairs of columns and their respective values to update.
     * @param mixed $where  Optional WHERE clause to specify which records to update.
     * @return bool
     */
    public function update(array $data, mixed $where = null): bool
    {
        // Apply WHERE condition if provided
        $this->where($where);

        // Abort if no WHERE condition is set to avoid accidental updates on all records
        if (!$this->hasWhere()) {
            return false;
        }

        // Prepare the table name
        $table = $this->grammar->wrapTable($this->prefix . $this->table);

        // Prepare the SQL update statement
        $statement = $this->database->prepare(
            sprintf(
                "UPDATE {$table} SET %s %s",
                implode(', ', array_map(fn($attr) => "$attr=:$attr", array_keys($data))),
                $this->getWhereSql()
            )
        );

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind the values for update
        foreach ($data as $key => $val) {
            $statement->bindValue(":$key", $val);
        }

        // Bind the WHERE clause parameters
        $this->bindWhere($statement);

        // Execute the statement and reset the WHERE clause
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->resetWhere();

        // Returns true if records are successfully updated, false otherwise.
        return $statement->rowCount() > 0;
    }

    /**
     * Deletes records from the database based on specified conditions.
     *
     * @param mixed $where  Optional WHERE clause to specify which records to delete.
     * @return bool
     */
    public function delete(mixed $where = null): bool
    {
        // Apply WHERE condition if provided
        $this->where($where);

        // Abort if no WHERE condition is set to avoid accidental deletion of all records
        if (!$this->hasWhere()) {
            return false;
        }

        // Prepare the table name
        $table = $this->grammar->wrapTable($this->prefix . $this->table);

        // Prepare the SQL delete statement
        $statement = $this->database->prepare("DELETE FROM {$table} {$this->getWhereSql()}");

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind the WHERE clause parameters
        $this->bindWhere($statement);

        // Execute the statement and reset the WHERE clause
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        // Reset current query builder.
        $this->resetWhere();

        // Returns true if records are successfully deleted, false otherwise.
        return $statement->rowCount() > 0;
    }

    /**
     * Specify the fields to include in the SELECT clause.
     *
     * @param array|string $fields A string or an array of column names to select.
     * @return self The current instance for method chaining.
     */
    public function select(array|string $fields = '*'): self
    {
        // Convert array of fields to a comma-separated string if necessary
        if (is_array($fields)) {
            $fields = implode(',', $fields);
        }

        if (stripos($fields, ' FROM ') === false) {
            // Build the FROM clause
            $fields .= isset($this->table) ? " FROM {$this->grammar->wrapTable($this->prefix . $this->table)}" : '';
        }

        // Build the initial SELECT SQL query
        $this->query['sql'] = "SELECT {$fields}";

        // Returns the current instance for method chaining.
        return $this;
    }

    /**
     * @param string      $field
     * @param string|null $name
     *
     * @return $this
     */
    public function max($field, $name = null)
    {
        $column = 'MAX(' . $field . ')' . (!$name === null ? " AS $name" : '');
        $this->select($column);

        return $this;
    }

    /**
     * @param string      $field
     * @param string|null $name
     *
     * @return $this
     */
    public function min($field, $name = null)
    {
        $column = 'MIN(' . $field . ')' . (!$name === null ? " AS $name" : '');
        $this->select($column);

        return $this;
    }

    /**
     * @param string      $field
     * @param string|null $name
     *
     * @return $this
     */
    public function sum($field, $name = null)
    {
        $column = 'SUM(' . $field . ')' . (!$name === null ? " AS $name" : '');
        $this->select($column);

        return $this;
    }

    /**
     * @param string      $field
     * @param string|null $name
     *
     * @return $this
     */
    public function avg($field, $name = null)
    {
        $column = 'AVG(' . $field . ')' . (!$name === null ? " AS $name" : '');
        $this->select($column);

        return $this;
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
    public function as(string $alias): self
    {
        if (stripos($alias, 'AS ') === false) {
            $alias = "AS {$alias}";
        }

        $this->query['alias'] = " {$alias} ";
        return $this;
    }

    /**
     * Sets an alias for the current table in the FROM clause.
     *
     * @param string $alias The alias for the table.
     *
     * @return self The current instance for method chaining.
     */
    public function alias(string $alias): self
    {
        return $this->as($alias);
    }

    /**
     * Adds a JOIN clause to the query.
     *
     * @param string      $table The table to join.
     * @param string|null $field1 The first field to join on.
     * @param string|null $operator The operator to use for the join.
     * @param string|null $field2 The second field to join on.
     * @param string      $type The type of the join (e.g., LEFT, RIGHT, INNER).
     *
     * @return self The current instance for method chaining.
     */
    public function join(string $table, $field1 = null, $operator = null, $field2 = null, $type = ''): self
    {
        $on = $field1;
        $table = $this->grammar->wrapTable($this->prefix . $table);

        if ($operator !== null) {
            if ($field2 === null) {
                $field2 = $operator;
                $operator = '=';
            }

            $on = "$field1 $operator $field2";
        }

        $this->query['joins'] .= " {$type}JOIN $table ON $on";

        return $this;
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
    public function innerJoin($table, $field1, $operator = '', $field2 = '')
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
    public function leftJoin($table, $field1, $operator = '', $field2 = '')
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
    public function rightJoin($table, $field1, $operator = '', $field2 = '')
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

    public function fullOuterJoin($table, $field1, $operator = '', $field2 = '')
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
    public function leftOuterJoin($table, $field1, $operator = '', $field2 = '')
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
    public function rightOuterJoin($table, $field1, $operator = '', $field2 = '')
    {
        return $this->join($table, $field1, $operator, $field2, 'RIGHT OUTER ');
    }

    /**
     * Sets the ordering clause for the query.
     *
     * @param ?string $sort Order by clause as a string (e.g., 'field ASC').
     * @return self
     */
    public function order(?string $sort = null): self
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
    public function orderBy(string $field, string $sort = 'ASC'): self
    {
        $this->query['order'] = "$field $sort";
        return $this;
    }

    /**
     * Sets ascending order for a specified field.
     *
     * @param string $field Field to order by in ascending order, defaults to 'id'.
     * @return self
     */
    public function orderAsc(string $field = 'id'): self
    {
        $this->query['order'] = "$field ASC";
        return $this;
    }

    /**
     * Sets descending order for a specified field.
     *
     * @param string $field Field to order by in descending order, defaults to 'id'.
     * @return self
     */
    public function orderDesc(string $field = 'id'): self
    {
        $this->query['order'] = "$field DESC";
        return $this;
    }

    /**
     * Sets the GROUP BY clause for the query.
     *
     * @param string|array $fields Group by clause as a string or array.
     * @return self
     */
    public function group(string|array $fields): self
    {
        $this->query['group'] = $this->grammar->columnize($fields);
        return $this;
    }

    /**
     * Alias for the group() method.
     *
     * @param string|array $group Group by clause as a string or array.
     * @return self
     */
    public function groupBy(string|array $group): self
    {
        return $this->group($group);
    }

    /**
     * Sets the HAVING clause for the query.
     *
     * @param string $having Having clause as a string.
     * @return self
     */
    public function having(string $having): self
    {
        $this->query['having'] = $having;
        return $this;
    }

    /**
     * Sets a limit and optional offset for the query.
     *
     * @param int|null $offset Starting point for the query, if specified.
     * @param int|null $limit Number of records to fetch.
     * @return self
     */
    public function limit(?int $offset = null, ?int $limit = null): self
    {
        if ($offset !== null) {
            $this->query['limit'] = sprintf(" %s%s", $offset, $limit !== null ? ", $limit" : '');
        }

        return $this;
    }

    /**
     * Specifies the number of records to fetch.
     *
     * @param int $limit Number of records to fetch.
     * @return self
     */
    public function take(int $limit): self
    {
        $this->query['limit'] = $limit;

        return $this;
    }

    /**
     * Specifies the fetch mode(s) for the query results.
     *
     * @param mixed ...$fetch PDO fetch styles (e.g., PDO::FETCH_ASSOC).
     * @return self
     */
    public function fetch(...$fetch): self
    {
        $this->query['fetch'] = $fetch;
        return $this;
    }

    /**
     * Retrieves the first result from the query.
     *
     * @return mixed
     */
    public function first(): mixed
    {
        // Execute current select query by limiting to single record.
        $this->take(1)->executeSelectQuery();

        // Fetch first record from database and apply mapper if exists.
        $result = $this->applyMapper(
            $this->getStatement()
                ->fetchAll(
                    ...$this->query['fetch'] ?? [PDO::FETCH_OBJ]
                )
        );

        // Reset current query builder.
        $this->resetQuery();

        // The first result as an object or false if none found.
        return $result[0] ?? false;
    }

    /**
     * Retrieves the last result by applying descending order and fetching the first.
     *
     * @return mixed
     */
    public function last(): mixed
    {
        // The last result as an object or false if none found.
        return $this->orderDesc()->first();
    }

    /**
     * Retrieves the latest results by ordering in descending order.
     *
     * @return array
     */
    public function latest(): array
    {
        // Array of the latest results.
        return $this->orderDesc()->result();
    }

    /**
     * Retrieves all results from the executed query.
     *
     * @return array Array of query results.
     */
    public function result(): array
    {
        // Execute current sql swlwct command.
        $this->executeSelectQuery();

        // Fetch all results from database.
        $result = $this->getStatement()
            ->fetchAll(
                ...$this->query['fetch'] ?? [PDO::FETCH_OBJ]
            );

        // Reset current query builder.
        $this->resetQuery();

        // Apply data mapper if exists in current query.
        return $this->applyMapper($result);
    }

    /**
     * Retrieves all results from the executed query.
     *
     * @return array Array of query results.
     */
    public function all(): array
    {
        return $this->result();
    }

    /**
     * Retrieves all results from the executed query and returns them in a collection.
     *
     * @return \Spark\Support\Collection Array of query results.
     */
    public function collect(): Collection
    {
        return collect($this->result());
    }

    /**
     * Paginates query results.
     *
     * @param int $limit Number of items per page.
     * @param string $keyword URL query parameter name for pagination.
     * @return Paginator
     */
    public function paginate(int $limit = 10, string $keyword = 'page'): Paginator
    {
        // Select records & Create a paginator object.
        if (empty($this->query['sql'])) {
            $this->select();
        }

        $paginator = get(Paginator::class);
        $paginator->limit = $limit;
        $paginator->keyword = $keyword;

        // Count total records from exisitng command only for serverside database driver.
        if ($this->database->isDriver('mysql')) {
            $this->query['sql'] = preg_replace('/SELECT /', 'SELECT SQL_CALC_FOUND_ROWS ', $this->query['sql'], 1);
        }

        // Set pagination count to limit database records, and execute query.
        $this->limit(
            ceil($limit * ($paginator->getKeywordValue() - 1)),
            $limit
        )
            ->executeSelectQuery();

        // Get total record count, from sqlite database and update it to paginator class.
        if ($this->database->isDriver('mysql')) {
            // Get number of records from exisitng query command.
            $total = $this->database->prepare('SELECT FOUND_ROWS()');
            $total->execute();

            // Update number of items into paginator class.
            $paginator->total = $total->fetch(PDO::FETCH_COLUMN);
        } else {
            $paginator->total = $this->count();
        }

        // Set database records into paginator class.
        $paginator->setData(
            $this->applyMapper(
                $this->getStatement()
                    ->fetchAll(...$this->query['fetch'] ?? [PDO::FETCH_OBJ])
            )
        );

        // Re-initialize paginator pages.
        $paginator->resetPaginator();

        // Reset current query builder.
        $this->resetQuery();

        // A paginator instance containing paginated results.
        return $paginator;
    }

    /**
     * Counts the number of rows matching the current query.
     *
     * @return int The number of matching rows.
     */
    public function count(): int
    {
        // Get table name.
        $table = $this->grammar->wrapTable($this->prefix . $this->table);

        // Create sql command to count rows.
        $statement = $this->database->prepare(
            "SELECT COUNT(1) FROM {$table}"
            . $this->query['alias']
            . $this->query['joins']
            . $this->getWhereSql()
            . (isset($this->query['group']) ? ' GROUP BY ' . trim($this->query['group']) : '')
            . (isset($this->query['having']) ? ' HAVING ' . trim($this->query['having']) : '')
        );

        // Apply where statement if exists.
        $this->bindWhere($statement);

        // Execute sql command.
        $statement->execute();

        // Returns number of found rows.
        return $statement->fetch(PDO::FETCH_COLUMN);
    }

    /** @internal helpers methods for this query builder class */

    /**
     * Applies all data mappers to a dataset.
     * 
     * @param array $data Data to process.
     * @return array Processed data after all mappers are applied.
     */
    private function applyMapper(array $data): array
    {
        foreach ($this->dataMapper as $key => $mapper) {
            unset($this->dataMapper[$key]);
            $data = call_user_func($mapper, $data);
        }

        return $data;
    }

    /**
     * Executes a SELECT query with the built query parts.
     *
     * @return void
     */
    private function executeSelectQuery(): void
    {
        // Prepare select command.
        if (empty($this->query['sql'])) {
            $this->select();
        }

        // Build complete select command with condition, order, and limit.
        $statement = $this->database->prepare(
            $this->query['sql']
            . $this->query['alias']
            . $this->query['joins']
            . $this->getWhereSql()
            . (isset($this->query['group']) ? ' GROUP BY ' . trim($this->query['group']) : '')
            . (isset($this->query['having']) ? ' HAVING ' . trim($this->query['having']) : '')
            . (isset($this->query['order']) ? ' ORDER BY ' . trim($this->query['order']) : '')
            . (isset($this->query['limit']) ? ' LIMIT ' . trim($this->query['limit']) : '')
        );

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind/Add conditions to filter records.
        $this->bindWhere($statement);

        // Execute current select command.
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        // Set select statement into query to modify dynamically.
        $this->query['statement'] = $statement;

    }

    /**
     * Get the PDOStatement of the last query
     *
     * @return PDOStatement The PDOStatement of the last query or false if no query has been executed
     */
    private function getStatement(): PDOStatement
    {
        return $this->query['statement'];
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
     * Binds the values of the WHERE clause conditions to the SQL statement.
     *
     * @param PDOStatement $statement The prepared PDO statement to bind values.
     * @return void
     */
    private function bindWhere(PDOStatement &$statement): void
    {
        // Bind where clause values to filter records.
        foreach ($this->where['bind'] ?? [] as $param => $value) {
            /** 
             * Create a placeholder of the parameter exactly added into the where clause.
             * Ex. "id = :id", ==> :id is the parameter.
             */
            $param = ':' . str_replace('.', '', $param);

            if (is_array($value)) {
                // binds clause values from a array condition, Ex. "id IN(1, 2, 3, 4)".
                foreach ($value as $index => $val) {
                    // Add multiple parameter into IN(), Ex. :id_0 => $value, :id_1 => $value;
                    $statement->bindValue("{$param}_$index", $val);
                }
            } else {
                // binds clause values from a string condition, Ex. "id = 1".
                $statement->bindValue($param, $value);
            }
        }
    }

    /**
     * Resets the WHERE clause and clears any existing conditions.
     *
     * @return void
     */
    private function resetWhere(): void
    {
        $this->where = ['sql' => '', 'bind' => [], 'grouped' => false];
    }

    /**
     * Resets the query components for reuse.
     *
     * @return void
     */
    private function resetQuery(): void
    {
        // Reset Select query parameters.
        $this->query = ['sql' => '', 'alias' => '', 'joins' => ''];

        // Reset where query parameters.
        $this->resetWhere();
    }

    /**
     * Compiles the INSERT SQL statement based on configuration.
     * 
     * @param array $data The data to be inserted.
     * @param array $config The configuration array.
     * @return string The compiled INSERT SQL statement.
     */
    private function compileInsert(array $data, array $config): string
    {
        $table = $this->grammar->wrapTable($this->prefix . $this->table);

        // Base command (INSERT/REPLACE)
        $command = $this->getInsertCommand($config);

        // IGNORE modifier
        $ignore = $this->getIgnoreModifier($config);

        // Columns
        $columns = $this->grammar->columnize(
            array_keys($data[0]) // Get keys from first record
        );

        // Values placeholders
        $values = $this->createPlaceholder($data);

        // ON CONFLICT/DUPLICATE KEY UPDATE clause
        $conflict = $this->compileConflictClause($config);

        // RETURNING clause (PostgreSQL)
        $returning = $this->compileReturningClause($config);

        return trim("$command $ignore INTO $table ($columns) VALUES $values $conflict $returning");
    }

    /**
     * Gets the appropriate INSERT command based on configuration.
     * 
     * @param array $config The configuration array.
     * @return string The INSERT command.
     */
    private function getInsertCommand(array $config): string
    {
        if (isset($config['replace']) && $config['replace'] === true) {
            return $this->grammar->isMySQL() ? 'REPLACE' : 'INSERT';
        }
        return 'INSERT';
    }

    /**
     * Gets the IGNORE modifier for the INSERT statement.
     * 
     * @param array $config The configuration array.
     * @return string The IGNORE modifier.
     */
    private function getIgnoreModifier(array $config): string
    {
        if (!isset($config['ignore']) || $config['ignore'] !== true) {
            return '';
        }

        if ($this->grammar->isSQLite()) {
            return 'OR IGNORE';
        }

        if ($this->grammar->isMySQL()) {
            return 'IGNORE';
        }

        // PostgreSQL doesn't support IGNORE, we'll use ON CONFLICT DO NOTHING instead
        return '';
    }

    /**
     * Compiles the conflict resolution clause.
     * 
     * @param array $config The configuration array.
     * @return string The compiled conflict resolution clause.
     */
    private function compileConflictClause(array $config): string
    {
        if (empty($config['update'])) {
            // For PostgreSQL with ignore but no update, use DO NOTHING
            if ($this->grammar->isPostgreSQL() && isset($config['ignore']) && $config['ignore'] === true) {
                $conflictColumns = $this->grammar->columnize($config['conflict'] ?? ['id']);
                return "ON CONFLICT ($conflictColumns) DO NOTHING";
            }
            return '';
        }

        $conflictColumns = $this->grammar->columnize($config['conflict'] ?? ['id']);
        $updates = [];

        foreach ($config['update'] as $key => $value) {
            if ($this->grammar->isPostgreSQL()) {
                $updates[] = $this->grammar->wrapColumn($key) . ' = EXCLUDED.' . $this->grammar->wrapColumn($value);
            } elseif ($this->grammar->isMySQL()) {
                $updates[] = $this->grammar->wrapColumn($key) . ' = VALUES(' . $this->grammar->wrapColumn($value) . ')';
            } elseif ($this->grammar->isSQLite()) {
                $updates[] = $this->grammar->wrapColumn($key) . ' = excluded.' . $this->grammar->wrapColumn($value);
            }
        }

        if ($this->grammar->isPostgreSQL() || $this->grammar->isSQLite()) {
            return "ON CONFLICT ($conflictColumns) DO UPDATE SET " . implode(', ', $updates);
        }

        return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }

    /**
     * Compiles the RETURNING clause for PostgreSQL.
     * 
     * @param array $config The configuration array.
     * @return string The compiled RETURNING clause.
     */
    private function compileReturningClause(array $config): string
    {
        if (!$this->grammar->isPostgreSQL() || empty($config['returning'])) {
            return '';
        }

        $returning = is_array($config['returning'])
            ? $config['returning']
            : [$config['returning']];

        return 'RETURNING ' . $this->grammar->columnize($returning);
    }

    /**
     * Binds all values for the insert statement.
     * 
     * @param PDOStatement $statement The PDO statement.
     * @param array $data The data to bind.
     */
    private function bindInsertValues(PDOStatement $statement, array $data, array $fields): void
    {
        foreach ($data as $serial => $row) {
            foreach ($fields as $column) {
                $value = $row[$column] ?? null;

                // Handle array values (for functions/expressions)
                if (is_array($value)) {
                    $value = $value['text'] ?? ($value['value'] ?? null);
                }

                $statement->bindValue(
                    sprintf(':%s_%s', $column, $serial),
                    $value,
                    $this->getParameterType($value)
                );
            }
        }
    }

    /**
     * Determines the PDO parameter type for a value.
     * 
     * @param mixed $value The value to determine the parameter type for.
     * @return int The PDO parameter type.
     */
    private function getParameterType(mixed $value): int
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }
        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }
        if (is_null($value)) {
            return PDO::PARAM_NULL;
        }
        return PDO::PARAM_STR;
    }

    /**
     * Handles dynamic method calls to the query result collection.
     *
     * @param string $method The method name.
     * @param array $args The method arguments.
     * @return mixed The result of the query result collection method call.
     */
    public function __call($method, $args)
    {
        return call_user_func([$this->collect(), $method], ...$args);
    }
}
