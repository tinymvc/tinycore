<?php

namespace Spark\Cache;

use Spark\Cache\Contracts\LockContract;
use Spark\Cache\Contracts\CacheStorageContract;
use Spark\Cache\Exceptions\LockException;
use Spark\Cache\Storage\RedisStorage;
use Spark\Cache\Storage\SqliteStorage;
use Spark\Support\Traits\Macroable;
use Spark\Utils\RedisConnector;
use function gethostname;
use function getmypid;
use function is_array;
use function sprintf;
use function strtolower;
use function uniqid;

/**
 * Public lock manager for the configured cache lock driver.
 *
 * This class owns the process-specific lock owner token and delegates SQLite
 * or Redis persistence details to storage classes.
 *
 * @implements \ArrayAccess<string, mixed>
 */
class Lock implements LockContract, \ArrayAccess
{
    use Macroable;

    private string $owner;

    private CacheStorageContract $storage;

    /**
     * Create a named lock manager.
     *
     * The name isolates lock storage in the same way cache names isolate cache
     * data: separate SQLite files or Redis namespaces per name.
     */
    public function __construct(string $name = 'default')
    {
        $connection = $this->resolveDriverConfig($name);
        $driver = strtolower((string) ($connection['driver'] ?? 'sqlite'));
        $this->storage = $driver === 'redis'
            ? new RedisStorage($name, $connection)
            : new SqliteStorage($name, $connection, 'lock');

        $this->owner = sprintf('%s-%s-%s', gethostname(), getmypid(), uniqid('', true));
    }

    /**
     * Create a named lock manager instance.
     *
     * This is a convenience method for creating a new instance without using the `new` keyword.
     *
     * @param string $name The name of the lock manager.
     * @return static A new instance of the Lock class.
     */
    public static function make(string $name = 'default'): static
    {
        return new static($name);
    }

    /**
     * Attempt to acquire a lock for the current owner.
     *
     * @param string $key The lock key to acquire.
     * @param int $timeout The lock expiration time in seconds (default is 10).
     * @param int $waitTimeout The maximum time to wait for the lock in seconds (default is 5).
     * @return bool True if the lock was acquired, false otherwise.
     */
    public function lock(string $key, int $timeout = 10, int $waitTimeout = 5): bool
    {
        return $this->storage->lock($key, $this->owner, $timeout, $waitTimeout);
    }

    /**
     * Release a lock only when it belongs to the current owner.
     *
     * @param string $key The lock key to release.
     * @return bool True if the lock was released, false otherwise.
     */
    public function unlock(string $key): bool
    {
        return $this->storage->unlock($key, $this->owner);
    }

    /**
     * Release all locks owned by this instance.
     *
     * @return int The number of locks released.
     */
    public function unlockAll(): int
    {
        return $this->storage->unlockAll($this->owner);
    }

    /**
     * Determine whether a lock key is currently held by any owner.
     *
     * @param string $key The lock key to check.
     * @return bool True if the lock is held, false otherwise.
     */
    public function isLocked(string $key): bool
    {
        return $this->storage->isLocked($key);
    }

    /**
     * Determine whether this instance owns the lock key.
     *
     * @param string $key The lock key to check.
     * @return bool True if this instance owns the lock, false otherwise.
     */
    public function ownsLock(string $key): bool
    {
        return $this->storage->ownsLock($key, $this->owner);
    }

    /**
     * Release expired lock records.
     *
     * @return int The number of expired locks released.
     */
    public function releaseExpiredLocks(): int
    {
        return $this->storage->releaseExpiredLocks();
    }

    /**
     * Extend a lock owned by this instance.
     *
     * @param string $key The lock key to extend.
     * @param int $additionalSeconds The additional time in seconds to extend the lock.
     * @return bool True if the lock was extended, false otherwise.
     */
    public function extendLock(string $key, int $additionalSeconds): bool
    {
        return $this->storage->extendLock($key, $this->owner, $additionalSeconds);
    }

    /**
     * Execute a callback while holding a lock.
     *
     * @param string $key The lock key to acquire.
     * @param callable $callback The callback to execute while holding the lock.
     * @param int $timeout The lock expiration time in seconds (default is 10).
     * @param int $waitTimeout The maximum time to wait for the lock in seconds (default is 5).
     * @return mixed The result of the callback execution.
     * @throws LockException If the lock cannot be acquired.
     */
    public function withLock(string $key, callable $callback, int $timeout = 10, int $waitTimeout = 5): mixed
    {
        if (!$this->lock($key, $timeout, $waitTimeout)) {
            throw new LockException("Failed to acquire lock for key: {$key}");
        }

        try {
            return $callback($this);
        } finally {
            $this->unlock($key);
        }
    }

    /**
     * Get the process-specific owner token.
     */
    public function getLockOwner(): string
    {
        return $this->owner;
    }

    /**
     * Return owner and expiration metadata for a lock key.
     *
     * @param string $key The lock key to retrieve information for.
     * @return null|array An associative array with 'owner' and 'expires_at'
     */
    public function getLockInfo(string $key): null|array
    {
        return $this->storage->getLockInfo($key);
    }

    /**
     * Release a lock without checking ownership.
     *
     * @param string $key The lock key to forcefully release.
     * @return bool True if the lock was released, false otherwise.
     */
    public function forceUnlock(string $key): bool
    {
        return $this->storage->forceUnlock($key);
    }

    /**
     * Optimize lock storage by clearing expired lock records.
     */
    public function optimize(): self
    {
        $this->storage->optimizeLocks();

        return $this;
    }

    /**
     * Best-effort cleanup of locks owned by this instance.
     */
    public function __destruct()
    {
        try {
            $this->unlockAll();
        } catch (\Throwable) {
            // Ignore cleanup errors.
        }
    }

    /**
     * Determine whether a lock exists for ArrayAccess.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->isLocked((string) $offset);
    }

    /**
     * Return lock metadata for ArrayAccess.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->getLockInfo((string) $offset);
    }

    /**
     * Acquire a lock for ArrayAccess.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_array($value)) {
            $this->lock((string) $offset, (int) ($value['timeout'] ?? 10), (int) ($value['waitTimeout'] ?? 5));
            return;
        }

        $this->lock((string) $offset, 10, 5);
    }

    /**
     * Release a lock for ArrayAccess.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->unlock((string) $offset);
    }

    /**
     * Resolve the configured cache driver and connection settings.
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
