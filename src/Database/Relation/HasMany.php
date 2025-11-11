<?php

namespace Spark\Database\Relation;

use Closure;
use Spark\Database\Model;

/**
 * Class HasMany
 * 
 * Represents a "has many" relationship in a database model.
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
        private string $related,
        private ?string $foreignKey = null,
        private ?string $localKey = null,
        private bool $lazy = true,
        private ?Closure $callback = null,
        ?Model $model = null
    ) {
        parent::__construct($model);
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

        // Create the related model with the foreign key set
        $relatedClass = $this->related;
        $relatedModel = $relatedClass::create(array_merge($attributes, [
            $this->foreignKey => $foreignKeyValue
        ]));

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
            $models[] = $relatedClass::create(array_merge($attributes, [
                $this->foreignKey => $foreignKeyValue
            ]));
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
    public function updateOrCreate(array $attributes = [], array $values = []): Model
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
        $attributes = array_merge($attributes, [
            $this->foreignKey => $foreignKeyValue
        ]);

        $relatedClass = $this->related;
        return $relatedClass::updateOrCreate(array_merge($attributes, $values), false, array_keys($attributes));
    }

    /**
     * Get or create a new instance of the related model.
     * 
     * @param array $attributes The attributes to search for.
     * @return Model The found or newly created model instance.
     */
    public function firstOrCreate(array $attributes = []): Model
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
        $attributes = array_merge($attributes, [
            $this->foreignKey => $foreignKeyValue
        ]);

        $relatedClass = $this->related;
        return $relatedClass::firstOrCreate($attributes);
    }
}