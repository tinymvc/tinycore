<?php

namespace Spark\Database\Relation;

use Spark\Database\Model;
use Spark\Database\QueryBuilder;

/**
 * Class Relation
 * 
 * This class serves as a base for defining relationships between models in a database.
 * It provides a common interface for accessing related models via QueryBuilder proxy pattern.
 * 
 * Supports method chaining: $post->comments()->where('approved', 1)->get()
 * 
 * @mixin QueryBuilder
 * 
 * @package Spark\Database\Relation
 */
abstract class Relation
{
    /**
     * The QueryBuilder instance for this relation.
     *
     * @var QueryBuilder|null
     */
    protected null|QueryBuilder $query = null;

    /**
     * Whether to load the relationship lazily.
     *
     * @var bool
     */
    protected bool $lazy = true;

    /**
     * Create a new Relation instance.
     * 
     * @param Model|null $model The model instance that this relationship belongs to.
     */
    public function __construct(protected null|Model $model = null)
    {
    }

    /**
     * Get the parent model instance.
     * 
     * @return Model|null
     */
    public function getParentModel(): null|Model
    {
        return $this->model;
    }

    /**
     * Build the base query for this relationship.
     * This method must be implemented by subclasses to set up the QueryBuilder
     * with appropriate constraints for the relationship type.
     * 
     * @return QueryBuilder
     */
    abstract protected function buildQuery(): QueryBuilder;

    /**
     * Get the QueryBuilder instance for this relation.
     * 
     * @return QueryBuilder
     */
    public function query(): QueryBuilder
    {
        return $this->query ??= $this->buildQuery();
    }

    /**
     * Determine if a relationship key is missing.
     *
     * @param mixed $value
     * @return bool
     */
    protected function missingKey(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Get the configuration for the relationship.
     * This method must be implemented by subclasses to return
     * the specific configuration for the relationship.
     * 
     * @return array The configuration for the relationship, which may 
     *      include related model class, foreign keys, owner keys, etc.
     */
    abstract public function getConfig(): array;

    /**
     * Disable lazy loading for this relationship.
     * 
     * @param bool $mode Whether to disable lazy loading (true to disable, false to enable).
     * @return self
     */
    public function disableLazyLoading(bool $mode = true): self
    {
        $this->lazy = !$mode;
        return $this;
    }

    /**
     * Check if lazy loading is disabled for this relationship.
     * 
     * @return bool True if lazy loading is disabled, false otherwise.
     */
    public function isLazyLoadingDisabled(): bool
    {
        return !$this->lazy;
    }

    /**
     * Wrap pivot fields for the given models.
     * This method is used to process the pivot fields in the related models
     * and wrap them into a structured format.
     * 
     * @param array $models The array of related model instances.
     * @return array The array of models with wrapped pivot fields.
     */
    public function wrapPivotFields(array $models): array
    {
        foreach ($models as $model) {
            $model->wrapPivotFields();
        }
        return $models;
    }

    /**
     * Proxy method calls to the underlying QueryBuilder.
     * 
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        $result = $this->query()->$method(...$parameters);

        // Return $this for fluent interface if QueryBuilder returned itself
        if ($result instanceof QueryBuilder) {
            return $this;
        }

        return $result;
    }
}
