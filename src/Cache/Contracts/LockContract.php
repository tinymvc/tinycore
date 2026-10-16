<?php

namespace Spark\Cache\Contracts;

/**
 * Contract for lock utility operations.
 */
interface LockContract
{
    /**
     * Attempts to acquire a lock for the given key.
     *
     * @param string $key The lock key.
     * @param int $timeout The lock timeout in seconds.
     * @param int $waitTimeout The maximum time to wait for the lock in seconds.
     * @return bool True if the lock was acquired, false otherwise.
     */
    public function lock(string $key, int $timeout = 10, int $waitTimeout = 5): bool;

    /**
     * Releases the lock for the given key.
     *
     * @param string $key The lock key.
     * @return bool True if the lock was released, false otherwise.
     */
    public function unlock(string $key): bool;

    /**
     * Checks if the given key is currently locked.
     *
     * @param string $key The lock key.
     * @return bool True if the key is locked, false otherwise.
     */
    public function isLocked(string $key): bool;

    /**
     * Checks if the current process owns the lock for the given key.
     *
     * @param string $key The lock key.
     * @return bool True if the current process owns the lock, false otherwise.
     */
    public function ownsLock(string $key): bool;

    /**
     * Extends the lock for the given key by the specified additional seconds.
     *
     * @param string $key The lock key.
     * @param int $additionalSeconds The number of additional seconds to extend the lock.
     * @return bool True if the lock was successfully extended, false otherwise.
     */
    public function extendLock(string $key, int $additionalSeconds): bool;

    /**
     * Forcefully releases the lock for the given key, regardless of ownership.
     *
     * @param string $key The lock key.
     * @return bool True if the lock was forcefully released, false otherwise.
     */
    public function forceUnlock(string $key): bool;

    /**
     * Execute a callback while holding the acquired lock, then release it.
     *
     * @param string $key
     * @param callable $callback
     * @param int $timeout
     * @param int $waitTimeout
     * @return mixed
     */
    public function withLock(string $key, callable $callback, int $timeout = 10, int $waitTimeout = 5): mixed;

    /**
     * Releases all locks held by the current process/owner.
     *
     * @return int Number of locks released.
     */
    public function unlockAll(): int;

    /**
     * Get the current lock owner identifier.
     *
     * @return string
     */
    public function getLockOwner(): string;

    /**
     * Get information about a lock.
     *
     * @param string $key
     * @return array|null
     */
    public function getLockInfo(string $key): ?array;
}
