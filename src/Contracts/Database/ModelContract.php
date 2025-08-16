<?php

namespace Spark\Contracts\Database;

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
     * Creates a new model instance and fills it with the given data.
     *
     * @param array|Arrayable $data The data to fill the model with.
     * @return static A new model instance populated with the given data.
     */
    public static function load(array|Arrayable $data): static;

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
     * @return int|bool The ID of the saved model or false on failure.
     */
    public function save(): int|bool;

    /**
     * Removes the model from the database.
     *
     * @return bool True if removal was successful, false otherwise.
     */
    public function remove(): bool;
}