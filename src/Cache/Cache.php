<?php

namespace Spark\Cache;

use PDO;
use PDOStatement;
use RuntimeException;
use Spark\Contracts\Cache\CacheContract;
use Spark\Exceptions\Cache\CacheException;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use Spark\Utils\RedisConnector;
use function array_key_exists;
use function array_map;
use function count;
use function dirname;
use function func_get_args;
use function is_array;
use function is_dir;
use function is_string;
use function max;
use function md5;
use function mkdir;
use function pathinfo;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function time;
use function trim;

/**
 * Class Cache
 *
 * SQLite or Redis based cache with automatic driver detection.
 *
 * @package Spark\Cache
 */
class Cache implements CacheContract, \ArrayAccess
{
    use Macroable, Conditionable;

    /** @var PDO The PDO connection instance for sqlite. */
    private ?PDO $pdo = null;

    /** @var \Redis The Redis connection for redis driver. */
    private ?\Redis $redis = null;

    /** @var array Cached prepared statements */
    private array $statements = [];

    /** @var string */
    private string $name;

    /** @var string */
    private string $driver = 'sqlite';

    /** @var array */
    private array $connection = [];

    /** @var string */
    private string $redisKeyPrefix = 'cache';

    public function __construct(string $name = 'default')
    {
        $this->name = $name;
        $this->connection = $this->resolveDriverConfig($name);
        $this->driver = strtolower((string) ($this->connection['driver'] ?? 'sqlite'));

        if ($this->driver === 'redis') {
            $this->initializeRedis();
            return;
        }

        $this->initializeSqlite($name, $this->connection);
    }

    /**
     * Factory method to create a Cache instance.
     *
     * @param string $name The name for the cache instance (used for cache file naming).
     * @return Cache The Cache instance.
     */
    public static function make(string $name = 'default'): Cache
    {
        return new self($name);
    }

    /**
     * Creates sqlite tables for cache metadata.
     */
    private function initializeSqlite(string $name, array $config): void
    {
        try {
            $cache = $this->sqliteCachePath($name, $config);
            $this->pdo = new PDO("sqlite:$cache");

            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $this->pdo->exec("PRAGMA journal_mode = WAL");
            $this->pdo->exec("PRAGMA synchronous = NORMAL");
            $this->pdo->exec("PRAGMA cache_size = 10000");
            $this->pdo->exec("PRAGMA temp_store = MEMORY");

            $this->createTables();
        } catch (\PDOException $e) {
            throw new RuntimeException('Failed to connect to SQLite database: ' . $e->getMessage());
        }
    }

    /**
     * Creates the Redis connection.
     */
    private function initializeRedis(): void
    {
        $config = RedisConnector::resolveConnectionConfig($this->connection);

        $this->redis = RedisConnector::make($config, $this->name);

        $prefix = trim((string) ($config['prefix'] ?? 'spark'));
        if ($prefix === '') {
            $prefix = 'spark';
        }
        $prefix = trim($prefix, ':');
        $this->redisKeyPrefix = sprintf('%s:cache:%s:', $prefix, md5($this->name));
    }

    /**
     * Reads cache driver and driver-specific configuration.
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

    /**
     * Resolves the SQLite cache database path.
     *
     * The recommended config value is a cache directory:
     * cache.connections.sqlite.path => /path/to/storage/cache
     */
    private function sqliteCachePath(string $name, array $config): string
    {
        $path = (string) ($config['path'] ?? storage_dir('cache'));
        if ($path === '') {
            $path = storage_dir('cache');
        }

        if ($this->looksLikeDirectoryPath($path)) {
            $this->ensureDirectory($path);
            return $this->normalizePath($path . DIRECTORY_SEPARATOR . md5($name) . '.cache');
        }

        $this->ensureDirectory(dirname($path));
        return $this->normalizePath($path);
    }

    private function looksLikeDirectoryPath(string $path): bool
    {
        return is_dir($path)
            || str_ends_with($path, '/')
            || str_ends_with($path, '\\')
            || pathinfo($path, PATHINFO_EXTENSION) === '';
    }

