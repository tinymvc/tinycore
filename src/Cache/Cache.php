<?php

namespace Spark\Cache;

use Spark\Cache\Contracts\CacheContract;
use Spark\Cache\Contracts\CacheStorageContract;
use Spark\Cache\Storage\RedisStorage;
use Spark\Cache\Storage\SqliteStorage;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use Spark\Utils\RedisConnector;
use function func_get_args;
use function is_array;
use function strtolower;

/**
 * Public cache repository for the configured cache driver.
 *
 * This class keeps the application-facing cache API stable while delegating all
 * SQLite and Redis persistence details to storage classes.
 *
 * @implements \ArrayAccess<string, mixed>
 */
class Cache implements CacheContract, \ArrayAccess
{
    use Macroable, Conditionable;

    private CacheStorageContract $storage;

    /**
     * Create a cache repository by name.
     *
     * The name is used by storage drivers to isolate cache data, such as by
     * SQLite file name or Redis key namespace.
     */
    public function __construct(string $name = 'default')
    {
        $connection = $this->resolveDriverConfig($name);
        $driver = strtolower((string) ($connection['driver'] ?? 'sqlite'));
        $this->storage = $driver === 'redis'
            ? new RedisStorage($name, $connection)
            : new SqliteStorage($name, $connection, 'cache');
    }

    /**
     * Create a named cache repository.
     *
     * This is a convenience method for creating a new instance of the Cache class.
     *
     * @param string $name The name of the cache repository.
     * @return Cache A new instance of the Cache class.
     */
    public static function make(string $name = 'default'): Cache
    {
        return new self($name);
    }

    /**
     * Determine whether an active cache key exists.
     *
     * @param string $key The cache key to check.
     * @param bool $eraseExpired Whether to erase expired entries when checking.
     * @return bool True if the key exists and is active, false otherwise.
     */
    public function has(string $key, bool $eraseExpired = false): bool
    {
        return $this->storage->has($key, $eraseExpired);
    }

    /**
     * Store a value with an optional strtotime-compatible expiration.
     *
     * @param string $key The cache key.
     * @param mixed $data The value to store.
     * @param null|string $expire The expiration time.
     * @return self
     */
    public function store(string $key, mixed $data, null|string $expire = null): self
    {
        $this->storage->store($key, $data, $expire);

        return $this;
    }

    /**
     * Retrieve a value or store the callback result when the key is missing.
     *
     * @param string $key The cache key.
     * @param callable $callback The callback to generate the value if missing.
     * @param null|string $expire The expiration time.
     * @return mixed The cached value or the result of the callback.
     */
    public function load(string $key, callable $callback, null|string $expire = null): mixed
    {
        if (!$this->has($key, $expire !== null)) {
            $data = $callback($this);
            $this->store($key, $data, $expire);
            return $data;
        }

        return $this->retrieve($key);
    }

    /**
     * Retrieve one cache value or an associative array of values.
     *
     * @param string|array $keys The cache key(s) to retrieve.
     * @param bool $eraseExpired Whether to erase expired entries when retrieving.
     * @return mixed The cached value(s).
     */
    public function retrieve(string|array $keys, bool $eraseExpired = false): mixed
    {
        return $this->storage->retrieve($keys, $eraseExpired);
    }

    /**
     * Return cache metadata for a key.
     *
     * @param string $key The cache key.
     * @return mixed The metadata associated with the cache key.
     */
    public function metadata(string $key): mixed
    {
        return $this->storage->metadata($key);
    }

    /**
     * Retrieve every active item in this named cache.
     *
     * @param bool $eraseExpired Whether to erase expired entries when retrieving.
     * @return array An associative array of all active cache items.
     */
    public function retrieveAll(bool $eraseExpired = false): array
    {
        return $this->storage->retrieveAll($eraseExpired);
    }

    /**
     * Remove one or more cache keys.
     *
     * @param string|array $keys The cache key(s) to remove.
     * @return self
     */
    public function erase(string|array $keys): self
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $this->storage->erase($keys);

