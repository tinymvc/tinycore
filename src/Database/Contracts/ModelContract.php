<?php

namespace Spark\Database\Contracts;

use Spark\Contracts\Support\Arrayable;
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
}