    private function ensureDirectory(string $directory): void
    {
        if ($directory !== '' && !is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace(['//', '\\\\', '/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    /**
     * Creates the necessary tables and indexes for caching and locking.
     *
     * @return void
     */
    private function createTables(): void
    {
        if (!$this->pdo) {
            return;
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS caches (
            key TEXT PRIMARY KEY,
            data BLOB NOT NULL,
            created_at INTEGER NOT NULL,
            expire_at INTEGER DEFAULT 0,
            CHECK (expire_at >= 0)
        )");

        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_key ON caches(key)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_created ON caches(created_at)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_expire ON caches(expire_at) WHERE expire_at > 0");
    }

    /**
     * Get or prepare a cached statement.
     */
    protected function statement(string $key, string $sql): PDOStatement
    {
        if (!$this->pdo) {
            throw new RuntimeException('SQLite statements are not available for redis cache.');
        }

        return $this->statements[$key] ??= $this->pdo->prepare($sql);
    }

    private function isRedis(): bool
    {
        return $this->driver === 'redis' && $this->redis instanceof \Redis;
    }

    private function cacheKey(string $key): string
    {
        return $this->redisKeyPrefix . $key;
    }

    private function packRedisValue(string $key, mixed $data, ?string $expire): array
    {
        $now = time();
        $expireAt = $expire !== null ? strtotime($expire) : 0;
        $expireAt = $expireAt === false ? 0 : (int) $expireAt;

        $payload = [
            'key' => $key,
            'value' => serialize($data),
            'created_at' => $now,
            'expire_at' => (int) $expireAt,
        ];

        return [
            'payload' => serialize($payload),
            'expireAt' => (int) $expireAt,
        ];
    }

    private function unpackRedisValue(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        /** @var array|false $payload */
        $payload = @unserialize($value);
        if (!is_array($payload) || !array_key_exists('value', $payload) || !array_key_exists('created_at', $payload)) {
            return null;
        }

        $decoded = @unserialize((string) $payload['value']);

        return [
            'value' => $decoded,
            'created_at' => (int) ($payload['created_at'] ?? 0),
            'expire_at' => (int) ($payload['expire_at'] ?? 0),
        ];
    }

    private function scanRedisKeys(string $pattern, callable $callback): void
    {
        if (!$this->redis) {
            return;
        }

        $cursor = 0;
        do {
            $keys = $this->redis->scan($cursor, $pattern);
            if ($keys === false) {
                break;
            }

            foreach ($keys as $key) {
                $callback($key);
            }
        } while ($cursor > 0);
    }

    private function cleanupExpiredRedis(): int
    {
        if (!$this->redis) {
            return 0;
        }

        $now = time();
        $deleted = 0;
        $expiredKeys = [];

        $this->scanRedisKeys($this->cachePrefixPattern(), function (string $redisKey) use ($now, &$expiredKeys) {
            $packed = $this->redis?->get($redisKey);
            $unpacked = $this->unpackRedisValue($packed);

            if ($unpacked === null || ($unpacked['expire_at'] !== 0 && $unpacked['expire_at'] < $now)) {
                $expiredKeys[] = $redisKey;
            }
        });

        if ($expiredKeys !== []) {
            $deleted = $this->redis->del($expiredKeys);
        }

        return (int) $deleted;
    }

    private function cachePrefixPattern(): string
    {
        return $this->redisKeyPrefix . '*';
    }

    /**
     * Checks if a cache key exists and optionally erases expired entries.
     */
    public function has(string $key, bool $eraseExpired = false): bool
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return false;
            }

            $redisKey = $this->cacheKey($key);
            $raw = $this->redis->get($redisKey);

            if ($raw === false) {
                return false;
            }

            $payload = $this->unpackRedisValue($raw);
            if (!is_array($payload)) {
                return false;
            }

            if ($payload['expire_at'] > 0 && $payload['expire_at'] <= time()) {
                if ($eraseExpired) {
                    $this->redis->del($redisKey);
                }

                return false;
            }

            return true;
        }

        $eraseExpired && $this->eraseExpired();

        $stmt = $this->statement(
            'has',
            'SELECT 1 FROM caches WHERE key = ? AND (expire_at = 0 OR expire_at > ?)'
        );

        $stmt->execute([$key, time()]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Stores data in the cache with an optional expiration time.
     */
    public function store(string $key, mixed $data, null|string $expire = null): self
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return $this;
            }

            $packed = $this->packRedisValue($key, $data, $expire);
            $redisKey = $this->cacheKey($key);
            $this->redis->set($redisKey, $packed['payload']);

            if ($packed['expireAt'] > 0) {
                $this->redis->expire($redisKey, max(1, $packed['expireAt'] - time()));
            }

            return $this;
        }

        $now = time();
        $expireAt = $expire !== null ? strtotime($expire) : 0;

        $stmt = $this->statement(
            'store',
            'INSERT OR REPLACE INTO caches (key, data, created_at, expire_at) VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([
            $key,
            serialize($data),
            $now,
            $expireAt
        ]);

        return $this;
    }

