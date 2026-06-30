<?php

namespace Spark\Utils;

use PDO;
use PDOStatement;
use RuntimeException;
use Spark\Contracts\Utils\LockUtilContract;
use Spark\Exceptions\Utils\LockUtilException;
use Spark\Support\Traits\Macroable;
use Spark\Utils\RedisConnector;
use function dirname;
use function is_array;
use function is_dir;
use function is_string;
use function md5;
use function mkdir;
use function pathinfo;
use function rtrim;
use function sprintf;
use function str_ends_with;

/**
 * Class Lock
 *
 * Cross-driver lock utility with sqlite (default) and redis backends.
 *
 * @package Spark\Utils
 */
class Lock implements LockUtilContract, \ArrayAccess
{
    use Macroable;

    private const LOCK_OK = 1;

    private const REDIS_OWNER_KEY = 'owner';
    private const REDIS_LOCKED_AT_KEY = 'locked_at';
    private const REDIS_EXPIRE_AT_KEY = 'expire_at';
    private const REDIS_EMPTY_VALUE = '0';

    /** @var PDO The PDO connection instance */
    private ?PDO $pdo = null;

    /** @var \Redis The Redis connection */
    private ?\Redis $redis = null;

    /** @var array Cached prepared statements */
    private array $statements = [];

    /** @var string Cache/lock driver: sqlite|redis */
    private string $driver = 'sqlite';

    /** @var string Unique identifier for this instance's lock owner */
    private string $owner;

    /** @var string */
    private string $name;

    /** @var string */
    private string $namespace = 'lock';

    /** @var array */
    private array $connection = [];

    /**
     * Lock constructor.
     */
    public function __construct(string $name = 'default')
    {
        $this->name = $name;
        $this->connection = $this->resolveDriverConfig($name);
        $this->driver = strtolower((string) ($this->connection['driver'] ?? 'sqlite'));

        if ($this->driver === 'redis') {
            $this->initializeRedis();
        } else {
            $this->initializeSqlite();
        }

        $this->owner = sprintf('%s-%s-%s', gethostname(), getmypid(), uniqid('', true));
    }

    /**
     * Factory method to create Lock instances.
     */
    public static function make(string $name = 'default'): static
    {
        return new static($name);
    }

    private function initializeSqlite(): void
    {
        $lockPath = $this->sqliteLockPath($this->connection);
        try {
            $this->pdo = new PDO("sqlite:$lockPath");

            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA cache_size = 10000');
            $this->pdo->exec('PRAGMA temp_store = MEMORY');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');

            $this->createTables();
        } catch (\PDOException $e) {
            throw new LockUtilException('Failed to connect to SQLite database: ' . $e->getMessage());
        }
    }

    private function initializeRedis(): void
    {
        $connection = RedisConnector::resolveConnectionConfig($this->connection);
        $this->redis = RedisConnector::make($connection, $this->name);

        $prefix = trim((string) ($connection['prefix'] ?? 'spark'));
        if ($prefix === '') {
            $prefix = 'spark';
        }
        $prefix = trim($prefix, ':');
        $this->namespace = sprintf('%s:lock:%s', $prefix, md5($this->name));
    }

    private function resolveDriverConfig(string $name): array
    {
        $cacheConfig = (array) config('cache', []);

        $driver = strtolower((string) ($cacheConfig['driver'] ?? 'sqlite'));
        $connections = (array) ($cacheConfig['connections'] ?? []);
        $driverConfig = is_array($connections[$driver] ?? null) ? $connections[$driver] : [];

        return [
            'driver' => $driver,
            'name' => $name,
            ...$driverConfig
        ];
    }

