<?php

namespace Spark\Database;

use Spark\Database\Casts\Castable;
use Spark\Database\Contracts\ModelContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Support\Jsonable;
use Spark\Database\QueryBuilder;
use Spark\Database\Traits\HasRelation;
use Spark\Support\Str;
use Spark\Support\Traits\Macroable;
use function array_key_exists;
use function array_slice;
use function func_get_args;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function sprintf;

/**
 * Class Model
 * 
 * This class provides a base for models, handling database operations and entity management.
 * It includes CRUD operations, data decoding, and dynamic method invocation.
 *
 * @method static QueryBuilder with($relations)
 * @method static QueryBuilder withFiltered(string $relation, string|array $filters)
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
 * @method static QueryBuilder withSum(string $relation, string $column, ?Closure $callback = null)
 * @method static QueryBuilder withAvg(string $relation, string $column, ?Closure $callback = null)
 * @method static QueryBuilder withMin(string $relation, string $column, ?Closure $callback = null)
 * @method static QueryBuilder withMax(string $relation, string $column, ?Closure $callback = null)
 * @method static QueryBuilder when(mixed $value, callable $callback)
 * @method static QueryBuilder unless(mixed $value, callable $callback)
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
 * @method static QueryBuilder whereContains(string $column, mixed $value)
 * @method static QueryBuilder whereStartsWith(string $column, mixed $value)
 * @method static QueryBuilder whereEndsWith(string $column, mixed $value)
 * @method static QueryBuilder whereDate(string $column, string $operator, $value = null)
 * @method static QueryBuilder whereYear(string $column, string $operator, $value = null)
 * @method static QueryBuilder findInSet($field, $key, $type = '', $andOr = 'AND')
 * @method static QueryBuilder notFindInSet($field, $key)
 * @method static QueryBuilder between($field, $value1, $value2, $type = '', $andOr = 'AND')
 * @method static QueryBuilder notBetween($field, $value1, $value2)
 * @method static QueryBuilder like($field, $data, $type = '', $andOr = 'AND')
 * @method static QueryBuilder grouped(Closure $callback)
 * @method static int update(array|Arrayable $data, mixed $where = null)
 * @method static int delete(mixed $where = null)
 * @method static int truncate()
 * @method static QueryBuilder select(array|string $fields = '*')
 * @method static QueryBuilder selectRaw(string $sql, array $bindings = [])
 * @method static QueryBuilder column(string $column)
 * @method static QueryBuilder max($field, $name = null)
 * @method static QueryBuilder min($field, $name = null)
 * @method static QueryBuilder sum($field, $name = null)
 * @method static QueryBuilder avg($field, $name = null)
 * @method static QueryBuilder as(string $alias)
 * @method static QueryBuilder join(string $table, $field1 = null, $operator = null, $field2 = null, $type = '')
 * @method static QueryBuilder order(string $sort)
 * @method static QueryBuilder orderBy(string $field, string $sort = 'ASC')
 * @method static QueryBuilder orderAsc(string $field = 'id')
 * @method static QueryBuilder orderDesc(string $field = 'id')
 * @method static QueryBuilder groupBy(string|array $field)
 * @method static QueryBuilder groupByRaw(string $sql, array $bindings = [])
 * @method static QueryBuilder having(string $having)
 * @method static QueryBuilder take(int $limit)
 * @method static QueryBuilder limit(?int $offset = null, ?int $limit = null)
 * @method static QueryBuilder fetch(...$fetch)
 * @method static QueryBuilder latest(string $field = 'created_at')
 * @method static QueryBuilder oldest(string $field = 'created_at')
 * @method static QueryBuilder random()
 * @method static QueryBuilder distinct(?string $column = null)
 * @method static QueryBuilder union(QueryBuilder|Closure $query, bool $all = false)
 * @method static mixed first()
 * @method static mixed firstOrFail()
 * @method static mixed last()
 * @method static false|Model find($value)
 * @method static Model findOrFail($value)
 * @method static bool destroy($value)
 * @method static array all($fields = null)
 * @method static array raw(string $sql, array $bindings = [])
 * @method static array pluck(string $column)
 * @method static mixed value(string $column)
 * @method static bool increment(string $column, int $value = 1, $where = null)
 * @method static bool decrement(string $column, int $value = 1, $where = null)
 * @method static int count()
 * @method static \Spark\Utils\Paginator paginate(int $limit = 10, string $keyword = 'page')
 * @method static \Spark\Support\Collection filter(?callable $callback = null)
 * @method static \Spark\Support\Collection map(callable $callback)
 * @method static \Spark\Support\Collection mapToDictionary(callable $callback)
 * @method static \Spark\Support\Collection mapWithKeys(callable $callback)
 * @method static \Spark\Support\Collection merge($items)
 * @method static \Spark\Support\Collection mergeRecursive($items)
 *
 * This class provides a base for models, handling database operations and entity management.
 * It includes CRUD operations, data decoding, and dynamic method invocation.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
abstract class Model implements ModelContract, Arrayable, Jsonable, \ArrayAccess, \IteratorAggregate
{
    use HasRelation, Castable, Macroable {
        __call as macroCall;
        __callStatic as staticMacroCall;
    }

    /**
     * @var string The table name associated with this model.
     */
    protected string $table;

    /**
     * @var string The primary key of the model.
     */
    protected string $primaryKey;

    /**
     * The model attributes.
     *
     * @var array
     */
    protected array $attributes = [];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected array $guarded = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected array $fillable = [];

    /**
     * The accessors to append to the model's array form.
     * 
     * @var array
     */
    protected array $appends = [];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected array $hidden = [];

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
        $this->hasPrimaryValue() && $this->castStoredData();
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
        $model = new static();

        return $query->table($model->getTable())
            ->fetchModel(static::class);
    }

    /**
     * Retrieves the table name associated with the model.
     *
     * @return string The table name for the model.
     */
    public function getTable(): string
    {
        return $this->table ??= Str::snake(Str::plural(class_basename(static::class)));
    }

    /**
     * Retrieves the primary key field name for the model.
     *
     * @return string The primary key field name.
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey ??= 'id';
    }

    /**
     * Retrieves the Events instance for the model.
     *
     * @return Events The Events instance.
     */
    protected function events(): Events
    {
        return Events::none();
    }

    /**
     * Creates a new model instance from the given data and saves it to the database.
     * 
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @return static The saved model instance.
     */
    public static function create(array|Arrayable $data): static
    {
        $model = new static();
        $model->fill($data);
        $model->save(forceCreate: true);

        return $model; // Return the saved model instance.
    }

    /**
     * Creates a new model instance from the given data and saves it to the database.
     *
     * @param array|Arrayable $data Key-value pairs of model properties.
     * @param array $uniqueBy Array of fields to uniquely identify the model.
     * @param array|Arrayable $values Additional values to set if creating a new model.
     * @return static The saved model instance.
     */
    public static function createOrUpdate(array|Arrayable $data, array $uniqueBy = [], array|Arrayable $values = []): static
    {
        $model = new static();
        $model->fill($data);

        $data = $model->getFillableData();

        if (empty($uniqueBy)) {
            $uniqueBy = array_keys($data);
        }

        $where = array_intersect_key($data, array_flip($uniqueBy));
        $model = self::query()->where($where)->first();
        $data = array_merge($data, !is_array($values) ? $values->toArray() : $values);

        if ($model) {
            $model->fill($data);
        } else {
            $model = new static();
            $model->fill($data);
        }

        $model->save(); // Save the model.

        return $model; // Return the saved model instance.
    }

    /**
     * Retrieves the first model matching the given data or creates a new one if none exists.
     *
     * @param array|Arrayable $attributes Key-value pairs of model properties.
     * @param array|Arrayable $values Additional values to set if creating a new model.
     * @return static The found or newly created model instance.
     */
    public static function firstOrCreate(array|Arrayable $attributes, array|Arrayable $values = []): static
    {
        $model = self::query()->where($attributes)->first();

        if ($model) {
            return $model;
        }

        $attributes = array_merge(
            !is_array($attributes) ? $attributes->toArray() : $attributes,
            !is_array($values) ? $values->toArray() : $values
        );

        return self::create($attributes);
    }

    /**
     * Retrieves the first model matching the given data or returns a new instance if none exists.
     *
     * @param array|Arrayable $attributes Key-value pairs of model properties.
     * @param array|Arrayable $values Additional values to set if creating a new model.
     * @return static The found model instance or a new instance with the given data.
     */
    public static function firstOrNew(array|Arrayable $attributes, array|Arrayable $values = []): static
    {
        $model = self::query()->where($attributes)->first();

        if ($model) {
            return $model;
        }

        $attributes = array_merge(
            !is_array($attributes) ? $attributes->toArray() : $attributes,
            !is_array($values) ? $values->toArray() : $values
        );

        $model = new static();
        $model->fill($attributes);

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
            $this->updateAttributeValue($key, $value);
        }

        $this->castStoredData(); // Apply casting to the data.

        return $this;
    }

    /**
     * Saves the model to the database, either updating or creating a new entry.
     *
     * @param bool $forceCreate If true, forces the creation of a new record even if the model has a primary key.
     * @return bool True on success, false on failure.
     */
    public function save(bool $forceCreate = false): bool
    {
        // Apply events for before save and encode array into json string. 
        $data = $this->castDataForStorage($this->getFillableData());

        // Initialize default status variables.
        $updatedStatus = false;
        $createdId = 0;

        unset($this->tracking['__was_updated'], $this->tracking['__was_created']); // Reset update and create tracking.

        // Update this records if it has an id, else insert this records into database.
        if ($this->hasPrimaryValue()) {
            $condition = [$this->getPrimaryKey() => $this->primaryValue()];
            $updatedStatus = (bool) $this->query()->update($data, $condition);

            // If update fails and no record exists, insert a new record.
            if (!$updatedStatus && $forceCreate) {
                try {
                    if ($this->query()->where($condition)->notExists()) {
                        $createdId = $this->query()->insert($data);
                    }
                } catch (\Exception $e) {
                    return false; // Return false on failure.
                }
            }
        } else {
            $createdId = $this->query()->insert($data);
        }

        // Save model id if it is newly created.
        if (!$this->hasPrimaryValue() && is_int($createdId) && $createdId > 0) {
            $this->attributes[$this->getPrimaryKey()] = $createdId;
        }

        $updatedStatus && $this->trackUpdated();
        $createdId && $this->trackCreated();

        return $updatedStatus || $createdId; // Return true on success.
    }

    /**
     * Removes the model from the database.
     *
     * @return bool True if removal was successful, false otherwise.
     */
    public function remove(): bool
    {
        $deleted = $this->query()->delete([$this->getPrimaryKey() => $this->primaryValue()]);
        if ($deleted) {
            $this->trackDeleted();
        }
        return $deleted;
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

        foreach ($this->attributes as $key => $value) {
            // If fillable is defined, only allow fillable fields unless guarded explicitly restricts it.
            if (!empty($this->fillable) && in_array($key, $this->fillable)) {
                $data[$key] = $value;
            }
            // If fillable is empty, assume all fields are fillable except the guarded ones.
            elseif (empty($this->fillable) && !in_array($key, $this->guarded)) {
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
    private function castDataForStorage(array $data): array
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
            // Handle Carbon time Object into string
            elseif ($value instanceof \Spark\Utils\Carbon) {
                $data[$key] = $value->toDateTimeString();
            }
            // Handle URL Object into string
            elseif ($value instanceof \Spark\Url) {
                $data[$key] = $value->getUrl();
            }
            // Handle Scalar values
            elseif ($value === null || is_int($value) || is_bool($value)) {
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
    private function castStoredData(): void
    {
        // Go Through all the properties of this model.
        if ($this->hasAnyCast()) {
            foreach ($this->attributes as $key => $value) {
                if ($this->hasCast($key)) {
                    $this->attributes[$key] = $this->castAttribute($key, $value);
                }
            }
        }

        // Store the initial hash of the model's data for change tracking.
        $this->hasPrimaryValue() && ($this->tracking['__hash'] ??= $this->makeHash());
    }

    /**
     * Gets the value of the primary key.
     *
     * @param mixed $default The value to return if the primary key is empty.
     * @return mixed The value of the primary key or the default value.
     */
    public function primaryValue($default = null): mixed
    {
        return $this->attributes[$this->getPrimaryKey()] ?? $default;
    }

    /**
     * Checks if the model has an original primary key value.
     *
     * @return bool True if the model has an original primary key, false otherwise.
     */
    public function hasPrimaryValue(): bool
    {
        return !empty($this->primaryValue());
    }

    /**
     * Checks if the model exists in the database.
     *
     * @return bool True if the model exists, false otherwise.
     */
    public function exists(): bool
    {
        if (!$this->hasPrimaryValue()) {
            return false;
        }

        return $this->tracking['__exists'] ??= $this->query()
            ->where([$this->getPrimaryKey() => $this->primaryValue()])
            ->exists();
    }

    /**
     * Checks if the model was newly created (i.e., it has a primary key but no original primary key).
     *
     * @return bool True if the model was newly created, false otherwise.
     */
    public function wasNewlyCreated(): bool
    {
        return $this->exists() && !$this->hasOriginal() && ($this->tracking['__was_created'] ?? false);
    }

    /**
     * Checks if the model was updated (i.e., it has an original primary key and changes were made).
     *
     * @return bool True if the model was updated, false otherwise.
     */
    public function wasUpdated(): bool
    {
        return $this->exists() && $this->hasOriginal() && ($this->tracking['__was_updated'] ?? false);
    }

    /**
     * Checks if the model was created (i.e., it has a primary key and was marked as created).
     *
     * @return bool True if the model was created, false otherwise.
     */
    public function wasCreated(): bool
    {
        return $this->exists() && ($this->tracking['__was_created'] ?? false);
    }

    /**
     * Checks if the model was either newly created or updated.
     *
     * @return bool True if the model was changed, false otherwise.
     */
    public function wasChanged(): bool
    {
        return $this->wasNewlyCreated() || $this->wasCreated() || $this->wasUpdated();
    }

    /**
     * Checks if the model was deleted.
     *
     * @return bool True if the model was deleted, false otherwise.
     */
    public function wasDeleted(): bool
    {
        return $this->tracking['__was_deleted'] ?? false;
    }

    /**
     * Magic setter method to set the value of a model attribute.
     *
     * @param string $name The name of the attribute to set.
     * @param mixed $value The value to set.
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->offsetSet($name, $value);
    }

    /**
     * Magic getter method to retrieve the value of a model attribute.
     *
     * @param string $name The name of the attribute to retrieve.
     * @return mixed|null The value of the attribute if set, or null if not set.
     */
    public function __get(string $name): mixed
    {
        return $this->offsetGet($name);
    }

    /**
     * Checks if the specified model attribute is set.
     *
     * @param string $name The name of the attribute to check.
     * @return bool True if the attribute is set, false otherwise.
     */
    public function __isset(string $name): bool
    {
        return $this->offsetExists($name);
    }

    /**
     * Magic unset method to remove the specified model attribute.
     *
     * @param string $name The name of the attribute to remove.
     * @return void
     */
    public function __unset(string $name): void
    {
        $this->offsetUnset($name);
    }

    /**
     * Converts the model to an associative array.
     *
     * @return array An array of model properties and their values.
     */
    public function toArray(): array
    {
        $attributes = [];

        // Process each attribute, applying accessors where they exist
        foreach ($this->attributes as $key => $value) {
            // Check for accessor first (highest priority)
            if ($this->hasAccessor($key)) {
                $attributes[$key] = $this->getAttributeValue($key, $value);
            }
            // Use the already processed value (cast during loading in decodeSavedData)
            else {
                $attributes[$key] = $value;
            }
        }

        $attributes = [...$attributes, ...$this->getRelations()];

        // Apply visibility rules with temporary overrides
        $hidden = $this->getHidden();
        $visible = $this->getVisible();

        if (!empty($visible)) {
            $attributes = array_intersect_key($attributes, array_flip($visible));
        }

        $attributes = array_diff_key($attributes, array_flip($hidden));

        foreach ($this->appends as $key) {
            if ($this->hasAccessor($key)) {
                $attributes[$key] = $this->getAttributeValue($key, $this->attributes[$key] ?? null);
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
        return [
            ...$this->tracking['__hidden'] ?? [],
            ...$this->hidden
        ];
    }

    /**
     * Get the visible attributes for the model.
     *
     * @return array
     */
    public function getVisible(): array
    {
        $visible = $this->tracking['__visible'] ?? [];

        return array_diff($visible, $this->getHidden());
    }

    /**
     * Make the given, typically hidden, attributes visible.
     *
     * @param string|array $attributes
     * @return $this
     */
    public function makeVisible(string|array $attributes): static
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();
        $visible = $this->tracking['__visible'] ?? [];

        $this->tracking['__visible'] = [...$visible, ...$attributes];

        return $this;
    }

    /**
     * Make the given, typically visible, attributes hidden.
     *
     * @param string|array $attributes
     * @return $this
     */
    public function makeHidden(string|array $attributes): static
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();
        $hidden = $this->tracking['__hidden'] ?? [];

        $this->tracking['__hidden'] = [...$hidden, ...$attributes];

        return $this;
    }

    /**
     * Converts the model to a JSON string.
     *
     * @param int $options Options for json_encode.
     * @return string A JSON representation of the model.
     */
    public function toJson($options = 0): string
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
            return $this->getAttributeValue($offset, $this->attributes[$offset] ?? null);
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
            $this->updateAttributeValue($offset, $value);
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
            $this->updateAttributeValue($offset, null);
            unset($this->attributes[$offset]); // Also remove the attribute from the model's attributes array.
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
            $this->updateNestedAttributeValue($name, $value);
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
                $this->trackNestedAttributeRemoval($name);
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
        if (!str_contains($name, '.')) {
            return $this->offsetGet($name) ?: $default;
        }

        $segments = explode('.', $name);
        $path = implode('.', array_slice($segments, 1));
        $rootKey = $segments[0];

        $rootValue = null;
        if ($this->hasAccessor($rootKey)) {
            $rootValue = $this->getAttributeValue($rootKey, $this->attributes[$rootKey] ?? null);
        } elseif ($this->relationLoaded($rootKey)) {
            $rootValue = $this->getRelationshipAttribute($rootKey);
        }

        if ($rootValue) {
            return data_get(array_filter($rootValue), $path, $default);
        }

        return data_get(array_filter($this->attributes), $name, $default);
    }

    /**
     * Check if the model has a specific attribute.
     *
     * @param array|string $column The name of the attribute to check.
     * @return bool True if the attribute exists, false otherwise.
     */
    public function isset(array|string $column): bool
    {
        $column = is_array($column) ? $column : func_get_args();
        foreach ($column as $name) {
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
     * Get the original value of a nested attribute before any changes.
     *
     * @param string $path The dot-notation path to the attribute.
     * @param mixed $default The default value if no original exists.
     * @return mixed The original value or the default.
     */
    public function getNestedOriginal(string $path, mixed $default = null): mixed
    {
        // Check if we have a direct record of this path's original value
        if (isset($this->tracking['__changed_paths'][$path])) {
            return $this->tracking['__changed_paths'][$path]['original'];
        }

        // Fall back to getting from the original root attribute
        $segments = explode('.', $path);
        $rootKey = array_shift($segments);

        if (isset($this->tracking['__original_attributes'][$rootKey])) {
            $originalRoot = $this->tracking['__original_attributes'][$rootKey];
            return data_get($originalRoot, implode('.', $segments), $default);
        }

        return data_get($this->attributes, $path, $default);
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

        return $this->tracking['__original_attributes'];
    }

    /**
     * Get all nested paths that have been changed.
     *
     * @return array An associative array of changed paths with their original and new values.
     */
    public function getNestedChanges(): array
    {
        return $this->tracking['__changed_paths'] ?? [];
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
        unset($this->tracking['__original_attributes'], $this->tracking['__changed_paths']);
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
     * Check if the model or a specific field is dirty (has changes).
     *
     * @param string|null $field The specific field to check for changes. If null, checks the entire model.
     * @return bool True if the model or the specified field has changes, false otherwise.
     */
    public function isDirty(null|string $field = null): bool
    {
        if (($originalHash = ($this->tracking['__hash'] ?? null)) === null) {
            return false; // No original hash means we can't determine if it's dirty.
        }

        $currentHash = $this->makeHash();

        $dirty = $field === null ? $this->hasChanges()
            : in_array($field, array_keys($this->getChanges()));

        // If the model is dirty, it must have changes and the current 
        // hash should differ from the original hash.
        return $dirty && !$this->wasUpdated() && $currentHash !== $originalHash;
    }

    /**
     * Check if a specific nested path has been changed.
     *
     * @param string|null $path The dot-notation path to check, or null to check if any nested path changed.
     * @return bool True if the path (or any path) has been changed.
     */
    public function isNestedDirty(null|string $path = null): bool
    {
        $changedPaths = $this->tracking['__changed_paths'] ?? [];

        if ($path === null) {
            return !empty($changedPaths);
        }

        // Check exact path match
        if (isset($changedPaths[$path])) {
            return true;
        }

        // Check if any child paths of the given path have changed
        $prefix = "$path.";
        foreach (array_keys($changedPaths) as $changedPath) {
            if (str_starts_with($changedPath, $prefix)) {
                return true;
            }
        }

        return false;
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
            $this->attributes = [...$this->attributes, ...$this->tracking['__original_attributes']];
            $this->clearOriginal();
        }
    }

    /**
     * Mark the model as updated.
     * 
     * @return void
     */
    public function trackUpdated(): void
    {
        $this->tracking['__was_updated'] = true;
        $this->tracking['__hash'] = $this->makeHash();
        $this->triggerEvent('updated'); // Trigger the 'updated' event.
    }

    /**
     * Mark the model as created.
     * 
     * @return void
     */
    public function trackCreated(): void
    {
        $this->tracking['__was_created'] = true;
        $this->tracking['__hash'] = $this->makeHash();
        $this->triggerEvent('created'); // Trigger the 'created' event.
    }

    /**
     * Mark the model as deleted.
     * 
     * @return void
     */
    public function trackDeleted(): void
    {
        $this->tracking['__was_deleted'] = true;
        $this->triggerEvent('deleted'); // Trigger the 'deleted' event.
    }

    /**
     * Trigger a model event if the events method exists.
     *
     * @param string $event The name of the event to trigger.
     * @return void
     */
    public function triggerEvent(string $event): void
    {
        /** @var \Spark\Database\Events $events */
        $events = $this->events();

        if (!$events->hasEvents()) {
            return; // No events to trigger.
        }

        if (in_array($event, ['created', 'updated', 'deleted'])) {
            $events->changed();
        }

        $events->$event(); // Call the event method dynamically.
    }

    /**
     * Clear all tracking information for the model.
     * 
     * @return void
     */
    public function clearTracks(): void
    {
        $this->tracking = [];
    }

    /**
     * Get a new instance of the model with only the specified attributes.
     *
     * @param string|array $field The attributes to include.
     * @return static
     */
    public function only(string|array $field): static
    {
        $field = is_array($field) ? $field : func_get_args();
        $filtered = array_intersect_key($this->attributes, array_flip($field));

        $model = new static();
        $model->fill($filtered);

        return $model;
    }

    /**
     * Get a new instance of the model with all attributes except the specified ones.
     *
     * @param string|array $field The attributes to exclude.
     * @return static
     */
    public function except(string|array $field): static
    {
        $field = is_array($field) ? $field : func_get_args();
        $filtered = array_diff_key($this->attributes, array_flip($field));

        $model = new static();
        $model->fill($filtered);

        return $model;
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
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->toArray());
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
        $method2 = sprintf('%sAttribute', Str::camel($name));

        return method_exists($this, $method) ||
            method_exists($this, $method2);
    }

    /**
     * Get the value of an attribute using its accessor method.
     *
     * @param string $name The name of the attribute.
     * @return mixed The value of the attribute.
     */
    public function getAttributeValue(string $name, $value): mixed
    {
        $method = sprintf('get%sAttribute', Str::studly($name));
        if (method_exists($this, $method)) {
            return $this->{$method}($value);
        }

        $method2 = sprintf('%sAttribute', Str::camel($name));
        return ($this->{$method2})->get($value);
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
        $method2 = sprintf('%sAttribute', Str::camel($name));

        return method_exists($this, $method)
            || method_exists($this, $method2);
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
        if (method_exists($this, $method)) {
            return $this->{$method}($value);
        }

        $method2 = sprintf('%sAttribute', Str::camel($name));
        return ($this->{$method2})->set($value);
    }

    /**
     * Refresh the model instance with the latest data from the database.
     *
     * @return static The refreshed model instance.
     */
    public function refresh(): static
    {
        if ($this->hasPrimaryValue()) {
            $fresh = static::query()
                ->where([$this->getPrimaryKey() => $this->primaryValue()])
                ->fetchAssoc()
                ->first();

            if ($fresh) {
                $this->attributes = $fresh; // Update attributes with fresh data.
                $this->clearTracks();
                $this->castStoredData();
                $this->reloadRelations();
            }
        }

        return $this;
    }

    /**
     * Handles dynamic method calls to the query instance.
     *
     * @param string $name The method name.
     * @param array $arguments The method arguments.
     * @return mixed The result of the query method call.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (static::hasMacro($name)) {
            return $this->macroCall($name, $arguments);
        }

        return $this->query()->useModel($this)->$name(...$arguments);
    }

    /**
     * Handles static method calls to the query instance.
     *
     * @param string $name The method name.
     * @param array $arguments The method arguments.
     * @return mixed The result of the query method call.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        if (static::hasMacro($name)) {
            return static::staticMacroCall($name, $arguments);
        }

        return self::query()->$name(...$arguments);
    }

    /**
     * Generate a hash of the model's fillable data for change tracking.
     *
     * @return string The generated hash.
     */
    protected function makeHash(): string
    {
        $data = $this->castDataForStorage($this->getFillableData());
        return md5(json_encode($data));
    }

    /**
     * Update the value of a specific attribute and track changes if necessary.
     *
     * @param string $key The name of the attribute to update.
     * @param mixed $value The new value for the attribute.
     * @return void
     */
    protected function updateAttributeValue(string $key, mixed $value): void
    {
        // Only track changes if the model has a primary key and an original hash exists (i.e., it exists in the database).
        if (
            $this->hasPrimaryValue() && isset($this->tracking['__hash']) &&
            !array_key_exists($key, $this->tracking['__original_attributes'] ?? []) &&
            ($this->attributes[$key] ?? null) !== $value
        ) {
            $this->tracking['__original_attributes'][$key] = ($this->attributes[$key] ?? null);
        }

        $this->attributes[$key] = $value; // Set the new value for the attribute.
    }

    /**
     * Update a nested attribute value using dot notation and track changes.
     *
     * This method handles both simple attributes (e.g., 'name') and nested paths
     * (e.g., 'meta.country'). For nested paths, it tracks the root attribute's original
     * value and records which specific nested paths were modified.
     *
     * @param string $path The dot-notation path to the attribute (e.g., 'meta.country').
     * @param mixed $value The new value for the attribute.
     * @return void
     */
    protected function updateNestedAttributeValue(string $path, mixed $value): void
    {
        // If no dot notation, delegate to simple attribute update
        if (!str_contains($path, '.')) {
            $this->updateAttributeValue($path, $value);
            return;
        }

        // Only track if model exists in database
        if ($this->hasPrimaryValue() && isset($this->tracking['__hash'])) {
            // Track the specific nested path that was changed
            $currentValue = data_get($this->attributes, $path);
            if ($currentValue !== $value) {
                return; // No change, so no need to track
            }

            // Extract the root key (e.g., 'meta' from 'meta.country')
            $segments = explode('.', $path);
            $rootKey = $segments[0];

            // Track the original value of the root attribute if not already tracked
            if (!array_key_exists($rootKey, $this->tracking['__original_attributes'] ?? [])) {
                $this->tracking['__original_attributes'][$rootKey] = $this->attributes[$rootKey] ?? null;
            }

            // Track the specific nested path change with original and new values
            $this->tracking['__changed_paths'][$path] = ['original' => $currentValue, 'new' => $value];
        }

        // Set the nested value
        data_set($this->attributes, $path, $value);
    }

    /**
     * Track the removal of a nested attribute before it's forgotten.
     *
     * @param string $path The dot-notation path to the attribute being removed.
     * @return void
     */
    protected function trackNestedAttributeRemoval(string $path): void
    {
        // Only track if model exists in database
        if (!$this->hasPrimaryValue() || !isset($this->tracking['__hash'])) {
            return;
        }

        if (!str_contains($path, '.')) {
            // Simple attribute removal - track the original value
            if (
                !array_key_exists($path, $this->tracking['__original_attributes'] ?? []) &&
                array_key_exists($path, $this->attributes)
            ) {
                $this->tracking['__original_attributes'][$path] = $this->attributes[$path];
            }
            return;
        }

        // For nested paths, track the root attribute's original value
        $segments = explode('.', $path);
        $rootKey = $segments[0];

        if (!array_key_exists($rootKey, $this->tracking['__original_attributes'] ?? [])) {
            $this->tracking['__original_attributes'][$rootKey] = $this->attributes[$rootKey] ?? null;
        }

        // Track the specific path removal
        $currentValue = data_get($this->attributes, $path);
        if ($currentValue !== null) {
            $this->tracking['__changed_paths'][$path] = [
                'original' => $currentValue,
                'new' => null,
                'removed' => true,
            ];
        }
    }
}
