<?php

namespace Spark\Cache\Storage;

use Generator;
use Spark\Cache\Contracts\CacheStorageContract;
use Spark\Cache\Exceptions\CacheException;
use function array_keys;
use function bin2hex;
use function chmod;
use function clearstatcache;
use function dirname;
use function fclose;
use function file_exists;
use function file_get_contents;
use function filemtime;
use function filesize;
use function flock;
use function fflush;
use function fopen;
use function function_exists;
use function fsync;
use function fwrite;
use function hash;
use function hash_equals;
use function is_array;
use function is_dir;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function md5;
use function microtime;
use function min;
use function mkdir;
use function octdec;
use function preg_match;
use function random_bytes;
use function rename;
use function rtrim;
use function scandir;
use function serialize;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtotime;
use function substr;
use function time;
use function touch;
use function trim;
use function unlink;
use function unserialize;
use function usleep;

/**
 * File based storage for cache entries and locks.
 *
 * Layout (one directory tree per cache name, sharded by the first two hex chars of sha256(key)):
 *
 *   <path>/<md5(name)>.cache.d/<xx>/<sha256(key)>.cache   cache entries
 *   <path>/<md5(name)>.cache.d/<xx>/.guard                per-shard guard file (flock target)
 *   <lock_path>/<md5(name)>.lock.d/<xx>/<sha256(key)>.lk  lock records
 *   <lock_path>/<md5(name)>.lock.d/<xx>/.guard            per-shard guard file
 *
 * Stability rules:
 *  - Every write goes to a unique temp file in the same directory and is moved into place with
 *    rename(), so a reader can only ever see the complete old file or the complete new file.
 *  - Every mutating / read-modify-write operation runs under an exclusive flock() on the shard's
 *    guard file. The guard file is never deleted or replaced, so its inode is stable (locking the
 *    data file itself would be racy because rename() swaps the inode).
 *  - flock() is always taken with LOCK_NB inside a bounded retry loop, so a stuck process can never
 *    block another one forever (and the loop stays friendly to coroutine runtimes).
 *  - Entries carry a key echo and a crc32 checksum; corrupt, truncated or foreign (hash collision)
 *    files are treated as a cache miss and swept by eraseExpired()/optimizeCache().
 *  - No user callback is ever executed while a guard is held, and guards are never nested.
 *
 * Supported config (all optional): path, lock_path, file_mode (0664), dir_mode (0775),
 * fsync (false), guard_timeout (5.0 seconds), gc_interval (300 seconds, 0 = sweep on every request).
 *
 * NOTE: flock() requires a local filesystem. Do not point this driver at NFS/SMB shares.
 */
class FileStorage implements CacheStorageContract
{
    private const ENTRY_EXT = '.cache';
    private const LOCK_EXT = '.lk';
    private const GUARD_FILE = '.guard';
    private const TMP_PREFIX = '.tmp-';
    private const TMP_MAX_AGE = 3600;

    private string $cacheRoot;

    private string $lockRoot;

    private int $fileMode;

    private int $dirMode;

    private bool $fsync;

    private float $guardTimeout;

    private int $gcInterval;

    /**
     * Locks acquired through this instance: owner => [key => true].
     *
     * An owner token is unique per Lock instance (host-pid-uniqid), and every Lock instance owns its
     * own storage instance, so this index is the complete set of records for that owner and lets
     * unlockAll() run in O(held locks) instead of scanning the whole lock directory.
     *
     * @var array<string, array<string, true>>
     */
    private array $held = [];

    public function __construct(
        private readonly string $name,
        private readonly array $config = [],
    ) {
        $cacheBase = $this->resolveDirectory($config['path'] ?? null);
        $lockBase = $this->resolveDirectory($config['lock_path'] ?? $config['path'] ?? null);
        $hash = md5($name);

        $this->cacheRoot = $cacheBase . DIRECTORY_SEPARATOR . $hash . '.cache.d';
        $this->lockRoot = $lockBase . DIRECTORY_SEPARATOR . $hash . '.lock.d';
        $this->fileMode = $this->mode($config['file_mode'] ?? 0664);
        $this->dirMode = $this->mode($config['dir_mode'] ?? 0775);
        $this->fsync = (bool) ($config['fsync'] ?? false);
        $this->guardTimeout = max(0.05, (float) ($config['guard_timeout'] ?? 5.0));
        $this->gcInterval = max(0, (int) ($config['gc_interval'] ?? 300));
    }

