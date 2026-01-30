<?php

namespace Spark\Database\Traits;

use Closure;
use Spark\Database\Exceptions\InvalidOrmException;
use Spark\Database\Exceptions\OrmDisabledLazyLoadingException;
use Spark\Database\Exceptions\UndefinedOrmException;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\BelongsTo;
use Spark\Database\Relation\BelongsToMany;
use Spark\Database\Relation\HasMany;
use Spark\Database\Relation\HasManyThrough;
use Spark\Database\Relation\HasOne;
use Spark\Database\Relation\HasOneThrough;
use Spark\Database\Relation\Relation;
use Spark\Support\Str;
use function array_key_exists;
use function func_get_args;
use function get_class;
use function is_array;
use function is_string;

/**
 * Laravel-style Mini ORM (Object Relational Mapping)
 * 
 * Provides Laravel Eloquent-like functionality for handling object-relational mapping (ORM).
 * Supports hasOne, hasMany, belongsTo, belongsToMany relationships with lazy and eager loading.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 * @package Spark\Database\Relation
 */
trait HasRelation
{
    /**
     * @var array $relations
     * Holds the loaded relationship data for the current instance.
     */
    private array $relations = [];

    /**
     * Get all loaded relations for the model.
     * 
     * @return array
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * Set a given relationship on the model.
     * 
     * @param string $relation
     * @param mixed $value
     * @return $this
     */
    public function setRelation(string $relation, $value): self
    {
        $this->relations[$relation] = $value;
        return $this;
    }

    /**
     * Unset a loaded relationship.
     * 
     * @param string|array $relations
     * @return $this
     */
    public function unsetRelation(string|array $relations): self
    {
        $relations = is_array($relations) ? $relations : func_get_args();
        foreach ($relations as $relation) {
            unset($this->relations[$relation]);
        }

        return $this;
    }

    /**
     * Determine if a relation is loaded.
     * 
     * @param string $key
     * @return bool
     */
    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    /**
     * Clear all loaded relationships.
     *
     * @return $this
     */
    public function clearRelations(): void
    {
        $this->relations = [];
    }

    /**
     * Reload all currently loaded relationships.
     * 
     * This method clears all currently loaded relationships and re-attaches them.
     * It is useful when you want to refresh the relationship data after changes to the model.
     * 
     * @return void
     */
    public function reloadRelations(): void
    {
        $relations = array_keys($this->relations);
        $this->clearRelations();
        $this->load($relations);
    }

    /**
     * Define a one-to-one relationship.
     * 
     * This method allows you to define a one-to-one relationship between 
     * the current model and another model.
     * 
     * @param string $related The related model class name
     * @param string|null $foreignKey The foreign key in the related table
     * @param string|null $localKey The local key in the current table
     * @param bool $lazy Whether to enable lazy loading
     * @param Closure|null $callback Custom query callback
     * 
     * @return \Spark\Database\Relation\HasOne
     */
    protected function hasOne(
        string $related,
        string|null $foreignKey = null,
        string|null $localKey = null,
        bool $lazy = true,
        null|Closure $callback = null
    ): HasOne {
        $foreignKey ??= $this->getForeignKey();
        $localKey ??= static::$primaryKey ?? 'id';

        return new HasOne(
            related: $related,
            foreignKey: $foreignKey,
            localKey: $localKey,
            lazy: $lazy,
            callback: $callback,
            model: $this
        );
    }

    /**
     * Define a one-to-many relationship.
     * 
     * This method allows you to define a one-to-many relationship between
     * the current model and another model.
     * 
     * @param string $related The related model class name
     * @param string|null $foreignKey The foreign key in the related table
     * @param string|null $localKey The local key in the current table
     * @param bool $lazy Whether to enable lazy loading
     * @param Closure|null $callback Custom query callback
     * 
     * @return \Spark\Database\Relation\HasMany
     */
    protected function hasMany(
        string $related,
        string|null $foreignKey = null,
        string|null $localKey = null,
        bool $lazy = true,
        null|Closure $callback = null
    ): HasMany {
        $foreignKey ??= $this->getForeignKey();
        $localKey ??= static::$primaryKey ?? 'id';

        return new HasMany(
            related: $related,
            foreignKey: $foreignKey,
            localKey: $localKey,
            lazy: $lazy,
            callback: $callback,
            model: $this
        );
    }

