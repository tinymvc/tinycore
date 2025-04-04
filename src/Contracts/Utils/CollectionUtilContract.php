<?php

namespace Spark\Contracts\Utils;

/**
 * Interface CollectionUtilContract
 * 
 * Defines a contract for a collection utility class, providing methods for
 * array manipulation and functional operations similar to Laravel's Collection.
 */
interface CollectionUtilContract
{
    /**
     * Creates a new collection instance.
     * 
     * @param array $items Initial collection items.
     * @return self
     */
    public static function make(array $items = []): self;

    /**
     * Retrieves all items in the collection.
     * 
     * @return array
     */
    public function all(): array;

    /**
     * Gets an item by key or returns a default value.
     * 
     * @param int|string $key The key to retrieve.
     * @param mixed $default Default value if the key doesn't exist.
     * @return mixed
     */
    public function get(int|string $key, $default = null): mixed;

    /**
     * Checks if a key exists in the collection.
     * 
     * @param int|string $key The key to check.
     * @return bool
     */
    public function has(int|string $key): bool;

    /**
     * Adds an item to the collection with the specified key.
     * 
     * @param int|string $key The key to add.
     * @param mixed $value The value to add.
     * @return self
     */
    public function add(int|string $key, $value): self;

    /**
     * Removes an item from the collection by key.
     * 
     * @param int|string $key The key to remove.
     * @return self
     */
    public function remove(int|string $key): self;

    /**
     * Sorts the collection by a specific column.
     * 
     * @param string $column The column to sort by.
     * @param bool $desc Whether to sort in descending order.
     * @return self
     */
    public function multiSort(string $column, bool $desc = false): self;

    /**
     * Filters the collection using a callback.
     * 
     * @param callable $callback The callback to filter by.
     * @return self
     */
    public function filter(callable $callback): self;

    /**
     * Applies a callback to each item in the collection.
     * 
     * @param callable $callback The callback to apply.
     * @return self
     */
    public function map(callable $callback): self;

    /**
     * Applies a callback to each key in the collection.
     * 
     * @param callable $callback The callback to apply.
     * @return self
     */
    public function mapK(callable $callback): self;

    /**
     * Retrieves a list of values for a given key.
     * 
     * @param string $key The key to pluck.
     * @return self
     */
    public function pluck(string $key): self;

    /**
     * Returns a collection without the specified keys.
     * 
     * @param array $keys The keys to exclude.
     * @return self
     */
    public function except(array $keys): self;

    /**
     * Returns a collection with only the specified keys.
     * 
     * @param array $keys The keys to include.
     * @return self
     */
    public function only(array $keys): self;

    /**
     * Finds an item in the collection using a callback.
     * 
     * @param callable $callback The callback to use for finding.
     * @param mixed $default Default value if no item is found.
     * @return mixed
     */
    public function find(callable $callback, $default = null): mixed;

    /**
     * Converts the collection to a JSON string.
     * 
     * @param mixed ...$args Additional arguments for JSON encoding.
     * @return string
     */
    public function toJson(...$args): string;

    /**
     * Converts the collection to a string with a specified separator.
     * 
     * @param string $separator The separator to use.
     * @return string
     */
    public function toString(string $separator = ''): string;
}