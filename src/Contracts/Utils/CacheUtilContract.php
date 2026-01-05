<?php

namespace Spark\Contracts\Utils;

/**
 * Interface for cache utilities.
 */
interface CacheUtilContract
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
}