    /**
     * Define an inverse one-to-one or one-to-many relationship.
     * 
     * This method allows you to define a relationship where the current model
     * belongs to another model, typically used for foreign key relationships.
     * 
     * @param string $related The related model class name
     * @param string|null $foreignKey The foreign key in the current table
     * @param string|null $ownerKey The primary key in the related table
     * @param bool $lazy Whether to enable lazy loading
     * @param Closure|null $callback Custom query callback
     * 
     * @return \Spark\Database\Relation\BelongsTo
     */
    protected function belongsTo(
        string $related,
        string|null $foreignKey = null,
        string|null $ownerKey = null,
        bool $lazy = true,
        null|Closure $callback = null
    ): BelongsTo {
        $relatedModel = new $related;
        $relatedTable = $relatedModel::$table ?? Str::snake(class_basename($related));
        $foreignKey ??= $this->generateForeignKey($relatedTable);
        $ownerKey ??= $relatedModel::$primaryKey ?? 'id';

        return new BelongsTo(
            related: $related,
            foreignKey: $foreignKey,
            ownerKey: $ownerKey,
            lazy: $lazy,
            callback: $callback,
            model: $this
        );
    }

    /**
     * Define a many-to-many relationship.
     * 
     * This method allows you to define a many-to-many relationship between the current model
     * and another model using a pivot table.
     * 
     * @param string $related The related model class name
     * @param string|null $table The intermediate table name
     * @param string|null $foreignPivotKey The foreign key in the pivot table for this model
     * @param string|null $relatedPivotKey The foreign key in the pivot table for the related model
     * @param string|null $parentKey The primary key in the current table
     * @param string|null $relatedKey The primary key in the related table
     * @param bool $lazy Whether to enable lazy loading
     * @param array $append The additional fields to append to the relationship
     * @param Closure|null $callback Custom query callback
     * 
     * @return \Spark\Database\Relation\BelongsToMany
     */
    protected function belongsToMany(
        string $related,
        string|null $table = null,
        string|null $foreignPivotKey = null,
        string|null $relatedPivotKey = null,
        string|null $parentKey = null,
        string|null $relatedKey = null,
        bool $lazy = true,
        array $append = [],
        null|Closure $callback = null,
    ): BelongsToMany {
        $relatedModel = new $related;
        $relatedTable = $relatedModel::$table ?? Str::snake(class_basename($related));
        $table ??= $this->generatePivotTableName($relatedModel);
        $foreignPivotKey ??= $this->getForeignKey();
        $relatedPivotKey ??= $this->generateForeignKey($relatedTable);
        $parentKey ??= static::$primaryKey ?? 'id';
        $relatedKey ??= $relatedModel::$primaryKey ?? 'id';

        return new BelongsToMany(
            related: $related,
            table: $table,
            foreignPivotKey: $foreignPivotKey,
            relatedPivotKey: $relatedPivotKey,
            parentKey: $parentKey,
            relatedKey: $relatedKey,
            lazy: $lazy,
            callback: $callback,
            append: $append,
            model: $this
        );
    }

