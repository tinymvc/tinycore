<?php

namespace Spark\Database\Relation;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use Spark\Contracts\Support\Arrayable;
use Spark\Database\Model;
use Spark\Support\Collection;
use Traversable;

/**
 * Class Relation
 * 
 * This class serves as a base for defining relationships between models in a database.
 * It provides a common interface for accessing related models
 * and allows for various types of relationships such as one-to-one, one-to-many, and many-to-many.
 *
 * This Class also handle direct relationship call such as: $post->user() instead of $post->user
 * 
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @implements \ArrayAccess<TKey, TValue>
 */
abstract class Relation implements ArrayAccess, Arrayable, IteratorAggregate
{
    /**
     * The name of the caller method that initiated the relation call.
     * e.g., 'user', 'posts', etc.
     *
     * @var string|null
     */
    private ?string $caller = null;

    /**
     * Create a new Relation instance.
     * 
     * This constructor captures the caller method name from the debug backtrace,
     * allowing the class to determine which relationship is being accessed.
     * 
     * @param Model|null $model The model instance that this relationship belongs to.
     */
    public function __construct(private ?Model $model = null)
    {
        $this->caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4)[3]['function'] ?? null;
    }

    /**
     * Get the related models for the current relationship.
     * 
     * This method retrieves the models associated with the relationship
     * by calling the `getRelationshipAttribute` method on the model.
     * If the model or caller is not set, it returns an empty array.
     * 
     * @return array|Collection|Model The related models, which can be an array, a Collection, or a single Model instance.
     */
    private function getModels(): array|Collection|Model
    {
        if (isset($this->model) && isset($this->caller)) {
            return $this->model->getRelationshipAttribute($this->caller);
        }

        return [];
    }

    /**
     * Get the parent model instance.
     * 
     * @return Model|null
     */
    protected function getParentModel(): ?Model
    {
        return $this->model;
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
     * Check if the given offset exists in the related models.
     * 
     * @param mixed $offset The offset to check for existence.
     * @return bool True if the offset exists, false otherwise.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->getModels()[$offset]);
    }

    /**
     * Get the value at the specified offset in the related models.
     * 
     * @param mixed $offset The offset to retrieve the value from.
     * @return mixed The value at the specified offset, or null if it does not exist.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->getModels()[$offset] ?? null;
    }

    /**
     * Set the value at the specified offset in the related models.
     * 
     * @param mixed $offset The offset to set the value at.
     * @param mixed $value The value to set at the specified offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->getModels()[$offset] = $value;
    }

    /**
     * Unset the value at the specified offset in the related models.
     * 
     * @param mixed $offset The offset to unset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->getModels()[$offset]);
    }

    /**
     * Get an iterator for the items.
     *
     * @return \ArrayIterator<TKey, TValue>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toArray());
    }

    /**
     * Convert the related models to an array.
     * This method returns the array representation of the related models,
     * which can be used for serialization or other purposes.
     * 
     * @return array The array representation of the related models.
     */
    public function toArray(): array
    {
        $data = $this->getModels();

        if ($data instanceof Arrayable) {
            return $data->toArray();
        }

        return $data ?? [];
    }

    /**
     * Get the string representation of the object for debugging.
     * 
     * This method returns an array representation of the related models,
     * which can be used for debugging purposes.
     * 
     * @return array The array representation of the related models.
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}