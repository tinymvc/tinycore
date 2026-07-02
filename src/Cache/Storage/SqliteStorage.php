<?php

namespace Spark\Cache\Storage;

use PDO;
use PDOStatement;
use RuntimeException;
use Spark\Cache\Contracts\CacheStorageContract;
use Spark\Cache\Exceptions\CacheException;
use function count;
use function dirname;
use function is_array;
use function is_dir;
use function is_numeric;
use function max;
use function md5;
use function mkdir;
use function pathinfo;
use function rtrim;
use function str_ends_with;
use function time;

class SqliteStorage implements CacheStorageContract
{
    private PDO $pdo;

    /** @var array<string, PDOStatement> */
    private array $statements = [];

    public function __construct(
        private readonly string $name,
        private readonly array $config,
        private readonly string $type = 'cache',
    ) {
        $path = $type === 'lock'
            ? $this->sqliteLockPath($config)
            : $this->sqliteCachePath($name, $config);

        try {
            $this->pdo = new PDO("sqlite:$path");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA cache_size = 10000');
            $this->pdo->exec('PRAGMA temp_store = MEMORY');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');

            if ($type === 'lock') {
                $this->createLockTables();
            } else {
                $this->createCacheTables();
            }
        } catch (\PDOException $e) {
            throw new RuntimeException('Failed to connect to SQLite database: ' . $e->getMessage(), previous: $e);
        }
    }

    public function has(string $key, bool $eraseExpired = false): bool
    {
        $eraseExpired && $this->eraseExpired();

        $stmt = $this->statement(
            'cache_has',
            'SELECT 1 FROM caches WHERE key = ? AND (expire_at = 0 OR expire_at > ?)'
        );

        $stmt->execute([$key, time()]);

        return (bool) $stmt->fetchColumn();
    }

    public function store(string $key, mixed $data, null|string $expire = null): void
    {
        $expireAt = $this->expireAt($expire);

        $stmt = $this->statement(
            'cache_store',
            'INSERT OR REPLACE INTO caches (key, data, created_at, expire_at) VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([$key, serialize($data), time(), $expireAt]);
    }

    public function retrieve(string|array $keys, bool $eraseExpired = false): mixed
    {
        $eraseExpired && $this->eraseExpired();

        if (is_array($keys)) {
            if ($keys === []) {
                return [];
            }

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
            'cache_retrieve',
            'SELECT data FROM caches WHERE key = ? AND (expire_at = 0 OR expire_at > ?)'
        );

        $stmt->execute([$keys, time()]);
        $result = $stmt->fetchColumn();

        return $result !== false ? unserialize($result) : null;
    }

    public function metadata(string $key): ?array
    {
        $stmt = $this->statement(
            'cache_metadata',
            'SELECT created_at, expire_at FROM caches WHERE key = ?'
        );

        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function retrieveAll(bool $eraseExpired = false): array
    {
        $eraseExpired && $this->eraseExpired();

        $stmt = $this->statement(
            'cache_retrieve_all',
            'SELECT key, data FROM caches WHERE expire_at = 0 OR expire_at > ?'
        );

        $stmt->execute([time()]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['key']] = unserialize($row['data']);
        }

        return $results;
    }

    public function erase(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $placeholders = rtrim(str_repeat('?,', count($keys)), ',');
        $stmt = $this->pdo->prepare("DELETE FROM caches WHERE key IN ($placeholders)");
        $stmt->execute($keys);
    }

    public function eraseExpired(): int
    {
        $stmt = $this->statement(
            'cache_erase_expired',
            'DELETE FROM caches WHERE expire_at > 0 AND expire_at <= ?'
        );

        $stmt->execute([time()]);

        return $stmt->rowCount();
    }

    public function getExpired(): array
    {
        $stmt = $this->statement(
            'cache_get_expired',
            'SELECT key, data FROM caches WHERE expire_at > 0 AND expire_at <= ?'
        );

        $stmt->execute([time()]);
        $expired = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $expired[$row['key']] = unserialize($row['data']);
        }

        return $expired;
    }