    /**
     * Define a has-many-through relationship.
     * 
     * This method allows you to define a has-many-through relationship,
     * which is a relationship where you can access a related model through an intermediate model.
     * 
     * @param string $related The final related model
     * @param string $through The intermediate model
     * @param string|null $firstKey Foreign key on the intermediate table
     * @param string|null $secondKey Foreign key on the final table
     * @param string|null $localKey Local key on this model
     * @param string|null $secondLocalKey Local key on the intermediate model
     * @param bool $lazy Whether to enable lazy loading
     * @param array $append Additional fields to append to the relationship
     * @param Closure|null $callback Custom query callback
     * 
     * @return \Spark\Database\Relation\HasManyThrough
     */
    protected function hasManyThrough(
        string $related,
        string $through,
        string|null $firstKey = null,
        string|null $secondKey = null,
        string|null $localKey = null,
        string|null $secondLocalKey = null,
        bool $lazy = true,
        array $append = [],
        null|Closure $callback = null
    ): HasManyThrough {
        $throughModel = new $through;
        $throughTable = $throughModel::$table ?? Str::snake(class_basename($through));

        $firstKey ??= $this->getForeignKey();
        $secondKey ??= $this->generateForeignKey($throughTable);
        $localKey ??= static::$primaryKey ?? 'id';
        $secondLocalKey ??= $throughModel::$primaryKey ?? 'id';

        return new HasManyThrough(
            related: $related,
            through: $through,
            firstKey: $firstKey,
            secondKey: $secondKey,
            localKey: $localKey,
            secondLocalKey: $secondLocalKey,
            lazy: $lazy,
            callback: $callback,
            append: $append,
            model: $this
        );
    }

    /**
     * Define a has-one-through relationship.
     * 
     * This method allows you to define a has-one-through relationship,
     * which is a relationship where you can access a related model through an intermediate model.
     * 
     * @param string $related The final related model
     * @param string $through The intermediate model
     * @param string|null $firstKey Foreign key on the intermediate table
     * @param string|null $secondKey Foreign key on the final table
     * @param string|null $localKey Local key on this model
     * @param string|null $secondLocalKey Local key on the intermediate model
     * @param bool $lazy Whether to enable lazy loading
     * @param array $append Additional fields to append to the relationship
     * @param Closure|null $callback Custom query callback
     * 
     * @return \Spark\Database\Relation\HasOneThrough
     */
    protected function hasOneThrough(
        string $related,
        string $through,
        string|null $firstKey = null,
        string|null $secondKey = null,
        string|null $localKey = null,
        string|null $secondLocalKey = null,
        bool $lazy = true,
        array $append = [],
        null|Closure $callback = null
    ): HasOneThrough {
        $throughModel = new $through;
        $throughTable = $throughModel::$table ?? Str::snake(class_basename($through));

        $firstKey ??= $this->getForeignKey();
        $secondKey ??= $this->generateForeignKey($throughTable);
        $localKey ??= static::$primaryKey ?? 'id';
        $secondLocalKey ??= $throughModel::$primaryKey ?? 'id';

        return new HasOneThrough(
            related: $related,
            through: $through,
            firstKey: $firstKey,
            secondKey: $secondKey,
            localKey: $localKey,
            secondLocalKey: $secondLocalKey,
            lazy: $lazy,
            callback: $callback,
            append: $append,
            model: $this
        );
    }

    /**
     * Load relationships for a collection of models.
     * 
     * This method allows you to load specified relationships for a collection of models.
     * It accepts a string or an array of relationship names, and modifies the models in place.
     * 
     * @param array|string $relations
     * @param array $models
     * @return void
     */
    public static function loadRelations(array|string $relations, array &$models = []): void
    {
        if (empty($models)) {
            return;
        }

        $model = new static;
        $relations = is_string($relations) ? [$relations] : $relations;

        foreach ($relations as $name => $constraints) {
            if (is_numeric($name)) {
                $name = $constraints;
                $constraints = null;
            }

            $relationConfig = $model->getRelationshipConfig($name);
            if ($relationConfig) {
                $model->loadRelation($models, $relationConfig, $name, $constraints);
            }
        }
    }

