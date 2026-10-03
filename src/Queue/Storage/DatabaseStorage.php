<?php

namespace Spark\Queue\Storage;

use JsonException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Spark\Carbon;
use Spark\Database\DB;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\QueueStorageContract;
use Spark\Queue\Exceptions\FailedToLoadJobsException;
use Spark\Queue\Exceptions\FailedToSaveJobsException;
use Throwable;
use function array_fill;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function explode;
use function function_exists;
use function get_class;
use function implode;
use function in_array;
use function is_int;
use function is_string;
use function json_encode;
use function max;
use function mb_check_encoding;
use function mb_convert_encoding;
use function mb_strcut;
use function preg_match;
use function random_int;
use function sha1;
use function sprintf;
use function str_replace;
use function strlen;
use function strtr;
use function substr;
use function usleep;

/**
 * Database (PDO) queue storage for the framework `jobs` and `failed_jobs` tables.
 *
 * Works on SQLite, MySQL/MariaDB and PostgreSQL through the framework DB connection
 * (DB::getPdo()). Tables are created by your migrations, never at runtime.
 *
 * Concurrency rules:
 *  - A worker claims a job with a compare-and-set UPDATE (`... WHERE id = ? AND status = 'pending'`),
 *    so two workers can never reserve the same job. If it loses a race it simply tries the next
 *    candidate instead of going back to sleep.
 *  - pushOnce() is serialized per job fingerprint (GET_LOCK on MySQL, pg_advisory_xact_lock on
 *    PostgreSQL, the write lock of a transaction on SQLite), so concurrent dispatchOnce() calls
 *    cannot create duplicates.
 *  - Multi-statement changes use $this->db->transaction(), which uses savepoints when the application has
 *    a transaction open. Jobs pushed inside an application transaction therefore become visible
 *    to workers only after it commits.
 *  - Deadlocks, serialization failures and SQLite busy errors are retried automatically.
 *
 * Supported config: connection (named DB connection, default = application connection), table
 * (default `jobs`), failed_table (default `failed_jobs`).
 */
class DatabaseStorage implements QueueStorageContract
{
    use SerializesJobs;

    /** How many due jobs a worker looks at per round before giving up on a lost race. */
    private const CLAIM_CANDIDATES = 10;

    private const CLAIM_ROUNDS = 3;

    /** Keeps exception text inside a MySQL TEXT column (65,535 bytes). */
    private const MAX_EXCEPTION_BYTES = 60000;

    private PDO $pdo;

    private DB $db;

    private string $driver;

    /** @var array<string, string> Placeholder => quoted identifier. */
    private array $map = [];

