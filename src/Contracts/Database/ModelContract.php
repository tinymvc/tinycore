<?php

namespace Spark\Contracts\Database;

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
     * Returns a model instance with the given ID.
     *
     * @param mixed $value The ID of the model to retrieve.
     * @return false|static The found model instance or false if not found.
     */
    public static function find($value): false|static;

    /**
     * Creates a new model instance and fills it with the given data.
     *
     * @param array $data The data to fill the model with.
     * @return static A new model instance populated with the given data.
     */
    public static function load(array $data): static;

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

    /**
     * Converts the model to an array.
     *
     * @return array An array representation of the model.
     */
    public function toArray(): array;
}