    /**
     * Get relationship data (supports lazy loading).
     * 
     * This method retrieves the relationship data for a given relationship name.
     * It checks if the relationship is already loaded,
     * and if not, it attempts to lazy load the relationship configuration.
     * 
     * When called as a property ($model->relation), it executes the query and returns results.
     * When called as a method ($model->relation()), the Relation instance is returned for chaining.
     * 
     * @param string $name
     * @return mixed
     */
    public function getRelationshipAttribute(string $name)
    {
        // Return cached relation if already loaded
        if ($this->relationLoaded($name)) {
            return $this->relations[$name];
        }

        // Check if a relationship method exists
        if (method_exists($this, $name)) {
            $relation = $this->$name();

            // If it's a Relation instance, execute and cache the results
            if ($relation instanceof Relation) {
                $results = $this->executeRelation($relation, $name);
                $this->setRelation($name, $results);
                return $results;
            }

            return $relation;
        }

        // Fallback to old eager loading logic for compatibility
        try {
            $relationConfig = $this->getRelationshipConfig($name);
        } catch (UndefinedOrmException $e) {
            // If the relationship is not defined, return null
            return null;
        }

        if ($relationConfig) {
            if (isset($relationConfig['lazy']) && !$relationConfig['lazy']) {
                throw new OrmDisabledLazyLoadingException(
                    "Lazy loading is disabled for relationship '{$name}' in " . static::class
                );
            }

            $this->loadRelation([$this], $relationConfig, $name);
            return $this->relations[$name] ?? null;
        }

        return null;
    }

    /**
     * Execute a Relation instance and return appropriate results.
     * 
     * @param Relation $relation
     * @param string $name
     * @return mixed
     */
    protected function executeRelation(Relation $relation, string $name)
    {
        // For hasOne and belongsTo, return single model or null
        if (
            $relation instanceof HasOne ||
            $relation instanceof BelongsTo ||
            $relation instanceof HasOneThrough
        ) {
            return $relation->first();
        }

        // For hasMany and belongsToMany, return Collection
        return $relation->get();
    }

    /**
     * Load relationships for the model.
     * 
     * This method allows you to eager loading one or more relationships for the model.
     * It accepts a string or an array of relationship names and loads them.
     * 
     * @param array|string $relations
     * @return void
     */
    public function load(array|string $relations): void
    {
        $relations = is_array($relations) ? $relations : func_get_args();
        foreach ($relations as $relation) {
            $this->getRelationshipAttribute($relation);
        }
    }

    /**
     * Check if a relationship exists and has data.
     * 
     * @param string $name
     * @return bool
     */
    public function relationshipExists(string $name): bool
    {
        $relation = $this->getRelationshipAttribute($name);
        return !blank($relation);
    }

    /**
     * Remove a relationship from memory.
     * 
     * @param array|string $relations
     * @return void
     */
    public function forgetRelation(array|string $relations): void
    {
        $relations = is_array($relations) ? $relations : func_get_args();
        foreach ($relations as $relation) {
            unset($this->relations[$relation]);
        }
    }

    /**
     * Get relationship configuration.
     * 
     * @param string $name
     * @return array
     */
    public function getRelationshipConfig(string $name): array
    {
        if (method_exists($this, $name)) {
            $relation = $this->$name();

            if ($relation && $relation instanceof Relation) {
                return [
                    ...$relation->getConfig(),
                    'type' => match (true) {
                        $relation instanceof HasOne => 'hasOne',
                        $relation instanceof HasMany => 'hasMany',
                        $relation instanceof BelongsTo => 'belongsTo',
                        $relation instanceof BelongsToMany => 'belongsToMany',
                        $relation instanceof HasManyThrough => 'hasManyThrough',
                        $relation instanceof HasOneThrough => 'hasOneThrough',
                        default => throw new InvalidOrmException("Invalid relationship instance: " . get_class($relation))
                    }
                ];
            }
        }

        throw new UndefinedOrmException("Undefined relationship: {$name} in " . static::class);
    }

