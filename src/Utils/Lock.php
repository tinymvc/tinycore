<?php

namespace Spark\Utils;

use PDO;
use PDOStatement;
use Spark\Contracts\Utils\LockUtilContract;
use Spark\Exceptions\Utils\LockUtilException;
use Spark\Support\Traits\Macroable;
use function sprintf;

/**
 * Class Lock
 *
 * A utility class for managing locks using an SQLite database.
 *
 * @package Spark\Utils
 */
class Lock implements LockUtilContract, \ArrayAccess
{
    use Macroable;

    /** @var PDO The PDO connection instance */
    private PDO $pdo;

    /** @var array Cached prepared statements */
    private array $statements = [];

    /** @var string Unique identifier for this instance's lock owner */
    private string $owner;

    /**
     * Lock constructor.
     *
     * @param string $name The name for the lock instance (used for cache file naming).
     */
    public function __construct(string $name = 'default')
    {
        try {
            // Determine the cache file path.
            $cache = sprintf('%s.lock', cache_dir(md5($name)));
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

            // Generate unique lock owner identifier
            $this->owner = sprintf('%s-%s-%s', gethostname(), getmypid(), uniqid());

            $createDB && $this->createTables(); // Create tables if they don't exist.
        } catch (\PDOException $e) {
            throw new LockUtilException("Failed to connect to SQLite database: " . $e->getMessage());
        }
    }

    /**
     * Factory method to create a Lock instance.
     *
     * @param string $name The name for the lock instance.
     * @return static
     */
    public static function make(string $name = 'default'): static
    {
        return new static($name);
    }

    /**
     * Creates the necessary tables and indexes for caching and locking.
     *
     * @return void
     */
    private function createTables(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS locks (
                key TEXT PRIMARY KEY,
                owner TEXT DEFAULT NULL,
                locked_at INTEGER NOT NULL,
                expire_at INTEGER NOT NULL,
                CHECK (expire_at >= 0)
            )
        ");

        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_lock_expire 
            ON locks(expire_at) 
            WHERE expire_at > 0
        ");

        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_lock_owner 
            ON locks(owner) 
            WHERE owner IS NOT NULL
        ");

        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_lock_locked 
            ON locks(locked_at)
        ");

        $this->pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_lock_key 
            ON locks(key)
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
     * Acquires a lock with the given key.
     *
     * @param string $key The lock key.
     * @param int $timeout Lock timeout in seconds (default: 10).
     * @param int $waitTimeout Maximum wait time in seconds (default: 5).
     * @return bool True if lock acquired, false otherwise.
     */
    public function lock(string $key, int $timeout = 10, int $waitTimeout = 5): bool
    {
        $startTime = time();
        $expireAt = time() + $timeout;

        while (true) {
            // Clean up expired locks
            $this->releaseExpiredLocks();

            try {
                $this->pdo->beginTransaction();

                // Try to acquire lock
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

                $this->pdo->commit();
                return true;
            } catch (\PDOException $e) {
                $this->pdo->rollBack();

                // Lock already exists, check if we should wait
                if (time() - $startTime >= $waitTimeout) {
                    return false;
                }

                // Wait a bit before retrying (with exponential backoff)
                usleep(min(100000, 10000 * (time() - $startTime + 1)));
            }
        }
    }

    /**
     * Releases a lock with the given key.
     *
     * @param string $key The lock key.
     * @return bool True if lock released, false if not owned or doesn't exist.
     */
    public function unlock(string $key): bool
    {
        try {
            $stmt = $this->statement(
                'lock_delete',
                "DELETE FROM locks WHERE key = ? AND owner = ?"
            );

            $stmt->execute([$key, $this->owner]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Releases all locks owned by this instance.
     *
     * @return int Number of locks released.
     */
    public function unlockAll(): int
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM locks WHERE owner = ?");
            $stmt->execute([$this->owner]);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /**
     * Checks if a lock exists for the given key.
     *
     * @param string $key The lock key.
     * @return bool True if locked, false otherwise.
     */
    public function isLocked(string $key): bool
    {
        $this->releaseExpiredLocks();

        $stmt = $this->statement(
            'lock_check',
            "SELECT 1 FROM locks WHERE key = ? AND expire_at > ?"
        );

        $stmt->execute([$key, time()]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Checks if this instance owns a lock for the given key.
     *
     * @param string $key The lock key.
     * @return bool True if owned, false otherwise.
     */
    public function ownsLock(string $key): bool
    {
        $stmt = $this->statement(
            'lock_owner_check',
            "SELECT 1 FROM locks WHERE key = ? AND owner = ? AND expire_at > ?"
        );

        $stmt->execute([$key, $this->owner, time()]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Releases all expired locks.
     *
     * @return int Number of expired locks released.
     */
    public function releaseExpiredLocks(): int
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM locks WHERE expire_at <= ?");
            $stmt->execute([time()]);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /**
     * Extends the expiration time of a lock.
     *
     * @param string $key The lock key.
     * @param int $additionalSeconds Additional seconds to extend the lock.
     * @return bool True if extended, false otherwise.
     */
    public function extendLock(string $key, int $additionalSeconds): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE locks SET expire_at = expire_at + ? WHERE key = ? AND owner = ? AND expire_at > ?"
            );

            $stmt->execute([$additionalSeconds, $key, $this->owner, time()]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Executes a callback while holding a lock.
     *
     * @param string $key The lock key.
     * @param callable $callback The callback to execute.
     * @param int $timeout Lock timeout in seconds (default: 10).
     * @param int $waitTimeout Maximum wait time in seconds (default: 5).
     * @return mixed The result of the callback.
     * @throws LockUtilException If lock cannot be acquired.
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
     *
     * @return string
     */
    public function getLockOwner(): string
    {
        return $this->owner;
    }

    /**
     * Get lock information for a specific key.
     *
     * @param string $key The lock key.
     * @return array|null Lock information or null if not locked.
     */
    public function getLockInfo(string $key): null|array
    {
        $this->releaseExpiredLocks();

        $stmt = $this->pdo->prepare(
            "SELECT owner, locked_at, expire_at FROM locks WHERE key = ? AND expire_at > ?"
        );

        $stmt->execute([$key, time()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Force release a lock (administrative operation).
     *
     * @param string $key The lock key.
     * @return bool True if lock released.
     */
    public function forceUnlock(string $key): bool
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM locks WHERE key = ?");
            $stmt->execute([$key]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            return false;
        }
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
            $this->releaseExpiredLocks();

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
     * Cleanup resources and release locks owned by this instance.
     */
    public function __destruct()
    {
        // Release all locks owned by this instance
        try {
            $this->unlockAll();
        } catch (\Throwable $e) {
            // Ignore cleanup errors
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
        if (is_numeric($value)) {
            $value = ['timeout' => (int) $value, 'waitTimeout' => 5];
        }

        $this->lock((string) $offset, (int) ($value['timeout'] ?? 10), (int) ($value['waitTimeout'] ?? 5));
    }

    /**
     * @param mixed $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->unlock((string) $offset);
    }
}
