<?php

namespace Spark\Cache\Contracts;

/**
 * Interface for cache utilities.
 */
interface CacheContract
{
    /**
     * Checks if a cache key exists and optionally erases expired entries.
     *
     * @param string $key The key to check in cache.
     * @param bool $eraseExpired Whether to erase expired entries before checking.
     * @return bool
     */
    public function has(string $key, bool $eraseExpired = false): bool;

    /**
     * Stores data in the cache with an optional expiration time.
     *
     * @param string $key Unique identifier for the cached data.
     * @param mixed $data The data to cache.
     * @param string|null $expire Expiration time as a string (e.g., '+1 day').
     * @return self The instance of the cache util for method chaining.
     */
    public function store(string $key, mixed $data, ?string $expire = null): self;

    /**
     * Loads data from cache or generates it using a callback if not present.
     *
     * @param string $key The cache key.
     * @param callable $callback Function to generate the data if not cached.
     * @param string|null $expire Optional expiration time.
     * @return mixed
     */
    public function load(string $key, callable $callback, ?string $expire = null): mixed;

    /**
     * Retrieves data from the cache for given keys, optionally erasing expired entries.
     *
     * @param string|array $keys Cache key(s) to retrieve.
     * @param bool $eraseExpired Whether to erase expired entries before retrieval.
     * @return mixed
     */
    public function retrieve(string|array $keys, bool $eraseExpired = false): mixed;

    /**
     * Erases specified cache entries.
     *
     * @param string|array $keys Cache key(s) to erase.
     * @return self The instance of the cache util for method chaining.
     */
    public function erase(string|array $keys): self;

    /**
     * Clears all cache data.
     *
     * @return self The instance of the cache util for method chaining.
     */
    public function flush(): self;

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * @param string $key
     * @param callable $callback
     * @param string|null $expire
     * @return mixed
     */
    public function remember(string $key, callable $callback, ?string $expire = null): mixed;

    /**
     * Retrieve an item from the cache and delete it.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache if the key does not already exist.
     *
     * @param string $key
     * @param mixed $value
     * @param string|null $expire
     * @return bool
     */
    public function add(string $key, mixed $value, ?string $expire = null): bool;

    /**
     * Increment the value of an item in the cache.
     *
     * @param string $key
     * @param int $amount
     * @return int|false
     */
    public function increment(string $key, int $amount = 1): int|false;

    /**
     * Decrement the value of an item in the cache.
     *
     * @param string $key
     * @param int $amount
     * @return int|false
     */
    public function decrement(string $key, int $amount = 1): int|false;

    /**
     * Store multiple items in the cache.
     *
     * @param array $items
     * @param string|null $expire
     * @return self
     */
    public function storeMany(array $items, ?string $expire = null): self;

    /**
     * Retrieve all items from the cache.
     *
     * @param bool $eraseExpired
     * @return array
     */
    public function retrieveAll(bool $eraseExpired = false): array;

    /**
     * Get remaining time-to-live in seconds for a cache key.
     *
     * @param string $key
     * @return int|null
     */
    public function ttl(string $key): ?int;

    /**
     * Retrieve an item from the cache by key (alias of retrieve).
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache (alias of store).
     *
     * @param string $key
     * @param mixed $data
     * @param string|null $expire
     * @return self
     */
    public function set(string $key, mixed $data, ?string $expire = null): self;

    /**
     * Store an item in the cache (alias of store).
     *
     * @param string $key
     * @param mixed $data
     * @param string|null $expire
     * @return self
     */
    public function put(string $key, mixed $data, ?string $expire = null): self;

    /**
     * Remove an item from the cache (alias of erase).
     *
     * @param string|array $keys
     * @return self
     */
    public function forget(string|array $keys): self;

    /**
     * Delete an item from the cache (alias of erase).
     *
     * @param string|array $keys
     * @return self
     */
    public function delete(string|array $keys): self;
}
