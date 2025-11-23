<?php

namespace Spark\Database\Traits;

use Spark\Database\Model;
use function is_int;

/**
 * Trait providing methods to delete HasOne, HasMany related 
 * models in a database relationship.
 * 
 * @package Spark\Database\Traits
 */
trait CreateDeleteForHasRelation
{
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
        $attributes = array_merge($attributes, [
            $this->foreignKey => $foreignKeyValue
        ]);

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

        $relatedClass = $this->related;
        $query = $relatedClass::where($this->foreignKey, $foreignKeyValue);

        if ($conditions !== null) {
            if (is_int($conditions)) {
                $query->where($relatedClass::$primaryKey, $conditions);
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