<?php

namespace Spark\Database\Relation;

use Closure;
use Spark\Database\Model;

/**
 * Class BelongsToMany
 * 
 * Represents a "belongs to many" relationship in a database model.
 * This class is used to define a many-to-many relationship
 * between two models, where a model can belong to multiple instances
 * of another model and vice versa.
 * 
 * @package Spark\Database\Relation
 */
class BelongsToMany extends Relation
{
    /**
     * Create a new BelongsToMany relationship instance.
     * 
     * @param string $related The related model class name.
     * @param string|null $table The pivot table name that holds the relationship.
     * @param string|null $foreignPivotKey The foreign key in the pivot table that references the current model.
     * @param string|null $relatedPivotKey The foreign key in the pivot table that references the related model.
     * @param string|null $parentKey The primary key in the current model that the foreign pivot key references.
     * @param string|null $relatedKey The primary key in the related model that the related pivot key references.
     * @param bool $lazy Whether to load the relationship lazily.
     * @param array $append The additional fields to append to the relationship.
     * @param Closure|null $callback An optional callback to modify the query for the relationship.
     * @param Model|null $model The model instance that this relationship belongs to.
     */
    public function __construct(
        private string $related,
        private ?string $table = null,
        private ?string $foreignPivotKey = null,
        private ?string $relatedPivotKey = null,
        private ?string $parentKey = null,
        private ?string $relatedKey = null,
        private bool $lazy = true,
        private array $append = [],
        private ?Closure $callback = null,
        ?Model $model = null,
    ) {
        parent::__construct($model);
    }

    /**
     * Get the configuration for the BelongsToMany relationship.
     * 
     * @return array{
     *     related: string,
     *     table: string|null,
     *     foreignPivotKey: string|null,
     *     relatedPivotKey: string|null,
     *     parentKey: string|null,
     *     relatedKey: string|null,
     *     lazy: bool,
     *     callback: Closure|null
     * }
     */
    public function getConfig(): array
    {
        return [
            'related' => $this->related,
            'table' => $this->table,
            'foreignPivotKey' => $this->foreignPivotKey,
            'relatedPivotKey' => $this->relatedPivotKey,
            'parentKey' => $this->parentKey,
            'relatedKey' => $this->relatedKey,
            'lazy' => $this->lazy,
            'append' => $this->append,
            'callback' => $this->callback,
        ];
    }
}