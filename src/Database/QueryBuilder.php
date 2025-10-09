<?php
namespace Spark\Database;

use Closure;
use PDO;
use PDOStatement;
use Spark\Contracts\Database\QueryBuilderContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Database\Exceptions\QueryBuilderException;
use Spark\Database\Exceptions\QueryBuilderInvalidWhereClauseException;
use Spark\Database\Relation\ManageRelation;
use Spark\Database\Schema\Grammar;
use Spark\Exceptions\NotFoundException;
use Spark\Support\Collection;
use Spark\Support\Traits\Macroable;
use Spark\Utils\Paginator;

/**
 * Class Query
 *
 * @method Collection except($keys)
 * @method Collection filter(?callable $callback = null)
 * @method Collection map(callable $callback)
 * @method Collection mapToDictionary(callable $callback)
 * @method Collection mapWithKeys(callable $callback)
 * @method Collection merge($items)
 * @method Collection mergeRecursive($items)
 * @method Collection only($keys)
 * @method Collection forget($keys)
 * @method bool contains($key, $operator = null, $value = null)
 * @method bool doesntContain($key, $operator = null, $value = null)
 * @method bool hasAny($key)
 * @method string implode($value, $glue = null)
 * @method Collection diff($items)
 * @method Collection diffUsing($items, callable $callback)
 * @method Collection diffAssoc($items)
 * @method Collection diffKeys($items)
 * @method Collection duplicates($callback = null, $strict = false)
 * @method Collection keyBy($keyBy)
 * @method Collection intersect($items)
 * @method Collection intersectAssoc($items)
 * @method Collection combine($values)
 * @method Collection union($items)
 * @method Collection nth($step, $offset = 0)
 * @method Collection|TValue|null pop($count = 1)
 * @method Collection|TValue|null shift($count = 1)
 * @method Collection prepend($value, $key = null)
 * @method Collection push(...$values)
 * @method Collection unshift(...$values)
 * @method Collection concat($source)
 * @method mixed pull($key, $default = null)
 * @method mixed random($number = null, $preserveKeys = false)
 * @method mixed search($value, $strict = false)
 * @method Collection put($key, $value)
 * @method Collection reverse()
 * @method Collection shuffle()
 * @method Collection sliding($size = 2, $step = 1)
 * @method Collection skip($count)
 * @method Collection skipUntil($value)
 * @method Collection skipWhile($value)
 * @method Collection slice($offset, $length = null)
 * @method Collection split($numberOfGroups)
 * @method Collection splitIn($numberOfGroups)
 * @method Collection chunk($size, $preserveKeys = true)
 * @method Collection chunkWhile(callable $callback)
 * @method Collection sort($callback = null)
 * @method Collection splice($offset, $length = null, $replacement = [])
 * @method Collection transform(callable $callback)
 * @method Collection dot()
 * @method Collection unique($key = null, $strict = false)
 * @method Collection pad($size, $value)
 * @method Collection add($item)
 *
 * This class provides methods to build and execute SQL queries for CRUD operations and
 * joins in a structured and dynamic way.
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class QueryBuilder implements QueryBuilderContract
{
    use ManageRelation, Macroable {
        __call as macroCall;
    }

    /**
     * Holds the collation to be used for string comparisons.
     *
     * @var string
     */
    public static string $collate;

    /**
     * Holds the table prefix to be used for the query.
     *
     * @var string $prefix
     */
    private static string $prefix;

    /**
     * Holds the SQL parameters for the query.
     *
     * @var array
     */
    private array $parameters = [];

    /**
     * Holds the SQL bind parameters for the query.
     *
     * @var array
     */
    private array $bindings = [];

    /**
     * Holds the SQL and bind parameters for the WHERE clause.
     *
     * @var array
     */
    private array $where = ['sql' => '', 'grouped' => false];

    /**
     * Holds the SQL structure, join conditions, and join count.
     *
     * @var array
     */
    private array $query = ['sql' => '', 'select' => '', 'from' => null, 'alias' => '', 'joins' => ''];

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
        if ($database->isSQLite()) {
            self::$collate = 'nocase'; // Set case insensitive collation for SQLite.
        }

        $this->grammar = new Grammar($database->getDriver());

        self::$prefix ??= ''; // Set default prefix if not already set
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
     * Gets the table name used for the query.
     *
     * @return string The table name.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Gets the alias used for the query.
     *
     * @return string|null The alias or null if not set.
     */
    public function getAlias(): ?string
    {
        if (isset($this->query['alias']) && !empty($this->query['alias'])) {
            return trim(str_ireplace('as ', '', $this->query['alias']));
        }

        return null;
    }

    /**
     * Checks if the query has an alias.
     *
     * @return bool True if the query has an alias, false otherwise.
     */
    public function hasAlias(): bool
    {
        return $this->getAlias() !== null;
    }

    /**
     * Gets the table name with the column name used for the query.
     *
     * @param string $column The column name.
     * @return string The table name with the column name.
     */
    public function withAlias(string $column): string
    {
        if ($this->hasAlias()) {
            return "{$this->getAlias()}.$column";
        }

        return "{$this->table}.$column";
    }

    /**
     * Sets the collation to be used for string comparisons in the query.
     *
     * This method allows you to specify a collation for string comparisons,
     * which can be useful for case sensitivity or locale-specific sorting.
     *
     * @param string $collate The collation to set.
     * @return self
     */
    public function collate(string $collate): self
    {
        self::$collate = $collate;
        return $this;
    }

    /**
     * Returns the database instance associated with this query.
     *
     * @return DB The database instance associated with this query.
     */
    public function getDB(): DB
    {
        return $this->database;
    }

    /**
     *  Sets the database instance for this query.
     *
     *  This method allows you to change the database instance associated with this query.
     *  It is useful when you need to switch databases or when the query needs to be executed
     *  against a different database connection.
     * 
     *  @param DB $database The database instance to set.
     *  @return self
     */
    public function setDB(DB $database): self
    {
        $this->database = $database;
        $this->grammar = new Grammar($database->getDriver());
        return $this;
    }

    /**
     * Retrieves the database schema grammar instance associated with this query.
     *
     * @return Grammar The database schema grammar instance associated with this query.
     */
    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * Sets the table prefix to be used for the query.
     *
     * @param string $prefix The table prefix to set.
     * @return self
     */
    public function prefix(string $prefix): self
    {
        self::$prefix = $prefix;
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
     * Adds a parameter for a query.
     *
     * @param string|array $parameter The parameter name(s) to add.
     * @param mixed ...$parameters Additional parameter names.
     * @return self Returns the query object.
     */
    public function parameter(string|array $parameter, ...$parameters): self
    {
        $placeholders = is_array($parameter) ? $parameter : [$parameter];
        $this->parameters = array_merge($this->parameters, $placeholders, $parameters);
        return $this;
    }

    /**
     * Adds a parameter for a query.
     *
     * @param string|array $parameter The parameter name(s) to add.
     * @param mixed ...$parameters Additional parameter names.
     * @return self Returns the query object.
     */
    public function param(string|array $parameter, ...$parameters): self
    {
        return $this->parameter($parameter, ...$parameters);
    }

    /**
     * Returns the query bindings.
     *
     * @return array The query bindings.
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Returns the query parameters.
     *
     * @return array The query parameters.
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Inserts data into the database with optional configurations.
     *
     * @param array|Arrayable $data The data to insert (single record or multiple records)
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
    public function insert(array|Arrayable $data, array $config = []): int|array
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        if (empty($data)) {
            return 0;
        }

        $started = microtime(true); // Start timing the query execution
        $startedMemory = memory_get_usage(true);

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

        $this->log($started, $startedMemory, $sql, $data);

        // Handle PostgreSQL RETURNING clause
        if ($this->grammar->isPostgreSQL() && isset($config['returning'])) {
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->database->getPdo()->lastInsertId();
    }

    /**
     * Update multiple records into the database with optional configurations.
     *
     * @param array|Arrayable $data
     * @param array $config
     * @return int
     */
    public function bulkUpdate(array|Arrayable $data, array $config = []): int|array
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

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
     * @param null|string|array|Closure $column
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
    public function where(null|string|array|Arrayable|Closure $column = null, ?string $operator = null, mixed $value = null, ?string $andOr = null, bool $not = false): self
    {
        if ($column instanceof Arrayable) {
            $column = $column->toArray();
        }

        if ($column === null) {
            return $this;
        } elseif ($column instanceof Closure) {
            return $this->grouped($column);
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

            $columnPlaceholder = $this->getWhereSqlColumn($column);
            $command = sprintf(
                "%s %s %s :%s",
                $andOr,
                $this->grammar->wrapColumn($column),
                $operator,
                $columnPlaceholder
            );

            $this->bindings[$columnPlaceholder] = $value;
        } elseif (is_array($column) && $operator === null && $value === null) {
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
                                $this->bindings[$columnPlaceholder] = $value; // Bind the value to the placeholder.
            
                                return $this->grammar->wrapColumn($attr) . (is_array($value) ?
                                    // Create a where clause to match IN(), Ex: "id IN(:id_0, :id_1, :id_2, :id_3)" .
                                    sprintf(
                                        ($not ? ' NOT' : '') . " IN (%s)",
                                        join(",", array_map(fn($index) => ":{$columnPlaceholder}_$index", array_keys($value)))
                                    )
                                    // Create a where close to match is equal, Ex. "id = :id_0"
                                    : ($not ? ' !=' : ' =') . " :" . $columnPlaceholder
                                );
                            },
                            $keys,
                            $values
                        )
                    )
                );
            } else {
                if (is_string($values[0])) {
                    return $this->where($values[0], $values[1] ?? null, $values[2] ?? null, $andOr, $not);
                }

                foreach ($values as $value) {
                    $this->where($value[0], $value[1] ?? null, $value[2] ?? null, $andOr, $not);
                }

                return $this; // Return early as where clauses are already added.
            }

        } elseif (is_string($column) && $operator === null && $value === null) {
            // Simply add a where clause from string.
            $command = "{$andOr} {$column}";
        } else {
            throw new QueryBuilderInvalidWhereClauseException('Invalid where clause');
        }

        // Grouped where clauses.
        if ($this->where['grouped']) {
            $command = "$andOr (" . ltrim($command, $andOr);
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
     * Adds a WHERE binding to the query.
     * This method allows you to add additional bindings to the WHERE clause.
     *
     * @param string|array $args
     * @return QueryBuilder
     */
    public function bind(string|array $args, bool $named = true): self
    {
        if ($named && is_array($args)) {
            $this->bindings = array_merge($this->bindings, $args);
        } else {
            $this->parameter($args);
        }

        return $this;
    }

    /**
     * Conditionally execute a callback.
     *
     * @param mixed $value
     * @param callable $callback
     * @return self
     */
    public function when(mixed $value, callable $callback): self
    {
        if ($value) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Conditionally execute a callback when value is falsy.
     *
     * @param mixed $value
     * @param callable $callback
     * @return self
     */
    public function unless(mixed $value, callable $callback): self
    {
        if (!$value) {
            $callback($this);
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
    public function whereRaw(string $sql, string|array $bindings = [], string $andOr = 'AND'): self
    {
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
    public function orWhereRaw(string $sql, string|array $bindings = []): self
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
    public function orWhere(null|string|array $column = null, ?string $operator = null, $value = null): self
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
    public function notWhere(null|string|array $column = null, ?string $operator = null, $value = null): self
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
    public function orNotWhere(null|string|array $column = null, ?string $operator = null, $value = null): self
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
        $where = $this->grammar->wrapColumn($where) . ' IS ' . ($not ? 'NOT' : '') . ' NULL';

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
    public function whereIn(string $column, array $values): self
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
    public function whereNotIn(string $column, array $values): self
    {
        return $this->where([$column => $values], not: true);
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
    public function orWhereIn($column, array $values): self
    {
        return $this->where([$column => $values], andOr: 'OR');
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
    public function orWhereNotIn($column, array $values): self
    {
        return $this->where([$column => $values], andOr: 'OR ', not: true);
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
    public function notIn(string $column, array $values): self
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
    public function orIn($column, array $values): self
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
    public function orNotIn($column, array $values): self
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
    public function findInSet($field, $key, $type = '', $andOr = 'AND'): self
    {
        // Get the SQL column placeholder for binding.
        $columnPlaceholder = $this->getWhereSqlColumn($field);

        // Construct the FIND_IN_SET condition
        if ($this->database->isDriver('sqlite')) {
            $where = $this->grammar->wrapColumn($field) . " {$type}LIKE :$columnPlaceholder";
            $key = "%$key%"; // SQLite uses LIKE for partial matches.
        } else {
            $where = "{$type}FIND_IN_SET (:$columnPlaceholder, {$this->grammar->wrapColumn($field)})";
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
    public function findInJson($field, $key, $value, $type = '', $andOr = 'AND'): self
    {
        // Get the SQL column placeholder for binding.
        $columnPlaceholder = $this->getWhereSqlColumn("{$field}_{$key}");

        // Construct the JSON condition
        $where = "JSON_EXTRACT({$this->grammar->wrapColumn($field)}, '$.{$key}') {$type}LIKE :$columnPlaceholder";

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
    public function notFindInJson($field, $key, $value): self
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
    public function orFindInJson($field, $key, $value): self
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
    public function orNotFindInJson($field, $key, $value): self
    {
        return $this->findInJson($field, $key, $value, 'NOT ', 'OR');
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
        $columnPlaceholder1 = $this->getWhereSqlColumn("{$field}1");
        $columnPlaceholder2 = $this->getWhereSqlColumn("{$field}2");

        $where = '(' . $this->grammar->wrapColumn($field) . ' ' . $type . 'BETWEEN '
            . (":$columnPlaceholder1 AND :$columnPlaceholder2") . ')';

        $this->bindings[$columnPlaceholder1] = $value1;
        $this->bindings[$columnPlaceholder2] = $value2;

        return $this->where($where, andOr: $andOr);
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
        $columnPlaceholder = $this->getWhereSqlColumn($field);
        $where = $this->grammar->wrapColumn($field) . " {$type}LIKE :$columnPlaceholder";

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
     * Add a WHERE clause that checks if a column contains a value.
     *
     * @param string $column
     * @param mixed $value
     * @param string $type
     * @param string $andOr
     * @return self
     */
    public function whereContains(string $column, mixed $value, string $type = '', string $andOr = 'AND'): self
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
    public function orWhereContains(string $column, mixed $value): self
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
    public function whereNotContains(string $column, mixed $value): self
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
    public function orWhereNotContains(string $column, mixed $value): self
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
    public function whereStartsWith(string $column, mixed $value, string $type = '', string $andOr = 'AND'): self
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
    public function orWhereStartsWith(string $column, mixed $value): self
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
    public function whereEndsWith(string $column, mixed $value, string $type = '', string $andOr = 'AND'): self
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
    public function orWhereEndsWith(string $column, mixed $value): self
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
    public function whereDate(string $column, string $operator, mixed $value = null, string $andOr = 'AND'): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $placeholder = $this->getWhereSqlColumn($column);

        if ($this->grammar->isMySQL()) {
            return $this->whereRaw("DATE({$this->grammar->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
        } elseif ($this->grammar->isSQLite()) {
            return $this->whereRaw("date({$this->grammar->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
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
    public function orWhereDate(string $column, string $operator, mixed $value = null): self
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
    public function whereYear(string $column, string $operator, mixed $value = null, string $andOr = 'AND'): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $placeholder = $this->getWhereSqlColumn($column);

        if ($this->grammar->isMySQL()) {
            return $this->whereRaw("YEAR({$this->grammar->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
        } elseif ($this->grammar->isSQLite()) {
            return $this->whereRaw("strftime('%Y', {$this->grammar->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
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
    public function orWhereYear(string $column, string $operator, mixed $value = null): self
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
    public function whereMonth(string $column, string $operator, mixed $value = null, string $andOr = 'AND'): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $placeholder = $this->getWhereSqlColumn($column);

        if ($this->grammar->isMySQL()) {
            return $this->whereRaw("MONTH({$this->grammar->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
        } elseif ($this->grammar->isSQLite()) {
            return $this->whereRaw("strftime('%m', {$this->grammar->wrapColumn($column)}) $operator :$placeholder", [$placeholder => $value], $andOr);
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
    public function orWhereMonth(string $column, string $operator, mixed $value = null): self
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
     * Updates records in the database based on specified data and conditions.
     *
     * @param array|Arrayable $data  Key-value pairs of columns and their respective values to update.
     * @param mixed $where  Optional WHERE clause to specify which records to update.
     * @return bool
     */
    public function update(array|Arrayable $data, mixed $where = null): bool
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        // Apply related model condition if necessary
        if ($this->isUsingModel()) {
            $this->getModelBeingUsed()->preserveOriginalBeforeUpdating($data);
            $this->applyModelPrimaryCondition();
        }

        // Apply WHERE condition if provided
        $this->where($where);

        // Abort if no WHERE condition is set to avoid accidental updates on all records
        if (!$this->hasWhere()) {
            return false;
        }

        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        // Prepare the table name
        $table = self::$prefix . $this->table;

        // Prepare the SQL update statement
        $sql = $this->addCollateToSql(
            sprintf(
                "UPDATE {$table} SET %s %s",
                implode(
                    ', ',
                    array_map(
                        fn($attr) => $this->grammar->wrapColumn($attr) . " = :$attr",
                        array_keys($data)
                    )
                ),
                $this->getWhereSql()
            )
        );
        $statement = $this->database->prepare($sql);

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind the values for update
        foreach ($data as $key => $val) {
            $statement->bindValue(":$key", $val, $this->getParameterType($val));
        }

        // Bind the WHERE clause parameters
        $this->bindParameters($statement);

        // Execute the statement and reset the WHERE clause
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->log($started, $startedMemory, $sql, $data);

        $this->resetWhere();

        // Returns true if records are successfully updated, false otherwise.
        $status = $statement->rowCount() > 0;

        if (!$status && $this->isUsingModel()) {
            $this->getModelBeingUsed()->restoreOriginal();
        }

        return $status; // Return true if rows were affected, false otherwise.
    }

    /**
     * Deletes records from the database based on specified conditions.
     *
     * @param mixed $where  Optional WHERE clause to specify which records to delete.
     * @return bool
     */
    public function delete(mixed $where = null): bool
    {
        // Apply related model condition if necessary
        if ($this->isUsingModel()) {
            $this->applyModelPrimaryCondition();
        }

        // Apply WHERE condition if provided
        $this->where($where);

        // Abort if no WHERE condition is set to avoid accidental deletion of all records
        if (!$this->hasWhere()) {
            return false;
        }

        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        // Prepare the table name
        $table = self::$prefix . $this->table;

        // Prepare the SQL delete statement
        $sql = $this->addCollateToSql("DELETE FROM {$table} {$this->getWhereSql()}");
        $statement = $this->database->prepare($sql);

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind the WHERE clause parameters
        $this->bindParameters($statement);

        // Execute the statement and reset the WHERE clause
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->log($started, $startedMemory, $sql, []);

        // Reset current query builder.
        $this->resetWhere();

        // Returns true if records are successfully deleted, false otherwise.
        return $statement->rowCount() > 0;
    }

    /**
     * Truncates the current table.
     *
     * This method removes all records from the table without logging individual row deletions.
     * It is faster than a DELETE statement and resets any auto-increment counters.
     *
     * @return bool Returns true on success, false on failure.
     * @throws QueryBuilderException If the statement preparation or execution fails.
     */
    public function truncate(): bool
    {
        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        // Prepare the table name
        $table = self::$prefix . $this->table;
        $sql = "TRUNCATE TABLE {$table}";

        // Prepare the SQL truncate statement
        $statement = $this->database->prepare($sql);

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Execute the statement
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->log($started, $startedMemory, $sql, []);

        return true;
    }

    /**
     * Execute a raw SQL query and return results.
     *
     * @param string $sql The raw SQL query to execute.
     * @param array $bindings Optional bindings for the SQL query.
     * @return array
     */
    public function raw(string $sql, array $bindings = []): array
    {
        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        $statement = $this->database->prepare($sql);

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind parameters
        foreach ($bindings as $key => $value) {
            if (is_string($key)) {
                $statement->bindValue($key, $value, $this->getParameterType($value));
            } else {
                $statement->bindValue($key + 1, $value, $this->getParameterType($value));
            }
        }

        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->log($started, $startedMemory, $sql, $bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Specify the fields to include in the SELECT clause.
     *
     * @param array|string $fields A string or an array of column names to select.
     * @return self The current instance for method chaining.
     */
    public function select(array|string $fields = '*', ...$args): self
    {
        // Merge additional arguments into the fields array if provided.
        // This allows for additional fields to be specified after the initial $fields parameter.
        if (is_string($fields) && !empty($args)) {
            $fields = array_merge((array) $fields, $args);
        }

        // Convert array of fields to a comma-separated string if necessary
        if (is_array($fields)) {
            $fields = implode(',', $fields);
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
    public function column(string $column): self
    {
        $this->query['select'] = $this->wrapAndEscapeColumns($column);
        return $this->fetch(PDO::FETCH_COLUMN);
    }

    /**
     * Get a single column's value from the first result.
     *
     * @param string $column The column name to retrieve.
     * @return mixed
     */
    public function value(string $column): mixed
    {
        $result = $this->first();

        if ($result === false) {
            return null;
        }

        return is_object($result) ? $result->$column : $result[$column];
    }

    /**
     * Get a single column's values from all matching rows.
     *
     * @param string $column The column name to retrieve.
     * @return array
     */
    public function pluck(string $column): array
    {
        $results = $this->select($column)->all();

        return array_map(fn($row) => is_object($row) ? $row->$column : $row[$column], $results);
    }

    /**
     * Adds a raw SQL expression to the SELECT clause.
     *
     * @param string $sql The raw SQL expression to add.
     * @param array $bindings Optional bindings for the SQL expression.
     * @return self The current instance for method chaining.
     */
    public function selectRaw(string $sql, array $bindings = []): self
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
     * @return self The current instance for method chaining.
     */
    public function from(string $table, ?string $alias = null): self
    {
        $this->query['from'] = $table;

        if (!empty($alias)) {
            $this->as($alias);
        }

        return $this;
    }

    /**
     * @param string      $field
     * @param string|null $name
     *
     * @return float
     */
    public function max($field, $name = null): float
    {
        $column = 'MAX(' . $this->grammar->wrapColumn($field) . ')' . (!$name === null ? " AS $name" : '');
        $this->select($column);

        return $this->fetch(PDO::FETCH_COLUMN)->first();
    }

    /**
     * @param string      $field
     * @param string|null $name
     *
     * @return float
     */
    public function min($field, $name = null): float
    {
        $column = 'MIN(' . $this->grammar->wrapColumn($field) . ')' . (!$name === null ? " AS $name" : '');
        $this->select($column);

        return $this->fetch(PDO::FETCH_COLUMN)->first();
    }

    /**
     * @param string      $field
     * @param string|null $name
     *
     * @return float
     */
    public function sum($field, $name = null): float
    {
        $column = 'SUM(' . $this->grammar->wrapColumn($field) . ')' . (!$name === null ? " AS $name" : '');
        $this->select($column);

        return $this->fetch(PDO::FETCH_COLUMN)->first();
    }

    /**
     * @param string      $field
     * @param string|null $name
     *
     * @return float
     */
    public function avg($field, $name = null): float
    {
        $column = 'AVG(' . $this->grammar->wrapColumn($field) . ')' . (!$name === null ? " AS $name" : '');
        $this->select($column);

        return $this->fetch(PDO::FETCH_COLUMN)->first();
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
            $alias = "AS " . $this->grammar->wrapColumn($alias);
        }

        $this->query['alias'] = " {$alias} ";
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
    public function join(string $table, $field1 = null, $operator = null, $field2 = null, $type = ''): self
    {
        $on = $field1;
        $table = self::$prefix . $table;

        if ($operator !== null) {
            if ($field2 === null) {
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
    public function joinRaw(string $sql, array $bindings = []): self
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
    public function on(string $field1, ?string $operator = null, ?string $field2 = null, null|string|array $parameters = null, string $orOn = 'ON', ): self
    {
        if ($operator !== null) {
            if ($field2 === null) {
                $field2 = $operator;
                $operator = '=';
            }

            $this->query['joins'] .= " " . $orOn . " " . $this->grammar->wrapColumn($field1) . " $operator " . $this->wrapOrValue($field2);
        } else {
            $this->query['joins'] .= " " . $orOn . " " . $this->wrapJoinOn($field1);
        }

        if ($parameters) {
            $this->parameter($parameters);
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
    public function orOn(string $field1, ?string $operator = null, ?string $field2 = null, null|string|array $parameters = null): self
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
    public function andOn(string $field1, ?string $operator = null, ?string $field2 = null, null|string|array $parameters = null): self
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
    public function innerJoin(string $table, $field1 = null, $operator = null, $field2 = null)
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
    public function leftJoin(string $table, $field1 = null, $operator = null, $field2 = null)
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
    public function rightJoin(string $table, $field1 = null, $operator = null, $field2 = null)
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

    public function fullOuterJoin(string $table, $field1 = null, $operator = null, $field2 = null)
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
    public function leftOuterJoin(string $table, $field1 = null, $operator = null, $field2 = null)
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
    public function rightOuterJoin(string $table, $field1 = null, $operator = null, $field2 = null)
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
     * @param ?string $field Field to order by in ascending order, defaults to 'id'.
     * @return self
     */
    public function orderAsc(?string $field = null): self
    {
        $field ??= $this->withAlias('id');

        $this->query['order'] = "$field ASC";
        return $this;
    }

    /**
     * Sets descending order for a specified field.
     *
     * @param ?string $field Field to order by in descending order, defaults to 'id'.
     * @return self
     */
    public function orderDesc(?string $field = null): self
    {
        $field ??= $this->withAlias('id');

        $this->query['order'] = "$field DESC";
        return $this;
    }

    /**
     * Sets the GROUP BY clause for the query.
     *
     * @param string|array $fields Group by clause as a string or array.
     * @return self
     */
    public function groupBy(string|array $fields): self
    {
        $this->query['group'] = $this->grammar->columnize((array) $fields);
        return $this;
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
     * @param int $from Starting point for the query.
     * @param int|null $to Ending point for the query, if specified.
     * @return self
     */
    public function limit(int $from, ?int $to = null): self
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
    public function offset(int $offset): self
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
    public function take(int $limit): self
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
    public function skip(int $count): self
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
    public function fetch(...$fetch): self
    {
        $this->query['fetch'] = $fetch;
        return $this;
    }

    /**
     * Specifies that results should be fetched as associative arrays.
     *
     * @return self
     */
    public function fetchAssoc(): self
    {
        return $this->fetch(PDO::FETCH_ASSOC);
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
     * Retrieves the first result or throws an exception if not found.
     *
     * @param mixed $where Optional WHERE clause to filter results.
     * @return mixed The first result object or throws NotFoundException.
     * @throws \Spark\Exceptions\NotFoundException If no results are found.
     */
    public function firstOrFail($where = null): mixed
    {
        if (!empty($where)) {
            $this->where($where);
        }

        // Get the first result, or throw an exception if not found.
        $result = $this->first();

        if ($result === false) {
            throw new NotFoundException('No results found for the query.');
        }

        return $result;
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
     * Sets the query to order results by the latest created_at timestamp.
     *
     * @return self
     */
    public function latest(): self
    {
        // Array of the latest results.
        return $this->orderDesc($this->withAlias('created_at'));
    }

    /**
     * Retrieves all results from the executed query.
     *
     * @return array Array of query results.
     */
    public function all(): array
    {
        // Execute current sql select command.
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
     * Retrieves all results from the executed query and returns them in a collection.
     *
     * @return \Spark\Support\Collection Array of query results.
     */
    public function get(): Collection
    {
        return collect($this->all());
    }

    /**
     * Paginates query results.
     *
     * @param int $limit Number of items per page.
     * @param string $keyword URL query parameter name for pagination.
     * @return \Spark\Utils\Paginator
     */
    public function paginate(int $limit = 10, string $keyword = 'page'): Paginator
    {
        // Select records & Create a paginator object.
        if (empty($this->query['select'])) {
            $this->select();
        }

        $paginator = new Paginator(limit: $limit, keyword: $keyword);

        // Count total records from exisitng command only for serverside database driver.
        if ($this->database->isDriver('mysql')) {
            $this->query['select'] = "SQL_CALC_FOUND_ROWS {$this->query['select']}";
        }

        // Set pagination count to limit database records, and execute query.
        $this->limit(
            ceil($limit * ($paginator->keywordValue() - 1)),
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
        $paginator->reset();

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
        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        $table = $this->getTableName(); // Get the table name with prefix if exists.

        $sql = $this->addCollateToSql(
            "SELECT COUNT(1) FROM {$table}"
            . $this->query['alias']
            . $this->query['joins']
            . $this->getWhereSql()
            . (isset($this->query['group']) ? ' GROUP BY ' . trim($this->query['group']) : '')
            . (isset($this->query['having']) ? ' HAVING ' . trim($this->query['having']) : '')
        );

        // Create sql command to count rows.
        $statement = $this->database->prepare($sql);

        // Apply where statement if exists.
        $this->bindParameters($statement);

        // Execute sql command.
        $statement->execute();

        $this->log($started, $startedMemory, $sql, []);

        // Returns number of found rows.
        return $statement->fetch(PDO::FETCH_COLUMN);
    }

    /**
     * Checks if any records exist based on the current query conditions.
     *
     * @return bool True if at least one record exists, false otherwise.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Checks if no records exist based on the current query conditions.
     *
     * @return bool True if no records exist, false otherwise.
     */
    public function notExists(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Create or update a record.
     *
     * @param array $attributes Attributes to search by.
     * @param array $values Values to update or create with.
     * @return int|array Returns last insert ID or array of returned data.
     */
    public function updateOrInsert(array $attributes, array $values = []): int|array
    {
        // Check if record exists
        if ($this->where($attributes)->exists()) {
            // Update existing record
            $this->where($attributes)->update($values);
            return 0; // Return 0 for update operations
        } else {
            // Insert new record
            return $this->insert(array_merge($attributes, $values));
        }
    }

    /**
     * Insert a new record and return the model.
     *
     * @param array $data
     * @return mixed
     */
    public function create(array $data): mixed
    {
        $id = $this->insert($data);

        if ($id) {
            return $this->where('id', $id)->first();
        }

        return false;
    }

    /**
     * Get distinct values for a column.
     *
     * @param null|string $column
     * @return self
     */
    public function distinct(?string $column = null): self
    {
        if ($column) {
            $this->query['select'] = "DISTINCT {$this->wrapAndEscapeColumns($column)}";
        } else {
            $this->query['select'] = str_replace('SELECT ', 'SELECT DISTINCT ', $this->query['select'] ?? '*');
        }

        return $this;
    }

    /**
     * Increment a column's value by a given amount.
     *
     * @param string $column
     * @param int $value
     * @param mixed $where
     * @return bool
     */
    public function increment(string $column, int $value = 1, $where = null): bool
    {
        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        $this->where($where);

        $sql = "UPDATE " . $this->getTableName() . " SET {$this->grammar->wrapColumn($column)} = {$this->grammar->wrapColumn($column)} + :increment " . $this->getWhereSql();
        $result = $this->raw(
            $sql,
            array_merge(['increment' => $value], $this->getBindings())
        );

        $this->log($started, $startedMemory, $sql, []);

        return $result !== false;
    }

    /**
     * Decrement a column's value by a given amount.
     *
     * @param string $column
     * @param int $value
     * @param mixed $where
     * @return bool
     */
    public function decrement(string $column, int $value = 1, $where = null): bool
    {
        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        $this->where($where);

        $sql = "UPDATE " . $this->getTableName() . " SET {$this->grammar->wrapColumn($column)} = {$this->grammar->wrapColumn($column)} - :decrement " . $this->getWhereSql();
        $result = $this->raw(
            $sql,
            array_merge(['decrement' => $value], $this->getBindings())
        );

        $this->log($started, $startedMemory, $sql, []);

        return $result !== false;
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

        return $this->addCollateToSql(
            "SELECT {$this->query['select']} FROM {$table}"
            . $this->query['alias']
            . $this->query['sql']
            . $this->query['joins']
            . $this->getWhereSql()
            . (isset($this->query['group']) ? ' GROUP BY ' . trim($this->query['group']) : '')
            . (isset($this->query['having']) ? ' HAVING ' . trim($this->query['having']) : '')
            . (isset($this->query['order']) ? ' ORDER BY ' . trim($this->query['order']) : '')
            . (isset($this->query['limit']) || isset($this->query['offset']) ? ' LIMIT ' . (isset($this->query['offset'], $this->query['limit']) ? "{$this->query['offset']}, {$this->query['limit']}" : $this->query['limit']) : '')
            . (isset($this->query['unions']) ? $this->query['unions'] : '')
        );
    }

    /**
     * Clone the query builder.
     *
     * @return self
     */
    public function clone(): self
    {
        return clone $this;
    }

    /**
     * Add a UNION clause to the query.
     *
     * @param QueryBuilder|Closure $query
     * @param bool $all
     * @return self
     */
    public function union(QueryBuilder|Closure $query, bool $all = false): self
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
        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Clone the query builder.
     *
     * @return self
     */
    public function copy(): self
    {
        return clone $this;
    }

    /**
     * Magic method to handle dynamic method calls.
     *
     * @param string $method The name of the method being called.
     * @param array $args The arguments passed to the method.
     * @return mixed
     */
    public function __call($method, $args)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $args);
        }

        // Check if the method exists in the related model's scope.
        if ($this->hasRelatedModel()) {
            $scope = sprintf('scope%s', ucfirst($method));
            $model = $this->getRelatedModel();

            // Call the scope method on the model if it exists.
            if (method_exists($model, $scope)) {
                return call_user_func_array([$model, $scope], [$this, ...$args]);
            }
        }

        // else, call the method from the collection instance.
        return call_user_func_array([$this->get(), $method], $args);
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
     * Returns the table name with prefix if exists.
     * 
     * @return string
     */
    private function getTableName(): string
    {
        // Returns the table name with prefix if exists.
        return $this->grammar->wrapTable(self::$prefix . (empty($this->query['from']) ? $this->table : $this->query['from']));
    }

    /**
     * Executes a SELECT query with the built query parts.
     *
     * @return void
     */
    private function executeSelectQuery(): void
    {
        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        $sql = $this->toSql(); // Get the complete SQL query.

        // Build complete select command with condition, order, and limit.
        $statement = $this->database->prepare($sql);

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind/Add conditions to filter records.
        $this->bindParameters($statement);

        // Execute current select command.
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->log($started, $startedMemory, $sql, []);

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
     * Get the Where SQL column name.
     * This method ensures that the column name is unique by appending an index if necessary.
     *
     * @param string $column The column name to be used in the WHERE clause.
     * @return string
     *   Returns a unique column name for the WHERE clause.
     */
    private function getWhereSqlColumn(string $column): string
    {
        $column = str_replace('.', '', $column);

        $index = 0;
        $x_column = $column;
        do {
            $x_column = $index === 0 ? $column : "$column$index";
            $index++;
        } while (isset($this->bindings[$x_column]));

        return $x_column;
    }

    /**
     * Binds the values of the parameters to the SQL statement.
     *
     * @param PDOStatement $statement The prepared PDO statement to bind values.
     * @return void
     */
    private function bindParameters(PDOStatement &$statement): void
    {
        if (!empty($this->bindings) && !empty($this->parameters)) {
            throw new QueryBuilderException('Cannot bind both named and positional parameters at the same time.');
        }

        // Bind where clause values to filter records.
        foreach ($this->bindings as $param => $value) {
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
                $statement->bindValue($param, $value, $this->getParameterType($value));
            }
        }

        foreach ($this->parameters as $key => $param) {
            $statement->bindValue($key + 1, $param, $this->getParameterType($param));
        }
    }

    /**
     * Resets the WHERE clause and clears any existing conditions.
     *
     * @return void
     */
    private function resetWhere(): void
    {
        $this->where = ['sql' => '', 'grouped' => false];
        $this->bindings = [];
        $this->parameters = [];
    }

    /**
     * Resets the query components for reuse.
     *
     * @return void
     */
    private function resetQuery(): void
    {
        // Reset Select query parameters.
        $this->query = ['sql' => '', 'select' => '', 'from' => null, 'alias' => '', 'joins' => ''];

        // Reset where query parameters.
        $this->resetWhere();
    }

    /**
     * Adds a COLLATE clause to the SQL statement if a collation is set.
     *
     * @param string $sql The SQL statement to modify.
     * @return string The modified SQL statement with COLLATE clause if applicable.
     */
    private function addCollateToSql(string $sql): string
    {
        if (isset(self::$collate) && strpos($sql, 'where') !== false) {
            return rtrim($sql, ';') . " COLLATE " . self::$collate . ";";
        }

        return $sql; // Return the original SQL if no collation is set.
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
        $table = self::$prefix . $this->table;

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
     * Creates a placeholder string for the INSERT statement.
     *
     * @param array $data The data to be inserted.
     * @return string The placeholder string.
     */
    private function createPlaceholder(array $data): string
    {
        $placeholders = [];
        foreach ($data as $serial => $row) {
            $placeholders[] = '(' . implode(',', array_map(fn($column) => ':' . $column . '_' . $serial, array_keys($row))) . ')';
        }
        return implode(',', $placeholders);
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
            if (is_int($key)) {
                $key = $value; // If key is an integer, use the value as the key
            }

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
            strpos($value, '?') !== false ||
            preg_match('/:\w+/', $value) ||
            preg_match('/\([^)]*\)/', $value)
        ) {
            return $value;
        }

        return $this->grammar->wrapColumn($value);
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
        $operator = strpos($on, '=') !== false ? '=' :
            (strpos($on, '!=') !== false ? '!=' : null);

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
        if (is_array($bindings) && strpos($sql, '?') === false && preg_match('/\:(\w+)/', $sql)) {
            $this->bindings = array_merge($this->bindings, $bindings);
        } else {
            $this->parameter($bindings);
        }
    }

    /**
     * Logs the execution time of a SQL query.
     *
     * @param float $started The start time of the query execution.
     * @param int $startedMemory The memory usage at the start of the query execution.
     * @param string $sql The SQL query that was executed.
     * @param array $bindings The bindings used in the query.
     * @return void
     */
    private function log(float $started, int $startedMemory, string $sql, array $bindings = []): void
    {
        if (env('debug') === false) {
            return;
        }

        $ended = microtime(true);
        $time = round(($ended - $started) * 1000, 6);

        if ($this->hasWhere()) {
            $parameters = !empty($this->bindings) ? $this->bindings : $this->parameters;
            if (!empty($bindings)) {
                $bindings['where'] = $parameters;
            } else {
                $bindings = $parameters;
            }
        }

        event('app:db.queryExecuted', ['query' => $sql, 'time' => $time, 'bindings' => $bindings, 'memory_before' => $startedMemory]);
    }
}
