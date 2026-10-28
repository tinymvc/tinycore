<?php

namespace Spark\Database;

use Spark\Database\Casts\Castable;
use Spark\Database\Concerns\InteractsWithSoftDeletes;
use Spark\Database\Contracts\ModelContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Support\Jsonable;
use Spark\Database\QueryBuilder;
use Spark\Database\Concerns\InteractsWithOrm;
use Spark\Database\Concerns\InteractsWithTracking;
use Spark\Support\Str;
use Spark\Support\Traits\Conditionable;
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
 * @mixin QueryBuilder
 * 
 * @method static QueryBuilder with($relations)
 * @method static QueryBuilder withExists(array|string $relations, ?Closure $callback = null)
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
 * @method static int|array upsert(array|Arrayable $data, null|array $conflict = null, null|array $update = null)
 * @method static QueryBuilder where(null|string|array|Closure $column = null, ?string $operator = null, mixed $value = null, ?string $boolean = null, bool $not = false)
 * @method static QueryBuilder whereRaw(string $sql, string|array $bindings = [])
 * @method static QueryBuilder whereNull($where, $not = false)
 * @method static QueryBuilder whereNotNull($where)
 * @method static QueryBuilder in(string $column, array $values)
 * @method static QueryBuilder notIn(string $column, array $values)
 * @method static QueryBuilder whereIn(string $column, array|string|QueryBuilder|Closure $values)
 * @method static QueryBuilder whereNotIn(string $column, array|string|QueryBuilder|Closure $values)
 * @method static QueryBuilder orWhereIn(string $column, array|string|QueryBuilder|Closure $values)
 * @method static QueryBuilder orWhereNotIn(string $column, array|string|QueryBuilder|Closure $values)
 * @method static QueryBuilder whereContains(string $column, mixed $value)
 * @method static QueryBuilder whereStartsWith(string $column, mixed $value)
 * @method static QueryBuilder whereEndsWith(string $column, mixed $value)
 * @method static QueryBuilder whereDate(string $column, string $operator, $value = null)
 * @method static QueryBuilder whereYear(string $column, string $operator, $value = null)
 * @method static QueryBuilder findInSet($field, $key, $type = '', $boolean = 'AND')
 * @method static QueryBuilder notFindInSet($field, $key)
 * @method static QueryBuilder between($field, $value1, $value2, $type = '', $boolean = 'AND')
 * @method static QueryBuilder notBetween($field, $value1, $value2)
 * @method static QueryBuilder like($field, $data, $type = '', $boolean = 'AND')
 * @method static QueryBuilder grouped(Closure $callback)
 * @method static int update(array|Arrayable $data, mixed $where = null)
 * @method static int delete(mixed $where = null)
 * @method static int truncate()
 * @method static QueryBuilder select(array|string $fields = '*')
 * @method static QueryBuilder selectSub(string|QueryBuilder|Closure $subquery, string $alias)
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
 * @method static QueryBuilder latest(string $field = 'created_at', $fields = null)
 * @method static QueryBuilder oldest(string $field = 'created_at', $fields = null)
 * @method static QueryBuilder random()
 * @method static QueryBuilder distinct(?string $column = null)
 * @method static QueryBuilder union(QueryBuilder|Closure $query, bool $all = false)
 * @method static QueryBuilder withTrashed(bool $withTrashed = true)
 * @method static QueryBuilder onlyTrashed()
 * @method static QueryBuilder withoutTrashed()
 * @method static QueryBuilder whereInSub(string $column, string|QueryBuilder|Closure $subquery, string $boolean = 'AND', bool $not = false)
 * @method static QueryBuilder whereNotInSub(string $column, string|QueryBuilder|Closure $subquery, string $boolean = 'AND')
 * @method static QueryBuilder whereExists(string|QueryBuilder|Closure $subquery, string $boolean = 'AND', bool $not = false)
 * @method static QueryBuilder whereNotExists(string|QueryBuilder|Closure $subquery, string $boolean = 'AND')
 * @method static QueryBuilder orWhereExists(string|QueryBuilder|Closure $subquery)
 * @method static QueryBuilder orWhereNotExists(string|QueryBuilder|Closure $subquery)
 * @method static int forceDelete(null|string|array|Arrayable|Closure $where = null)
 * @method static mixed first($fields = null)
 * @method static mixed firstOrFail($where = null, $fields = null)
 * @method static mixed last($fields = null)
 * @method static Model|null find($value)
 * @method static Model findOrFail($value)
 * @method static QueryBuilder whereKey(string|int|array $value)
 * @method static QueryBuilder whereNotKey(string|int|array $value)
 * @method static QueryBuilder lock(bool|string|null $value = true)
 * @method static QueryBuilder lockForUpdate()
 * @method static QueryBuilder sharedLock()
 * @method static int destroy($value)
 * @method static array all($fields = null)
 * @method static Expression raw(string $sql)
 * @method static array run(string $sql, array $bindings = [])
 * @method static array pluck(string $column, ?string $key = null)
 * @method static mixed value(string $column)
 * @method static bool increment(string $column, int $value = 1, $where = null)
 * @method static bool decrement(string $column, int $value = 1, $where = null)
 * @method static int count()
 * @method static \Spark\Paginator\Paginator paginate(int $limit = 10, string $keyword = 'page', $fields = null)
 * @method static \Spark\Paginator\SimplePaginator simplePaginate(int $perPage = 15, array|string $columns = ['*'], string $pageName = 'page', ?int $page = null)
 * @method static \Spark\Paginator\CursorPaginator cursorPaginate(int $perPage = 15, array|string $columns = ['*'], string $cursorName = 'cursor', \Spark\Paginator\Cursor|string|null $cursor = null)
 * @method static \Spark\Support\Collection filter(?callable $callback = null)
 * @method static \Spark\Support\Collection each(callable $callback)
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
    use InteractsWithOrm, InteractsWithSoftDeletes, InteractsWithTracking, Castable, Conditionable, Macroable {
        __call as macroCall;
        __callStatic as staticMacroCall;
    }

    /** @var ModelContext|null The context for the model's database and table namespace. */
    private ?ModelContext $queryContext = null;

    /** Identity of the stored row, independent of unsaved attribute changes. */
    private mixed $persistenceKey = null;

    /** Indicates whether the model should automatically manage timestamps. */
    protected const USE_TIMESTAMPS = true;

    /** The name of the "created at" column. */
    protected const CREATED_AT = 'created_at';

    /** The name of the "updated at" column. */
    protected const UPDATED_AT = 'updated_at';

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
     * model constructor.
     * Initializes the model instance and decodes any previously saved data if an ID is set.
     */
    public function __construct()
    {
        $this->castStoredData();
    }

    /**
     * Creates a new query instance for the model's table.
     *
     * @return QueryBuilder Returns a query object for database operations.
     */
    public static function query(): QueryBuilder
    {
        $model = new static();
        $model->queryContext = ModelContext::currentQueryContext(static::class);
        return $model->buildModelQuery($model);
    }

    /** Create a fresh query in this model's database and table namespace. */
    public function newQuery(): QueryBuilder
    {
        if ($this->queryContext !== null) {
            return $this->queryContext->queryFor(static::class, static fn() => static::query());
        }
        return static::query();
    }

    private function buildModelQuery(Model $model): QueryBuilder
    {
        $query = app(QueryBuilder::class);
        if ($this->queryContext !== null) {
            $query->setDB($this->queryContext->database())->prefix($this->queryContext->prefix);
        }

        $model->queryContext = $this->queryContext;
        return $query->table($this->getTable())->fetchModel(static::class)->useModel($model);
    }

    /** @internal Propagate the parent's context to a related model. */
    public function newRelatedQuery(Model $model): QueryBuilder
    {
        $model->queryContext = $this->queryContext;
        return $model->newQuery();
    }

    /** @internal */
    public function setQueryContext(ModelContext $context): static
    {
        $this->queryContext = $context;
        return $this;
    }

    /** @internal */
    public function getQueryContext(): ?ModelContext
    {
        return $this->queryContext;
    }

    /** @internal Identity used for instance reads and writes. */
    public function getPersistenceKey(): mixed
    {
        return $this->persistenceKey ?? $this->getOriginal($this->getPrimaryKey());
    }

    /** @internal Record the identity actually read from or written to storage. */
    public function syncPersistenceKey(mixed $key): void
    {
        $this->persistenceKey = $key;
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
        $model = static::query()->where($where)->first();
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
        $model = static::query()->where($attributes)->first();

        if ($model) {
            return $model;
        }

        $attributes = array_merge(
            !is_array($attributes) ? $attributes->toArray() : $attributes,
            !is_array($values) ? $values->toArray() : $values
        );

        return static::create($attributes);
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
        $model = static::query()->where($attributes)->first();

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
            // fill() receives application values, not database ciphertext/custom storage values.
            if ($this->hasCast($key) && !$this->isCustomCast($key) && $this->getCastType($key) !== 'encrypted') {
                $value = $this->castAttribute($key, $value);
            }
            $this->updateAttributeValue($key, $value);
        }

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
        $this->queryContext ??= $this->newQuery()->getModelContext();
        $data = $this->getFillableData();

        // Apply timestamp casting if the model uses HasTimestamp trait and timestamps are defined.
        if (static::USE_TIMESTAMPS) {
            $data = [...$data, ...$this->getCastedTimestampsForStorage()];
        }

        // Cast the data for storage, applying any necessary transformations or encodings.
        $data = $this->castDataForStorage($data);

        // Initialize default status variables.
        $updatedStatus = false;
        $createdId = 0;

        unset($this->tracking['__was_updated'], $this->tracking['__was_created']); // Reset update and create tracking.

        // Update the stored row even if its primary-key attribute was edited.
        $identity = $this->getPersistenceKey();
        if ($identity !== null && $identity !== '') {
            $condition = [$this->getPrimaryKey() => $identity];
            $updatedStatus = (bool) $this->newQuery()->update($data, $condition);
            if (!$updatedStatus && $this->newQuery()->where($condition)->exists()) {
                $this->tracking['__hash'] = $this->makeHash();
                return true;
            }

            // If update fails and no record exists, insert a new record.
            if (!$updatedStatus && $forceCreate) {
                try {
                    if ($this->newQuery()->where($condition)->doesntExist()) {
                        $createdId = $this->newQuery()->insert($data);
                    }
                } catch (\Exception $e) {
                    return false; // Return false on failure.
                }
            }
        } else {
            $createdId = $this->newQuery()->insert($data);
        }

        // Save model id if it is newly created.
        if (!$this->hasPrimaryValue() && is_int($createdId) && $createdId > 0) {
            $this->attributes[$this->getPrimaryKey()] = $createdId;
        }

        if ($updatedStatus) {
            $this->syncPersistenceKey($data[$this->getPrimaryKey()] ?? $identity);
            $this->trackUpdated();
        }
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
        $identity = $this->getPersistenceKey();
        if ($identity === null || $identity === '') {
            return false;
        }
        $deleted = $this->newQuery()->delete([$this->getPrimaryKey() => $identity]);
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
    public function getFillableData(): array
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
    public function castDataForStorage(array $data): array
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
            elseif ($value instanceof \Spark\Carbon) {
                $data[$key] = $value->toDateTimeString();
            }
            // Handle DateTimeInterface Object into string
            elseif ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format('Y-m-d H:i:s');
            }
            // Handle URL Object into string
            elseif ($value instanceof \Spark\Url) {
                $data[$key] = $value->getUrl();
            }
            // Handle array-like values into JSON strings.
            elseif ($value instanceof Arrayable) {
                $data[$key] = json_encode($value->toArray());
            } elseif ($value instanceof Jsonable) {
                $data[$key] = $value->toJson();
            } elseif (is_array($value)) {
                $data[$key] = json_encode($value);
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
    public function castStoredData(): void
    {
        if (empty($this->attributes)) {
            return; // No attributes to process.
        }

        // Go Through all the properties of this model.
        if ($this->hasAnyCast()) {
            foreach ($this->attributes as $key => $value) {
                if ($this->hasCast($key)) {
                    $this->attributes[$key] = $this->castAttribute($key, $value);
                }
            }
        }

        // If the model uses timestamps, decode them into Carbon instances.
        static::USE_TIMESTAMPS && $this->decodeTimestamps();

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
        $value = $this->primaryValue();

        return $value !== null && $value !== '';
    }

    /**
     * Checks if the model exists in the database.
     *
     * @return bool True if the model exists, false otherwise.
     */
    public function exists(): bool
    {
        $identity = $this->getPersistenceKey();
        if ($identity === null || $identity === '') {
            return false;
        }

        return $this->tracking['__exists'] ??= $this->newQuery()
            ->where([$this->getPrimaryKey() => $identity])
            ->exists();
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

        // Convert special objects to their string representations for JSON serialization
        foreach ($attributes as &$attribute) {
            if ($attribute instanceof \Spark\Carbon) {
                $attribute = $attribute->toIsoUtcString();
            } elseif ($attribute instanceof \DateTimeInterface) {
                $attribute = $attribute->format(\DateTimeInterface::ATOM);
            } elseif ($attribute instanceof \Spark\Url) {
                $attribute = $attribute->getUrl();
            } elseif ($attribute instanceof Arrayable) {
                $attribute = $attribute->toArray();
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
        if (array_key_exists($offset, $this->attributes)) {
            return isset($this->attributes[$offset]);
        }

        return $this->relationExists($offset);
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

        if (array_key_exists($offset, $this->attributes)) {
            return $this->attributes[$offset];
        }

        return $this->getRelation($offset);
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
            return $this->offsetGet($name) ?? $default;
        }

        $segments = explode('.', $name);
        $path = implode('.', array_slice($segments, 1));
        $rootKey = $segments[0];

        $rootValue = null;
        if ($this->hasAccessor($rootKey)) {
            $rootValue = $this->getAttributeValue($rootKey, $this->attributes[$rootKey] ?? null);
        } elseif ($this->relationLoaded($rootKey)) {
            $rootValue = $this->getRelation($rootKey);
        }

        if ($rootValue !== null) {
            return data_get($rootValue, $path, $default);
        }

        return data_get($this->attributes, $name, $default);
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
        $model->setAttributes($filtered);

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
        $model->setAttributes($filtered);

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
        return ($this->{$method2}())->get($value);
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
        return ($this->{$method2}())->set($value);
    }

    /**
     * Refresh the model instance with the latest data from the database.
     *
     * @return static The refreshed model instance.
     */
    public function refresh(): static
    {
        $identity = $this->getPersistenceKey();
        if ($identity !== null && $identity !== '') {
            $fresh = $this->newQuery()
                ->where([$this->getPrimaryKey() => $identity])
                ->fetchAssoc()
                ->first();

            if ($fresh) {
                $this->attributes = $fresh; // Update attributes with fresh data.
                $this->clearTracks();
                $this->castStoredData();
                $this->syncPersistenceKey($this->primaryValue());
                $this->reloadRelations();
            }
        }

        return $this;
    }

    public function fresh(): static
    {
        return $this->refresh();
    }

    /**
     * Handles dynamic method calls to the query instance.
     *
     * @param string $name The method name.
     * @param array $arguments The method arguments.
     * @return QueryBuilder<static> The result of the query method call.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (static::hasMacro($name)) {
            return $this->macroCall($name, $arguments);
        }

        return $this->newQuery()->useModel($this)->$name(...$arguments);
    }

    /**
     * Handles static method calls to the query instance.
     *
     * @param string $name The method name.
     * @param array $arguments The method arguments.
     * @return QueryBuilder<static> The result of the query method call.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        if (static::hasMacro($name)) {
            return static::staticMacroCall($name, $arguments);
        }

        return static::query()->$name(...$arguments);
    }

    /**
     * Prepare the timestamp attributes for storage when saving the model.
     *
     * @return array An array containing the casted timestamps for storage.
     */
    public function getCastedTimestampsForStorage(): array
    {
        if (!static::USE_TIMESTAMPS) {
            return [];
        }

        $timestamps = array_filter([static::CREATED_AT, static::UPDATED_AT]);

        $castedTimestamps = [];

        if (in_array(static::CREATED_AT, $timestamps)) {
            $castedTimestamps[static::CREATED_AT] = $this->attributes[static::CREATED_AT] ??= now();
        }

        if (in_array(static::UPDATED_AT, $timestamps)) {
            if ($this->isDirty()) {
                unset($this->attributes[static::UPDATED_AT]);
            }

            $castedTimestamps[static::UPDATED_AT] = $this->attributes[static::UPDATED_AT] ??= now();
        }

        return $castedTimestamps;
    }

    /**
     * Decode timestamp attributes from storage format to Carbon instances.
     *
     * This method checks for the presence of timestamp attributes (created_at and updated_at)
     * and converts their values from string format to Carbon instances for easier manipulation.
     *
     * @return void
     */
    public function decodeTimestamps(): void
    {
        $timestamps = array_filter([static::CREATED_AT, static::UPDATED_AT]);

        foreach ($timestamps as $timestamp) {
            if (isset($this->attributes[$timestamp])) {
                $this->attributes[$timestamp] = carbon($this->attributes[$timestamp]);
            }
        }
    }

    /**
     * Get the names of the timestamp attributes used by the model.
     *
     * @return array An associative array with keys 'created_at' and 'updated_at' if they are used.
     */
    public function usesTimestamps(): array
    {
        if (!static::USE_TIMESTAMPS) {
            return [];
        }

        $timestamps = [];

        if (static::CREATED_AT) {
            $timestamps[static::CREATED_AT] = now();
        }

        if (static::UPDATED_AT) {
            $timestamps[static::UPDATED_AT] = now();
        }

        return $timestamps;
    }

    /**
     * Set the model's attributes.
     *
     * @param array $attributes The attributes to set on the model.
     * @return void
     */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    /**
     * Get the model's attributes.
     *
     * @return array The model's attributes.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Check if the model has a specific attribute.
     *
     * @param string $name The name of the attribute to check.
     * @return bool True if the attribute exists, false otherwise.
     */
    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /**
     * Get the value of a specific attribute.
     *
     * @param string $name The name of the attribute to get.
     * @param mixed $default The default value to return if the attribute is not set.
     * @return mixed The value of the attribute or the default value if not set.
     */
    public function getAttribute(string $name, $default = null): mixed
    {
        return $this->attributes[$name] ?? value($default);
    }

    /**
     * Check if this model is the same as another model.
     *
     * @param Model $model The model to compare with.
     * @return bool True if the models are the same, false otherwise.
     */
    public function is(Model $model): bool
    {
        return $this->getTable() === $model->getTable() &&
            $this->getPrimaryKey() === $model->getPrimaryKey() &&
            $this->primaryValue() === $model->primaryValue();
    }
}
