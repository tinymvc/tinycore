<?php

namespace Spark\Database;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use Spark\Contracts\Database\ModelContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Support\Jsonable;
use Spark\Database\Exceptions\InvalidModelFillableException;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\HasRelation;
use Spark\Support\Str;
use Spark\Support\Traits\Macroable;
use Traversable;

/**
 * Class Model
 * 
 * This class provides a base for models, handling database operations and entity management.
 * It includes CRUD operations, data decoding, and dynamic method invocation.
 *
 * @method static QueryBuilder with($relations)
 * @method static QueryBuilder has(string $relation, string $operator = '>=', int $count = 1)
 * @method static QueryBuilder doesntHave(string $relation)
 * @method static QueryBuilder whereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1)
 * @method static QueryBuilder whereDoesntHave(string $relation, ?Closure $callback = null)
 * @method static QueryBuilder whereRelation(string $relation, string $column, string $operator = '=', $value = null)
 * @method static QueryBuilder whereRelationIn(string $relation, string $column, array $values)
 * @method static QueryBuilder whereRelationNotIn(string $relation, string $column, array $values)
 * @method static QueryBuilder whereRelationNull(string $relation, string $column)
 * @method static QueryBuilder whereRelationNotNull(string $relation, string $column)
 * @method static QueryBuilder whereRelationLike(string $relation, string $column, string $pattern)
 * @method static QueryBuilder whereRelationBetween(string $relation, string $column, $min, $max)
 * @method static QueryBuilder whereRelationFindInSet(string $relation, string $column, $value)
 * @method static QueryBuilder whereRelationJson(string $relation, string $column, string $key, $value)
 * @method static QueryBuilder withCount(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1)
 * @method static int|array insert(array|Arrayable $data, array $config = [])
 * @method static int|array bulkUpdate(array|Arrayable $data, array $config = [])
 * @method static QueryBuilder where(null|string|array|Closure $column = null, ?string $operator = null, mixed $value = null, ?string $andOr = null, bool $not = false)
 * @method static QueryBuilder whereRaw(string $sql, string|array $bindings = [])
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
 * @method static QueryBuilder selectRaw(string $sql, array $bindings = [])
 * @method static QueryBuilder column(string $column)
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
 * @method static QueryBuilder collate(string $collation)
 * @method static QueryBuilder take(int $limit)
 * @method static QueryBuilder limit(?int $offset = null, ?int $limit = null)
 * @method static QueryBuilder fetch(...$fetch)
 * @method static mixed first()
 * @method static mixed firstOrFail()
 * @method static mixed last()
 * @method static false|Model find($value)
 * @method static Model findOrFail($value)
 * @method static bool destroy($value)
 * @method static array latest()
 * @method static array all()
 * @method static int count()
 * @method static \Spark\Utils\Paginator paginate(int $limit = 10, string $keyword = 'page')
 * @method static \Spark\Support\Collection get()
 * @method static \Spark\Support\Collection filter(?callable $callback = null)
 * @method static \Spark\Support\Collection map(callable $callback)
 * @method static \Spark\Support\Collection mapToDictionary(callable $callback)
 * @method static \Spark\Support\Collection mapWithKeys(callaseletble $callback)
 * @method static \Spark\Support\Collection merge($items)
 * @method static \Spark\Support\Collection mergeRecursive($items)
 *
 * This class provides a base for models, handling database operations and entity management.
 * It includes CRUD operations, data decoding, and dynamic method invocation.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
abstract class Model implements ModelContract, Arrayable, ArrayAccess, IteratorAggregate, Jsonable
{
    use HasRelation, Casts, Macroable {
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
     * Track vars for internal usage.
     *
     * @var array
     */
    protected array $tracking = [];

    /**
     * model constructor.
     * Initializes the model instance and decodes any previously saved data if an ID is set.
     */
    public function __construct()
    {
        $this->primaryValue() && $this->decodeSavedData();
    }

    /**
     * Creates a new query instance for the model's table.
     *
     * @return QueryBuilder Returns a query object for database operations.
     */
    public static function query(): QueryBuilder
    {
        /** @var QueryBuilder The query builder instance. */
        $query = app(QueryBuilder::class);

        return $query->table(
            static::$table ??= Str::snake(Str::plural(class_basename(static::class)))
        )
            ->fetchModel(static::class);
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
     * Creates a new model instance from the given data and saves it to the database.
     *
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @param bool $ignoreEmpty If true, empty values will be ignored.
     * @return static The saved model instance.
     */
    public static function createOrUpdate(array|Arrayable $data, bool $ignoreEmpty = false, array $uniqueBy = []): static
    {
        $model = self::load($data, $ignoreEmpty);

        $data = $model->getFillableData();

        if (empty($uniqueBy)) {
            $uniqueBy = [static::$primaryKey];
        }

        $where = array_intersect_key($data, array_flip($uniqueBy));
        $model = self::query()->where($where)->first();

        if ($model) {
            $model->fill($data);
        } else {
            $model = self::load($data);
        }

        $model->save();

        return $model; // Return the saved model instance.
    }

    /**
     * Retrieves the first model matching the given data or creates a new one if none exists.
     *
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @return static The found or newly created model instance.
     */
    public static function firstOrCreate(array|Arrayable $data): static
    {
        $model = self::query()->where($data)->first();

        if ($model) {
            return $model;
        }

        return self::create($data);
    }

    /**
     * Retrieves the first model matching the given data or returns a new instance if none exists.
     *
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @return static The found model instance or a new instance with the given data.
     */
    public static function firstOrNew(array|Arrayable $data): static
    {
        $model = self::query()->where($data)->first();

        if ($model) {
            return $model;
        }

        return self::load($data);
    }

    /**
     * Updates an existing model or creates a new one if it doesn't exist.
     *
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @param bool $ignoreEmpty If true, empty values will be ignored.
     * @param array $uniqueBy Array of fields to uniquely identify the model.
     * @return static The updated or created model instance.
     */
    public static function updateOrCreate(array|Arrayable $data, bool $ignoreEmpty = false, array $uniqueBy = []): static
    {
        return self::createOrUpdate($data, $ignoreEmpty, $uniqueBy);
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
            $condition = [static::$primaryKey => $this->primaryValue()];
            $status = $this->query()->update($data, $condition);

            // If update fails and no record exists, insert a new record.
            if (!$status && $this->query()->where($condition)->notExists()) {
                $status = $this->query()->insert($data);
            }
        } else {
            $status = $this->query()->insert($data);
        }

        // Save model id if it is newly created.
        if (is_int($status)) {
            $this->attributes[static::$primaryKey] = $status;
        }

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
     * Loads the model attributes from the given data array.
     * 
     * This is helpful when you want to populate a model instance with 
     * directly inserting database's data.
     *
     * @param array $data Key-value pairs of model properties.
     * @return static The current model instance.
     */
    public function loadAttributes(array $data): static
    {
        $this->attributes = $data;
        $this->decodeSavedData();

        return $this;
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
        if (!isset($this->fillable) && !isset($this->guarded)) {
            throw new InvalidModelFillableException(
                'Either fillable or guarded must be defined for the modal: ' . static::class
            );
        }

        $data = [];

        $fillable = $this->fillable ?? [];
        $guarded = $this->guarded ?? [];

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
     * Prepares data before saving, such as encoding arrays to JSON and applying mutators.
     *
     * @param array $data The data to prepare.
     * @return array The prepared data for database insertion or update.
     */
    private function encodeToSaveData(array $data): array
    {
        // Apply mutators, casts, and encode data for saving
        foreach ($data as $key => $value) {
            // Check if there's a custom mutator for this attribute
            if ($this->hasMutator($key)) {
                $data[$key] = $this->mutateAttribute($key, $value);
            }
            // Check if there's a cast for this attribute
            elseif ($this->hasCast($key)) {
                $data[$key] = $this->castAttributeForStorage($key, $value);
            }
            // Handle Arrayable and Jsonable objects
            elseif ($value instanceof Jsonable) {
                $data[$key] = $value->toJson();
            }
            // Default behavior: Parse model property into string if they are in array
            elseif (is_array($value)) {
                $data[$key] = json_encode($value);
            }
            // Handle Scalar values
            elseif (is_int($value) || is_bool($value) || is_null($value)) {
                $data[$key] = $value;
            }
            // Fallback: Convert all other values to string
            else {
                $data[$key] = (string) $value;
            }
        }

        // returns associative array of model properties.
        return $data;
    }

    /**
     * Decodes JSON strings in properties to their original formats and applies casts.
     * 
     * @return void
     */
    private function decodeSavedData(): void
    {
        // Keep track of original attributes for change detection.
        if ($this->hasAnyCast()) {
            $this->tracking['__original_attributes'] = $this->attributes;
        }

        // Go Through all the properties of this model.
        foreach ($this->attributes as $key => $value) {
            // Check if there's a cast for this attribute
            if ($this->hasCast($key)) {
                $this->attributes[$key] = $this->castAttribute($key, $value);
            }
            // Fallback: if the property is json format then decode json string to associative array
            elseif (
                is_string($value) && (strpos($value, '[') === 0 || strpos($value, '{') === 0)
            ) {
                $decodedValue = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->attributes[$key] = $decodedValue;
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
        $attributes = [];

        // Process each attribute, applying accessors where they exist
        foreach ($this->attributes as $key => $value) {
            // Check for accessor first (highest priority)
            if ($this->hasAccessor($key)) {
                $attributes[$key] = $this->getAttributeValue($key);
            }
            // Use the already processed value (cast during loading in decodeSavedData)
            else {
                $attributes[$key] = $value;
            }
        }

        $attributes = array_merge($attributes, $this->getRelations());

        // Apply visibility rules with temporary overrides
        $hidden = $this->getHidden();
        $visible = $this->getVisible();

        if (!empty($visible)) {
            $attributes = array_intersect_key($attributes, array_flip($visible));
        }

        $attributes = array_diff_key($attributes, array_flip($hidden));

        if (property_exists($this, 'appends')) {
            foreach ((array) $this->appends as $key) {
                if ($this->hasAccessor($key)) {
                    $attributes[$key] = $this->getAttributeValue($key);
                }
            }
        }

        return $attributes;
    }

    /**
     * Get the hidden attributes for the model.
     *
     * @return array
     */
    public function getHidden(): array
    {
        return array_merge(
            $this->tracking['__hidden'] ?? [],
            property_exists($this, 'hidden') ? (array) $this->hidden : []
        );
    }

    /**
     * Get the visible attributes for the model.
     *
     * @return array
     */
    public function getVisible(): array
    {
        $visible = array_merge(
            $this->tracking['__visible'] ?? [],
            property_exists($this, 'visible') ? (array) $this->visible : []
        );

        return array_diff($visible, $this->getHidden());
    }

    /**
     * Make the given, typically hidden, attributes visible.
     *
     * @param string|array ...$attributes
     * @return $this
     */
    public function makeVisible(string|array ...$attributes): static
    {
        $attributes = is_array($attributes[0]) ? $attributes[0] : $attributes;
        $visible = $this->tracking['__visible'] ?? [];

        $this->tracking['__visible'] = array_merge($visible, $attributes);

        return $this;
    }

    /**
     * Make the given, typically visible, attributes hidden.
     *
     * @param string|array ...$attributes
     * @return $this
     */
    public function makeHidden(string|array ...$attributes): static
    {
        $attributes = is_array($attributes[0]) ? $attributes[0] : $attributes;
        $hidden = $this->tracking['__hidden'] ?? [];

        $this->tracking['__hidden'] = array_merge($hidden, $attributes);

        return $this;
    }

    /**
     * Converts the model to a JSON string.
     *
     * @param int $options Options for json_encode.
     * @return string A JSON representation of the model.
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
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
        // Check for accessor first (highest priority)
        if ($this->hasAccessor($offset)) {
            return $this->getAttributeValue($offset);
        }

        // Get raw value
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
        if ($this->relationLoaded($name)) {
            $this->setRelation($name, $value);
        } else {
            data_set($this->attributes, $name, $value);
        }
    }

    /**
     * Unset a specific attribute.
     *
     * @param string $name The name of the attribute to unset.
     * @return void
     */
    public function unset(string $name, ...$names): void
    {
        foreach (func_get_args() as $name) {
            if ($this->relationLoaded($name)) {
                $this->forgetRelation($name);
            } else {
                data_forget($this->attributes, $name);
            }
        }
    }

    /**
     * Get the value of a specific attribute.
     *
     * @param string $name The name of the attribute to get.
     * @param mixed $default The default value to return if the attribute is not set.
     * @return mixed The value of the attribute or the default value if not set.
     */
    public function get(string $name, $default = null): mixed
    {
        if ($this->hasAccessor($name)) {
            return $this->getAttributeValue($name);
        }

        $attributes = array_merge($this->attributes, $this->getRelations());
        return data_get(array_filter($attributes), $name, $default);
    }

    /**
     * Check if the model has a specific attribute.
     *
     * @param array|string ...$names The name of the attribute to check.
     * @return bool True if the attribute exists, false otherwise.
     */
    public function isset(array|string ...$names): bool
    {
        $names = is_array($names[0]) ? $names[0] : $names;
        foreach ($names as $name) {
            if ($this->get($name) === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get the original value of a specific attribute before any changes were made.
     *
     * @param string $name The name of the attribute to get.
     * @param mixed $default The default value to return if the attribute is not set.
     * @return mixed The original value of the attribute or the default value if not set.
     */
    public function getOriginal(string $name, $default = null): mixed
    {
        return $this->tracking['__original_attributes'][$name] ?? $this->attributes[$name] ?? $default;
    }

    /**
     * Get the changes made to the model compared to its original state.
     *
     * @return array An associative array of changed attributes and their new values.
     */
    public function getChanges(): array
    {
        if (!$this->hasOriginal()) {
            return []; // No original data to compare with.
        }

        $original = $this->tracking['__original_attributes'];
        $changes = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $original) || $original[$key] !== $value) {
                $changes[$key] = $value;
            }
        }

        return $changes;
    }

    /**
     * Check if the model has any changes compared to its original state.
     *
     * @return bool True if there are changes, false otherwise.
     */
    public function hasChanges(): bool
    {
        return !empty($this->getChanges());
    }

    /**
     * Clear the original attributes of the model.
     * 
     * @return void
     */
    public function clearOriginal(): void
    {
        unset($this->tracking['__original_attributes']);
    }

    /**
     * Check if the model has original attributes stored.
     * 
     * @return bool
     */
    public function hasOriginal(): bool
    {
        return isset($this->tracking['__original_attributes']);
    }

    /**
     * Preserve the current attributes as original before updating.
     * 
     * This method should be called before making updates to the model.
     * 
     * @param array $attributes The new data to fill the model with.
     * @return void
     */
    public function preserveOriginalBeforeUpdating(array $attributes): void
    {
        $this->tracking['__original_attributes'] = $this->attributes;
        $this->fill($attributes); // Fill the model with the new data.
    }

    /**
     * Restore the model's attributes to their original state.
     * 
     * This method reverts any changes made to the model since the last save.
     * 
     * @return void
     */
    public function restoreOriginal(): void
    {
        if ($this->hasOriginal()) {
            $this->attributes = $this->tracking['__original_attributes'];
            $this->clearOriginal();
        }
    }

    /**
     * Get a new instance of the model with only the specified attributes.
     *
     * @param string|array ...$fields The attributes to include.
     * @return static
     */
    public function only(string|array ...$fields): static
    {
        $fields = is_array($fields[0]) ? $fields[0] : $fields;
        $filtered = array_intersect_key($this->attributes, array_flip((array) $fields));
        return static::load($filtered);
    }

    /**
     * Get a new instance of the model with all attributes except the specified ones.
     *
     * @param string|array ...$fields The attributes to exclude.
     * @return static
     */
    public function except(string|array ...$fields): static
    {
        $fields = is_array($fields[0]) ? $fields[0] : $fields;
        $filtered = array_diff_key($this->attributes, array_flip((array) $fields));
        return static::load($filtered);
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
     * Create a copy of the model instance.
     *
     * @return static
     */
    public function copy(): static
    {
        return clone $this;
    }

    /**
     * Check if an accessor method exists for a given attribute.
     *
     * @param string $name The name of the attribute.
     * @return bool True if an accessor method exists, false otherwise.
     */
    public function hasAccessor(string $name): bool
    {
        $method = sprintf('get%sAttribute', Str::studly($name));
        return method_exists($this, $method);
    }

    /**
     * Get the value of an attribute using its accessor method.
     *
     * @param string $name The name of the attribute.
     * @return mixed The value of the attribute.
     */
    public function getAttributeValue(string $name): mixed
    {
        $method = sprintf('get%sAttribute', Str::studly($name));
        return $this->{$method}();
    }

    /**
     * Check if a mutator method exists for a given attribute.
     *
     * @param string $name The name of the attribute.
     * @return bool True if a mutator method exists, false otherwise.
     */
    public function hasMutator(string $name): bool
    {
        $method = sprintf('set%sAttribute', Str::studly($name));
        return method_exists($this, $method);
    }

    /**
     * Mutate the value of an attribute using its mutator method.
     *
     * @param string $name The name of the attribute.
     * @param mixed $value The value to mutate.
     * @return mixed The mutated value of the attribute.
     */
    public function mutateAttribute(string $name, mixed $value): mixed
    {
        $method = sprintf('set%sAttribute', Str::studly($name));
        return $this->{$method}($value);
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

        return call_user_func([$this->query()->useModel($this), $name], ...$arguments);
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