    /* ---------------------------------------------------------------------
     | Cache
     | ------------------------------------------------------------------ */

    public function has(string $key, bool $eraseExpired = false): bool
    {
        $eraseExpired && $this->sweepThrottled();

        [, $path] = $this->target($this->cacheRoot, $key, self::ENTRY_EXT);
        $entry = $this->readEntry($path, $key);

        if ($entry === null) {
            return false;
        }

        if ($this->isExpired($entry)) {
            $eraseExpired && $this->eraseIfExpired($key);
            return false;
        }

        return true;
    }

    public function store(string $key, mixed $data, null|string $expire = null): void
    {
        $payload = $this->encodeEntry($key, $data, $this->expireAt($expire));
        [$dir, $path] = $this->target($this->cacheRoot, $key, self::ENTRY_EXT);

        $this->withGuard($dir, fn() => $this->writeAtomic($dir, $path, $payload));
    }

    public function retrieve(string|array $keys, bool $eraseExpired = false): mixed
    {
        $eraseExpired && $this->sweepThrottled();

        if (is_array($keys)) {
            $results = [];

            foreach ($keys as $key) {
                $key = (string) $key;
                [, $path] = $this->target($this->cacheRoot, $key, self::ENTRY_EXT);
                $entry = $this->readEntry($path, $key);

                if ($entry !== null && !$this->isExpired($entry)) {
                    $results[$key] = $this->entryValue($entry);
                }
            }

            return $results;
        }

        [, $path] = $this->target($this->cacheRoot, $keys, self::ENTRY_EXT);
        $entry = $this->readEntry($path, $keys);

        return $entry !== null && !$this->isExpired($entry) ? $this->entryValue($entry) : null;
    }

    public function retrieveAll(bool $eraseExpired = false): array
    {
        $eraseExpired && $this->eraseExpired();

        $results = [];

        foreach ($this->files($this->cacheRoot, self::ENTRY_EXT) as $path) {
            $entry = $this->readEntry($path);

            if ($entry !== null && !$this->isExpired($entry)) {
                $results[$entry['key']] = $this->entryValue($entry);
            }
        }

        return $results;
    }

    public function erase(array $keys): void
    {
        foreach ($keys as $key) {
            [$dir, $path] = $this->target($this->cacheRoot, (string) $key, self::ENTRY_EXT);

            $this->withGuard($dir, function () use ($path) {
                @unlink($path);
            });
        }
    }

    public function eraseExpired(): int
    {
        $removed = 0;

        foreach ($this->files($this->cacheRoot, self::ENTRY_EXT) as $path) {
            $entry = $this->readEntry($path);

            // Valid and still active: nothing to do.
            if ($entry !== null && !$this->isExpired($entry)) {
                continue;
            }

            $removed += (int) $this->withGuard(dirname($path), function () use ($path) {
                // Re-check under the guard: a writer may have replaced the file in the meantime.
                $entry = $this->readEntry($path);

                if ($entry !== null && !$this->isExpired($entry)) {
                    return 0;
                }

                return file_exists($path) && @unlink($path) ? 1 : 0;
            });
        }

        return $removed;
    }

    public function getExpired(): array
    {
        $expired = [];

        foreach ($this->files($this->cacheRoot, self::ENTRY_EXT) as $path) {
            $entry = $this->readEntry($path);

            if ($entry !== null && $this->isExpired($entry)) {
                $expired[$entry['key']] = $this->entryValue($entry);
            }
        }

        return $expired;
    }

    public function flush(): void
    {
        foreach ($this->shards($this->cacheRoot) as $dir) {
            $this->withGuard($dir, function () use ($dir) {
                foreach ($this->listDir($dir) as $file) {
                    if (str_ends_with($file, self::ENTRY_EXT)) {
                        @unlink($dir . DIRECTORY_SEPARATOR . $file);
                    }
                }
            });
        }
    }

    public function clear(): void
    {
        $this->flush();
        $this->removeStaleTempFiles($this->cacheRoot);
    }

    public function storeMany(array $items, null|string $expire = null): void
    {
        if ($items === []) {
            return;
        }

        try {
            foreach ($items as $key => $value) {
                $this->store((string) $key, $value, $expire);
            }
        } catch (CacheException $e) {
            throw new CacheException('Failed to store multiple items: ' . $e->getMessage(), previous: $e);
        }
    }

