<?php

namespace Spark\Utils;

/**
 * Class Collect
 * 
 * A utility collection class inspired by Laravel's Collection, providing a variety of methods
 * for array manipulation and functional operations.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Collect
{
    /** @var array The collection items. */
    private array $items;

    /**
     * collect constructor.
     *
     * @param array $items Initial collection items.
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Creates a new collection instance.
     *
     * @param array $items The items for the collection.
     * @return self
     */
    public static function make(array $items = []): self
    {
        return new self($items);
    }

    /**
     * Retrieves all items in the collection.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Gets an item by key or returns a default value.
     *
     * @param int|string $key The key to retrieve.
     * @param mixed $default Default value if the key doesn't exist.
     * @return mixed
     */
    public function get(int|string $key, $default = null)
    {
        return $this->items[$key] ?? $default;
    }

    /**
     * Checks if a key exists in the collection.
     *
     * @param int|string $key The key to check.
     * @return bool
     */
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * Checks if a value exists in the collection.
     *
     * @param mixed $needle The value to search for.
     * @return bool
     */
    public function in(mixed $needle): bool
    {
        return in_array($needle, $this->items);
    }

    /**
     * Adds an item to the collection.
     *
     * @param int|string $key The key for the item.
     * @param mixed $value The item value.
     * @return self
     */
    public function add(int|string $key, $value): self
    {
        $this->items[$key] = $value;
        return $this;
    }

    /**
     * Removes an item from the collection by key.
     *
     * @param int|string $key The key to remove.
     * @return self
     */
    public function remove(int|string $key): self
    {
        unset($this->items[$key]);
        return $this;
    }

    /**
     * Removes the last item and returns it.
     *
     * @return mixed
     */
    public function pop(): mixed
    {
        return array_pop($this->items);
    }

    /**
     * Adds one or more items to the end of the collection.
     *
     * @param mixed ...$values Values to add.
     * @return self
     */
    public function push(...$values): self
    {
        array_push($this->items, ...$values);
        return $this;
    }

    /**
     * Removes the first item and returns it.
     *
     * @return mixed
     */
    public function shift(): mixed
    {
        return array_shift($this->items);
    }

    /**
     * Adds one or more items to the beginning of the collection.
     *
     * @param mixed ...$values Values to add.
     * @return self
     */
    public function unshift(...$values): self
    {
        array_unshift($this->items, ...$values);
        return $this;
    }

    /**
     * Merges the collection with one or more arrays.
     *
     * @param array ...$arrays Arrays to merge.
     * @return self
     */
    public function merge(array ...$arrays): self
    {
        $this->items = array_merge($this->items, ...$arrays);
        return $this;
    }

    /**
     * Applies a callback to reduce the collection to a single value.
     *
     * @param callable $callback The reducing function.
     * @param mixed $initial The initial value.
     * @return mixed
     */
    public function reduce(callable $callback, $initial = null)
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Sorts the collection items.
     *
     * @param callable|null $callback Sorting function.
     * @return self
     */
    public function sort(?callable $callback = null): self
    {
        if ($callback) {
            usort($this->items, $callback);
        } else {
            sort($this->items);
        }

        return $this;
    }

    /**
     * Sorts the collection by keys.
     *
     * @param callable|null $callback Key sorting function.
     * @return self
     */
    public function sortKeys(?callable $callback = null): self
    {
        if ($callback) {
            uksort($this->items, $callback);
        } else {
            ksort($this->items);
        }

        return $this;
    }

    /**
     * Sorts items by a specified field in ascending or descending order.
     *
     * @param string $column Column to sort by.
     * @param bool $desc Sort in descending order.
     * @return self
     */
    public function multiSort(string $column, bool $desc = false): self
    {
        $numbers = array_column($this->items, $column);
        array_multisort($numbers, $desc ? SORT_DESC : SORT_ASC, $this->items);
        return $this;
    }

    /**
     * Sorts items by multiple fields.
     *
     * @param array $fields Array of fields and sort direction.
     * @return self
     */
    public function sortBy(array $fields): self
    {
        $items = $this->items;

        usort($items, function ($a, $b) use ($fields) {
            foreach ($fields as $field => $direction) {
                $comparison = ($a[$field] ?? null) <=> ($b[$field] ?? null);
                if ($comparison !== 0) {
                    return $direction === 'asc' ? $comparison : -$comparison;
                }
            }

            return 0;
        });

        return new self($items);
    }

    /**
     * Returns a new collection containing only the keys of the current items.
     *
     * @return self
     */
    public function keys()
    {
        return new self(array_keys($this->items));
    }

    /**
     * Removes duplicate values from the collection and returns a new collection.
     *
     * @return self
     */
    public function unique(): self
    {
        return new self(array_unique($this->items, SORT_REGULAR));
    }

    /**
     * Reverses the order of items in the collection and returns a new collection.
     *
     * @return self
     */
    public function reverse(): self
    {
        return new self(array_reverse($this->items, true));
    }

    /**
     * Filters the collection using a callback and returns a new collection.
     * The callback should return true for items that should remain in the collection.
     *
     * @param callable $callback
     * @return self
     */
    public function filter(callable $callback): self
    {
        return new self(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Applies a callback to each item in the collection and returns a new collection.
     *
     * @param callable $callback
     * @return self
     */
    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items, array_keys($this->items)));
    }

    /**
     * Maps items with keys using a callback and returns a new collection.
     * Allows mapping to new key-value pairs within the callback.
     *
     * @param callable $callback
     * @return self
     */
    public function mapK(callable $callback): self
    {
        $result = [];

        foreach ($this->items as $key => $value) {
            $mapped = $callback($value, $key);
            foreach ($mapped as $newKey => $newValue) {
                $result[$newKey] = $newValue;
            }
        }

        return new self($result);
    }

    /**
     * Extracts values associated with a specified key from each item in the collection.
     * Returns a new collection with non-null values.
     *
     * @param string $key
     * @return self
     */
    public function pluck(string $key): self
    {
        $results = array_map(
            fn($item) => is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->$key ?? null) : null),
            $this->items
        );

        return new self(array_filter($results));
    }

    /**
     * Returns a new collection containing a slice of items.
     *
     * @param int $offset
     * @param int|null $limit
     * @param bool $preserve_keys
     * @return self
     */
    public function slice(int $offset, ?int $limit = null, bool $preserve_keys = false): self
    {
        return new self(array_slice($this->items, $offset, $limit, $preserve_keys));
    }

    /**
     * Filters the collection to items where a specified key has a specified value.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function where(string $key, $value): self
    {
        return new self(array_filter($this->items, fn($item) => is_array($item) ? (($item[$key] ?? null) === $value) : (is_object($item) ? ($item->$key ?? null) === $value : false)));
    }

    /**
     * Removes specified keys from the collection and returns a new collection.
     *
     * @param array $keys
     * @return self
     */
    public function except(array $keys): self
    {
        $removeKeys = function ($array, $keys) use (&$removeKeys) {
            $filtered = [];
            foreach ($array as $key => $value) {
                if (!in_array($key, $keys)) {
                    $filtered[$key] = is_array($value) ? $removeKeys($value, $keys) : $value;
                }
            }

            return $filtered;
        };

        return new self($removeKeys($this->items, $keys));
    }

    /**
     * Returns a new collection containing only the specified keys.
     *
     * @param array $keys
     * @return self
     */
    public function only(array $keys): self
    {
        $filterKeys = function ($array, $keys) use (&$filterKeys) {
            $filtered = [];
            foreach ($array as $key => $value) {
                if (in_array($key, $keys)) {
                    $filtered[$key] = is_array($value) ? $filterKeys($value, $keys) : $value;
                } elseif (is_array($value)) {
                    $nested = $filterKeys($value, $keys);
                    if (!empty($nested)) {
                        $filtered[$key] = $nested;
                    }
                }
            }

            return $filtered;
        };

        return new self($filterKeys($this->items, $keys));
    }

    /**
     * Groups items in the collection by a specified key and returns a new collection.
     *
     * @param string $group
     * @return self
     */
    public function group(string $group): self
    {
        $array = [];

        foreach ($this->items as $k => $value) {
            $key = is_array($value) ? $value[$group] : (is_object($value) ? $value->$group : null);
            if ($key !== null) {
                $array[$key][$k] = $value;
            }
        }

        return new self($array);
    }

    /**
     * Finds an item in the collection using a callback. Returns the item if found, otherwise returns default.
     *
     * @param callable $callback
     * @param mixed $default
     * @return mixed
     */
    public function find(callable $callback, $default = null)
    {
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Returns the first item in the collection, or the first item that matches the callback.
     *
     * @param callable|null $callback
     * @param mixed $default
     * @return mixed
     */
    public function first(callable $callback = null, $default = null)
    {
        if ($callback === null) {
            return $this->items[0] ?? $default;
        }

        return $this->find($callback, $default);
    }

    /**
     * Returns the last item in the collection, or the last item that matches the callback.
     *
     * @param callable|null $callback
     * @param mixed $default
     * @return mixed
     */
    public function last(callable $callback = null, $default = null)
    {
        if ($callback === null) {
            return $this->items[count($this->items) - 1] ?? $default;
        }

        foreach (array_reverse($this->items, true) as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Returns the number of items in the collection.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Converts the collection to a JSON string.
     *
     * @return string
     */
    public function toJson(...$args): string
    {
        return json_encode($this->items, ...$args);
    }

    /**
     * Converts the collection to a string, with an optional delimiter between items.
     *
     * @param string $separator
     * @return string
     */
    public function toString(string $separator = ''): string
    {
        return implode($separator, $this->items);
    }
}