    /**
     * Loads data from cache or generates it using a callback if not present.
     */
    public function load(string $key, callable $callback, null|string $expire = null): mixed
    {
        if (null !== ($expire) && $this->isRedis()) {
            $this->cleanupExpiredRedis();
        }

        if (!$this->has($key, $expire !== null)) {
            $data = $callback($this);
            $this->store($key, $data, $expire);
            return $data;
        }

        return $this->retrieve($key);
    }

    /**
     * Retrieves data from the cache for given keys, optionally erasing expired entries.
     */
    public function retrieve(string|array $keys, bool $eraseExpired = false): mixed
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return is_array($keys) ? [] : null;
            }

            if (empty($keys)) {
                return is_array($keys) ? [] : null;
            }

            if ($eraseExpired) {
                $this->cleanupExpiredRedis();
            }

            if (is_array($keys)) {
                $redisKeys = array_map($this->cacheKey(...), $keys);
                $values = $this->redis->mget($redisKeys);

                $results = [];
                foreach ($keys as $index => $key) {
                    $raw = $values[$index] ?? false;

                    if ($raw === false) {
                        continue;
                    }

                    $payload = $this->unpackRedisValue($raw);
                    if (!is_array($payload)) {
                        continue;
                    }

                    if ($payload['expire_at'] > 0 && $payload['expire_at'] <= time()) {
                        continue;
                    }

                    $results[$key] = $payload['value'];
                }

                return $results;
            }

            $raw = $this->redis->get($this->cacheKey((string) $keys));
            $payload = $this->unpackRedisValue($raw);

            if (!is_array($payload) || ($payload['expire_at'] > 0 && $payload['expire_at'] <= time())) {
                return null;
            }

            return $payload['value'];
        }

        if (is_array($keys)) {
            if ($keys === []) {
                return [];
            }

            $placeholders = rtrim(str_repeat('?,', count($keys)), ',');
            $stmt = $this->pdo?->prepare(
                "SELECT key, data FROM caches WHERE key IN ($placeholders) AND (expire_at = 0 OR expire_at > ?)"
            );

            if (!$stmt) {
                return [];
            }

            $stmt->execute([...$keys, time()]);
            $results = [];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[$row['key']] = unserialize($row['data']);
            }

            return $results;
        }

        $stmt = $this->statement(
            'retrieve',
            'SELECT data FROM caches WHERE key = ? AND (expire_at = 0 OR expire_at > ?)'
        );

        $stmt->execute([$keys, time()]);
        $result = $stmt->fetchColumn();

        return $result !== false ? unserialize($result) : null;
    }

    /**
     * Retrieves metadata for a cache key.
     */
    public function metadata(string $key): mixed
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return null;
            }

            $row = $this->unpackRedisValue($this->redis->get($this->cacheKey($key)));

            if (!is_array($row)) {
                return null;
            }

            return [
                'created_at' => $row['created_at'],
                'expire_at' => $row['expire_at'],
            ];
        }

        $stmt = $this->statement(
            'metadata',
            'SELECT created_at, expire_at FROM caches WHERE key = ?'
        );

        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Retrieves all data from the cache, optionally erasing expired entries.
     */
    public function retrieveAll(bool $eraseExpired = false): array
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return [];
            }

            if ($eraseExpired) {
                $this->cleanupExpiredRedis();
            }

            $result = [];
            $this->scanRedisKeys($this->cachePrefixPattern(), function (string $redisKey) use (&$result) {
                if ($this->redis === null) {
                    return;
                }

                $raw = $this->redis->get($redisKey);
                $payload = $this->unpackRedisValue($raw);

                if (!is_array($payload)) {
                    return;
                }

                if ($payload['expire_at'] > 0 && $payload['expire_at'] <= time()) {
                    return;
                }

                $key = substr($redisKey, strlen($this->redisKeyPrefix));
                $result[$key] = $payload['value'];
            });

            return $result;
        }

        $eraseExpired && $this->eraseExpired();

        $stmt = $this->statement(
            'retrieve_all',
            'SELECT key, data FROM caches WHERE expire_at = 0 OR expire_at > ?'
        );

        $stmt->execute([time()]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['key']] = unserialize($row['data']);
        }

        return $results;
    }

    /**
     * Erases specified cache entries.
     */
    public function erase(string|array $keys): self
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        if ($keys === []) {
            return $this;
        }

        if ($this->isRedis()) {
            if (!$this->redis) {
                return $this;
            }

            if (empty($keys)) {
                return $this;
            }

            $redisKeys = array_map($this->cacheKey(...), $keys);
            $this->redis->del($redisKeys);
            return $this;
        }

        $placeholders = rtrim(str_repeat('?,', count($keys)), ',');

        $stmt = $this->pdo?->prepare("DELETE FROM caches WHERE key IN ($placeholders)");

        if (!$stmt) {
            return $this;
        }

        $stmt->execute($keys);
        return $this;
    }

    /**
     * Erases expired cache entries based on their timestamps.
     */
    public function eraseExpired(): self
    {
        if ($this->isRedis()) {
            $this->cleanupExpiredRedis();
            return $this;
        }

        $stmt = $this->statement(
            'erase_expired',
            'DELETE FROM caches WHERE expire_at > 0 AND expire_at < ?'
        );

        $stmt->execute([time()]);

        return $this;
    }

    /**
     * Retrieves all expired cache entries.
     */
    public function getExpired(): array
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return [];
            }

            $result = [];
            $now = time();
            $this->scanRedisKeys($this->cachePrefixPattern(), function (string $redisKey) use (&$result, $now) {
                if (!$this->redis) {
                    return;
                }

                $raw = $this->redis->get($redisKey);
                $payload = $this->unpackRedisValue($raw);

                if (!is_array($payload)) {
                    return;
                }

                if ($payload['expire_at'] > 0 && $payload['expire_at'] < $now) {
                    $key = substr($redisKey, strlen($this->redisKeyPrefix));
                    $result[$key] = $payload['value'];
                }
            });

            return $result;
        }

        $stmt = $this->statement(
            'get_expired',
            'SELECT key, data FROM caches WHERE expire_at > 0 AND expire_at < ?'
        );

        $stmt->execute([time()]);
        $expired = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $expired[$row['key']] = unserialize($row['data']);
        }

        return $expired;
    }

    /**
     * Clears all cache data.
     */
    public function flush(): self
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return $this;
            }

            $this->cleanupExpiredRedis();

            $keys = [];
            $this->scanRedisKeys($this->cachePrefixPattern(), function (string $redisKey) use (&$keys) {
                $keys[] = $redisKey;
            });

            if ($keys !== []) {
                $this->redis->del($keys);
            }

            return $this;
        }

        $this->pdo?->exec('DELETE FROM caches');

        return $this;
    }

    public function flushIf(bool $condition): self
    {
        $condition && $this->flush();

        return $this;
    }

    /**
     * Clears all cache data and reclaims storage.
     */
    public function clear(): self
    {
        $this->flush();

        if ($this->isRedis()) {
            return $this;
        }

        $this->pdo?->exec('VACUUM');

        return $this;
    }

    /**
     * Stores multiple cache entries in a single transaction.
     */
    public function storeMany(array $items, null|string $expire = null): self
    {
        if ($items === []) {
            return $this;
        }

        if ($this->isRedis()) {
            $now = time();
            $redisPayloads = [];

            foreach ($items as $key => $value) {
                $packed = $this->packRedisValue((string) $key, $value, $expire);
                $redisPayloads[$this->cacheKey((string) $key)] = $packed['payload'];
            }

            foreach ($redisPayloads as $redisKey => $payload) {
                $this->redis?->set($redisKey, $payload);

                // Use per-key expiry from same configured value.
                if ($expire !== null) {
                    $expireAt = strtotime($expire);
                    if ($expireAt !== false && $expireAt > $now) {
                        $this->redis?->expire($redisKey, max(1, $expireAt - $now));
                    }
                }
            }

            return $this;
        }

        $now = time();
        $expireAt = $expire !== null ? strtotime($expire) : 0;
        $expireAt = $expireAt === false ? 0 : (int) $expireAt;

        try {
            $this->pdo?->beginTransaction();

            $stmt = $this->statement(
                'store_many',
                'INSERT OR REPLACE INTO caches (key, data, created_at, expire_at) VALUES (?, ?, ?, ?)'
            );

            foreach ($items as $key => $value) {
                $stmt->execute([
                    $key,
                    serialize($value),
                    $now,
                    $expireAt
                ]);
            }

            $this->pdo?->commit();
        } catch (\PDOException $e) {
            $this->pdo?->rollBack();
            throw new CacheException("Failed to store multiple items: " . $e->getMessage());
        }

        return $this;
    }

    /**
     * Increment a numeric cache value.
     */
    public function increment(string $key, int $amount = 1): int|false
    {
        if ($this->isRedis()) {
            $value = $this->retrieve($key);

            if (!is_numeric($value)) {
                return false;
            }

            $value = (int) $value + $amount;
            $stored = $this->metadata($key);
            $ttl = $stored['expire_at'] ?? 0;

            if ($ttl > 0) {
                $seconds = max(1, $ttl - time());
            }

            $this->store($key, $value, $ttl > 0 ? ('+' . $seconds . ' seconds') : null);
            return $value;
        }

        try {
            $this->pdo?->beginTransaction();

            $value = $this->retrieve($key);

            if ($value === null || !is_numeric($value)) {
                $this->pdo?->rollBack();
                return false;
            }

            $newValue = (int) $value + $amount;
            $stmt = $this->statement(
                'increment_update',
                'UPDATE caches SET data = ?, created_at = ? WHERE key = ?'
            );
            $stmt->execute([serialize($newValue), time(), $key]);

            $this->pdo?->commit();
            return $newValue;
        } catch (\PDOException $e) {
            $this->pdo?->rollBack();
            return false;
        }
    }

    /**
     * Decrement a numeric cache value.
     */
    public function decrement(string $key, int $amount = 1): int|false
    {
        return $this->increment($key, -$amount);
    }

    /**
     * Store a value if the key doesn't exist.
     */
    public function add(string $key, mixed $value, null|string $expire = null): bool
    {
        if ($this->isRedis()) {
            $packed = $this->packRedisValue($key, $value, $expire);
            $redisKey = $this->cacheKey($key);
            $options = ['nx'];

            if ($packed['expireAt'] > 0) {
                $options['ex'] = max(1, $packed['expireAt'] - time());
            }

            $added = $this->redis?->set($redisKey, $packed['payload'], $options);

            return $added === true;
        }

        if (!$this->has($key)) {
            $this->store($key, $value, $expire);
            return true;
        }

        return false;
    }

    /**
     * Remember a value in cache forever or retrieve it from callback.
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
     * Gets statistics about the cache.
     */
    public function stats(): array
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return [];
            }

            $now = time();
            $totalEntries = 0;
            $expiredEntries = 0;

            $this->scanRedisKeys($this->cachePrefixPattern(), function (string $redisKey) use (&$totalEntries, &$expiredEntries, $now) {
                if (!$this->redis) {
                    return;
                }

                $raw = $this->redis->get($redisKey);
                $payload = $this->unpackRedisValue($raw);

                if (!is_array($payload)) {
                    return;
                }

                $totalEntries++;
                if ($payload['expire_at'] > 0 && $payload['expire_at'] < $now) {
                    $expiredEntries++;
                }
            });

            $memory = 0;
            $info = $this->redis->info('memory');
            if (is_array($info) && isset($info['used_memory'])) {
                $memory = (int) $info['used_memory'];
            }

            return [
                'total_entries' => $totalEntries,
                'active_entries' => $totalEntries - $expiredEntries,
                'expired_entries' => $expiredEntries,
                'database_size' => $memory,
            ];
        }

        try {
            $stmt = $this->pdo?->query('SELECT COUNT(*) FROM caches');
            $totalEntries = (int) $stmt?->fetchColumn();

            $stmt = $this->pdo?->prepare('SELECT COUNT(*) FROM caches WHERE expire_at > 0 AND expire_at < ?');
            $stmt?->execute([time()]);
            $expiredEntries = (int) ($stmt?->fetchColumn() ?? 0);

            $activeEntries = $totalEntries - $expiredEntries;

            $stmt = $this->pdo?->query('PRAGMA page_count');
            $pageCount = (int) ($stmt?->fetchColumn() ?? 0);

            $stmt = $this->pdo?->query('PRAGMA page_size');
            $pageSize = (int) ($stmt?->fetchColumn() ?? 0);

            $dbSize = $pageCount * $pageSize;

            return [
                'total_entries' => $totalEntries,
                'active_entries' => $activeEntries,
                'expired_entries' => $expiredEntries,
                'database_size' => $dbSize,
            ];
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Pull a value from the cache and delete it.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return $default;
            }

            $value = $this->retrieve($key);
            if ($value !== null) {
                $this->erase($key);
                return $value;
            }

            return $default;
        }

        try {
            $this->pdo?->beginTransaction();

            $value = $this->retrieve($key);

            if ($value !== null) {
                $this->erase($key);
                $this->pdo?->commit();
                return $value;
            }

            $this->pdo?->commit();
            return $default;
        } catch (\PDOException $e) {
            $this->pdo?->rollBack();
            return $default;
        }
    }

    /**
     * Store multiple cache entries from an array where keys expire at different times.
     */
    public function storeManyWithExpiry(array $items): self
    {
        if (empty($items)) {
            return $this;
        }

        $now = time();

        if ($this->isRedis()) {

            foreach ($items as $key => $config) {
                if (!is_array($config)) {
                    continue;
                }

                $value = $config['value'] ?? null;
                $expire = $config['expire'] ?? null;

                $packed = $this->packRedisValue((string) $key, $value, $expire);
                $redisKey = $this->cacheKey((string) $key);
                $this->redis?->set($redisKey, $packed['payload']);

                if ($packed['expireAt'] > 0) {
                    $this->redis?->expire($redisKey, max(1, $packed['expireAt'] - $now));
                }
            }

            return $this;
        }

        try {
            $this->pdo?->beginTransaction();

            $stmt = $this->statement(
                'store_many_expiry',
                'INSERT OR REPLACE INTO caches (key, data, created_at, expire_at) VALUES (?, ?, ?, ?)'
            );

            foreach ($items as $key => $config) {
                if (!is_array($config)) {
                    continue;
                }

                $value = $config['value'] ?? null;
                $expire = $config['expire'] ?? null;
                $expireAt = $expire !== null ? strtotime($expire) : 0;
                $expireAt = $expireAt === false ? 0 : (int) $expireAt;

                $stmt->execute([
                    $key,
                    serialize($value),
                    $now,
                    $expireAt
                ]);
            }

            $this->pdo?->commit();
        } catch (\PDOException $e) {
            $this->pdo?->rollBack();
            throw new CacheException('Failed to store items with expiry: ' . $e->getMessage());
        }

        return $this;
    }

    /**
     * Get the remaining time to live for a cache key.
     */
    public function ttl(string $key): ?int
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return null;
            }

            if ($this->has($key)) {
                $metadata = $this->metadata($key);

                if (($metadata['expire_at'] ?? 0) === 0) {
                    return null;
                }

                $ttl = (int) (($metadata['expire_at'] ?? 0) - time());
                return $ttl > 0 ? $ttl : 0;
            }

            return null;
        }

        $metadata = $this->metadata($key);

        if (!$metadata || $metadata['expire_at'] == 0) {
            return null;
        }

        $ttl = $metadata['expire_at'] - time();
        return $ttl > 0 ? $ttl : 0;
    }

    /**
     * Optimize the storage backend.
     */
    public function optimize(): self
    {
        if ($this->isRedis()) {
            $this->cleanupExpiredRedis();
            return $this;
        }

        try {
            $this->eraseExpired();
            $this->pdo?->exec('VACUUM');
            $this->pdo?->exec('ANALYZE');
        } catch (\PDOException $e) {
            // Ignore optimize errors.
        }

        return $this;
    }

    /**
     * ArrayAccess method to check if a cache key exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    /**
     * ArrayAccess method to retrieve a cache entry.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->retrieve((string) $offset);
    }

    /**
     * ArrayAccess method to store a cache entry.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->store((string) $offset, $value);
    }

    /**
     * ArrayAccess method to erase a cache entry.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->erase((string) $offset);
    }
}
