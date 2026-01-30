<?php

namespace Spark\Database\Relation;

use Closure;
use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Database\Traits\CreateDeleteForHasRelation;

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
    use CreateDeleteForHasRelation;

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
}