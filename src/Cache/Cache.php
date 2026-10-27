<?php

namespace Spark\Cache;

use DateInterval;
use DateTimeInterface;
use Spark\Carbon;

use Spark\Cache\Contracts\CacheContract;
use Spark\Cache\Contracts\CacheStorageContract;
use Spark\Cache\Storage\FileStorage;
use Spark\Cache\Storage\RedisStorage;
use Spark\Cache\Storage\DatabaseStorage;
use Spark\Foundation\Application;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function func_get_args;
use function is_array;
use function strtolower;

/**
 * Public cache repository for the configured cache driver.
 *
 * This class keeps the application-facing cache API stable while delegating all
 * database, file and Redis persistence details to storage classes.
 *
 * @implements \ArrayAccess<string, mixed>
 */
class Cache implements CacheContract, \ArrayAccess
{
    use Macroable, Conditionable;

    private CacheStorageContract $storage;

    private ?\WeakReference $manager = null;

    private array $config;

    /**
     * Create a cache repository by name.
     *
     * The name is used by storage drivers to isolate cache data, such as by
     * database group, file directory or Redis key namespace.
     */
    public function __construct(
        private string $name = 'default',
        ?array $config = null,
        ?CacheManager $manager = null,
        private ?string $storeName = null,
    ) {
        $this->manager = $manager === null ? null : \WeakReference::create($manager);
        $connection = $this->config = $config ?? CacheManager::resolveConfig((array) config('cache', []));
        $driver = strtolower((string) ($connection['driver'] ?? 'database'));

        $this->storage = match ($driver) {
            'redis' => new RedisStorage($name, $connection),
            'file' => new FileStorage($name, $connection, trackLocks: false),
            'database' => new DatabaseStorage($name, $connection, 'cache', trackLocks: false),
            default => throw new \InvalidArgumentException("Unsupported storage driver [{$driver}]. Use database, file or redis."),
        };
    }

    /**
     * Resolve a named cache repository shared within the current application.
     *
     * @param string $name The name of the cache repository.
     * @return Cache The application's cache instance for this namespace.
     */
    public static function make(string $name = 'default'): Cache
    {
        if (Application::$app !== null) {
            return Application::$app->get(CacheManager::class)->make($name);
        }
        return new Cache($name);
    }

    /** Select a namespace within this repository's configured store. */
    public function withNamespace(string $name): Cache
    {
        if ($name === $this->name) {
            return $this;
        }
        $manager = $this->manager?->get();
        if ($manager !== null && $this->storeName !== null && $manager->ownsRepository($this->storeName, $this->name, $this)) {
            return $manager->repository($this->storeName, $name);
        }
        return new Cache($name, $this->config);
    }

    /** Create an independent owner for one resource in this cache store and namespace. */
    public function lock(string $key, int $seconds = 10, ?string $owner = null): LockHandle
    {
        return new LockHandle($this->storage, $key, $seconds, $owner);
    }

    /** Restore a transferred owner token, typically to release a lock in another process. */
    public function restoreLock(string $key, string $owner): LockHandle
    {
        return $this->lock($key, owner: $owner);
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
     * Store a value with an optional expiration in seconds, a date expression, or a deadline.
     *
     * @param string $key The cache key.
     * @param mixed $data The value to store.
     * @param int|string|DateTimeInterface|Carbon|DateInterval|null $expire The expiration time.
     * @return self
     */
    public function store(string $key, mixed $data, int|string|DateTimeInterface|Carbon|DateInterval|null $expire = null): self
    {
        $this->storage->store($key, $data, Expiration::forStorage($expire));

        return $this;
    }

    /**
     * Retrieve a value or store the callback result when the key is missing.
     *
     * @param string $key The cache key.
     * @param callable $callback The callback to generate the value if missing.
     * @param int|string|DateTimeInterface|Carbon|DateInterval|null $expire The expiration time.
     * @return mixed The cached value or the result of the callback.
     */
    public function load(string $key, callable $callback, int|string|DateTimeInterface|Carbon|DateInterval|null $expire = null): mixed
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
     * Store multiple values with one shared expiration.
     *
     * @param array $items An associative array of key-value pairs to store.
     * @param int|string|DateTimeInterface|Carbon|DateInterval|null $expire The expiration time for all items.
     * @return self
     */
    public function storeMany(array $items, int|string|DateTimeInterface|Carbon|DateInterval|null $expire = null): self
    {
        $this->storage->storeMany($items, Expiration::forStorage($expire));

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
     * @param int|string|DateTimeInterface|Carbon|DateInterval|null $expire The expiration time.
     * @return bool True if the value was stored, false if the key already exists.
     */
    public function add(string $key, mixed $value, int|string|DateTimeInterface|Carbon|DateInterval|null $expire = null): bool
    {
        return $this->storage->add($key, $value, Expiration::forStorage($expire));
    }

    /**
     * Return a cached value or remember the callback result.
     *
     * @param string $key The cache key.
     * @param callable $callback The callback to generate the value if missing.
     * @param int|string|DateTimeInterface|Carbon|DateInterval|null $expire The expiration time.
     * @return mixed The cached value or the result of the callback.
     */
    public function remember(string $key, callable $callback, int|string|DateTimeInterface|Carbon|DateInterval|null $expire = null): mixed
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
        foreach ($items as $key => $item) {
            $items[$key]['expire'] = Expiration::forStorage($item['expire'] ?? null);
        }

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
     * Retrieve an item from the cache by key (alias of retrieve).
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->retrieve($key);
    }

    /**
     * Store an item in the cache (alias of store).
     *
     * @param string $key
     * @param mixed $data
     * @param int|string|DateTimeInterface|Carbon|DateInterval|null $expire
     * @return self
     */
    public function set(string $key, mixed $data, int|string|DateTimeInterface|Carbon|DateInterval|null $expire = null): self
    {
        return $this->store($key, $data, $expire);
    }

    /**
     * Store an item in the cache (alias of store).
     *
     * @param string $key
     * @param mixed $data
     * @param int|string|DateTimeInterface|Carbon|DateInterval|null $expire
     * @return self
     */
    public function put(string $key, mixed $data, int|string|DateTimeInterface|Carbon|DateInterval|null $expire = null): self
    {
        return $this->store($key, $data, $expire);
    }

    /**
     * Remove an item from the cache (alias of erase).
     *
     * @param string|array $keys
     * @return self
     */
    public function forget(string|array $keys): self
    {
        return $this->erase($keys);
    }

    /**
     * Delete an item from the cache (alias of erase).
     *
     * @param string|array $keys
     * @return self
     */
    public function delete(string|array $keys): self
    {
        return $this->erase($keys);
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

}
