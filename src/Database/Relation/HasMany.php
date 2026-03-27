<?php

namespace Spark\Database\Relation;

use Closure;
use Spark\Database\Model;
use Spark\Database\QueryBuilder;

/**
 * Class HasMany
 * 
 * Represents a "has many" relationship in a database model.
 * 
 * This class is used to define a one-to-many relationship
 * between two models, where a model can have multiple instances
 * of another model associated with it.
 * 
 * @package Spark\Database\Relation
 */
class HasMany extends Relation
{
    /**
     * Create a new HasMany relationship instance.
     * 
     * @param string $related The related model class name.
     * @param string|null $foreignKey The foreign key in the related model that references the current model.
     * @param string|null $localKey The primary key in the current model that the foreign key references.
     * @param bool $lazy Whether to load the relationship lazily.
     * @param Closure|null $callback An optional callback to modify the query for the relationship.
     * @param Model|null $model The model instance that this relationship belongs to.
     */
    public function __construct(
        protected string $related,
        protected null|string $foreignKey = null,
        protected null|string $localKey = null,
        protected bool $lazy = true,
        protected null|Closure $callback = null,
        null|Model $model = null
    ) {
        parent::__construct($model);
    }

    /**
     * Build the query for this relationship.
     * 
     * @return QueryBuilder
     */
    protected function buildQuery(): QueryBuilder
    {
        /** @var Model $relatedInstance */
        $relatedInstance = new ($this->related)();
        $query = $relatedInstance::query();

        // Add relationship constraint
        if ($this->model) {
            $localValue = $this->model->{$this->localKey};
            $query->where($this->foreignKey, '=', $localValue);
        }

        // Apply custom callback if provided
        if ($this->callback) {
            ($this->callback)($query);
        }

        return $query;
    }

    /**
     * Get the configuration for the HasMany relationship.
     * 
     * @return array{
     *     related: string,
     *     foreignKey: string|null,
     *     localKey: string|null,
     *     lazy: bool,
     *     callback: Closure|null
     * }
     */
    public function getConfig(): array
    {
        return [
            'related' => $this->related,
            'foreignKey' => $this->foreignKey,
            'localKey' => $this->localKey,
            'lazy' => $this->lazy,
            'callback' => $this->callback,
        ];
    }

    /**
     * Create a new instance of the related model.
     * 
     * @param array $attributes The attributes for the new model.
     * @return Model The newly created model instance.
     */
    public function create(array $attributes = []): Model
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot create related model without a parent model instance.');
        }

        // Get the foreign key value from the parent model
        $foreignKeyValue = $parent->{$this->localKey};

        if (empty($foreignKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->localKey} must be set before creating related models.");
        }

        // Merge the foreign key into the attributes
        $attributes[$this->foreignKey] = $foreignKeyValue;

        // Create the related model with the foreign key set
        $relatedClass = $this->related;
        $relatedModel = $relatedClass::create($attributes);

        return $relatedModel;
    }

    /**
     * Create multiple instances of the related model.
     * 
     * @param array $records Array of attribute arrays for each model.
     * @return array Array of newly created model instances.
     */
    public function createMany(array $records): array
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot create related models without a parent model instance.');
        }

        // Get the foreign key value from the parent model
        $foreignKeyValue = $parent->{$this->localKey};

        if (empty($foreignKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->localKey} must be set before creating related models.");
        }

        $models = [];
        $relatedClass = $this->related;

        foreach ($records as $attributes) {
            $models[] = $relatedClass::create([...$attributes, $this->foreignKey => $foreignKeyValue]);
        }

        return $models;
    }

    /**
     * Create a new instance of the related model or update if it exists.
     * 
     * @param array $attributes The attributes for the model.
     * @param array $values Additional values to set on the model.
     * @return Model The created or updated model instance.
     */
    public function createOrUpdate(array $attributes = [], array $values = []): Model
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot create related model without a parent model instance.');
        }

        // Get the foreign key value from the parent model
        $foreignKeyValue = $parent->{$this->localKey};

        if (empty($foreignKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->localKey} must be set before creating related models.");
        }

        // Merge the foreign key into the attributes
        $attributes[$this->foreignKey] = $foreignKeyValue;

        $relatedClass = $this->related;
        return $relatedClass::createOrUpdate([...$attributes, ...$values], array_keys($attributes));
    }

    /**
     * Get or create a new instance of the related model.
     * 
     * @param array $attributes The attributes to search for.
     * @return Model The found or newly created model instance.
     */
    public function firstOrCreate(array $attributes = [], array $values = []): Model
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot create related model without a parent model instance.');
        }

        // Get the foreign key value from the parent model
        $foreignKeyValue = $parent->{$this->localKey};

        if (empty($foreignKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->localKey} must be set before creating related models.");
        }

        // Merge the foreign key into the attributes
        $attributes[$this->foreignKey] = $foreignKeyValue;

        $relatedClass = $this->related;
        return $relatedClass::firstOrCreate($attributes, $values);
    }

    /**
     * Delete related model(s) based on specific conditions.
     * 
     * @param null|int|string|array $conditions Conditions to filter which related models to delete.
     * @return int The number of deleted models.
     * 
     * @throws \RuntimeException If the parent model instance is not set or the local key is missing.
     */
    public function deleteAll(null|int|string|array $conditions = null): int
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot delete related models without a parent model instance.');
        }

        // Get the foreign key value from the parent model
        $foreignKeyValue = $parent->{$this->localKey};

        if (empty($foreignKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->localKey} must be set before deleting related models.");
        }

        $relatedClass = new ($this->related);
        $query = $relatedClass->where($this->foreignKey, $foreignKeyValue);

        if ($conditions !== null) {
            if (is_int($conditions)) {
                $query->where($relatedClass->getPrimaryKey(), $conditions);
            } else {
                $query->where($conditions);
            }
        }

        return $query->delete();
    }

    /**
     * Delete related model(s) based on specific conditions.
     * 
     * @param int|string|array|null $conditions Conditions to filter which related models to delete.
     * @return int The number of deleted models.
     */
    public function deleteWhere(int|string|array $conditions): int
    {
        return $this->deleteAll($conditions);
    }

    /**
     * Delete a related model by its ID.
     * 
     * @param int $id The ID of the related model to delete.
     * @return int The number of deleted models.
     */
    public function deleteById(int $id): int
    {
        return $this->deleteWhere($id);
    }
}