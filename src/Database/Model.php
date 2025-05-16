<?php

namespace Spark\Database;

use ArrayAccess;
use PDO;
use Spark\Contracts\Database\ModelContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Database\Exceptions\InvalidModelFillableException;
use Spark\Database\QueryBuilder;
use Spark\Database\Traits\HasOrm;
use Spark\Support\Traits\Macroable;

/**
 * Class Model
 * 
 * @method static int|array insert(array $data, array $config = [])
 * @method static int|array bulkUpdate(array $data, array $config = [])
 * @method static QueryBuilder where(null|string|array|Closure $column = null, ?string $operator = null, mixed $value = null, ?string $andOr = null, bool $not = false)
 * @method static QueryBuilder whereNull($where, $not = false)
 * @method static QueryBuilder whereNotNull($where)
 * @method static QueryBuilder in(string $column, array $values)
 * @method static QueryBuilder notIn(string $column, array $values)
 * @method static QueryBuilder findInSet($field, $key, $type = '', $andOr = 'AND')
 * @method static QueryBuilder notFindInSet($field, $key)
 * @method static QueryBuilder between($field, $value1, $value2, $type = '', $andOr = 'AND')
 * @method static QueryBuilder notBetween($field, $value1, $value2)
 * @method static QueryBuilder like($field, $data, $type = '', $andOr = 'AND')
 * @method static QueryBuilder grouped(Closure $callback)
 * @method static bool update(array $data, mixed $where = null)
 * @method static bool delete(mixed $where = null)
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
abstract class Model implements ModelContract, Arrayable, ArrayAccess
{
    use HasOrm, Macroable {
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
        return get(QueryBuilder::class)
            ->table(static::$table)
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
     * Loads an array of data into a new model instance.
     *
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @return static A model instance populated with the given data.
     */
    public static function load(array|Arrayable $data): static
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        // Create & Hold a new model.
        $model = new static();

        $model->fill($data);

        // Return the new model object.
        return $model;
    }

    /**
     * Creates a new model instance from the given data and saves it to the database.
     * 
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @return static The saved model instance.
     */
    public static function create(array|Arrayable $data): static
    {
        $model = self::load($data);
        $model->save();

        return $model;
    }

    /**
     * Fills the model with the given data.
     *
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @return static The current model instance.
     */
    public function fill(array|Arrayable $data): static
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        // Fill the model with the given data.
        foreach ($data as $key => $value) {
            $this->attributes[$key] = $value;
        }

        // Decode model properties from JSON to array if necessary.
        $this->decodeSavedData();

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
        $data = $this->beforeSaveData($this->getFillableData());

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
        $eventStatus = $this->afterSavedData();

        if (!$status && $eventStatus) {
            $status = true;
        }

        // Return database operation status.
        return $status;
    }

    /**
     * Removes the model from the database.
     *
     * @return bool True if removal was successful, false otherwise.
     */
    public function remove(): bool
    {
        // Call events when this model is about to be deleted.
        $this->beforeRemoveData();

        // Remove this record from database.
        $removed = $this->query()->delete([static::$primaryKey => $this->primaryValue()]);

        // Call events when this model is deleted.
        if ($removed) {
            $this->afterRemovedData();
        }

        // Returns database operation status.
        return $removed;
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
    private function beforeSaveData(array $data): array
    {
        // Call beforeSave event
        if (method_exists($this, 'beforeSave')) {
            $data = $this->beforeSave($data);
        }

        // Parse model property into string if the are in array.
        foreach ($data as $key => $value) {
            $data[$key] = is_array($value) ? json_encode($value) : $value;
        }

        // returns associative array of model properties.
        return $data;
    }

    /**
     * Callback after saving data to handle post-save tasks.
     * 
     * @return bool
     */
    private function afterSavedData(): bool
    {
        $status = false;

        // Call afterSave event
        if (method_exists($this, 'afterSave')) {
            $status = $this->afterSave();
        }

        return $status;
    }

    /**
     * Called before removing the model from the database.
     * 
     * @return void
     */
    private function beforeRemoveData(): void
    {
        // Call beforeRemove event
        if (method_exists($this, 'beforeRemove')) {
            $this->beforeRemove();
        }
    }

    /**
     * Callback after removing data to handle post-remove tasks.
     * 
     * @return void
     */
    private function afterRemovedData(): void
    {
        // Call afterRemove event
        if (method_exists($this, 'afterRemove')) {
            $this->afterRemove();
        }
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
        $this->attributes[$name] = $value;
    }

    /**
     * Magic getter method to retrieve the value of a model attribute.
     *
     * @param string $name The name of the attribute to retrieve.
     * @return mixed|null The value of the attribute if set, or null if not set.
     */
    public function __get($name)
    {
        if (isset($this->attributes[$name])) {
            return $this->attributes[$name];
        }

        return $this->getFromOrm($name);
    }

    /**
     * Checks if the specified model attribute is set.
     *
     * @param string $name The name of the attribute to check.
     * @return bool True if the attribute is set, false otherwise.
     */
    public function __isset($name)
    {
        return isset($this->attributes[$name]) || $this->existsInOrm($name);
    }

    /**
     * Magic unset method to remove the specified model attribute.
     *
     * @param string $name The name of the attribute to remove.
     * @return void
     */
    public function __unset($name)
    {
        if (isset($this->attributes[$name])) {
            unset($this->attributes[$name]);
        } else {
            $this->removeFromOrm($name);
        }
    }

    /**
     * Converts the model to an associative array.
     *
     * @return array An array of model properties and their values.
     */
    public function toArray()
    {
        return $this->attributes;
    }

    /**
     * Check if the model has a specific attribute.
     * 
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    /**
     * Get the value of a specific attribute.
     * 
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
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
        $this->attributes[$offset] = $value;
    }

    /**
     * Unset a specific attribute.
     * 
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
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

    /**
     * Converts the model to a string representation.
     *
     * @return string A string representation of the model instance.
     */
    public function __toString()
    {
        return sprintf('model: (%s), %s(%d)', static::class, static::$table, $this->primaryValue('#'));
    }
}
