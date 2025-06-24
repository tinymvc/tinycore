<?php

namespace Spark\Database\Relation;

use Closure;
use Spark\Database\Model;

/**
 * Class HasOne
 * 
 * Represents a "has one" relationship in a database model.
 * 
 * This class is used to define a one-to-one relationship
 * between two models, where a model can have
 * exactly one instance of another model associated with it.
 * 
 * @package Spark\Database\Relation
 */
class HasOne extends Relation
{
    /**
     * Create a new HasOne relationship instance.
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
     * Get the configuration for the HasOne relationship.
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