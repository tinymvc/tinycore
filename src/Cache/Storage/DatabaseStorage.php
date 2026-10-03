<?php

namespace Spark\Cache\Storage;

use PDO;
use PDOException;
use PDOStatement;
use Spark\Cache\Contracts\CacheStorageContract;
use Spark\Cache\Exceptions\CacheException;
use Spark\Database\DB;
use function array_chunk;
use function array_fill;
use function array_keys;
use function array_map;
use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function microtime;
use function min;
use function preg_match;
use function random_int;
use function serialize;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtotime;
use function substr;
use function time;
use function unserialize;
use function usleep;

/**
 * Database (PDO) storage for cache entries and locks.
 *
 * Works with the framework `caches` and `locks` tables on SQLite, MySQL/MariaDB and PostgreSQL,
 * through the framework DB connection (DB::getPdo()). Nothing is created at runtime: run your
 * migrations first.
 *
 * Table mapping:
 *   caches(key PK, group, data, expiration)  -> `group` holds the namespace hash, `key` is "<namespace hash>:<key>:"
 *   locks(key PK, owner, expiration)         -> `key` is "<namespace hash>:<key>:"
 *
 * Design rules (what keeps it stable under concurrency):
 *  - No operation relies on an application-level check-then-write. Writes are single atomic
 *    statements (upsert, INSERT ... + conditional UPDATE) or run in a transaction with a row lock.
 *  - Transactions go through DB::transaction(), which uses savepoints when the application already
 *    has a transaction open, so Cache calls can safely be made inside DB::transaction().
 *  - Locks always use their own PDO connection (unless `lock_connection` names one), so a lock is
 *    visible to other processes immediately and is not rolled back with an application transaction.
 *  - Values are stored as base64(serialize()) so binary data is safe in TEXT columns on every driver.
 *  - Non-contention database errors (missing table, bad credentials, ...) throw CacheException instead
 *    of being reported as "lock busy".
 *
 * Supported config: table, connection, lock_table, lock_connection.
 */
class DatabaseStorage implements CacheStorageContract
{
    /** Length of the `key` column created by the default migration (string => VARCHAR(255)). */
    private const MAX_KEY_LENGTH = 255;

    /** Maximum number of bound parameters per IN (...) list. */
    private const CHUNK_SIZE = 500;

    private string $prefix;

    /** @var null|array<string, mixed> */
    private ?array $cacheHandle = null;

    /** @var null|array<string, mixed> */
    private ?array $lockHandle = null;

    /**
     * Locks acquired through this instance: owner => [key => true].
     *
     * The owner token is unique per Lock instance and every Lock owns its storage instance, so this
     * is the complete set of rows for that owner. It lets unlockAll() (called from Lock::__destruct)
     * skip the database entirely when nothing was locked.
     *
     * @var array<string, array<string, true>>
     */
    private array $held = [];

    /**
     * @param string $type Kept for constructor compatibility with Cache/Lock; both tables are resolved lazily.
     */
    public function __construct(
        private readonly string $name,
        private readonly array $config = [],
        private readonly string $type = 'cache',
    ) {
        $this->prefix = hash('sha256', $name) . ':';
    }

    /* ---------------------------------------------------------------------
     | Cache
     | ------------------------------------------------------------------ */

    public function has(string $key, bool $eraseExpired = false): bool
    {
        $h = $this->cacheHandle();
        $physical = $this->physicalKey($key);

        $row = $this->run(
            $h,
            "SELECT {$h['key']} AS k, {$h['exp']} AS e FROM {$h['table']} WHERE {$h['key']} = ?",
            [$physical]
        )->fetch(PDO::FETCH_ASSOC);

        // Exact comparison: MySQL collations are usually case-insensitive.
        if (!is_array($row) || $row['k'] !== $physical) {
            return false;
        }

        if (!$this->isActive((int) $row['e'])) {
            $eraseExpired && $this->eraseKeyIfExpired($h, $physical);
            return false;
        }

        return true;
    }