    /**
     * Load a relationship for given models.
     * 
     * @param array $models
     * @param array $config
     * @param string $name
     * @param Closure|null $constraints
     * @param null|array $nested Additional nested relationships to load
     * @param null|array|string $columns Specific columns to select
     * @return array
     */
    public function loadRelation(array $models, array $config, string $name, null|Closure $constraints = null, null|array $nested = null, null|array|string $columns = null): array
    {
        // Store nested relationships in config for later use
        if ($nested && !empty($nested)) {
            $config['nested'] = $nested;
        }

        // Store specific columns in config for later use
        if ($columns && !empty($columns)) {
            $config['columns'] = $columns;
        }

        return match ($config['type']) {
            'hasOne' => $this->loadHasOne($models, $config, $name, $constraints),
            'hasMany' => $this->loadHasMany($models, $config, $name, $constraints),
            'belongsTo' => $this->loadBelongsTo($models, $config, $name, $constraints),
            'belongsToMany' => $this->loadBelongsToMany($models, $config, $name, $constraints),
            'hasManyThrough', 'hasOneThrough' => $this->loadHasThrough($models, $config, $name, $constraints),
            default => throw new InvalidOrmException("Invalid relationship type: {$config['type']}")
        };
    }

    /**
     * Load polymorphic relationships for a collection of models.
     * 
     * This method handles polymorphic relationship loading where the related model
     * type varies based on a morph type column.
     * 
     * @param array $models The models to load relationships for
     * @param string $relation The polymorphic relationship name
     * @param array $morphMap Array mapping morph types to their relationships
     * @param string|null $typeColumn The morph type column name
     * @param string|null $idColumn The morph id column name
     * @return array
     */
    public function loadMorphRelation(array $models, string $relation, array $morphMap, string|null $typeColumn = null, string|null $idColumn = null): array
    {
        if (empty($models)) {
            return $models;
        }

        // Set default column names if not provided
        $typeColumn ??= $relation . '_type';
        $idColumn ??= $relation . '_id';

        // Group models by their morph type
        $modelsByType = [];
        foreach ($models as $index => $model) {
            if (!$model instanceof \Spark\Database\Model) {
                continue;
            }

            $type = $model->get($typeColumn);
            $id = $model->get($idColumn);

            if ($type && $id) {
                if (!isset($modelsByType[$type])) {
                    $modelsByType[$type] = [];
                }
                $modelsByType[$type][] = ['index' => $index, 'model' => $model, 'id' => $id];
            }
        }

        // Load relationships for each morph type
        foreach ($modelsByType as $type => $items) {
            if (!isset($morphMap[$type])) {
                continue;
            }

            // Resolve the morph model class
            $morphClass = $this->resolveMorphClass($type);
            if (!$morphClass) {
                continue;
            }

            // Get nested relations for this morph type
            $relations = $morphMap[$type];
            $relations = is_string($relations) ? [$relations] : $relations;

            // Extract unique IDs
            $ids = array_unique(array_column($items, 'id'));

            // Load the polymorphic models
            $morphModel = new $morphClass;
            $primaryKey = $morphModel::$primaryKey ?? 'id';

            $query = $morphModel->query()->whereIn($primaryKey, $ids);

            // Eager load nested relationships
            if (!empty($relations)) {
                $query->with(...$relations);
            }

            $relatedModels = $query->all();

            // Index by primary key for quick lookup
            $relatedByKey = [];
            foreach ($relatedModels as $related) {
                $relatedByKey[$related->primaryValue()] = $related;
            }

            // Attach to original models
            foreach ($items as $item) {
                if (isset($relatedByKey[$item['id']])) {
                    $models[$item['index']]->setRelation($relation, $relatedByKey[$item['id']]);
                }
            }
        }

        return $models;
    }

    /**
     * Load hasOne relationship.
     * 
     * This method loads a hasOne relationship for the given models.
     * It retrieves the related models based on the foreign key and local key,
     * and matches them with the original models.
     * 
     * @param array $models
     * @param array $config
     * @param string $name
     * @param Closure|null $constraints
     * @return array
     */
    private function loadHasOne(array $models, array $config, string $name, null|Closure $constraints = null): array
    {
        $relatedModel = new $config['related'];
        $localValues = collect($models)->pluck($config['localKey'])->unique()->filter()->all();

        if (empty($localValues)) {
            return $this->initializeRelation($models, $name, null);
        }

        $query = $relatedModel->query()
            ->select($config['columns'] ?? '*')
            ->whereIn($config['foreignKey'], $localValues);

        $this->applyConstraints($query, $config, $constraints);

        // Apply nested relationships if any
        if (!empty($config['nested'])) {
            $query->with(...$config['nested']);
        }

        $results = $query->all();

        return $this->matchModels($models, $results, $config, $name, 'one');
    }

