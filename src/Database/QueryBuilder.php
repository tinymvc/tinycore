<?php
namespace Spark\Database;

use PDOStatement;
use Spark\Database\Contracts\QueryBuilderContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Database\Exceptions\QueryBuilderException;
use Spark\Database\Schema\Contracts\WrapperContract;
use Spark\Database\Schema\Wrapper;
use Spark\Database\Concerns\InteractsWithRelation;
use Spark\Support\Collection;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function func_get_args;
use function is_array;
use function is_bool;
use function sprintf;

/**
 * Class Query
 *
 * @method Collection except($keys)
 * @method Collection filter(?callable $callback = null)
 * @method Collection map(callable $callback)
 * @method Collection each(callable $callback)
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
 * @method Collection nth($step, $offset = 0)
 * @method Collection prepend($value, $key = null)
 * @method Collection push(...$values)
 * @method Collection unshift(...$values)
 * @method Collection concat($source)
 * @method mixed random($number = null, $preserveKeys = false)
 * @method mixed search($value, $strict = false)
 * @method Collection put($key, $value)
 * @method Collection reverse()
 * @method Collection shuffle()
 * @method Collection sliding($size = 2, $step = 1)
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
    use InteractsWithRelation,
        Conditionable,
        Macroable,
        Query\BuildsWhereClauses,
        Query\ExecutesWriteQueries,
        Query\BuildsSelectQueries,
        Query\RetrievesQueryResults {
        Macroable::__call as macroCall;
    }

    /**
     * Holds the table prefix to be used for the query.
     *
     * @var string $prefix
     */
    private string $prefix;

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
     * Holds the query wrapper instance.
     * 
     * @var \Spark\Database\Schema\WrapperContract $wrapper The query wrapper.
     */
    private WrapperContract $wrapper;

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
        $this->wrapper = new Wrapper($database->getDriver());
        $this->prefix ??= ''; // Set default prefix if not already set
    }

    /**
     * Sets the table name to be used for the query.
     *
     * @param string $table The table name to set.
     * @param string|null $alias Optional alias for the table.
     * @return self
     */
    public function table(string $table, ?string $alias = null): self
    {
        if (stripos($table, ' as ') !== false && empty($alias)) {
            [$table, $alias] = array_map('trim', explode(' as ', $table, 2));
        }

        $this->table = $table;
        $this->query['alias'] = $alias ?: '';

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
    public function getAlias(): null|string
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
        $this->wrapper = new Wrapper($database->getDriver());
        return $this;
    }

    /**
     * Returns the query wrapper.
     *
     * @return WrapperContract The query wrapper.
     */
    public function getWrapper(): WrapperContract
    {
        return $this->wrapper;
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
     * Adds a parameter for a query.
     *
     * @param string|array $parameter Additional parameter names.
     * @return self Returns the query object.
     */
    public function param(string|array $parameter): self
    {
        $parameter = is_array($parameter) ? $parameter : func_get_args();
        $this->parameters = [...$this->parameters, ...$parameter];
        return $this;
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
     * Clone the query builder.
     *
     * @return self
     */
    public function clone(): self
    {
        return clone $this;
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
    public function __call(string $method, array $args)
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
                return $model->$scope($this, ...$args);
            }
        }

        // else, call the method from the collection instance.
        return $this->get()->$method(...$args);
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
            $data = $mapper($data);
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
        return $this->wrapper->wrapTable($this->prefix . (empty($this->query['from']) ? $this->table : $this->query['from']));
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
            $param = $this->normalizeNamedBinding(str_replace('.', '', $param));

            if (is_array($value)) {
                // binds clause values from a array condition, Ex. "id IN(1, 2, 3, 4)".
                foreach ($value as $index => $val) {
                    // Add multiple parameter into IN(), Ex. :id_0 => $value, :id_1 => $value;
                    $statement->bindValue(
                        param: "{$param}_$index",
                        value: $this->castValue($val),
                        type: $this->getParameterType($val)
                    );
                }
            } else {
                // binds clause values from a string condition, Ex. "id = 1".
                $statement->bindValue(
                    param: $param,
                    value: $this->castValue($value),
                    type: $this->getParameterType($value)
                );
            }
        }

        foreach ($this->parameters as $key => $param) {
            $statement->bindValue(
                param: $key + 1,
                value: $this->castValue($param),
                type: $this->getParameterType($param)
            );
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
        if (!is_debug_mode()) {
            return; // Skip in non-debug mode.
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

    /**
     * Casts a value to a string representation suitable for database storage.
     *
     * @param mixed $value The value to cast.
     * @return string|null The casted string value or null if the value is empty.
     */
    private function castValue(mixed $value): ?string
    {
        $value = value($value);

        if (empty($value)) {
            return null;
        }

        // instanceof \Spark\Url
        if ($value instanceof \Spark\Url) {
            return $value->getUrl();
        } elseif ($value instanceof \Spark\Carbon) {
            return $value->toDateTimeString();
        } elseif ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        } elseif (is_bool($value)) {
            return $value ? '1' : '0';
        } elseif ($value instanceof Arrayable) {
            return json_encode($value->toArray());
        } elseif (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }
}
