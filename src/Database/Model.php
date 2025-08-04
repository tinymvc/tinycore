<?php

namespace Spark\Database;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use PDO;
use Spark\Contracts\Database\ModelContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Database\Exceptions\InvalidModelFillableException;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\ManageRelationship;
use Spark\Support\Str;
use Spark\Support\Traits\Macroable;
use Traversable;

/**
 * Class Model
 * 
 * This class provides a base for models, handling database operations and entity management.
 * It includes CRUD operations, data decoding, and dynamic method invocation.
 *
 * @method static int|array insert(array|Arrayable $data, array $config = [])
 * @method static int|array bulkUpdate(array|Arrayable $data, array $config = [])
 * @method static QueryBuilder where(null|string|array|Closure $column = null, ?string $operator = null, mixed $value = null, ?string $andOr = null, bool $not = false)
 * @method static QueryBuilder whereNull($where, $not = false)
 * @method static QueryBuilder whereNotNull($where)
 * @method static QueryBuilder in(string $column, array $values)
 * @method static QueryBuilder notIn(string $column, array $values)
 * @method static QueryBuilder whereIn(string $column, array $values)
 * @method static QueryBuilder whereNotIn(string $column, array $values)
 * @method static QueryBuilder findInSet($field, $key, $type = '', $andOr = 'AND')
 * @method static QueryBuilder notFindInSet($field, $key)
 * @method static QueryBuilder between($field, $value1, $value2, $type = '', $andOr = 'AND')
 * @method static QueryBuilder notBetween($field, $value1, $value2)
 * @method static QueryBuilder like($field, $data, $type = '', $andOr = 'AND')
 * @method static QueryBuilder grouped(Closure $callback)
 * @method static bool update(array|Arrayable $data, mixed $where = null)
 * @method static bool delete(mixed $where = null)
 * @method static bool truncate()
 * @method static QueryBuilder select(array|string $fields = '*')
 * @method static QueryBuilder max($field, $name = null)
 * @method static QueryBuilder min($field, $name = null)
 * @method static QueryBuilder sum($field, $name = null)
 * @method static QueryBuilder avg($field, $name = null)
 * @method static QueryBuilder as(string $alias)
 * @method static QueryBuilder join(string $table, $field1 = null, $operator = null, $field2 = null, $type = '')
 * @method static QueryBuilder order(?string $sort = null)
 * @method static QueryBuilder orderBy(string $field, string $sort = 'ASC')
 * @method static QueryBuilder orderAsc(string $field = 'id')
 * @method static QueryBuilder orderDesc(string $field = 'id')
 * @method static QueryBuilder groupBy(string|array $fields)
 * @method static QueryBuilder having(string $having)
 * @method static QueryBuilder take(int $limit)
 * @method static QueryBuilder limit(?int $offset = null, ?int $limit = null)
 * @method static QueryBuilder fetch(...$fetch)
 * @method static mixed first()
 * @method static mixed firstOrFail()
 * @method static mixed last()
 * @method static array latest()
 * @method static array all()
 * @method static int count()
 * @method static \Spark\Utils\Paginator paginate(int $limit = 10, string $keyword = 'page')
 * @method static \Spark\Support\Collection get()
 * @method static \Spark\Support\Collection except($keys)
 * @method static \Spark\Support\Collection filter(?callable $callback = null)
 * @method static \Spark\Support\Collection map(callable $callback)
 * @method static \Spark\Support\Collection mapToDictionary(callable $callback)
 * @method static \Spark\Support\Collection mapWithKeys(callable $callback)
 * @method static \Spark\Support\Collection merge($items)
 * @method static \Spark\Support\Collection mergeRecursive($items)
 * @method static \Spark\Support\Collection only($keys)
 *
 * This class provides a base for models, handling database operations and entity management.
 * It includes CRUD operations, data decoding, and dynamic method invocation.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
abstract class Model implements ModelContract, Arrayable, ArrayAccess, IteratorAggregate
{
    use ManageRelationship, Macroable {
        __call as macroCall;
        __callStatic as staticMacroCall;
    }

    /**
     * @var string The table name associated with this model.
     */
    public static string $table;

    /**
     * The primary key of the model.
     *
     * @var string Default value: 'id'
     */
    public static string $primaryKey = 'id';

    /**
     * The model attributes.
     *
     * @var array
     */
    protected array $attributes = [];

    /**
     * model constructor.
     * Initializes the model instance and decodes any previously saved data if an ID is set.
     */
    public function __construct()
    {
        if ($this->primaryValue()) {
            $this->decodeSavedData();
        }
    }

    /**
     * Creates a new query instance for the model's table.
     *
     * @return QueryBuilder Returns a query object for database operations.
     */
    public static function query(): QueryBuilder
    {
        // Return a new database query builder object.
        return app(QueryBuilder::class)
            ->table(static::$table ?? Str::snake(Str::plural(class_basename(static::class))))
            ->fetch(PDO::FETCH_CLASS, static::class);
    }

    /**
     * Finds a model by its primary key ID.
     *
     * @param int $value The Unique Identifier of the model to retrieve.
     * @return false|static The found model instance or false if not found.
     */
    public static function find($value): false|static
    {
        return self::query()
            ->where([static::$primaryKey => $value])
            ->first();
    }

    /**
     * Finds a model by its primary key ID or throws an exception if not found.
     *
     * @param int $value The Unique Identifier of the model to retrieve.
     * @return static The found model instance.
     * @throws \Spark\Exceptions\NotFoundException If the model is not found.
     */
    public static function findOrFail($value): static
    {
        return self::query()
            ->where([static::$primaryKey => $value])
            ->firstOrFail();
    }

    /**
     * Loads an array of data into a new model instance.
     *
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @param bool $ignoreEmpty If true, empty values will be ignored.
     * @return static A model instance populated with the given data.
     */
    public static function load(array|Arrayable $data, bool $ignoreEmpty = false): static
    {
        // Create & Hold a new model.
        $model = new static();

        $model->fill($data, $ignoreEmpty); // Fill the model with the given data.

        // Decode model properties from JSON to array if necessary.
        $model->decodeSavedData();

        // Return the new model object.
        return $model;
    }

    /**
     * Creates a new model instance from the given data and saves it to the database.
     * 
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @param bool $ignoreEmpty If true, empty values will be ignored.
     * @return static The saved model instance.
     */
    public static function create(array|Arrayable $data, bool $ignoreEmpty = false): static
    {
        $model = self::load($data, $ignoreEmpty);
        $model->save();

        return $model; // Return the saved model instance.
    }

    /**
     * Fills the model with the given data.
     *
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @param bool $ignoreEmpty If true, empty values will be ignored.
     * @return static The current model instance.
     */
    public function fill(array|Arrayable $data, bool $ignoreEmpty = false): static
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        // Fill the model with the given data.
        foreach ($data as $key => $value) {
            if ($ignoreEmpty && empty($value)) {
                continue;
            }

            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * Saves the model to the database, either updating or creating a new entry.
     *
     * @return int|bool The ID of the saved model or false on failure.
     */
    public function save(): int|bool
    {
        // Apply events for before save and encode array into json string. 
        $data = $this->encodeToSaveData($this->getFillableData());

        // Update this records if it has an id, else insert this records into database.
        if (!empty($this->primaryValue())) {
            $status = $this->query()->update($data, [static::$primaryKey => $this->primaryValue()]);
        } else {
            $status = $this->query()->insert($data);
        }

        // Save model id if it is newly created.
        if (is_int($status)) {
            $this->attributes[static::$primaryKey] = $status;
        }

        // Apply model events after saved the record.
        $this->decodeSavedData();

        return $status; // Return database operation status.
    }

    /**
     * Removes the model from the database.
     *
     * @return bool True if removal was successful, false otherwise.
     */
    public function remove(): bool
    {
        return $this->query()->delete([static::$primaryKey => $this->primaryValue()]);
    }

    /**
     * Deletes the model from the database by its primary key value.
     *
     * @param int $value The unique identifier of the model to delete.
     * @return bool True if deletion was successful, false otherwise.
     */
    public static function destroy($value): bool
    {
        return self::query()->delete([static::$primaryKey => $value]);
    }

    /**
     * Retrieves the fillable fields of the model based on the $fillable and $guarded properties.
     *
     * If $fillable is defined, only the fields specified in the array are included unless
     * explicitly restricted by the $guarded property. If $fillable is empty, all fields
     * are included except the ones that are explicitly guarded by the $guarded property.
     *
     * @return array The fillable fields of the model.
     */
    private function getFillableData(): array
    {
        $data = [];

        $fillable = $this->fillable ?? [];
        $guarded = $this->guarded ?? [];

        if (!isset($this->fillable) && !isset($this->guarded)) {
            throw new InvalidModelFillableException(
                'Either fillable or guarded must be defined for the modal: ' . static::class
            );
        }

        foreach ($this->attributes as $key => $value) {
            // If fillable is defined, only allow fillable fields unless guarded explicitly restricts it.
            if (!empty($fillable) && in_array($key, $fillable)) {
                $data[$key] = $value;
            }
            // If fillable is empty, assume all fields are fillable except the guarded ones.
            elseif (empty($fillable) && !in_array($key, $guarded)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * Prepares data before saving, such as encoding arrays to JSON.
     *
     * @param array $data The data to prepare.
     * @return array The prepared data for database insertion or update.
     */
    private function encodeToSaveData(array $data): array
    {
        // Parse model property into string if the are in array.
        foreach ($data as $key => $value) {
            $data[$key] = is_array($value) ? json_encode($value) : $value;
        }

        // returns associative array of model properties.
        return $data;
    }

    /**
     * Decodes JSON strings in properties to their original formats.
     * 
     * @return void
     */
    private function decodeSavedData(): void
    {
        // Go Through all the properties of this model.
        foreach ($this->attributes as $key => $value) {
            /** 
             * if the property is json format then decode json
             * string to associative array.
             */
            if (
                is_string($value) && (strpos($value, '[') === 0
                    || strpos($value, '{') === 0)
            ) {
                $value = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->attributes[$key] = $value;
                }
            }
        }
    }

    /**
     * Gets the value of the primary key.
     *
     * @param mixed $default The value to return if the primary key is empty.
     * @return mixed The value of the primary key or the default value.
     */
    public function primaryValue($default = null): mixed
    {
        return $this->attributes[static::$primaryKey] ?? $default;
    }

    /**
     * Magic setter method to set the value of a model attribute.
     *
     * @param string $name The name of the attribute to set.
     * @param mixed $value The value to set.
     * @return void
     */
    public function __set($name, $value)
    {
        $this->offsetSet($name, $value);
    }

    /**
     * Magic getter method to retrieve the value of a model attribute.
     *
     * @param string $name The name of the attribute to retrieve.
     * @return mixed|null The value of the attribute if set, or null if not set.
     */
    public function __get($name)
    {
        return $this->offsetGet($name);
    }

    /**
     * Checks if the specified model attribute is set.
     *
     * @param string $name The name of the attribute to check.
     * @return bool True if the attribute is set, false otherwise.
     */
    public function __isset($name)
    {
        return $this->offsetExists($name);
    }

    /**
     * Magic unset method to remove the specified model attribute.
     *
     * @param string $name The name of the attribute to remove.
     * @return void
     */
    public function __unset($name)
    {
        $this->offsetUnset($name);
    }

    /**
     * Converts the model to an associative array.
     *
     * @return array An array of model properties and their values.
     */
    public function toArray()
    {
        return array_merge($this->attributes, $this->getRelations());
    }

    /**
     * Check if the model has a specific attribute.
     * 
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]) || $this->relationshipExists($offset);
    }

    /**
     * Get the value of a specific attribute.
     * 
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? $this->getRelationshipAttribute($offset);
    }

    /**
     * Set the value of a specific attribute.
     * 
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($this->relationLoaded($offset)) {
            $this->setRelation($offset, $value);
        } else {
            $this->attributes[$offset] = $value;
        }
    }

    /**
     * Unset a specific attribute.
     * 
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        if ($this->relationLoaded($offset)) {
            $this->forgetRelation($offset);
        } else {
            unset($this->attributes[$offset]);
        }
    }

    /**
     * Set a value for a specific attribute.
     *
     * @param string $name The name of the attribute to set.
     * @param mixed $value The value to set for the attribute.
     * @return void
     */
    public function set(string $name, mixed $value): void
    {
        data_set($this->attributes, $name, $value);
    }

    /**
     * Unset a specific attribute.
     *
     * @param string $name The name of the attribute to unset.
     * @return void
     */
    public function unset(string $name): void
    {
        data_forget($this->attributes, $name);
    }

    /**
     * Get the value of a specific attribute.
     *
     * @param string $name The name of the attribute to get.
     * @param mixed $default The default value to return if the attribute is not set.
     * @return mixed The value of the attribute or the default value if not set.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return data_get(array_filter($this->toArray()), $name, $default);
    }

    /**
     * Check if the model has a specific attribute.
     *
     * @param string $name The name of the attribute to check.
     * @return bool True if the attribute exists, false otherwise.
     */
    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * Get an iterator for the items.
     * 
     * This method allows the model to be iterated over like an array.
     * 
     * @template TKey of array-key
     *
     * @template-covariant TValue
     *
     * @implements \ArrayAccess<TKey, TValue>
     *
     * @return \ArrayIterator<TKey, TValue>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toArray());
    }

    /**
     * Handles dynamic method calls to the query instance.
     *
     * @param string $name The method name.
     * @param array $arguments The method arguments.
     * @return mixed The result of the query method call.
     */
    public function __call($name, $arguments)
    {
        if (static::hasMacro($name)) {
            return $this->macroCall($name, $arguments);
        }

        return call_user_func([$this->query(), $name], ...$arguments);
    }

    /**
     * Handles static method calls to the query instance.
     *
     * @param string $name The method name.
     * @param array $arguments The method arguments.
     * @return mixed The result of the query method call.
     */
    public static function __callStatic($name, $arguments)
    {
        if (static::hasMacro($name)) {
            return static::staticMacroCall($name, $arguments);
        }

        return call_user_func([self::query(), $name], ...$arguments);
    }
}
