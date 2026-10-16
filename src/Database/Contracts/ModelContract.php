<?php

namespace Spark\Database\Contracts;

use Spark\Contracts\Support\Arrayable;
use Spark\Database\Model;
use Spark\Database\QueryBuilder;

/**
 * Interface ModelContract
 *
 * This interface defines the methods required by the Model class.
 * This class is the base class for all models in the application.
 */
interface ModelContract
{
    /**
     * Returns a new instance of the QueryBuilder class.
     *
     * @return QueryBuilder A new instance of the QueryBuilder class.
     */
    public static function query(): QueryBuilder;

    /**
     * Fills the model with the given data.
     *
     * @param array|Arrayable $data The data to fill the model with.
     * @return static The current model instance.
     */
    public function fill(array|Arrayable $data): static;

    /**
     * Creates a new model instance from the given data and saves it to the database.
     *
     * @param array|Arrayable $data The data to fill the model with.
     * @return static The saved model instance.
     */
    public static function create(array|Arrayable $data): static;

    /**
     * Saves the model to the database.
     *
     * @param bool $forceCreate Whether to force the creation of a new record.
     * @return bool True on success, false on failure.
     */
    public function save(bool $forceCreate = false): bool;

    /**
     * Removes the model from the database.
     *
     * @return bool True if removal was successful, false otherwise.
     */
    public function remove(): bool;

    /**
     * Creates or updates a model instance based on unique attributes.
     *
     * @param array|Arrayable $data The data to fill the model with.
     * @param array $uniqueBy The attributes to check for uniqueness.
     * @param array|Arrayable $values The values to update if the model exists.
     * @return static The created or updated model instance.
     */
    public static function createOrUpdate(array|Arrayable $data, array $uniqueBy = [], array|Arrayable $values = []): static;

    /**
     * Finds the first model matching the attributes or creates a new one.
     *
     * @param array|Arrayable $attributes The attributes to search for.
     * @param array|Arrayable $values The values to set if a new model is created.
     * @return static The found or newly created model instance.
     */
    public static function firstOrCreate(array|Arrayable $attributes, array|Arrayable $values = []): static;

    /**
     * Finds the first model matching the attributes or returns a new instance.
     *
     * @param array|Arrayable $attributes The attributes to search for.
     * @param array|Arrayable $values The values to set if a new model is created.
     * @return static The found model instance or a new instance.
     */
    public static function firstOrNew(array|Arrayable $attributes, array|Arrayable $values = []): static;

    /**
     * Get the table name associated with the model.
     *
     * @return string
     */
    public function getTable(): string;

    /**
     * Get the primary key column name.
     *
     * @return string
     */
    public function getPrimaryKey(): string;

    /**
     * Get the primary key value.
     *
     * @param mixed $default
     * @return mixed
     */
    public function primaryValue($default = null): mixed;

    /**
     * Determine if the model has a non-empty primary key value.
     *
     * @return bool
     */
    public function hasPrimaryValue(): bool;

    /**
     * Determine if the model exists in the database.
     *
     * @return bool
     */
    public function exists(): bool;

    /**
     * Reload the current model instance with fresh attributes from the database.
     *
     * @return static
     */
    public function refresh(): static;

    /**
     * Determine if the model or a specific field has been modified.
     *
     * @param string|null $field
     * @return bool
     */
    public function isDirty(?string $field = null): bool;

    /**
     * Determine if the model or a specific field is clean (unmodified).
     *
     * @param string|null $field
     * @return bool
     */
    public function isClean(?string $field = null): bool;

    /**
     * Get all attributes of the model.
     *
     * @return array
     */
    public function getAttributes(): array;

    /**
     * Set multiple attributes on the model.
     *
     * @param array $attributes
     * @return void
     */
    public function setAttributes(array $attributes): void;

    /**
     * Get an attribute from the model.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getAttribute(string $name, $default = null): mixed;

    /**
     * Determine if the model has a given attribute.
     *
     * @param string $name
     * @return bool
     */
    public function hasAttribute(string $name): bool;

    /**
     * Get an original attribute value prior to any modifications.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getOriginal(string $name, $default = null): mixed;

    /**
     * Get the attributes that have been changed since the last sync.
     *
     * @return array
     */
    public function getChanges(): array;

    /**
     * Determine if the model has any changed attributes.
     *
     * @return bool
     */
    public function hasChanges(): bool;

    /**
     * Determine if two models have the same ID and belong to the same table.
     *
     * @param Model $model
     * @return bool
     */
    public function is(Model $model): bool;
}