    public function store(string $key, mixed $data, null|string $expire = null): void
    {
        $h = $this->cacheHandle();

        $this->run($h, $this->upsertSql($h), [
            $this->physicalKey($key),
            hash('sha256', $this->name),
            $this->encode($data),
            $this->expireAt($expire),
        ]);
    }

    public function retrieve(string|array $keys, bool $eraseExpired = false): mixed
    {
        $h = $this->cacheHandle();

        if (is_array($keys)) {
            $wanted = [];
            foreach ($keys as $key) {
                $wanted[$this->physicalKey((string) $key)] = (string) $key;
            }

            $results = [];

            foreach (array_chunk(array_keys($wanted), self::CHUNK_SIZE) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
                $stmt = $this->run(
                    $h,
                    "SELECT {$h['key']} AS k, {$h['data']} AS d, {$h['exp']} AS e FROM {$h['table']} WHERE {$h['key']} IN ($placeholders)",
                    $chunk
                );

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!isset($wanted[$row['k']])) {
                        continue; // Case-insensitive collation match of a different key.
                    }

                    if (!$this->isActive((int) $row['e'])) {
                        $eraseExpired && $this->eraseKeyIfExpired($h, $row['k']);
                        continue;
                    }

                    [$ok, $value] = $this->decode($row['d']);
                    $ok && $results[$wanted[$row['k']]] = $value;
                }
            }