    public function flush(): void
    {
        $this->pdo->exec('DELETE FROM caches');
    }

    public function clear(): void
    {
        $this->flush();
        $this->pdo->exec('VACUUM');
    }

    public function storeMany(array $items, null|string $expire = null): void
    {
        if ($items === []) {
            return;
        }

        $expireAt = $this->expireAt($expire);
        $now = time();

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->statement(
                'cache_store_many',
                'INSERT OR REPLACE INTO caches (key, data, created_at, expire_at) VALUES (?, ?, ?, ?)'
            );

            foreach ($items as $key => $value) {
                $stmt->execute([(string) $key, serialize($value), $now, $expireAt]);
            }

            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->rollBack();
            throw new CacheException('Failed to store multiple items: ' . $e->getMessage(), previous: $e);
        }
    }

    public function increment(string $key, int $amount = 1): int|false
    {
        try {
            $this->pdo->beginTransaction();

            $value = $this->retrieve($key);
            if ($value === null || !is_numeric($value)) {
                $this->rollBack();
                return false;
            }

            $newValue = (int) $value + $amount;
            $stmt = $this->statement(
                'cache_increment_update',
                'UPDATE caches SET data = ?, created_at = ? WHERE key = ?'
            );
            $stmt->execute([serialize($newValue), time(), $key]);

            $this->pdo->commit();
            return $newValue;
        } catch (\PDOException) {
            $this->rollBack();
            return false;
        }
    }

    public function add(string $key, mixed $value, null|string $expire = null): bool
    {
        if ($this->has($key)) {
            return false;
        }

        $this->store($key, $value, $expire);
        return true;
    }

    public function stats(): array
    {
        try {
            $totalEntries = (int) $this->pdo->query('SELECT COUNT(*) FROM caches')->fetchColumn();

            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM caches WHERE expire_at > 0 AND expire_at <= ?');
            $stmt->execute([time()]);
            $expiredEntries = (int) $stmt->fetchColumn();

            $pageCount = (int) $this->pdo->query('PRAGMA page_count')->fetchColumn();
            $pageSize = (int) $this->pdo->query('PRAGMA page_size')->fetchColumn();

            return [
                'total_entries' => $totalEntries,
                'active_entries' => $totalEntries - $expiredEntries,
                'expired_entries' => $expiredEntries,
                'database_size' => $pageCount * $pageSize,
            ];
        } catch (\PDOException) {
            return [];
        }
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        try {
            $this->pdo->beginTransaction();

            $value = $this->retrieve($key);
            if ($value !== null) {
                $this->erase([$key]);
                $this->pdo->commit();
                return $value;
            }

            $this->pdo->commit();
            return $default;
        } catch (\PDOException) {
            $this->rollBack();
            return $default;
        }
    }

    public function storeManyWithExpiry(array $items): void
    {
        if ($items === []) {
            return;
        }

        $now = time();

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->statement(
                'cache_store_many_expiry',
                'INSERT OR REPLACE INTO caches (key, data, created_at, expire_at) VALUES (?, ?, ?, ?)'
            );

            foreach ($items as $key => $config) {
                if (!is_array($config)) {
                    continue;
                }

                $stmt->execute([
                    (string) $key,
                    serialize($config['value'] ?? null),
                    $now,
                    $this->expireAt($config['expire'] ?? null),
                ]);
            }

            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->rollBack();
            throw new CacheException('Failed to store items with expiry: ' . $e->getMessage(), previous: $e);
        }
    }

    public function ttl(string $key): ?int
    {
        $metadata = $this->metadata($key);
        if (!$metadata || (int) $metadata['expire_at'] === 0) {
            return null;
        }

        $ttl = (int) $metadata['expire_at'] - time();
        return $ttl > 0 ? $ttl : 0;
    }

    public function optimizeCache(): void
    {
        try {
            $this->eraseExpired();
            $this->pdo->exec('VACUUM');
            $this->pdo->exec('ANALYZE');
        } catch (\PDOException) {
            // Ignore optimize errors.
        }
    }

    public function lock(string $key, string $owner, int $timeout = 10, int $waitTimeout = 5): bool
    {
        $startTime = time();
        $timeout = max(1, $timeout);
        $waitTimeout = max(0, $waitTimeout);
        $expireAt = time() + $timeout;

        while (true) {
            $this->releaseExpiredLocks();

            try {
                $this->pdo->beginTransaction();

                $stmt = $this->statement(
                    'lock_insert',
                    'INSERT INTO locks (key, owner, locked_at, expire_at) VALUES (?, ?, ?, ?)'
                );

                $stmt->execute([$key, $owner, time(), $expireAt]);

                $this->pdo->commit();
                return true;
            } catch (\PDOException) {
                $this->rollBack();

                if ($waitTimeout === 0 || time() - $startTime >= $waitTimeout) {
                    return false;
                }

                usleep(10000);
            }
        }
    }

    public function unlock(string $key, string $owner): bool
    {
        try {
            $stmt = $this->statement('lock_delete', 'DELETE FROM locks WHERE key = ? AND owner = ?');
            $stmt->execute([$key, $owner]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    public function unlockAll(string $owner): int
    {
        try {
            $stmt = $this->statement('lock_delete_owner', 'DELETE FROM locks WHERE owner = ?');
            $stmt->execute([$owner]);
            return $stmt->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    public function isLocked(string $key): bool
    {
        $this->releaseExpiredLocks();

        $stmt = $this->statement(
            'lock_check',
            'SELECT 1 FROM locks WHERE key = ? AND expire_at > ?'
        );

        $stmt->execute([$key, time()]);
        return (bool) $stmt->fetchColumn();
    }

    public function ownsLock(string $key, string $owner): bool
    {
        $stmt = $this->statement(
            'lock_owner_check',
            'SELECT 1 FROM locks WHERE key = ? AND owner = ? AND expire_at > ?'
        );

        $stmt->execute([$key, $owner, time()]);
        return (bool) $stmt->fetchColumn();
    }

    public function releaseExpiredLocks(): int
    {
        try {
            $stmt = $this->statement('lock_delete_expired', 'DELETE FROM locks WHERE expire_at <= ?');
            $stmt->execute([time()]);
            return $stmt->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    public function extendLock(string $key, string $owner, int $additionalSeconds): bool
    {
        try {
            $stmt = $this->statement(
                'lock_extend',
                'UPDATE locks SET expire_at = expire_at + ? WHERE key = ? AND owner = ? AND expire_at > ?'
            );

            $stmt->execute([max(1, $additionalSeconds), $key, $owner, time()]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    public function getLockInfo(string $key): ?array
    {
        $stmt = $this->statement(
            'lock_info',
            'SELECT owner, locked_at, expire_at FROM locks WHERE key = ? AND expire_at > ?'
        );

        $stmt->execute([$key, time()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function forceUnlock(string $key): bool
    {
        try {
            $stmt = $this->statement('lock_force_unlock', 'DELETE FROM locks WHERE key = ?');
            $stmt->execute([$key]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    public function optimizeLocks(): void
    {
        $this->releaseExpiredLocks();
    }

    private function statement(string $key, string $sql): PDOStatement
    {
        return $this->statements[$key] ??= $this->pdo->prepare($sql);
    }

    private function createCacheTables(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS caches (
            key TEXT PRIMARY KEY,
            data BLOB NOT NULL,
            created_at INTEGER NOT NULL,
            expire_at INTEGER DEFAULT 0,
            CHECK (expire_at >= 0)
        )");

        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_key ON caches(key)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_created ON caches(created_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_expire ON caches(expire_at) WHERE expire_at > 0');
    }

    private function createLockTables(): void
    {
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

    private function expireAt(null|string $expire): int
    {
        if ($expire === null) {
            return 0;
        }

        $expireAt = strtotime($expire);

        return $expireAt === false ? 0 : (int) $expireAt;
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
