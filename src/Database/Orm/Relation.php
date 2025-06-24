<?php

namespace Spark\Database\Orm;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use Spark\Contracts\Support\Arrayable;
use Spark\Database\Model;
use Spark\Support\Collection;
use Traversable;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @implements \ArrayAccess<TKey, TValue>
 */
abstract class Relation implements ArrayAccess, Arrayable, IteratorAggregate
{
    private ?string $caller = null;

    public function __construct(private ?Model $model = null)
    {
        $this->caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4)[3]['function'] ?? null;
    }

    private function getModels(): array|Collection|Model
    {
        if (isset($this->model) && isset($this->caller)) {
            return $this->model->getRelationshipAttribute($this->caller);
        }

        return [];
    }

    abstract public function getConfig(): array;

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->getModels()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getModels()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->getModels()[$offset] = $value;
    }

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
        $data = $this->getModels();

        if ($data instanceof Collection) {
            return $data->getIterator();
        } elseif ($data instanceof Model) {
            return new ArrayIterator([$data]);
        }

        return new ArrayIterator($data);
    }

    public function __debugInfo(): array
    {
        $data = $this->getModels();

        if ($data instanceof Collection) {
            return $data->toArray();
        } elseif ($data instanceof Model) {
            return $data->toArray();
        }

        return $data;
    }

    public function toArray(): array
    {
        return $this->getModels();
    }
}