    public function increment(string $key, int $amount = 1): int|false
    {
        [$dir, $path] = $this->target($this->cacheRoot, $key, self::ENTRY_EXT);

        try {
            return $this->withGuard($dir, function () use ($dir, $path, $key, $amount) {
                $entry = $this->readEntry($path, $key);

                if ($entry === null || $this->isExpired($entry)) {
                    return false;
                }

                $value = $this->entryValue($entry);
                if ($value === null || !is_numeric($value)) {
                    return false;
                }

                $newValue = (int) $value + $amount;

                // Keep the existing expiration, refresh created_at (same as the SQLite driver).
                $this->writeAtomic($dir, $path, $this->encodeEntry($key, $newValue, $entry['expire_at']));

                return $newValue;
            });
        } catch (CacheException) {
            return false;
        }
    }

    public function add(string $key, mixed $value, null|string $expire = null): bool
    {
        $payload = $this->encodeEntry($key, $value, $this->expireAt($expire));
        [$dir, $path] = $this->target($this->cacheRoot, $key, self::ENTRY_EXT);

        return (bool) $this->withGuard($dir, function () use ($dir, $path, $key, $payload) {
            $existing = $this->readEntry($path, $key);

            if ($existing !== null && !$this->isExpired($existing)) {
                return false;
            }

            $this->writeAtomic($dir, $path, $payload);

            return true;
        });
    }

    public function stats(): array
    {
        $total = 0;
        $expired = 0;
        $size = 0;

        foreach ($this->files($this->cacheRoot, self::ENTRY_EXT) as $path) {
            $entry = $this->readEntry($path);

            if ($entry === null) {
                continue;
            }

            $total++;
            $this->isExpired($entry) && $expired++;
            $size += (int) @filesize($path);
        }

        return [
            'total_entries' => $total,
            'active_entries' => $total - $expired,
            'expired_entries' => $expired,
            'database_size' => $size,
        ];
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        [$dir, $path] = $this->target($this->cacheRoot, $key, self::ENTRY_EXT);

        try {
            return $this->withGuard($dir, function () use ($path, $key, $default) {
                $entry = $this->readEntry($path, $key);

                if ($entry === null || $this->isExpired($entry)) {
                    return $default;
                }

                @unlink($path);

                return $this->entryValue($entry);
            });
        } catch (CacheException) {
            return $default;
        }
    }

    public function storeManyWithExpiry(array $items): void
    {
        if ($items === []) {
            return;
        }

        try {
            foreach ($items as $key => $config) {
                if (!is_array($config)) {
                    continue;
                }

                $this->store((string) $key, $config['value'] ?? null, $config['expire'] ?? null);
            }
        } catch (CacheException $e) {
            throw new CacheException('Failed to store items with expiry: ' . $e->getMessage(), previous: $e);
        }
    }

    public function ttl(string $key): ?int
    {
        if (!$this->has($key)) {
            return null;
        }

        [, $path] = $this->target($this->cacheRoot, $key, self::ENTRY_EXT);
        $entry = $this->readEntry($path, $key);

        if ($entry === null) {
            return null;
        }

        $expire_at = $entry['expire_at'];

        if ($expire_at === null || (int) $expire_at === 0) {
            return null;
        }

        $ttl = (int) $expire_at - time();

        return $ttl > 0 ? $ttl : 0;
    }

    public function optimizeCache(): void
    {
        try {
            $this->eraseExpired();
            $this->removeStaleTempFiles($this->cacheRoot);
        } catch (CacheException) {
            // Ignore optimize errors.
        }
    }

    /* ---------------------------------------------------------------------
     | Locks
     | ------------------------------------------------------------------ */

    public function lock(string $key, string $owner, int $timeout = 10, int $waitTimeout = 5): bool
    {
        $timeout = max(1, $timeout);
        $waitTimeout = max(0, $waitTimeout);
        $deadline = microtime(true) + $waitTimeout;
        [$dir, $path] = $this->target($this->lockRoot, $key, self::LOCK_EXT);
        $sleep = 5000;

        while (true) {
            if ($this->tryAcquire($dir, $path, $key, $owner, $timeout)) {
                $this->held[$owner][$key] = true;
                return true;
            }

            if ($waitTimeout === 0 || microtime(true) >= $deadline) {
                return false;
            }

            // Backoff with jitter (5ms -> 50ms) so waiters do not stampede the guard.
            usleep($sleep + random_int(0, 2000));
            $sleep = min($sleep * 2, 50000);
        }
    }