    /**
     * Load hasMany relationship.
     * 
     * This method loads a hasMany relationship for the given models.
     * It retrieves the related models based on the foreign key and local key,
     * and matches them with the original models.
     * 
     * @param array $models
     * @param array $config
     * @param string $name
     * @param Closure|null $constraints
     * @return array
     */
    private function loadHasMany(array $models, array $config, string $name, null|Closure $constraints = null): array
    {
        $relatedModel = new $config['related'];
        $localValues = collect($models)->pluck($config['localKey'])->unique()->filter()->all();

        if (empty($localValues)) {
            return $this->initializeRelation($models, $name, []);
        }

        $query = $relatedModel->query()
            ->select($config['columns'] ?? '*')
            ->whereIn($config['foreignKey'], $localValues);

        $this->applyConstraints($query, $config, $constraints);

        // Apply nested relationships if any
        if (!empty($config['nested'])) {
            $query->with(...$config['nested']);
        }

        $results = $query->all();

        return $this->matchModels($models, $results, $config, $name, 'many');
    }

    /**
     * Load belongsTo relationship.
     * 
     * This method loads a belongsTo relationship for the given models.
     * It retrieves the related models based on the foreign key and owner key,
     * and matches them with the original models.
     * 
     * @param array $models
     * @param array $config
     * @param string $name
     * @param Closure|null $constraints
     * @return array
     */
    private function loadBelongsTo(array $models, array $config, string $name, null|Closure $constraints = null): array
    {
        $relatedModel = new $config['related'];
        $foreignValues = collect($models)->pluck($config['foreignKey'])->unique()->filter()->all();

        if (empty($foreignValues)) {
            return $this->initializeRelation($models, $name, null);
        }

        $query = $relatedModel->query()
            ->select($config['columns'] ?? '*')
            ->whereIn($config['ownerKey'], $foreignValues);

        $this->applyConstraints($query, $config, $constraints);

        // Apply nested relationships if any
        if (!empty($config['nested'])) {
            $query->with(...$config['nested']);
        }

        $results = $query->all();

        return $this->matchBelongsTo($models, $results, $config, $name);
    }

    /**
     * Load belongsToMany relationship.
     * 
     * This method loads a belongsToMany relationship for the given models.
     * It retrieves the related models based on the pivot table,
     * and matches them with the original models.
     * 
     * @param array $models
     * @param array $config
     * @param string $name
     * @param Closure|null $constraints
     * @return array
     */
    private function loadBelongsToMany(array $models, array $config, string $name, null|Closure $constraints = null): array
    {
        $relatedModel = new $config['related'];
        $relatedTable = $relatedModel::$table ?? Str::snake(class_basename($config['related']));
        $parentValues = collect($models)->pluck($config['parentKey'])->unique()->filter()->all();

        if (empty($parentValues)) {
            return $this->initializeRelation($models, $name, []);
        }

        $appendField = join(
            ', ',
            array_map(fn($field) => "p.{$field}", $config['append'] ?? [])
        );
        $appendField = !empty($appendField) ? ", {$appendField}" : '';

        $query = $relatedModel->query()
            ->select("r.*, p.{$config['foreignPivotKey']}, p.{$config['relatedPivotKey']}{$appendField}")
            ->from($relatedTable, 'r')
            ->join($config['table'] . ' as p', "p.{$config['relatedPivotKey']} = r.{$config['relatedKey']}")
            ->whereIn("p.{$config['foreignPivotKey']}", $parentValues);

        $this->applyConstraints($query, $config, $constraints);

        // Apply nested relationships if any
        if (!empty($config['nested'])) {
            $query->with(...$config['nested']);
        }

        $results = $query->all();

        return $this->matchBelongsToMany($models, $results, $config, $name);
    }