        return $this;
    }

    /**
     * Remove expired cache entries.
     */
    public function eraseExpired(): self
    {
        $this->storage->eraseExpired();

        return $this;
    }

    /**
     * Return expired entries without clearing active entries.
     */
    public function getExpired(): array
    {
        return $this->storage->getExpired();
    }

    /**
     * Remove all entries from this named cache.
     */
    public function flush(): self
    {
        $this->storage->flush();

        return $this;
    }

    /**
     * Flush the cache when the condition is true.
     *
     * @param bool $condition The condition to evaluate.
     */
    public function flushIf(bool $condition): self
    {
        $condition && $this->flush();

        return $this;
    }

    /**
     * Clear all entries and reclaim storage when the driver supports it.
     */
    public function clear(): self
    {
        $this->storage->clear();

        return $this;
    }

    /**
     * Store multiple values with one shared expiration.
     *
     * @param array $items An associative array of key-value pairs to store.
     * @param null|string $expire The expiration time for all items.
     * @return self
     */
    public function storeMany(array $items, null|string $expire = null): self
    {
        $this->storage->storeMany($items, $expire);

        return $this;
    }

    /**
     * Increment an existing numeric cache value.
     *
     * @param string $key The cache key to increment.
     * @param int $amount The amount to increment by (default is 1).
     * @return int|false The new value after incrementing, or false on failure.
     */
    public function increment(string $key, int $amount = 1): int|false
    {
        return $this->storage->increment($key, $amount);
    }

    /**
     * Decrement an existing numeric cache value.
     *
     * @param string $key The cache key to decrement.
     * @param int $amount The amount to decrement by (default is 1).
     * @return int|false The new value after decrementing, or false on failure.
     */
    public function decrement(string $key, int $amount = 1): int|false
    {
        return $this->increment($key, -$amount);
    }

    /**
     * Store a value only when the key does not already exist.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     * @param null|string $expire The expiration time.
     * @return bool True if the value was stored, false if the key already exists.
     */
    public function add(string $key, mixed $value, null|string $expire = null): bool
    {
        return $this->storage->add($key, $value, $expire);
    }

    /**
     * Return a cached value or remember the callback result.
     *
     * @param string $key The cache key.
     * @param callable $callback The callback to generate the value if missing.
     * @param null|string $expire The expiration time.
     * @return mixed The cached value or the result of the callback.
     */
    public function remember(string $key, callable $callback, null|string $expire = null): mixed
    {
        if ($this->has($key, $expire !== null)) {
            return $this->retrieve($key);
        }

        $value = $callback($this);
        $this->store($key, $value, $expire);

        return $value;
    }

    /**
     * Return driver-level cache statistics.
     */
    public function stats(): array
    {
        return $this->storage->stats();
    }

    /**
     * Retrieve a value and remove it from cache.
     *
     * @param string $key The cache key to pull.
     * @param mixed $default The default value to return if the key does not exist.
     * @return mixed The cached value or the default value.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->storage->pull($key, $default);
    }

    /**
     * Store multiple values where each item has its own expiration.
     *
     * @param array $items An associative array of key-value pairs to store,
     *                  where each value is an array with 'value' and 'expire' keys.
     * @return self
     */
    public function storeManyWithExpiry(array $items): self
    {
        $this->storage->storeManyWithExpiry($items);

        return $this;
    }

    /**
     * Get the remaining time to live in seconds.
     *
     * @param string $key The cache key to check.
     * @return int|null The remaining time to live in seconds, or null if the key
     */
    public function ttl(string $key): ?int
    {
        return $this->storage->ttl($key);
    }

    /**
     * Optimize the underlying cache storage.
     */
    public function optimize(): self
    {
        $this->storage->optimizeCache();

        return $this;
    }

    /**
     * Determine whether a cache key exists for ArrayAccess.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    /**
     * Retrieve a cache value for ArrayAccess.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->retrieve((string) $offset);
    }

    /**
     * Store a cache value for ArrayAccess.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->store((string) $offset, $value);
    }

    /**
     * Remove a cache key for ArrayAccess.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->erase((string) $offset);
    }

    /**
     * Resolve the configured cache driver and its connection settings.
     */
    private function resolveDriverConfig(string $name): array
    {
        $cacheConfig = (array) config('cache', []);
        $driver = strtolower((string) ($cacheConfig['driver'] ?? 'sqlite'));
        $connections = (array) ($cacheConfig['connections'] ?? []);
        $driverConfig = is_array($connections[$driver] ?? null) ? $connections[$driver] : [];

        return RedisConnector::mergeConfig([
            'driver' => $driver,
            'name' => $name,
        ], $driverConfig);
    }
}
