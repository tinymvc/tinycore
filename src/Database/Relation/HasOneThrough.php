<?php

namespace Spark\Database\Relation;

use Closure;
use Spark\Database\Model;

/**
 * Class HasManyThrough
 * 
 * Represents a "has many through" relationship in a database model.
 * 
 * This class is used to define a many-to-many relationship
 * between two models through an intermediate model.
 * It encapsulates the related model, the through model,
 * 
 * the keys used to establish the relationship,
 * and other parameters necessary to establish the relationship.
 * 
 * @package Spark\Database\Relation
 */
class HasOneThrough extends Relation
{
    /**
     * Create a new HasOneThrough relationship instance.
     * 
     * @param string $related The related model class name.
     * @param string $through The through model class name that connects the related model.
     * @param string|null $firstKey The foreign key in the through model that references the current model.
     * @param string|null $secondKey The foreign key in the through model that references the related model.
     * @param string|null $localKey The primary key in the current model that the first key references.
     * @param string|null $secondLocalKey The primary key in the related model that the second key references.
     * @param bool $lazy Whether to load the relationship lazily.
     * @param array $append Additional fields to append to the relationship.
     * @param Closure|null $callback An optional callback to modify the query for the relationship.
     * @param Model|null $model The model instance that this relationship belongs to.
     * 
     */
    public function __construct(
        private string $related,
        private string $through,
        private ?string $firstKey = null,
        private ?string $secondKey = null,
        private ?string $localKey = null,
        private ?string $secondLocalKey = null,
        private bool $lazy = true,
        private array $append = [],
        private ?Closure $callback = null,
        ?Model $model = null
    ) {
        parent::__construct($model);
    }

    /**
     * Get the configuration for the HasManyThrough relationship.
     * 
     * @return array{
     *     related: string,
     *     through: string,
     *     firstKey: string|null,
     *     secondKey: string|null,
     *     localKey: string|null,
     *     secondLocalKey: string|null,
     *     lazy: bool,
     *     callback: Closure|null
     * }
     */
    public function getConfig(): array
    {
        return [
            'related' => $this->related,
            'through' => $this->through,
            'firstKey' => $this->firstKey,
            'secondKey' => $this->secondKey,
            'localKey' => $this->localKey,
            'secondLocalKey' => $this->secondLocalKey,
            'lazy' => $this->lazy,
            'append' => $this->append,
            'callback' => $this->callback,
        ];
    }
}