            return $results;
        }

        $physical = $this->physicalKey($keys);
        $row = $this->run(
            $h,
            "SELECT {$h['key']} AS k, {$h['data']} AS d, {$h['exp']} AS e FROM {$h['table']} WHERE {$h['key']} = ?",
            [$physical]
        )->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || $row['k'] !== $physical) {
            return null;
        }

        if (!$this->isActive((int) $row['e'])) {
            $eraseExpired && $this->eraseKeyIfExpired($h, $physical);
            return null;
        }

        [$ok, $value] = $this->decode($row['d']);

        return $ok ? $value : null;
    }

    public function retrieveAll(bool $eraseExpired = false): array
    {
        $eraseExpired && $this->eraseExpired();

        $h = $this->cacheHandle();
        $stmt = $this->run(
            $h,
            "SELECT {$h['key']} AS k, {$h['data']} AS d FROM {$h['table']} WHERE {$h['group']} = ? AND ({$h['exp']} = 0 OR {$h['exp']} > ?)",
            [hash('sha256', $this->name), time()]
        );

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!str_starts_with($row['k'], $this->prefix)) {
                continue;
            }

            [$ok, $value] = $this->decode($row['d']);
            $ok && $results[substr($row['k'], strlen($this->prefix), -1)] = $value;
        }

        return $results;
    }

    public function erase(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $h = $this->cacheHandle();
        $physical = array_map(fn($key) => $this->physicalKey((string) $key), $keys);

        foreach (array_chunk($physical, self::CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $this->run($h, "DELETE FROM {$h['table']} WHERE {$h['key']} IN ($placeholders)", $chunk);
        }
    }

    public function eraseExpired(): int
    {
        $h = $this->cacheHandle();

        return $this->run(
            $h,
            "DELETE FROM {$h['table']} WHERE {$h['group']} = ? AND {$h['exp']} > 0 AND {$h['exp']} <= ?",
            [hash('sha256', $this->name), time()]
        )->rowCount();
    }

    public function getExpired(): array
    {
        $h = $this->cacheHandle();
        $stmt = $this->run(
            $h,
            "SELECT {$h['key']} AS k, {$h['data']} AS d FROM {$h['table']} WHERE {$h['group']} = ? AND {$h['exp']} > 0 AND {$h['exp']} <= ?",
            [hash('sha256', $this->name), time()]
        );

        $expired = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!str_starts_with($row['k'], $this->prefix)) {
                continue;
            }

            [$ok, $value] = $this->decode($row['d']);
            $ok && $expired[substr($row['k'], strlen($this->prefix), -1)] = $value;
        }

        return $expired;
    }

    public function flush(): void
    {
        $h = $this->cacheHandle();
        $this->run($h, "DELETE FROM {$h['table']} WHERE {$h['group']} = ?", [hash('sha256', $this->name)]);
    }

    public function storeMany(array $items, null|string $expire = null): void
    {
        if ($items === []) {
            return;
        }

        $h = $this->cacheHandle();
        $sql = $this->upsertSql($h);
        $expireAt = $this->expireAt($expire);

        try {
            $h['db']->transaction(function () use ($h, $sql, $items, $expireAt) {
                foreach ($items as $key => $value) {
                    $this->run($h, $sql, [$this->physicalKey((string) $key), hash('sha256', $this->name), $this->encode($value), $expireAt]);
                }
            });
        } catch (PDOException | CacheException $e) {
            throw new CacheException('Failed to store multiple items: ' . $e->getMessage(), 0, $e);
        }
    }

    public function increment(string $key, int $amount = 1): int|false
    {
        $h = $this->cacheHandle();
        $physical = $this->physicalKey($key);

        try {
            return $h['db']->transaction(function () use ($h, $physical, $amount) {
                $row = $this->selectForUpdate($h, $physical);

                if ($row === null || !$this->isActive((int) $row['e'])) {
                    return false;
                }

                [$ok, $value] = $this->decode($row['d']);
                if (!$ok || $value === null || !is_numeric($value)) {
                    return false;
                }

                $newValue = (int) $value + $amount;

                $this->run(
                    $h,
                    "UPDATE {$h['table']} SET {$h['data']} = ? WHERE {$h['key']} = ?",
                    [$this->encode($newValue), $physical]
                );

                return $newValue;
            });
        } catch (PDOException | CacheException) {
            return false;
        }
    }

    public function add(string $key, mixed $value, null|string $expire = null): bool
    {
        $h = $this->cacheHandle();
        $physical = $this->physicalKey($key);
        $data = $this->encode($value);
        $expireAt = $this->expireAt($expire);

        $inserted = $this->insertIfAbsent(
            $h,
            "{$h['key']}, {$h['group']}, {$h['data']}, {$h['exp']}",
            [$physical, hash('sha256', $this->name), $data, $expireAt]
        );

        if ($inserted) {
            return true;
        }

        // The key exists: take it over only if it is expired. The condition is part of the UPDATE,
        // so two racing callers can never both succeed.
        return $this->run(
            $h,
            "UPDATE {$h['table']} SET {$h['group']} = ?, {$h['data']} = ?, {$h['exp']} = ? WHERE {$h['key']} = ? AND {$h['exp']} > 0 AND {$h['exp']} <= ?",
            [hash('sha256', $this->name), $data, $expireAt, $physical, time()]
        )->rowCount() === 1;
    }

    public function stats(): array
    {
        $h = $this->cacheHandle();

        try {
            $row = $this->run(
                $h,
                "SELECT COUNT(*) AS total, SUM(CASE WHEN {$h['exp']} > 0 AND {$h['exp']} <= ? THEN 1 ELSE 0 END) AS expired, COALESCE(SUM(LENGTH({$h['data']})), 0) AS size FROM {$h['table']} WHERE {$h['group']} = ?",
                [time(), hash('sha256', $this->name)]
            )->fetch(PDO::FETCH_ASSOC);
        } catch (CacheException) {
            return [];
        }

        $total = (int) ($row['total'] ?? 0);
        $expired = (int) ($row['expired'] ?? 0);

        return [
            'total_entries' => $total,
            'active_entries' => $total - $expired,
            'expired_entries' => $expired,
            'database_size' => (int) ($row['size'] ?? 0),
        ];
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $h = $this->cacheHandle();
        $physical = $this->physicalKey($key);

        try {
            return $h['db']->transaction(function () use ($h, $physical, $default) {
                $row = $this->selectForUpdate($h, $physical);

                if ($row === null || !$this->isActive((int) $row['e'])) {
                    return $default;
                }

                [$ok, $value] = $this->decode($row['d']);
                if (!$ok) {
                    return $default;
                }

                $this->run($h, "DELETE FROM {$h['table']} WHERE {$h['key']} = ?", [$physical]);

                return $value;
            });
        } catch (PDOException | CacheException) {
            return $default;
        }
    }

    public function storeManyWithExpiry(array $items): void
    {
        if ($items === []) {
            return;
        }

        $h = $this->cacheHandle();
        $sql = $this->upsertSql($h);

        try {
            $h['db']->transaction(function () use ($h, $sql, $items) {
                foreach ($items as $key => $config) {
                    if (!is_array($config)) {
                        continue;
                    }

                    $this->run($h, $sql, [
                        $this->physicalKey((string) $key),
                        hash('sha256', $this->name),
                        $this->encode($config['value'] ?? null),
                        $this->expireAt($config['expire'] ?? null),
                    ]);
                }
            });
        } catch (PDOException | CacheException $e) {
            throw new CacheException('Failed to store items with expiry: ' . $e->getMessage(), 0, $e);
        }
    }

    public function ttl(string $key): ?int
    {
        $h = $this->cacheHandle();
        $physical = $this->physicalKey($key);

        $row = $this->run(
            $h,
            "SELECT {$h['key']} AS k, {$h['exp']} AS e FROM {$h['table']} WHERE {$h['key']} = ?",
            [$physical]
        )->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || $row['k'] !== $physical) {
            return null;
        }

        $expires_at = (int) $row['e'];

        if ($expires_at === null || $expires_at === 0) {
            return null;
        }

        $ttl = $expires_at - time();

        // Already expired entries are reported as missing, like the Redis and file drivers.
        return $ttl > 0 ? $ttl : null;
    }

    public function optimizeCache(): void
    {
        try {
            $this->eraseExpired();
        } catch (CacheException) {
            // Ignore optimize errors.
        }
    }

    /* ---------------------------------------------------------------------
     | Locks
     | ------------------------------------------------------------------ */

    public function lock(string $key, string $owner, int $timeout = 10, int $waitTimeout = 5): bool
    {
        $h = $this->lockHandle();
        $physical = $this->physicalKey($key);
        $timeout = max(1, $timeout);
        $waitTimeout = max(0, $waitTimeout);
        $deadline = microtime(true) + $waitTimeout;
        $sleep = 5000;

        while (true) {
            try {
                if ($this->tryAcquire($h, $physical, $owner, $timeout)) {
                    $this->held[$owner][$key] = true;
                    return true;
                }
            } catch (CacheException $e) {
                // Deadlocks / busy database are contention. Anything else (missing table, auth, ...)
                // must not be disguised as "someone else holds the lock".
                if (!$this->isRetryable($e)) {
                    throw $e;
                }
            }

            if ($waitTimeout === 0 || microtime(true) >= $deadline) {
                return false;
            }

            // Backoff with jitter (5ms -> 50ms).
            usleep($sleep + random_int(0, 2000));
            $sleep = min($sleep * 2, 50000);
        }
    }

    public function unlock(string $key, string $owner): bool
    {
        unset($this->held[$owner][$key]);

        $h = $this->lockHandle();
        $ownerColumn = $h['driver'] === 'mysql' ? "CAST({$h['owner']} AS BINARY)" : $h['owner'];

        try {
            return $this->run(
                $h,
                "DELETE FROM {$h['table']} WHERE {$h['key']} = ? AND $ownerColumn = ?",
                [$this->physicalKey($key), $owner]
            )->rowCount() > 0;
        } catch (CacheException) {
            return false;
        }
    }

    public function unlockAll(string $owner): int
    {
        $released = 0;

        foreach (array_keys($this->held[$owner] ?? []) as $key) {
            $released += (int) $this->unlock((string) $key, $owner);
        }

        unset($this->held[$owner]);

        return $released;
    }

    public function isLocked(string $key): bool
    {
        $h = $this->lockHandle();
        $physical = $this->physicalKey($key);

        $row = $this->run(
            $h,
            "SELECT {$h['key']} AS k FROM {$h['table']} WHERE {$h['key']} = ? AND {$h['exp']} > ?",
            [$physical, time()]
        )->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && $row['k'] === $physical;
    }

    public function ownsLock(string $key, string $owner): bool
    {
        $h = $this->lockHandle();
        $physical = $this->physicalKey($key);

        $row = $this->run(
            $h,
            "SELECT {$h['key']} AS k, {$h['owner']} AS o FROM {$h['table']} WHERE {$h['key']} = ? AND {$h['exp']} > ?",
            [$physical, time()]
        )->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && $row['k'] === $physical && $row['o'] === $owner;
    }

    public function releaseExpiredLocks(): int
    {
        $h = $this->lockHandle();

        try {
            return $this->run($h, "DELETE FROM {$h['table']} WHERE {$h['exp']} <= ?", [time()])->rowCount();
        } catch (CacheException) {
            return 0;
        }
    }

    public function extendLock(string $key, string $owner, int $additionalSeconds): bool
    {
        $h = $this->lockHandle();
        $ownerColumn = $h['driver'] === 'mysql' ? "CAST({$h['owner']} AS BINARY)" : $h['owner'];

        try {
            return $this->run(
                $h,
                "UPDATE {$h['table']} SET {$h['exp']} = {$h['exp']} + ? WHERE {$h['key']} = ? AND $ownerColumn = ? AND {$h['exp']} > ?",
                [max(1, $additionalSeconds), $this->physicalKey($key), $owner, time()]
            )->rowCount() > 0;
        } catch (CacheException) {
            return false;
        }
    }

    public function getLockInfo(string $key): ?array
    {
        $h = $this->lockHandle();
        $physical = $this->physicalKey($key);

        $row = $this->run(
            $h,
            "SELECT {$h['key']} AS k, {$h['owner']} AS o, {$h['exp']} AS e FROM {$h['table']} WHERE {$h['key']} = ? AND {$h['exp']} > ?",
            [$physical, time()]
        )->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || $row['k'] !== $physical) {
            return null;
        }

        return [
            'owner' => $row['o'],
            'expire_at' => (int) $row['e'],
        ];
    }

    public function forceUnlock(string $key): bool
    {
        $h = $this->lockHandle();

        try {
            return $this->run($h, "DELETE FROM {$h['table']} WHERE {$h['key']} = ?", [$this->physicalKey($key)])->rowCount() > 0;
        } catch (CacheException) {
            return false;
        }
    }

    public function optimizeLocks(): void
    {
        $this->releaseExpiredLocks();
    }

    /* ---------------------------------------------------------------------
     | Internals: lock acquisition
     | ------------------------------------------------------------------ */

    /**
     * One acquisition attempt, atomic without an explicit transaction:
     *  1. INSERT the row. Succeeds only if nobody holds the key.
     *  2. If the row exists, UPDATE it to the new owner only WHERE it is expired. The expiry check
     *     is part of the statement, so two processes taking over the same expired lock can never
     *     both win.
     * INSERT comes first so the common uncontended path takes no gap locks on InnoDB.
     */
    private function tryAcquire(array $h, string $physical, string $owner, int $timeout): bool
    {
        $now = time();
        $expireAt = $now + $timeout;

        if ($this->insertIfAbsent($h, "{$h['key']}, {$h['owner']}, {$h['exp']}", [$physical, $owner, $expireAt])) {
            return true;
        }

        return $this->run(
            $h,
            "UPDATE {$h['table']} SET {$h['owner']} = ?, {$h['exp']} = ? WHERE {$h['key']} = ? AND {$h['exp']} <= ?",
            [$owner, $expireAt, $physical, $now]
        )->rowCount() === 1;
    }

    /* ---------------------------------------------------------------------
     | Internals: SQL helpers
     | ------------------------------------------------------------------ */

    /**
     * Insert a row unless the primary key already exists. Returns true when the row was inserted.
     *
     * PostgreSQL aborts the whole transaction on a duplicate-key error, so PostgreSQL and SQLite use
     * ON CONFLICT DO NOTHING (no error is raised). MySQL has no safe equivalent (INSERT IGNORE
     * turns truncation errors into warnings), so it uses a plain INSERT and catches error 1062.
     */
    private function insertIfAbsent(array $h, string $columns, array $params): bool
    {
        $placeholders = implode(', ', array_fill(0, count($params), '?'));
        $sql = "INSERT INTO {$h['table']} ($columns) VALUES ($placeholders)";

        if ($h['driver'] !== 'mysql') {
            return $this->run($h, $sql . ' ON CONFLICT DO NOTHING', $params)->rowCount() === 1;
        }

        try {
            $this->run($h, $sql, $params);
            return true;
        } catch (CacheException $e) {
            $previous = $e->getPrevious();

            if ($previous instanceof PDOException && (int) ($previous->errorInfo[1] ?? 0) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    private function upsertSql(array $h): string
    {
        $insert = "INSERT INTO {$h['table']} ({$h['key']}, {$h['group']}, {$h['data']}, {$h['exp']}) VALUES (?, ?, ?, ?)";

        if ($h['driver'] === 'mysql') {
            return "$insert ON DUPLICATE KEY UPDATE {$h['group']} = VALUES({$h['group']}), {$h['data']} = VALUES({$h['data']}), {$h['exp']} = VALUES({$h['exp']})";
        }

        return "$insert ON CONFLICT ({$h['key']}) DO UPDATE SET {$h['group']} = excluded.{$h['group']}, {$h['data']} = excluded.{$h['data']}, {$h['exp']} = excluded.{$h['exp']}";
    }

    /**
     * Read one cache row and hold a write lock on it until the surrounding transaction ends.
     *
     * MySQL/PostgreSQL: SELECT ... FOR UPDATE (always reads the latest committed row).
     * SQLite has no FOR UPDATE, so a no-op UPDATE takes the database write lock first.
     * Must be called inside DB::transaction().
     *
     * @return null|array{k: string, d: string, e: mixed}
     */
    private function selectForUpdate(array $h, string $physical): ?array
    {
        $select = "SELECT {$h['key']} AS k, {$h['data']} AS d, {$h['exp']} AS e FROM {$h['table']} WHERE {$h['key']} = ?";

        if ($h['driver'] === 'sqlite') {
            $this->run($h, "UPDATE {$h['table']} SET {$h['exp']} = {$h['exp']} WHERE {$h['key']} = ?", [$physical]);
        } else {
            $select .= ' FOR UPDATE';
        }

        $row = $this->run($h, $select, [$physical])->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && $row['k'] === $physical ? $row : null;
    }

    private function eraseKeyIfExpired(array $h, string $physical): void
    {
        try {
            $this->run(
                $h,
                "DELETE FROM {$h['table']} WHERE {$h['key']} = ? AND {$h['exp']} > 0 AND {$h['exp']} <= ?",
                [$physical, time()]
            );
        } catch (CacheException) {
            // Best effort: an expired entry is already reported as missing.
        }
    }

    /**
     * Prepare, bind (ints as PARAM_INT) and execute. PDOException is rethrown as CacheException
     * with the original attached as the previous exception.
     */
    private function run(array $h, string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $h['db']->getPdo()->prepare($sql);

            foreach (array_values($params) as $i => $value) {
                $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }

            $stmt->execute();

            return $stmt;
        } catch (PDOException $e) {
            throw new CacheException('Cache database error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Deadlock, serialization failure, lock wait timeout and SQLite busy/locked are safe to retry.
     */
    private function isRetryable(CacheException $e): bool
    {
        $previous = $e->getPrevious();

        if (!$previous instanceof PDOException) {
            return false;
        }

        $sqlState = (string) ($previous->errorInfo[0] ?? '');
        $driverCode = (int) ($previous->errorInfo[1] ?? 0);

        return in_array($sqlState, ['40001', '40P01'], true)
            || in_array($driverCode, [1205, 1213], true)      // MySQL: lock wait timeout, deadlock
            || ($sqlState === 'HY000' && in_array($driverCode & 0xFF, [5, 6], true)); // SQLite: BUSY, LOCKED
    }

    /* ---------------------------------------------------------------------
     | Internals: connection handles
     | ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function cacheHandle(): array
    {
        return $this->cacheHandle ??= $this->makeHandle(
            (string) ($this->config['table'] ?? 'caches'),
            $this->config['connection'] ?? null,
            false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lockHandle(): array
    {
        return $this->lockHandle ??= $this->makeHandle(
            (string) ($this->config['lock_table'] ?? 'locks'),
            $this->config['lock_connection'] ?? null,
            true,
        );
    }

    /**
     * Resolve the DB instance and pre-quote identifiers for its driver.
     *
     * - Named connection: DB::connection($name), which is its own PDO.
     * - Cache without a named connection: the application's shared DB (app(DB::class)).
     * - Locks without a named connection: a dedicated DB::connection() so lock rows are committed
     *   immediately and are independent of any transaction the application has open.
     *
     * @return array<string, mixed>
     */
    private function makeHandle(string $table, mixed $connection, bool $dedicated): array
    {
        $connection = is_string($connection) && $connection !== '' ? $connection : null;
        $owned = true;

        if ($connection !== null) {
            $db = DB::connection($connection);
        } elseif ($dedicated) {
            $db = DB::connection();
        } else {
            $db = app(DB::class);
            $owned = false;
        }

        $driver = $db->getDriver();
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new CacheException("Unsupported database driver [{$driver}] for cache/locks. Use mysql, pgsql or sqlite.");
        }

        if ($owned && $driver === 'sqlite') {
            try {
                $db->getPdo()->exec('PRAGMA busy_timeout = 5000');
            } catch (PDOException $e) {
                throw new CacheException('Cache database error: ' . $e->getMessage(), 0, $e);
            }
        }

        $q = $driver === 'mysql' ? '`' : '"';
        $quote = static fn(string $identifier): string => $q . str_replace($q, $q . $q, $identifier) . $q;

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table) !== 1) {
            throw new CacheException("Invalid cache table name [{$table}].");
        }

        return [
            'db' => $db,
            'driver' => $driver,
            'table' => implode('.', array_map($quote, explode('.', $table))),
            'key' => $quote('key'),
            'group' => $quote('group'),
            'data' => $quote('data'),
            'exp' => $quote('expiration'),
            'owner' => $quote('owner'),
        ];
    }

    /* ---------------------------------------------------------------------
     | Internals: value helpers
     | ------------------------------------------------------------------ */

    /**
     * Rows are namespaced by cache name: the table has a single-column primary key, so the name is
     * part of the stored key ("<namespace hash>:<key>:") and also stored in the `group` column.
     */
    private function physicalKey(string $key): string
    {
        $physical = $this->prefix . $key . ':';

        if (strlen($physical) > self::MAX_KEY_LENGTH) {
            throw new CacheException(
                "Cache key is too long ({$this->prefix}{$key}): the stored key (namespace hash + key + separators) may be at most "
                . self::MAX_KEY_LENGTH . ' bytes.'
            );
        }

        return $physical;
    }

    /** base64 keeps binary-unsafe serialize() output (NUL bytes, invalid UTF-8) valid in TEXT columns. */
    private function encode(mixed $data): string
    {
        return base64_encode(serialize($data));
    }

    /**
     * @return array{0: bool, 1: mixed} [decoded successfully, value]
     */
    private function decode(mixed $raw): array
    {
        if (!is_string($raw)) {
            return [false, null];
        }

        $binary = base64_decode($raw, true);
        if ($binary === false) {
            return [false, null];
        }

        $value = @unserialize($binary);

        // unserialize() returns false both for stored false and for failures.
        if ($value === false && $binary !== 'b:0;') {
            return [false, null];
        }

        return [true, $value];
    }

    private function isActive(int $expiration): bool
    {
        return $expiration === 0 || $expiration > time();
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
