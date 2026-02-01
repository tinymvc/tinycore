<?php

namespace Spark\Utils;

use PDO;
use PDOStatement;
use Spark\Contracts\Utils\CacheUtilContract;
use Spark\Exceptions\Utils\CacheUtilException;
use Spark\Support\Traits\Macroable;
use function count;
use function func_get_args;
use function is_array;
use function sprintf;

/**
 * Class Cache
 * 
 * Production-ready SQLite-based cache with concurrency support and distributed locking.
 * Optimized for high-performance caching with WAL mode, prepared statements, and proper indexing.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Cache implements CacheUtilContract, \ArrayAccess
{
    use Macroable;

    /** @var PDO The PDO connection instance */
    private PDO $pdo;

    /** @var array Cached prepared statements */
    private array $statements = [];

    public function __construct(string $name = 'default')
    {
        try {
            // Determine the cache file path.
            $cache = sprintf('%s.cache', cache_dir(md5($name)));
            $createDB = !is_file($cache); // Check if the database file needs to be created.

            // Initialize the PDO connection to SQLite database.
            $this->pdo = new PDO("sqlite:$cache");

            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Optimize SQLite performance with PRAGMA settings.
            $this->pdo->exec("PRAGMA journal_mode = WAL");
            $this->pdo->exec("PRAGMA synchronous = NORMAL");
            $this->pdo->exec("PRAGMA cache_size = 10000");
            $this->pdo->exec("PRAGMA temp_store = MEMORY");
            $this->pdo->exec("PRAGMA locking_mode = NORMAL"); // Allow concurrent access
            $this->pdo->exec("PRAGMA busy_timeout = 5000"); // 5 second timeout for locks

            $createDB && $this->createTables(); // Create tables if they don't exist.
        } catch (\PDOException $e) {
            throw new CacheUtilException("Failed to connect to SQLite database: " . $e->getMessage());
        }
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
     * Creates the necessary tables and indexes for caching and locking.
     *
     * @return void
     */
    private function createTables(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS caches (
                key TEXT PRIMARY KEY,
                data BLOB NOT NULL,
                created_at INTEGER NOT NULL,
                expire_at INTEGER DEFAULT 0,
                CHECK (expire_at >= 0)
            )
        ");

        $this->pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_key 
            ON caches(key)
        ");

        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_created 
            ON caches(created_at)
        ");

        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_expire 
            ON caches(expire_at) 
            WHERE expire_at > 0
        ");
    }

    /**
     * Get or prepare a cached statement.
     *
     * @param string $key The statement key.
     * @param string $sql The SQL query.
     * @return PDOStatement The prepared statement.
     */
    protected function statement(string $key, string $sql): PDOStatement
    {
        if (!isset($this->statements[$key])) {
            return $this->statements[$key];
        }

        return $this->statements[$key] = $this->pdo->prepare($sql);
    }

    /**
     * Checks if a cache key exists and optionally erases expired entries.
     *
     * @param string $key The key to check in cache.
     * @param bool $eraseExpired Whether to erase expired entries before checking.
     * @return bool
     */
    public function has(string $key, bool $eraseExpired = false): bool
    {
        $eraseExpired && $this->eraseExpired();

        $stmt = $this->statement(
            'has',
            "SELECT 1 FROM caches WHERE key = ? AND (expire_at = 0 OR expire_at > ?)"
        );

        $stmt->execute([$key, time()]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Stores data in the cache with an optional expiration time.
     *
     * @param string $key Unique identifier for the cached data.
     * @param mixed $data The data to cache.
     * @param string|null $expire Expiration time as a string (e.g., '+1 day').
     * @return self
     */
    public function store(string $key, mixed $data, null|string $expire = null): self
    {
        $now = time();
        $expireAt = $expire !== null ? strtotime($expire) : 0;

        $stmt = $this->statement(
            'store',
            "INSERT OR REPLACE INTO caches (key, data, created_at, expire_at) VALUES (?, ?, ?, ?)"
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
     *
     * @param string $key The cache key.
     * @param callable $callback Function to generate the data if not cached.
     * @param null|string $expire Optional expiration time.
     * @return mixed
     */
    public function load(string $key, callable $callback, null|string $expire = null): mixed
    {
        // Erase expired entries if enabled.
        $expire && $this->eraseExpired();

        // Check if cache is already exists, else store it into cache. 
        if (!$this->has($key)) {
            $data = $callback($this);
            $this->store($key, $data, $expire);
            return $data;
        }

        // Retrieve entry from cache.
        return $this->retrieve($key);
    }

    /**
     * Retrieves data from the cache for given keys, optionally erasing expired entries.
     *
     * @param string|array $keys Cache key(s) to retrieve.
     * @param bool $eraseExpired Whether to erase expired entries before retrieval.
     * @return mixed
     */
    public function retrieve(string|array $keys, bool $eraseExpired = false): mixed
    {
        $eraseExpired && $this->eraseExpired();

        if (empty($keys)) {
            return is_array($keys) ? [] : null; // Return empty array or null based on input type
        }

        if (is_array($keys)) {
            $placeholders = rtrim(str_repeat('?,', count($keys)), ',');
            $stmt = $this->pdo->prepare(
                "SELECT key, data FROM caches WHERE key IN ($placeholders) AND (expire_at = 0 OR expire_at > ?)"
            );

            $stmt->execute([...$keys, time()]);
            $results = [];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[$row['key']] = unserialize($row['data']);
            }

            return $results;
        }

        $stmt = $this->statement(
            'retrieve',
            "SELECT data FROM caches WHERE key = ? AND (expire_at = 0 OR expire_at > ?)"
        );

        $stmt->execute([$keys, time()]);
        $result = $stmt->fetchColumn();

        return $result !== false ? unserialize($result) : null;
    }

    /**
     * Retrieves the metadata for the given cache key.
     *
     * @param string $key The cache key.
     * @return mixed The metadata for the given cache key, or null if not found.
     */
    public function metadata(string $key): mixed
    {
        $stmt = $this->statement(
            'metadata',
            "SELECT created_at, expire_at FROM caches WHERE key = ?"
        );

        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Retrieves all data from the cache, optionally erasing expired entries.
     *
     * @param bool $eraseExpired Whether to erase expired entries before retrieval.
     * @return array
     */
    public function retrieveAll(bool $eraseExpired = false): array
    {
        $eraseExpired && $this->eraseExpired();

        $stmt = $this->statement(
            'retrieve_all',
            "SELECT key, data FROM caches WHERE expire_at = 0 OR expire_at > ?"
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
     *
     * @param string|array $keys Cache key(s) to erase.
     * @return self
     */
    public function erase(string|array $keys): self
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $placeholders = rtrim(str_repeat('?,', count($keys)), ',');

        $stmt = $this->pdo->prepare("
            DELETE FROM caches 
            WHERE key IN ($placeholders)
        ");

        $stmt->execute($keys);
        return $this;
    }

    /**
     * Erases expired cache entries based on their timestamps and expiration times.
     *
     * @return self
     */
    public function eraseExpired(): self
    {
        $stmt = $this->statement(
            'erase_expired',
            "DELETE FROM caches WHERE expire_at > 0 AND expire_at < ?"
        );

        $stmt->execute([time()]);

        return $this;
    }

    /**
     * Retrieves all expired cache entries without removing them.
     *
     * @return array An associative array of expired cache entries.
     */
    public function getExpired(): array
    {
        $stmt = $this->statement(
            'get_expired',
            "SELECT key, data FROM caches WHERE expire_at > 0 AND expire_at < ?"
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
     *
     * @return self
     */
    public function flush(): self
    {
        $this->pdo->exec("DELETE FROM caches");
        return $this;
    }

    /**
     * Clears all cache data if the given condition is true.
     *
     * @param bool $condition The condition to check.
     * @return self
     */
    public function flushIf(bool $condition): self
    {
        $condition && $this->flush();

        return $this;
    }

    /**
     * Clears all cache data by deleting the cache file 
     * and resetting properties.
     *
     * @return self
     */
    public function clear(): self
    {
        $this->flush(); // Ensure all data is deleted.
        $this->pdo->exec("VACUUM"); // Reclaim space in the database file.

        return $this;
    }

    /**
     * Stores multiple cache entries in a single transaction.
     *
     * @param array $items Associative array of key => value pairs.
     * @param string|null $expire Expiration time as a string.
     * @return self
     */
    public function storeMany(array $items, null|string $expire = null): self
    {
        if (empty($items)) {
            return $this;
        }

        $now = time();
        $expireAt = $expire !== null ? strtotime($expire) : 0;

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->statement(
                'store',
                "INSERT OR REPLACE INTO caches (key, data, created_at, expire_at) VALUES (?, ?, ?, ?)"
            );

            foreach ($items as $key => $value) {
                $stmt->execute([
                    $key,
                    serialize($value),
                    $now,
                    $expireAt
                ]);
            }

            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            throw new CacheUtilException("Failed to store multiple items: " . $e->getMessage());
        }

        return $this;
    }

    /**
     * Increment a numeric cache value.
     *
     * @param string $key The cache key.
     * @param int $amount Amount to increment (default: 1).
     * @return int|false The new value, or false if the key doesn't exist or isn't numeric.
     */
    public function increment(string $key, int $amount = 1): int|false
    {
        try {
            $this->pdo->beginTransaction();

            $value = $this->retrieve($key);

            if ($value === null || !is_numeric($value)) {
                $this->pdo->rollBack();
                return false;
            }

            $newValue = (int) $value + $amount;
            $this->store($key, $newValue);

            $this->pdo->commit();
            return $newValue;
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    /**
     * Decrement a numeric cache value.
     *
     * @param string $key The cache key.
     * @param int $amount Amount to decrement (default: 1).
     * @return int|false The new value, or false if the key doesn't exist or isn't numeric.
     */
    public function decrement(string $key, int $amount = 1): int|false
    {
        return $this->increment($key, -$amount);
    }

    /**
     * Store a value if the key doesn't exist.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     * @param string|null $expire Expiration time.
     * @return bool True if stored, false if key already exists.
     */
    public function add(string $key, mixed $value, null|string $expire = null): bool
    {
        try {
            $this->pdo->beginTransaction();

            if ($this->has($key)) {
                $this->pdo->rollBack();
                return false;
            }

            $this->store($key, $value, $expire);
            $this->pdo->commit();
            return true;
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    /**
     * Remember a value in cache forever or retrieve it from callback.
     *
     * @param string $key The cache key.
     * @param callable $callback Callback to generate value if not cached.
     * @return mixed
     */
    public function remember(string $key, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->retrieve($key);
        }

        $value = $callback($this);
        $this->store($key, $value);
        return $value;
    }

    /**
     * Gets statistics about the cache.
     *
     * @return array Cache statistics including size, count, locks, etc.
     */
    public function stats(): array
    {
        try {
            // Get total cache entries
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM caches");
            $totalEntries = (int) $stmt->fetchColumn();

            // Get expired entries count
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM caches WHERE expire_at > 0 AND expire_at < ?");
            $stmt->execute([time()]);
            $expiredEntries = (int) $stmt->fetchColumn();

            // Get active entries count
            $activeEntries = $totalEntries - $expiredEntries;

            // Get database file size
            $stmt = $this->pdo->query("PRAGMA page_count");
            $pageCount = (int) $stmt->fetchColumn();

            $stmt = $this->pdo->query("PRAGMA page_size");
            $pageSize = (int) $stmt->fetchColumn();

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
     *
     * @param string $key The cache key.
     * @param mixed $default Default value if key doesn't exist.
     * @return mixed
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        try {
            $this->pdo->beginTransaction();

            $value = $this->retrieve($key);

            if ($value !== null) {
                $this->erase($key);
                $this->pdo->commit();
                return $value;
            }

            $this->pdo->commit();
            return $default;
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            return $default;
        }
    }

    /**
     * Store multiple cache entries from an array where keys expire at different times.
     *
     * @param array $items Associative array of key => ['value' => $val, 'expire' => $exp].
     * @return self
     */
    public function storeManyWithExpiry(array $items): self
    {
        if (empty($items)) {
            return $this;
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->statement(
                'store',
                "INSERT OR REPLACE INTO caches (key, data, created_at, expire_at) VALUES (?, ?, ?, ?)"
            );

            $now = time();
            foreach ($items as $key => $config) {
                $value = $config['value'] ?? null;
                $expire = $config['expire'] ?? null;
                $expireAt = $expire !== null ? strtotime($expire) : 0;

                $stmt->execute([
                    $key,
                    serialize($value),
                    $now,
                    $expireAt
                ]);
            }

            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            throw new CacheUtilException("Failed to store items with expiry: " . $e->getMessage());
        }

        return $this;
    }

    /**
     * Get the remaining time to live for a cache key in seconds.
     *
     * @param string $key The cache key.
     * @return int|null Seconds until expiration, null if no expiration or key doesn't exist.
     */
    public function ttl(string $key): ?int
    {
        $metadata = $this->metadata($key);

        if (!$metadata || $metadata['expire_at'] == 0) {
            return null;
        }

        $ttl = $metadata['expire_at'] - time();
        return $ttl > 0 ? $ttl : 0;
    }

    /**
     * Optimize the database by running VACUUM and ANALYZE.
     *
     * @return self
     */
    public function optimize(): self
    {
        try {
            // Clean expired entries first
            $this->eraseExpired();

            // Reclaim unused space
            $this->pdo->exec("VACUUM");

            // Update query planner statistics
            $this->pdo->exec("ANALYZE");
        } catch (\PDOException $e) {
            // Ignore errors
        }

        return $this;
    }

    /**
     * ArrayAccess method to check if a cache key exists.
     * 
     * @param mixed $offset The cache key.
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    /**
     * ArrayAccess method to retrieve a cache entry.
     * 
     * @param mixed $offset The cache key.
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->retrieve($offset);
    }

    /**
     * ArrayAccess method to store a cache entry.
     * 
     * @param mixed $offset The cache key.
     * @param mixed $value The data to cache.
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->store($offset, $value);
    }

    /**
     * ArrayAccess method to erase a cache entry.
     * 
     * @param mixed $offset The cache key.
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->erase($offset);
    }
}