    /**
     * Resolves the SQLite lock database path from cache sqlite config.
     */
    private function sqliteLockPath(array $config): string
    {
        $path = (string) ($config['lock_path'] ?? $config['path'] ?? storage_dir('cache'));
        if ($path === '') {
            $path = storage_dir('cache');
        }

        if ($this->looksLikeDirectoryPath($path)) {
            $this->ensureDirectory($path);
            return $this->normalizePath($path . DIRECTORY_SEPARATOR . md5($this->name) . '.lock');
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

    private function isRedis(): bool
    {
        return $this->driver === 'redis' && $this->redis instanceof \Redis;
    }

    private function lockKey(string $key): string
    {
        return sprintf('%s:%s', $this->namespace, $key);
    }

    private function createTables(): void
    {
        if (!$this->pdo) {
            return;
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS locks (
            key TEXT PRIMARY KEY,
            owner TEXT DEFAULT NULL,
            locked_at INTEGER NOT NULL,
            expire_at INTEGER NOT NULL,
            CHECK (expire_at >= 0)
        )");

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_lock_expire ON locks(expire_at) WHERE expire_at > 0');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_lock_owner ON locks(owner) WHERE owner IS NOT NULL');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_lock_locked ON locks(locked_at)');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_lock_key ON locks(key)');
    }

    private function statement(string $key, string $sql): PDOStatement
    {
        if (!$this->pdo) {
            throw new RuntimeException('SQLite statements are only available for sqlite lock driver.');
        }

        return $this->statements[$key] ??= $this->pdo->prepare($sql);
    }

    private function encodeRedisLockData(int $timeout): array
    {
        $lockedAt = time();

        return [
            self::REDIS_OWNER_KEY => $this->owner,
            self::REDIS_LOCKED_AT_KEY => $lockedAt,
            self::REDIS_EXPIRE_AT_KEY => $lockedAt + $timeout,
        ];
    }

    /**
     * Acquires a lock with the given key.
     */
    public function lock(string $key, int $timeout = 10, int $waitTimeout = 5): bool
    {
        if ($this->isRedis()) {
            return $this->lockWithRedis($key, $timeout, $waitTimeout);
        }

        $startTime = time();
        $timeout = max(1, $timeout);
        $expireAt = time() + $timeout;

        while (true) {
            $this->releaseExpiredLocks();

            try {
                $this->pdo?->beginTransaction();

                $stmt = $this->statement(
                    'lock_insert',
                    "INSERT INTO locks (key, owner, locked_at, expire_at) VALUES (?, ?, ?, ?)"
                );

                $stmt->execute([
                    $key,
                    $this->owner,
                    time(),
                    $expireAt
                ]);

                $this->pdo?->commit();
                return true;
            } catch (\PDOException) {
                $this->pdo?->rollBack();

                if (time() - $startTime >= $waitTimeout) {
                    return false;
                }

                usleep(10000);
            }
        }
    }

    private function lockWithRedis(string $key, int $timeout, int $waitTimeout): bool
    {
        if (!$this->redis) {
            return false;
        }

        $redisKey = $this->lockKey($key);
        $waitTimeout = max(0, $waitTimeout);
        $end = time() + $waitTimeout;

        while (true) {
            $payload = $this->encodeRedisLockData(max(1, $timeout));
            $payloadData = json_encode($payload, JSON_UNESCAPED_UNICODE);

            $acquired = $this->redis->set($redisKey, $payloadData, ['nx', 'ex' => max(1, $timeout)]);
            if ($acquired) {
                return true;
            }

            if ($waitTimeout === 0 || time() >= $end) {
                return false;
            }

            usleep(100000);
        }
    }

    /**
     * Releases a lock with the given key.
     */
    public function unlock(string $key): bool
    {
        if ($this->isRedis()) {
            return $this->unlockWithRedis($key);
        }

        try {
            $stmt = $this->statement(
                'lock_delete',
                'DELETE FROM locks WHERE key = ? AND owner = ?'
            );

            $stmt->execute([$key, $this->owner]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    private function unlockWithRedis(string $key): bool
    {
        if (!$this->redis) {
            return false;
        }

        $redisKey = $this->lockKey($key);
        $script = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if not current then
    return 0
end
local data = cjson.decode(current)
if not data or not data.owner then
    return 0
end
if data.owner == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

        /** @var int $result */
        $result = (int) $this->redis->eval($script, [$redisKey, $this->owner], 1);
        return $result > 0;
    }

    /**
     * Releases all locks owned by this instance.
     */
    public function unlockAll(): int
    {
        if ($this->isRedis()) {
            return $this->unlockAllFromRedis();
        }

        try {
            $stmt = $this->pdo?->prepare('DELETE FROM locks WHERE owner = ?');
            $stmt?->execute([$this->owner]);
            return (int) ($stmt?->rowCount() ?? 0);
        } catch (\PDOException) {
            return 0;
        }
    }

    private function unlockAllFromRedis(): int
    {
        if (!$this->redis) {
            return 0;
        }

        $keys = [];
        $cursor = 0;
        do {
            $found = $this->redis->scan($cursor, $this->namespace . ':*');
            if ($found === false) {
                break;
            }

            $keys = array_merge($keys, (array) $found);
        } while ($cursor > 0);

        if ($keys === []) {
            return 0;
        }

        $deleted = 0;
        foreach ($keys as $redisKey) {
            if (!$this->redis) {
                break;
            }

            $raw = $this->redis->get($redisKey);
            if (!is_string($raw)) {
                continue;
            }

            $payload = @json_decode($raw, true);
            if (!is_array($payload) || ($payload[self::REDIS_OWNER_KEY] ?? null) !== $this->owner) {
                continue;
            }

            $deleted += (int) $this->redis->del($redisKey);
        }

        return $deleted;
    }

    /**
     * Checks if a lock exists for the given key.
     */
    public function isLocked(string $key): bool
    {
        if ($this->isRedis()) {
            return $this->isLockedRedis($key);
        }

        $this->releaseExpiredLocks();

        $stmt = $this->statement(
            'lock_check',
            'SELECT 1 FROM locks WHERE key = ? AND expire_at > ?'
        );

        $stmt->execute([$key, time()]);
        return (bool) $stmt->fetchColumn();
    }

    private function isLockedRedis(string $key): bool
    {
        if (!$this->redis) {
            return false;
        }

        $payload = $this->redis->get($this->lockKey($key));
        if (!is_string($payload)) {
            return false;
        }

        $data = @json_decode($payload, true);
        if (!is_array($data)) {
            return false;
        }

        $expiresAt = (int) ($data[self::REDIS_EXPIRE_AT_KEY] ?? 0);
        if ($expiresAt <= 0) {
            return true;
        }

        if ($expiresAt <= time()) {
            $this->redis->del($this->lockKey($key));
            return false;
        }

        return true;
    }

    /**
     * Checks if this instance owns a lock for the given key.
     */
    public function ownsLock(string $key): bool
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return false;
            }

            $payload = $this->redis->get($this->lockKey($key));
            if (!is_string($payload)) {
                return false;
            }

            $data = @json_decode($payload, true);
            if (!is_array($data)) {
                return false;
            }

            return ($data[self::REDIS_OWNER_KEY] ?? null) === $this->owner;
        }

        $stmt = $this->statement(
            'lock_owner_check',
            'SELECT 1 FROM locks WHERE key = ? AND owner = ? AND expire_at > ?'
        );

        $stmt->execute([$key, $this->owner, time()]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Releases all expired locks.
     */
    public function releaseExpiredLocks(): int
    {
        if ($this->isRedis()) {
            return $this->releaseExpiredLocksRedis();
        }

        try {
            $stmt = $this->pdo?->prepare('DELETE FROM locks WHERE expire_at <= ?');
            $stmt?->execute([time()]);
            return (int) ($stmt?->rowCount() ?? 0);
        } catch (\PDOException) {
            return 0;
        }
    }

    private function releaseExpiredLocksRedis(): int
    {
        if (!$this->redis) {
            return 0;
        }

        $now = time();
        $cursor = 0;
        $deleted = 0;
        do {
            $found = $this->redis->scan($cursor, $this->namespace . ':*');
            if ($found === false) {
                break;
            }

            foreach ((array) $found as $redisKey) {
                if (!is_string($redisKey)) {
                    continue;
                }

                $raw = $this->redis->get($redisKey);
                if (!is_string($raw)) {
                    continue;
                }

                $payload = @json_decode($raw, true);
                if (!is_array($payload)) {
                    continue;
                }

                $expiresAt = (int) ($payload[self::REDIS_EXPIRE_AT_KEY] ?? 0);
                if ($expiresAt > 0 && $expiresAt <= $now) {
                    $deleted += (int) $this->redis->del($redisKey);
                }
            }
        } while ($cursor > 0);

        return $deleted;
    }

    /**
     * Extends the expiration time of a lock.
     */
    public function extendLock(string $key, int $additionalSeconds): bool
    {
        if ($this->isRedis()) {
            return $this->extendRedisLock($key, $additionalSeconds);
        }

        try {
            $stmt = $this->statement(
                'lock_extend',
                'UPDATE locks SET expire_at = expire_at + ? WHERE key = ? AND owner = ? AND expire_at > ?'
            );

            $stmt->execute([$additionalSeconds, $key, $this->owner, time()]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    private function extendRedisLock(string $key, int $additionalSeconds): bool
    {
        if (!$this->redis) {
            return false;
        }

        $script = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if not current then
    return 0
end
local data = cjson.decode(current)
if not data or not data.owner or not data.expire_at then
    return 0
end
if data.owner ~= ARGV[1] then
    return 0
end
local nextExpire = tonumber(data.expire_at) + tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local ttl = nextExpire - now
if ttl <= 0 then
    return 0
end
data.expire_at = nextExpire
redis.call('SET', KEYS[1], cjson.encode(data), 'XX', 'EX', ttl)
return 1
LUA;

        $result = (int) $this->redis->eval(
            $script,
            [
                $this->lockKey($key),
                $this->owner,
                (string) max(1, $additionalSeconds),
                (string) time(),
            ],
            1
        );

        return $result > 0;
    }

    /**
     * Executes a callback while holding a lock.
     */
    public function withLock(string $key, callable $callback, int $timeout = 10, int $waitTimeout = 5): mixed
    {
        if (!$this->lock($key, $timeout, $waitTimeout)) {
            throw new LockUtilException("Failed to acquire lock for key: {$key}");
        }

        try {
            return $callback($this);
        } finally {
            $this->unlock($key);
        }
    }

    /**
     * Gets the current lock owner identifier.
     */
    public function getLockOwner(): string
    {
        return $this->owner;
    }

    /**
     * Get lock information for a specific key.
     */
    public function getLockInfo(string $key): null|array
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return null;
            }

            $raw = $this->redis->get($this->lockKey($key));
            if (!is_string($raw)) {
                return null;
            }

            $payload = @json_decode($raw, true);
            if (!is_array($payload)) {
                return null;
            }

            return [
                'owner' => $payload[self::REDIS_OWNER_KEY] ?? null,
                'locked_at' => $payload[self::REDIS_LOCKED_AT_KEY] ?? null,
                'expire_at' => $payload[self::REDIS_EXPIRE_AT_KEY] ?? null,
            ];
        }

        $stmt = $this->statement(
            'lock_info',
            'SELECT owner, locked_at, expire_at FROM locks WHERE key = ? AND expire_at > ?'
        );

        $stmt->execute([$key, time()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Force release a lock (administrative operation).
     */
    public function forceUnlock(string $key): bool
    {
        if ($this->isRedis()) {
            if (!$this->redis) {
                return false;
            }

            return (bool) $this->redis->del($this->lockKey($key));
        }

        try {
            $stmt = $this->statement('force_unlock', 'DELETE FROM locks WHERE key = ?');
            $stmt->execute([$key]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    public function optimize(): self
    {
        if ($this->isRedis()) {
            $this->releaseExpiredLocks();
            return $this;
        }

        try {
            $this->releaseExpiredLocks();
        } catch (\PDOException $e) {
            return $this;
        }

        return $this;
    }

    public function __destruct()
    {
        try {
            $this->unlockAll();
        } catch (\Throwable) {
            // Ignore cleanup errors.
        }
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->isLocked((string) $offset);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->getLockInfo((string) $offset);
    }

    /**
     * @param mixed $offset
     * @param mixed $value
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
     * @param mixed $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->unlock((string) $offset);
    }
}
