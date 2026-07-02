<?php

namespace Spark\Cache\Contracts;

/**
 * Driver contract shared by cache and lock storage backends.
 *
 * Cache and Lock both use the configured cache connection, so SQLite and Redis
 * storage drivers expose the complete persistence surface used by both public
 * repositories.
 */
interface CacheStorageContract
{
    public function has(string $key, bool $eraseExpired = false): bool;

    public function store(string $key, mixed $data, ?string $expire = null): void;

    public function retrieve(string|array $keys, bool $eraseExpired = false): mixed;

    public function metadata(string $key): ?array;

    public function retrieveAll(bool $eraseExpired = false): array;

    public function erase(array $keys): void;

    public function eraseExpired(): int;

    public function getExpired(): array;

    public function flush(): void;

    public function clear(): void;

    public function storeMany(array $items, ?string $expire = null): void;

    public function increment(string $key, int $amount = 1): int|false;

    public function add(string $key, mixed $value, ?string $expire = null): bool;

    public function stats(): array;

    public function pull(string $key, mixed $default = null): mixed;

    public function storeManyWithExpiry(array $items): void;

    public function ttl(string $key): ?int;

    public function optimizeCache(): void;

    public function lock(string $key, string $owner, int $timeout = 10, int $waitTimeout = 5): bool;

    public function unlock(string $key, string $owner): bool;

    public function unlockAll(string $owner): int;

    public function isLocked(string $key): bool;

    public function ownsLock(string $key, string $owner): bool;

    public function releaseExpiredLocks(): int;

    public function extendLock(string $key, string $owner, int $additionalSeconds): bool;

    public function getLockInfo(string $key): ?array;

    public function forceUnlock(string $key): bool;

    public function optimizeLocks(): void;
}