    public function unlock(string $key, string $owner): bool
    {
        [$dir, $path] = $this->target($this->lockRoot, $key, self::LOCK_EXT);

        unset($this->held[$owner][$key]);

        try {
            return (bool) $this->withGuard($dir, function () use ($path, $key, $owner) {
                $record = $this->readLock($path, $key);

                if ($record === null || $record['owner'] !== $owner) {
                    return false;
                }

                return @unlink($path) || !file_exists($path);
            });
        } catch (CacheException) {
            return false;
        }
    }

    public function unlockAll(string $owner): int
    {
        $released = 0;

        foreach (array_keys($this->held[$owner] ?? []) as $key) {
            $this->unlock((string) $key, $owner) && $released++;
        }

        unset($this->held[$owner]);

        return $released;
    }

    public function isLocked(string $key): bool
    {
        [, $path] = $this->target($this->lockRoot, $key, self::LOCK_EXT);
        $record = $this->readLock($path, $key);

        return $record !== null && $record['expire_at'] > time();
    }

    public function ownsLock(string $key, string $owner): bool
    {
        [, $path] = $this->target($this->lockRoot, $key, self::LOCK_EXT);
        $record = $this->readLock($path, $key);

        return $record !== null && $record['owner'] === $owner && $record['expire_at'] > time();
    }

    public function releaseExpiredLocks(): int
    {
        $released = 0;

        try {
            foreach ($this->files($this->lockRoot, self::LOCK_EXT) as $path) {
                $record = $this->readLock($path);

                if ($record !== null && $record['expire_at'] > time()) {
                    continue;
                }

                $released += (int) $this->withGuard(dirname($path), function () use ($path) {
                    $record = $this->readLock($path);

                    if ($record !== null && $record['expire_at'] > time()) {
                        return 0;
                    }

                    return file_exists($path) && @unlink($path) ? 1 : 0;
                });
            }
        } catch (CacheException) {
            // Best effort, same as the other drivers.
        }

        return $released;
    }

    public function extendLock(string $key, string $owner, int $additionalSeconds): bool
    {
        [$dir, $path] = $this->target($this->lockRoot, $key, self::LOCK_EXT);

        try {
            return (bool) $this->withGuard($dir, function () use ($dir, $path, $key, $owner, $additionalSeconds) {
                $record = $this->readLock($path, $key);

                if ($record === null || $record['owner'] !== $owner || $record['expire_at'] <= time()) {
                    return false;
                }

                $this->writeAtomic($dir, $path, $this->encodeLock(
                    $key,
                    $owner,
                    $record['expire_at'] + max(1, $additionalSeconds),
                ));

                return true;
            });
        } catch (CacheException) {
            return false;
        }
    }

    public function getLockInfo(string $key): ?array
    {
        [, $path] = $this->target($this->lockRoot, $key, self::LOCK_EXT);
        $record = $this->readLock($path, $key);

        if ($record === null || $record['expire_at'] <= time()) {
            return null;
        }

        return [
            'owner' => $record['owner'],
            'expire_at' => $record['expire_at'],
        ];
    }

    public function forceUnlock(string $key): bool
    {
        [$dir, $path] = $this->target($this->lockRoot, $key, self::LOCK_EXT);

        try {
            return (bool) $this->withGuard($dir, fn() => file_exists($path) && @unlink($path));
        } catch (CacheException) {
            return false;
        }
    }

    public function optimizeLocks(): void
    {
        $this->releaseExpiredLocks();

        try {
            $this->removeStaleTempFiles($this->lockRoot);
        } catch (CacheException) {
            // Ignore optimize errors.
        }
    }

    /* ---------------------------------------------------------------------
     | Internals: locking primitives
     | ------------------------------------------------------------------ */

    /**
     * Try to create or take over a lock record. Returns false when another owner holds an active
     * lock or when the guard cannot be obtained in time (treated as contention).
     */
    private function tryAcquire(string $dir, string $path, string $key, string $owner, int $timeout): bool
    {
        try {
            return (bool) $this->withGuard($dir, function () use ($dir, $path, $key, $owner, $timeout) {
                $now = time();
                $record = $this->readLock($path, $key);

                if ($record !== null && $record['expire_at'] > $now) {
                    return false;
                }

                $this->writeAtomic($dir, $path, $this->encodeLock($key, $owner, $now + $timeout));

                return true;
            });
        } catch (CacheException) {
            return false;
        }
    }