    /**
     * Load hasThrough relationship
     * 
     * This method loads both hasOneThrough and hasManyThrough relationships for the given models.
     * It retrieves the related models through an intermediate model,
     * and matches them with the original models.
     * 
     * @param array $models
     * @param array $config
     * @param string $name
     * @param Closure|null $constraints
     * @return array
     */
    private function loadHasThrough(array $models, array $config, string $name, null|Closure $constraints = null): array
    {
        $relatedModel = new $config['related'];
        $throughModel = new $config['through'];
        $relatedTable = $relatedModel::$table ?? Str::snake(class_basename($config['related']));
        $throughTable = $throughModel::$table ?? Str::snake(class_basename($config['through']));
        $localValues = collect($models)->pluck($config['localKey'])->unique()->filter()->all();

        $isOne = $config['type'] === 'hasOneThrough';

        if (empty($localValues)) {
            return $this->initializeRelation($models, $name, $isOne ? null : []);
        }

        $appendField = join(
            ', ',
            array_map(fn($field) => "t.{$field}", $config['append'] ?? [])
        );
        $appendField = !empty($appendField) ? ", {$appendField}" : '';

        $query = $relatedModel->query()
            ->select('r.*', "t.{$config['firstKey']}{$appendField}")
            ->from($relatedTable, 'r')
            ->join("$throughTable as t", "t.{$config['secondLocalKey']} = r.{$config['secondKey']}")
            ->whereIn("t.{$config['firstKey']}", $localValues);

        $this->applyConstraints($query, $config, $constraints);

        // Apply nested relationships if any
        if (!empty($config['nested'])) {
            $query->with(...$config['nested']);
        }

        if ($isOne) {
            $query->take(1);
        }

        $results = $query->all();

        return $this->matchHasThrough($models, $results, $config, $name, $isOne);
    }

    /**
     * Match models with their relationships.
     * 
     * This method matches the results of a relationship query with the original models.
     * It iterates through the models and results,
     * and sets the related models as a relation on each original model.
     * 
     * @param array $models
     * @param array $results
     * @param array $config
     * @param string $name
     * @param string $type
     * @return array
     */
    private function matchModels(array $models, array $results, array $config, string $name, string $type): array
    {
        foreach ($models as $model) {
            $related = $type === 'one' ? null : [];

            foreach ($results as $result) {
                if ($result->{$config['foreignKey']} == $model->{$config['localKey']}) {
                    if ($type === 'one') {
                        $related = $result;
                        break;
                    } else {
                        $related[] = $result;
                    }
                }
            }

            if ($type === 'many') {
                $related = collect($related);
            }

            $model->setRelation($name, $related);
        }

        return $models;
    }

    /**
     * Match belongsTo relationships.
     * 
     * This method matches the results of a belongsTo relationship query
     * with the original models.
     * 
     * @param array $models
     * @param array $results
     * @param array $config
     * @param string $name
     * @return array
     */
    private function matchBelongsTo(array $models, array $results, array $config, string $name): array
    {
        foreach ($models as $model) {
            $related = null;

            foreach ($results as $result) {
                if ($result->{$config['ownerKey']} == $model->{$config['foreignKey']}) {
                    $related = $result;
                    break;
                }
            }

            $model->setRelation($name, $related);
        }

        return $models;
    }

    /**
     * Match belongsToMany relationships.
     * 
     * This method matches the results of a belongsToMany relationship query
     * with the original models.
     * 
     * @param array $models
     * @param array $results
     * @param array $config
     * @param string $name
     * @return array
     */
    private function matchBelongsToMany(array $models, array $results, array $config, string $name): array
    {
        foreach ($models as $model) {
            $related = [];

            foreach ($results as $result) {
                if ($result->{$config['foreignPivotKey']} == $model->{$config['parentKey']}) {
                    $related[] = $result;
                }
            }

            $model->setRelation($name, collect($related));
        }

        return $models;
    }