    public function __construct(private readonly array $config = [])
    {
        $connection = $config['connection'] ?? null;

        $this->db = is_string($connection) && $connection !== ''
            ? DB::connection($connection)
            : app(DB::class);

        $this->pdo = $this->db->getPdo();

        $this->driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (!in_array($this->driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Unsupported database driver [{$this->driver}] for the queue. Use mysql, pgsql or sqlite.");
        }

        $q = $this->driver === 'mysql' ? '`' : '"';
        $quote = static fn(string $identifier): string => $q . str_replace($q, $q . $q, $identifier) . $q;

        $this->map = [
            '{jobs}' => $this->quoteTable((string) ($config['table'] ?? 'jobs'), $quote),
            '{failed}' => $this->quoteTable((string) ($config['failed_table'] ?? 'failed_jobs'), $quote),
        ];

        foreach ([
            'id',
            'queue',
            'payload',
            'scheduled_time',
            'reserved_at',
            'repeat',
            'status',
            'attempts',
            'job_id',
            'failed_at',
            'exception',
        ] as $column) {
            $this->map['{' . $column . '}'] = $quote($column);
        }
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /* ---------------------------------------------------------------------
     | Producing
     | ------------------------------------------------------------------ */

    public function push(JobContract $job, string $queue = 'default'): void
    {
        try {
            $this->insertJob($job, $queue);
        } catch (PDOException | JsonException $e) {
            throw new FailedToSaveJobsException('Failed to add job to the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    public function pushOnce(JobContract $job, string $queue = 'default'): void
    {
        try {
            $payload = $this->encodePayload($job);
            $repeat = $job->isRepeated() ? $job->getRepeat() : null;
            $fingerprint = sha1($queue . "\0" . $payload . "\0" . ($repeat ?? ''));

            $this->retrying(function () use ($job, $queue, $payload, $repeat, $fingerprint) {
                $this->withDedupeLock($fingerprint, function () use ($job, $queue, $payload, $repeat) {
                    if (!$this->jobExists($payload, $queue, $repeat)) {
                        $this->insertJob($job, $queue);
                    }
                });
            });
        } catch (PDOException | JsonException $e) {
            throw new FailedToSaveJobsException('Failed to add job to the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    /* ---------------------------------------------------------------------
     | Clearing / removing
     | ------------------------------------------------------------------ */

    public function clearAllJobs(): void
    {
        $this->db->transaction(function () {
            $this->run('DELETE FROM {failed}');
            $this->run('DELETE FROM {jobs}');
        });
    }

    public function clearRepeatedJobs(): void
    {
        $this->run('DELETE FROM {jobs} WHERE {repeat} IS NOT NULL');
    }

    public function clearFailedJobs(): void
    {
        $this->db->transaction(function () {
            $this->run("DELETE FROM {jobs} WHERE {status} = 'failed'");
            $this->run('DELETE FROM {failed}');
        });
    }

    public function removeJobById(int $id): bool
    {
        // failed_jobs rows are removed by the ON DELETE CASCADE foreign key.
        return $this->run('DELETE FROM {jobs} WHERE {id} = ?', [$id])->rowCount() > 0;
    }

    public function removeQueue(string $name): bool
    {
        return $this->run('DELETE FROM {jobs} WHERE {queue} = ?', [$name])->rowCount() > 0;
    }

    /* ---------------------------------------------------------------------
     | Consuming
     | ------------------------------------------------------------------ */

    public function getNextJob(array|string $queue = 'default'): false|JobContract
    {
        $names = $this->toList($queue);
        if ($names === []) {
            return false;
        }

        try {
            return $this->retrying(function () use ($names) {
                $in = implode(', ', array_fill(0, count($names), '?'));

                for ($round = 0; $round < self::CLAIM_ROUNDS; $round++) {
                    $now = $this->timestamp(now());

                    $rows = $this->run(
                        'SELECT {id}, {queue}, {payload}, {scheduled_time}, {reserved_at}, {repeat}, {status}, {attempts} '
                        . "FROM {jobs} WHERE {queue} IN ($in) AND {status} = 'pending' AND {scheduled_time} <= ? "
                        . 'ORDER BY {scheduled_time} ASC, {id} ASC LIMIT ' . self::CLAIM_CANDIDATES,
                        [...$names, $now]
                    )->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($rows as $row) {
                        // Compare-and-set: only one worker can flip pending -> reserved.
                        $claimed = $this->run(
                            "UPDATE {jobs} SET {status} = 'reserved', {reserved_at} = ? WHERE {id} = ? AND {status} = 'pending' AND {scheduled_time} = ? AND {scheduled_time} <= ?",
                            [$now, (int) $row['id'], $row['scheduled_time'], $now]
                        )->rowCount();

                        if ($claimed === 1) {
                            $row['status'] = 'reserved';
                            $row['reserved_at'] = $now;

                            return $this->unserializeJob($row);
                        }
                    }

                    // Nothing was due, or fewer candidates than the limit: no point in another round.
                    if (count($rows) < self::CLAIM_CANDIDATES) {
                        return false;
                    }
                }

                return false;
            });
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to get next job: ' . $e->getMessage(), previous: $e);
        }
    }

    public function updateJobStatus(int $jobId, string $status, int $attempts): void
    {
        $this->run(
            'UPDATE {jobs} SET {status} = ?, {attempts} = ?, {reserved_at} = ? WHERE {id} = ?',
            [
                $status,
                max(0, $attempts),
                in_array($status, ['reserved', 'processing'], true) ? $this->timestamp(now()) : null,
                $jobId,
            ]
        );
    }

    public function rescheduleJob(int $jobId, Carbon $nextRun): void
    {
        $this->run(
            "UPDATE {jobs} SET {scheduled_time} = ?, {status} = 'pending', {attempts} = 0, {reserved_at} = NULL WHERE {id} = ?",
            [$this->timestamp($nextRun), $jobId]
        );
    }

    public function markJobAsFailed(int $jobId, Throwable $exception, int $attempts): void
    {
        $stackTrace = $exception->getPrevious()?->getTraceAsString() ?? $exception->getTraceAsString();
        $text = $this->limitText(
            sprintf("%s: %s\nStack trace:\n%s", get_class($exception), $exception->getMessage(), $stackTrace)
        );

        try {
            $this->retrying(function () use ($jobId, $attempts, $text) {
                $this->db->transaction(function () use ($jobId, $attempts, $text) {
                    $updated = $this->run(
                        "UPDATE {jobs} SET {status} = 'failed', {attempts} = ?, {reserved_at} = NULL WHERE {id} = ?",
                        [$attempts, $jobId]
                    )->rowCount();

                    // rowCount() is 0 both for a missing job and for an unchanged row.
                    if ($updated === 0 && $this->run('SELECT 1 FROM {jobs} WHERE {id} = ?', [$jobId])->fetchColumn() === false) {
                        return; // The job was removed in the meantime.
                    }

                    // Idempotent: never two failure records for one job.
                    $this->run('DELETE FROM {failed} WHERE {job_id} = ?', [$jobId]);
                    $this->run(
                        'INSERT INTO {failed} ({job_id}, {failed_at}, {exception}, {attempts}) VALUES (?, ?, ?, ?)',
                        [$jobId, $this->timestamp(now()), $text, $attempts]
                    );
                });
            });
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to mark job as failed: ' . $e->getMessage(), previous: $e);
        }
    }

    public function retryJob(int $jobId, Carbon $retryTime, int $attempts): void
    {
        $this->run(
            "UPDATE {jobs} SET {scheduled_time} = ?, {status} = 'pending', {attempts} = ?, {reserved_at} = NULL WHERE {id} = ?",
            [$this->timestamp($retryTime), $attempts, $jobId]
        );
    }

    public function recoverStaleJobs(int $timeout = 3600): int
    {
        try {
            return $this->run(
                "UPDATE {jobs} SET {status} = 'pending', {reserved_at} = NULL "
                . "WHERE {status} IN ('processing', 'reserved') AND {reserved_at} IS NOT NULL AND {reserved_at} < ?",
                [$this->timestamp(now()->subSeconds($timeout))]
            )->rowCount();
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to recover stale jobs: ' . $e->getMessage(), previous: $e);
        }
    }

    /* ---------------------------------------------------------------------
     | Inspecting
     | ------------------------------------------------------------------ */

    public function getJobs(
        array|string|null $queue = null,
        array|string|null $status = null,
        int $from = 0,
        int $to = 500,
    ): array {
        try {
            $where = [];
            $params = [];

            foreach ([['{queue}', $queue], ['{status}', $status]] as [$column, $values]) {
                $list = $this->toList($values);

                if ($list !== []) {
                    $where[] = $column . ' IN (' . implode(', ', array_fill(0, count($list), '?')) . ')';
                    $params = [...$params, ...$list];
                }
            }

            $rows = $this->run(
                'SELECT {id}, {queue}, {payload}, {scheduled_time}, {reserved_at}, {repeat}, {status}, {attempts} FROM {jobs}'
                . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
                . ' ORDER BY {scheduled_time} ASC, {id} ASC LIMIT ? OFFSET ?',
                [...$params, max(0, $to), max(0, $from)]
            )->fetchAll(PDO::FETCH_ASSOC);

            return array_map(fn(array $row) => $this->unserializeJob($row), $rows);
        } catch (PDOException $e) {
            throw new FailedToLoadJobsException('Failed to load jobs from the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    public function getFailedJobs(int $from = 0, int $to = 500): array
    {
        try {
            $rows = $this->run(
                'SELECT fj.{id} AS failed_job_id, fj.{job_id} AS {id}, fj.{failed_at} AS {failed_at}, '
                . 'fj.{exception} AS {exception}, fj.{attempts} AS {attempts}, j.{payload} AS {payload}, '
                . 'j.{queue} AS {queue}, j.{scheduled_time} AS {scheduled_time}, j.{repeat} AS {repeat}, j.{status} AS {status} '
                . 'FROM {failed} fj JOIN {jobs} j ON fj.{job_id} = j.{id} '
                . 'ORDER BY fj.{failed_at} DESC, fj.{id} DESC LIMIT ? OFFSET ?',
                [max(0, $to), max(0, $from)]
            )->fetchAll(PDO::FETCH_ASSOC);

            return array_map(fn(array $row) => $this->unserializeJob($row), $rows);
        } catch (PDOException $e) {
            throw new FailedToLoadJobsException('Failed to load failed jobs from the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    public function retryFailedJobs(): void
    {
        try {
            $this->retrying(function () {
                $this->db->transaction(function () {
                    $this->run(
                        "UPDATE {jobs} SET {status} = 'pending', {attempts} = 0, {reserved_at} = NULL "
                        . 'WHERE {id} IN (SELECT {job_id} FROM {failed})'
                    );
                    $this->run('DELETE FROM {failed}');
                });
            });
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to retry failed jobs: ' . $e->getMessage(), previous: $e);
        }
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------ */

    /**
     * The default `jobs` table has no created_at column, so created_at is always null.
     *
     * @throws JsonException
     */
    private function insertJob(JobContract $job, string $queue): void
    {
        $this->run(
            'INSERT INTO {jobs} ({payload}, {queue}, {scheduled_time}, {repeat}, {status}, {attempts}) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $this->encodePayload($job),
                $queue,
                $this->timestamp($job->getScheduledTime()),
                $job->isRepeated() ? $job->getRepeat() : null,
                'pending',
                0,
            ]
        );
    }

    /**
     * Same payload + queue + repeat already stored (any status), compared exactly in PHP because
     * MySQL text comparison is usually case-insensitive.
     */
    private function jobExists(string $payload, string $queue, ?string $repeat): bool
    {
        $stmt = $this->run(
            'SELECT {payload}, {queue} FROM {jobs} WHERE {queue} = ? AND {payload} = ? AND '
            . ($repeat === null ? '{repeat} IS NULL' : '{repeat} = ?'),
            $repeat === null ? [$queue, $payload] : [$queue, $payload, $repeat]
        );

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['payload'] === $payload && $row['queue'] === $queue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Serialize concurrent pushOnce() calls for the same fingerprint.
     */
    private function withDedupeLock(string $fingerprint, callable $callback): mixed
    {
        if ($this->driver === 'mysql') {
            if ($this->pdo->inTransaction()) {
                throw new \LogicException('MySQL pushOnce() must run after the application transaction commits.');
            }

            $name = 'spark_queue_' . $fingerprint;

            if ((int) $this->run('SELECT GET_LOCK(?, 10)', [$name])->fetchColumn() !== 1) {
                throw new RuntimeException('Timed out waiting for the queue dedupe lock.');
            }

            try {
                return $callback();
            } finally {
                $this->run('SELECT RELEASE_LOCK(?)', [$name]);
            }
        }

        return $this->db->transaction(function () use ($fingerprint, $callback) {
            if ($this->driver === 'pgsql') {
                $this->run('SELECT pg_advisory_xact_lock(hashtext(?))', [$fingerprint]);
            } else {
                // SQLite: a write statement takes the database write lock until the transaction ends.
                $this->run('UPDATE {jobs} SET {id} = {id} WHERE 1 = 0');
            }

            return $callback();
        });
    }

    /**
     * Retry on deadlock / serialization failure / SQLite busy. Never retries inside an application
     * transaction, because the database may already have rolled the whole transaction back.
     */
    private function retrying(callable $callback, int $times = 3): mixed
    {
        $nested = $this->pdo->inTransaction();

        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (PDOException $e) {
                if ($nested || $attempt >= $times || !$this->isRetryable($e)) {
                    throw $e;
                }

                usleep(random_int(10000, 50000) * $attempt);
            }
        }
    }

    private function isRetryable(PDOException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return in_array($sqlState, ['40001', '40P01'], true)
            || in_array($driverCode, [1205, 1213], true)
            || ($sqlState === 'HY000' && in_array($driverCode & 0xFF, [5, 6], true));
    }

    /**
     * Prepare, bind and execute. Placeholders like {jobs} and {repeat} are replaced with identifiers
     * quoted for the current driver (`repeat` is a reserved word in MySQL).
     */
    private function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare(strtr($sql, $this->map));

        foreach (array_values($params) as $i => $value) {
            $stmt->bindValue(
                $i + 1,
                $value,
                $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR)
            );
        }

        $stmt->execute();

        return $stmt;
    }

    /**
     * @throws JsonException
     */
    private function encodePayload(JobContract $job): string
    {
        $payload = $this->serializeJob($job);

        return json_encode(
            ['callback' => $payload['callback'], 'parameters' => $payload['parameters']],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function timestamp(Carbon|\DateTimeInterface|string $value): string
    {
        return Carbon::parse($value)->utc()->toDateTimeString();
    }

    /**
     * Valid UTF-8 and short enough for a TEXT column, so recording a failure can never fail itself.
     */
    private function limitText(string $text): string
    {
        if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        if (strlen($text) <= self::MAX_EXCEPTION_BYTES) {
            return $text;
        }

        return function_exists('mb_strcut')
            ? mb_strcut($text, 0, self::MAX_EXCEPTION_BYTES, 'UTF-8')
            : substr($text, 0, self::MAX_EXCEPTION_BYTES);
    }

    /**
     * @return string[]
     */
    private function toList(array|string|null $values): array
    {
        if ($values === null || $values === '' || $values === []) {
            return [];
        }

        if (is_string($values)) {
            $values = explode(',', $values);
        }

        return array_values(array_filter(array_map(static fn($v) => trim((string) $v), $values), static fn($v) => $v !== ''));
    }

    private function quoteTable(string $table, callable $quote): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table) !== 1) {
            throw new RuntimeException("Invalid queue table name [{$table}].");
        }

        return implode('.', array_map($quote, explode('.', $table)));
    }
}