    /**
     * Run a callback while holding an exclusive flock() on the shard guard file.
     *
     * Never call this re-entrantly for the same shard and never run user code inside the callback:
     * two descriptors in one process contend with each other and would only time out.
     */
    private function withGuard(string $dir, callable $callback): mixed
    {
        $this->ensureDirectory($dir);

        $handle = @fopen($dir . DIRECTORY_SEPARATOR . self::GUARD_FILE, 'c+');
        if ($handle === false) {
            throw new CacheException("Unable to open cache guard file in [{$dir}].");
        }

        try {
            $deadline = microtime(true) + $this->guardTimeout;
            $sleep = 200;

            while (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if (!$wouldBlock) {
                    throw new CacheException("Unable to lock cache guard file in [{$dir}].");
                }

                if (microtime(true) >= $deadline) {
                    throw new CacheException("Timed out waiting for cache guard in [{$dir}].");
                }

                usleep($sleep);
                $sleep = min($sleep * 2, 5000);
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Write a file atomically: unique temp file in the same directory, optional fsync, then rename().
     */
    private function writeAtomic(string $dir, string $path, string $contents): void
    {
        $tmp = $dir . DIRECTORY_SEPARATOR . self::TMP_PREFIX . bin2hex(random_bytes(8));
        $handle = @fopen($tmp, 'xb');

        if ($handle === false) {
            throw new CacheException("Unable to create temporary cache file in [{$dir}].");
        }

        try {
            $length = strlen($contents);
            $written = 0;

            while ($written < $length) {
                $bytes = @fwrite($handle, substr($contents, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new CacheException("Failed writing cache file [{$path}] (disk full?).");
                }

                $written += $bytes;
            }

            fflush($handle);
            $this->fsync && function_exists('fsync') && @fsync($handle);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }

        fclose($handle);
        @chmod($tmp, $this->fileMode);

        // rename() is atomic on POSIX. On Windows it can fail briefly while a reader holds the target
        // open, so retry a few times before giving up.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            if (@rename($tmp, $path)) {
                return;
            }

            usleep(10000 * $attempt);
            clearstatcache(true, $path);
        }

        @unlink($tmp);

        throw new CacheException("Unable to move cache file into place [{$path}].");
    }

    /* ---------------------------------------------------------------------
     | Internals: entry / lock record format
     | ------------------------------------------------------------------ */

    private function encodeEntry(string $key, mixed $data, int $expireAt): string
    {
        $value = serialize($data);

        return serialize([
            'k' => $key,
            'v' => $value,
            'e' => $expireAt,
            'h' => hash('crc32b', $value),
        ]);
    }

    /**
     * Read and validate an entry. Returns null for missing, truncated, corrupt or foreign files.
     *
     * @return null|array{key: string, raw: string, expire_at: int}
     */
    private function readEntry(string $path, ?string $key = null): ?array
    {
        $raw = @file_get_contents($path);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $payload = @unserialize($raw, ['allowed_classes' => false]);

        if (
            !is_array($payload)
            || !isset($payload['k'], $payload['v'], $payload['h'])
            || !is_string($payload['k'])
            || !is_string($payload['v'])
            || !is_string($payload['h'])
            || !hash_equals($payload['h'], hash('crc32b', $payload['v']))
        ) {
            return null;
        }

        // Guards against sha256 path collisions and hand-edited files.
        if ($key !== null && $payload['k'] !== $key) {
            return null;
        }

        return [
            'key' => $payload['k'],
            'raw' => $payload['v'],
            'expire_at' => (int) ($payload['e'] ?? 0),
        ];
    }

    private function entryValue(array $entry): mixed
    {
        return @unserialize($entry['raw']);
    }

    private function isExpired(array $entry): bool
    {
        return $entry['expire_at'] > 0 && $entry['expire_at'] <= time();
    }

    private function eraseIfExpired(string $key): void
    {
        [$dir, $path] = $this->target($this->cacheRoot, $key, self::ENTRY_EXT);

        try {
            $this->withGuard($dir, function () use ($path, $key) {
                $entry = $this->readEntry($path, $key);

                if ($entry !== null && $this->isExpired($entry)) {
                    @unlink($path);
                }
            });
        } catch (CacheException) {
            // Best effort: an expired entry is already reported as missing.
        }
    }

    private function encodeLock(string $key, string $owner, int $expireAt): string
    {
        return (string) json_encode([
            'key' => $key,
            'owner' => $owner,
            'expire_at' => $expireAt,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return null|array{key: string, owner: string, expire_at: int}
     */
    private function readLock(string $path, ?string $key = null): ?array
    {
        $raw = @file_get_contents($path);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (
            !is_array($data)
            || !isset($data['key'], $data['owner'])
            || !is_string($data['key'])
            || !is_string($data['owner'])
            || !is_int($data['expire_at'] ?? null)
        ) {
            return null;
        }

        if ($key !== null && $data['key'] !== $key) {
            return null;
        }

        return [
            'key' => $data['key'],
            'owner' => $data['owner'],
            'expire_at' => $data['expire_at'],
        ];
    }

    /* ---------------------------------------------------------------------
     | Internals: filesystem helpers
     | ------------------------------------------------------------------ */

    /**
     * @return array{0: string, 1: string} Shard directory and full file path for a key.
     */
    private function target(string $root, string $key, string $extension): array
    {
        $hash = hash('sha256', $key);
        $dir = $root . DIRECTORY_SEPARATOR . substr($hash, 0, 2);

        return [$dir, $dir . DIRECTORY_SEPARATOR . $hash . $extension];
    }

    /**
     * @return Generator<int, string> Shard directory paths that exist under a root.
     */
    private function shards(string $root): Generator
    {
        if (!is_dir($root)) {
            return;
        }

        foreach ($this->listDir($root) as $name) {
            if (preg_match('/^[0-9a-f]{2}$/', $name) === 1 && is_dir($root . DIRECTORY_SEPARATOR . $name)) {
                yield $root . DIRECTORY_SEPARATOR . $name;
            }
        }
    }

    /**
     * @return Generator<int, string> Full paths of every file with the given extension under a root.
     */
    private function files(string $root, string $extension): Generator
    {
        foreach ($this->shards($root) as $dir) {
            foreach ($this->listDir($dir) as $file) {
                if (str_ends_with($file, $extension)) {
                    yield $dir . DIRECTORY_SEPARATOR . $file;
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private function listDir(string $dir): array
    {
        $items = @scandir($dir);

        if ($items === false) {
            return [];
        }

        return array_values(array_diff($items, ['.', '..']));
    }

    private function removeStaleTempFiles(string $root): void
    {
        $limit = time() - self::TMP_MAX_AGE;

        foreach ($this->shards($root) as $dir) {
            foreach ($this->listDir($dir) as $file) {
                if (!str_starts_with($file, self::TMP_PREFIX)) {
                    continue;
                }

                $path = $dir . DIRECTORY_SEPARATOR . $file;
                $mtime = @filemtime($path);

                if ($mtime !== false && $mtime < $limit) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Run eraseExpired() at most once per gc_interval seconds.
     *
     * Cache::load() passes $eraseExpired = true on every call that has an expiration, and a full
     * directory sweep on every call would be far too slow for a file driver.
     */
    private function sweepThrottled(): void
    {
        if ($this->gcInterval === 0) {
            $this->eraseExpired();
            return;
        }

        $marker = $this->cacheRoot . DIRECTORY_SEPARATOR . '.gc';
        clearstatcache(true, $marker);
        $mtime = @filemtime($marker);

        if ($mtime !== false && time() - $mtime < $this->gcInterval) {
            return;
        }

        $this->ensureDirectory($this->cacheRoot);
        @touch($marker);

        $this->eraseExpired();
    }

    private function ensureDirectory(string $directory): void
    {
        if ($directory === '' || is_dir($directory)) {
            return;
        }

        // Another process may create it between the check and mkdir(), so re-check on failure.
        if (!@mkdir($directory, $this->dirMode, true) && !is_dir($directory)) {
            throw new CacheException("Unable to create cache directory [{$directory}].");
        }
    }

    private function resolveDirectory(mixed $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            $path = (string) storage_dir('cache');
        }

        return rtrim($path, '/\\');
    }

    private function mode(mixed $mode): int
    {
        if (is_string($mode)) {
            return (int) octdec(ltrim($mode, '0') ?: '0');
        }

        return (int) $mode;
    }

    private function expireAt(null|string $expire): int
    {
        if ($expire === null) {
            return 0;
        }

        $expireAt = strtotime($expire);

        return $expireAt === false ? 0 : (int) $expireAt;
    }
}