    /**
     * Match hasThrough relationships.
     * 
     * This method matches the results of both hasOneThrough or hasManyThrough
     * relationship query with the original models.
     * 
     * @param array $models
     * @param array $results
     * @param array $config
     * @param string $name
     * @param bool $isOne
     * @return array
     */
    private function matchHasThrough(array $models, array $results, array $config, string $name, bool $isOne): array
    {
        foreach ($models as $model) {
            $related = $isOne ? null : [];

            foreach ($results as $result) {
                if ($result->{$config['firstKey']} == $model->{$config['localKey']}) {
                    if ($isOne) {
                        $related = $result;
                        break; // Only get the first match for hasOne
                    } else {
                        $related[] = $result;
                    }
                }
            }

            if (!$isOne) {
                $related = collect($related);
            }

            $model->setRelation($name, $related);
        }

        return $models;
    }

    /**
     * Initialize relationship with default value.
     * 
     * This method initializes a relationship for the given models
     * with a default value.
     * 
     * @param array $models
     * @param string $name
     * @param mixed $defaultValue
     * @return array
     */
    private function initializeRelation(array $models, string $name, $defaultValue): array
    {
        foreach ($models as $model) {
            $model->setRelation($name, $defaultValue);
        }
        return $models;
    }

    /**
     * Apply callback constraints to query.
     * 
     * This method applies any additional constraints to the query builder
     * based on the relationship configuration and optional callback.
     * 
     * @param QueryBuilder $query
     * @param array $config
     * @param Closure|null $constraints
     * @return void
     * 
     * @throws InvalidOrmException
     * @throws UndefinedOrmException
     */
    private function applyConstraints(QueryBuilder &$query, array $config, null|Closure $constraints = null): void
    {
        if (isset($config['callback']) && is_callable($config['callback'])) {
            $config['callback']($query);
        }

        if ($constraints && is_callable($constraints)) {
            $constraints($query);
        }
    }

    /**
     * Get the foreign key for this model.
     * 
     * @return string
     */
    private function getForeignKey(): string
    {
        return $this->generateForeignKey($this->getTable());
    }

    /**
     * Get the table name for this model.
     * 
     * @return string
     */
    private function getTable(): string
    {
        return static::$table ?? Str::snake(Str::plural(class_basename(static::class)));
    }

    /**
     * Generate a foreign key name from a table name.
     * 
     * This method generates a foreign key name based on the table name.
     * It assumes the table name follows a convention where the foreign key is 
     * the singular form of the table name followed by "_id".
     * 
     * @param string $table
     */
    private function generateForeignKey(string $table): string
    {
        return Str::lower(Str::singular(Str::beforeLast($table, '_id'))) . '_id';
    }

    /**
     * Generate pivot table name for many-to-many relationships.
     * 
     * This method generates a pivot table name based on the related model's table name
     * and the current model's table name.
     * 
     * @param string $relatedModel
     * @return string
     */
    private function generatePivotTableName($relatedModel): string
    {
        $relatedTable = $relatedModel::$table ?? Str::snake(Str::plural(class_basename($relatedModel)));
        $currentTable = $this->getTable();

        $tables = [Str::lower($currentTable), Str::lower($relatedTable)];
        sort($tables);
        return implode('_', $tables);
    }

    /**
     * Resolve the morph class from a morph type string.
     * 
     * @param string $type The morph type
     * @return string|null The resolved class name
     */
    private function resolveMorphClass(string $type): string|null
    {
        // If already a valid class name, return it
        if (class_exists($type)) {
            return $type;
        }

        // Convert snake_case or kebab-case to StudlyCase
        $studlyType = Str::studly(str_replace('-', '_', $type));

        // Try common namespace patterns
        $className = "App\\Models\\$studlyType";
        if (class_exists($className)) {
            return $className;
        }

        return null;
    }
}
