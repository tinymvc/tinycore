<?php

namespace Spark\Queue;

use PDO;
use PDOException;
use RuntimeException;
use Spark\Console\Prompt;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\QueueContract;
use Spark\Queue\Exceptions\FailedToLoadJobsException;
use Spark\Queue\Exceptions\FailedToSaveJobsException;
use Spark\Queue\Exceptions\InvalidStorageFileException;
use Spark\Support\Traits\Macroable;
use Spark\Utils\Carbon;
use Spark\Utils\RedisConnector;
use function array_map;
use function array_slice;
use function array_unique;
use function count;
use function dirname;
use function explode;
use function get_class;
use function in_array;
use function is_array;
use function is_bool;
use function is_dir;
use function is_string;
use function max;
use function mkdir;
use function pathinfo;
use function rtrim;
use function sprintf;
use function str_ends_with;

/**
 * A job queue backed by SQLite by default, with Redis support when configured.
 *
 * This class uses SQLite by default and Redis when configured.
 */
class Queue implements QueueContract
{
    use Macroable;

    private const REDIS_NULL = '__spark_null__';

    /** @var bool|string */
    private bool|string $log;

    /** @var string */
    private string $driver = 'sqlite';

    /** @var array */
    private array $connection = [];

    /** @var PDO */
    private ?PDO $pdo = null;

    /** @var \Redis|null */
    private ?\Redis $redis = null;

    /** @var string */
    private string $redisPrefix = 'spark:queue';

    public function __construct(bool|string $log = false)
    {
        $this->connection = $this->resolveDriverConfig();
        $this->driver = strtolower((string) ($this->connection['driver'] ?? 'sqlite'));

        if ($this->driver === 'redis') {
            $this->initializeRedis();
        } else {
            $this->initializeSqlite();
        }

        $this->logging($log);
    }

    public function getPdoConnection(): ?PDO
    {
        return $this->pdo;
    }

    private function initializeSqlite(): void
    {
        try {
            $path = $this->sqliteQueuePath($this->connection);
            $this->pdo = new PDO("sqlite:$path");
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA cache_size = 10000');
            $this->pdo->exec('PRAGMA temp_store = MEMORY');
            $this->createJobsTableIfNotExists();
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to connect to the SQLite database: ' . $e->getMessage());
        }
    }

    private function initializeRedis(): void
    {
        $redisConfig = RedisConnector::resolveConnectionConfig($this->connection);
        $this->redis = RedisConnector::make($redisConfig, $this->driver);

        $prefix = trim((string) ($redisConfig['prefix'] ?? 'spark'));
        if ($prefix === '') {
            $prefix = 'spark';
        }
        $prefix = trim($prefix, ':');
        $this->redisPrefix = sprintf('%s:queue:%s', $prefix, md5($this->driver));

        // Ensure lock keys for failed cleanup never outlive jobs unless explicitly removed.
        $this->deleteNamespaceMetaKeys();
    }

    private function resolveDriverConfig(): array
    {
        $queueConfig = (array) config('queue', []);

        $driver = strtolower((string) ($queueConfig['driver'] ?? 'sqlite'));
        $connections = (array) ($queueConfig['connections'] ?? []);
        $connection = is_array($connections[$driver] ?? null) ? $connections[$driver] : [];

        return [
            ...$connection,
            'driver' => $driver
        ];
    }

    /**
     * Resolves the SQLite queue database file path.
     */
    private function sqliteQueuePath(array $config): string
    {
        $path = (string) ($config['path'] ?? storage_dir('queue/jobs.db'));
        if ($path === '') {
            $path = storage_dir('queue/jobs.db');
        }

        if ($this->looksLikeDirectoryPath($path)) {
            $path = $path . DIRECTORY_SEPARATOR . 'jobs.db';
        }

        $this->ensureDirectory(dirname($path));
        return $this->normalizePath($path);
    }

    /**
     * Creates the jobs table if it does not exist.
     */
    private function createJobsTableIfNotExists(): void
    {
        if (!$this->pdo instanceof PDO) {
            return;
        }

        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    payload TEXT NOT NULL,
                    queue TEXT DEFAULT NULL,
                    scheduled_time DATETIME NOT NULL,
                    created_at DATETIME NOT NULL,
                    repeat TEXT DEFAULT NULL,
                    status TEXT NOT NULL,
                    attempts INTEGER DEFAULT 0,
                    reserved_at DATETIME DEFAULT NULL
                )"
            );

            // Indexes for jobs table
            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS idx_jobs_status_scheduled ON jobs(status, scheduled_time)"
            );

            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS idx_jobs_queue_status ON jobs(queue, status) WHERE queue IS NOT NULL"
            );

            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS idx_jobs_created_at ON jobs(created_at)"
            );

            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS idx_jobs_reserved_at ON jobs(reserved_at) WHERE reserved_at IS NOT NULL"
            );

            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS failed_jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_id INTEGER NOT NULL,
                    failed_at DATETIME NOT NULL,
                    exception TEXT NOT NULL,
                    attempts INTEGER DEFAULT 0,
                    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
                )"
            );

            // Index for failed_jobs table
            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS idx_failed_jobs_job_id ON failed_jobs(job_id)"
            );

            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS idx_failed_jobs_failed_at ON failed_jobs(failed_at)"
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to create jobs table: ' . $e->getMessage());
        }
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

    public function logging(bool|string $log = true): void
    {
        if ($log === true) {
            $this->log = storage_dir('logs/queue.log');
        } else {
            $this->log = $log;
        }

        if ($this->log) {
            if (!is_file($this->log) && !touch($this->log)) {
                throw new InvalidStorageFileException('Failed to create the queue log file.');
            } elseif (!is_writable($this->log) && !chmod($this->log, 0666)) {
                throw new InvalidStorageFileException(
                    sprintf('The queue log file (%s) is not writable.', $this->log)
                );
            }
        }
    }

    /**
     * Adds a job to the queue.
     */
    public function push(JobContract $job, string $queue = 'default'): void
    {
        if ($this->isRedis()) {
            $this->pushRedis($job, $queue);
            return;
        }

        $payload = $this->serializeJob($job);
        try {
            $this->pdo?->prepare(
                'INSERT INTO jobs (payload, queue, scheduled_time, created_at, repeat, status, attempts) ' .
                'VALUES (:payload, :queue, :scheduled_time, :created_at, :repeat, :status, :attempts)'
            )->execute([
                        ':payload' => json_encode([
                            'callback' => $payload['callback'],
                            'parameters' => $payload['parameters'],
                        ]),
                        ':queue' => $queue,
                        ':scheduled_time' => $payload['scheduledTime'],
                        ':created_at' => now(),
                        ':repeat' => $payload['repeat'],
                        ':status' => 'pending',
                        ':attempts' => 0,
                    ]);
        } catch (PDOException $e) {
            throw new FailedToSaveJobsException('Failed to add job to the queue: ' . $e->getMessage());
        }
    }

    public function pushOnce(JobContract $job, string $queue = 'default'): void
    {
        if ($this->isRedis()) {
            $this->pushOnceRedis($job, $queue);
            return;
        }

        $payload = json_encode([
            'callback' => $job->getCallback(),
            'parameters' => $job->getParameters(),
        ]);

        $repeatCondition = $job->isRepeated() ? '= :repeat' : 'IS NULL';
        $stmt = $this->pdo?->prepare(
            "SELECT COUNT(*) FROM jobs WHERE payload = :payload AND queue = :queue AND repeat $repeatCondition"
        );
        if (!$stmt) {
            throw new RuntimeException('Queue storage is not initialized.');
        }

        $params = [
            ':payload' => $payload,
            ':queue' => $queue,
        ];

        if ($job->isRepeated()) {
            $params[':repeat'] = $job->getRepeat();
        }

        $stmt->execute($params);

        $count = $stmt->fetchColumn();
        if ((int) $count === 0) {
            $this->push($job, $queue);
        }
    }

    public function clearAllJobs(): void
    {
        if ($this->isRedis()) {
            $this->clearAllJobsRedis();
            return;
        }

        $this->pdo?->exec('DELETE FROM jobs');
    }

    public function clearRepeatedJobs(): void
    {
        if ($this->isRedis()) {
            $this->clearJobsByFilterRedis(fn(array $job): bool => (string) ($job['repeat'] ?? '') !== '');
            return;
        }

        $this->pdo?->exec("DELETE FROM jobs WHERE repeat IS NOT NULL");
    }

    public function clearFailedJobs(): void
    {
        if ($this->isRedis()) {
            $this->clearJobsByFilterRedis(fn(array $job): bool => (string) ($job['status'] ?? '') === 'failed');
            return;
        }

        $this->pdo?->exec("DELETE FROM jobs WHERE status = 'failed'");
    }

    public function removeJobById(int $id): bool
    {
        if ($this->isRedis()) {
            return $this->removeJobByIdRedis($id);
        }

        $statement = $this->pdo?->prepare('DELETE FROM jobs WHERE id = :id');
        $statement?->execute([':id' => $id]);

        return (int) ($statement?->rowCount() ?? 0) > 0;
    }

    public function removeQueue(string $name): bool
    {
        if ($this->isRedis()) {
            return $this->removeQueueRedis($name);
        }

        $statement = $this->pdo?->prepare('DELETE FROM jobs WHERE queue = :queue');
        $statement?->execute([':queue' => $name]);

        return (int) ($statement?->rowCount() ?? 0) > 0;
    }

    private function getNextJob(array|string $queue = 'default'): false|JobContract
    {
        if ($this->isRedis()) {
            return $this->getNextJobRedis($queue);
        }

        try {
            $this->pdo?->beginTransaction();

            $queueClause = $this->sqliteInClause($queue, 'queue');
            if ($queueClause['sql'] === '') {
                $this->pdo?->commit();
                return false;
            }

            $statement = $this->pdo?->prepare(
                "SELECT * FROM jobs WHERE queue IN({$queueClause['sql']}) AND status = 'pending' " .
                "AND (scheduled_time IS NULL OR scheduled_time <= :now) " .
                "ORDER BY scheduled_time ASC LIMIT 1"
            );

            if (!$statement) {
                return false;
            }

            $statement->execute([
                ...$queueClause['params'],
                ':now' => now(),
            ]);
            $job = $statement->fetch(PDO::FETCH_ASSOC);

            if ($job) {
                $updateStmt = $this->pdo?->prepare(
                    "UPDATE jobs SET status = 'reserved', reserved_at = :reserved_at WHERE id = :id AND status = 'pending'"
                );

                $updateStmt?->execute([
                    ':reserved_at' => now(),
                    ':id' => $job['id']
                ]);

                if (($updateStmt?->rowCount() ?? 0) === 0) {
                    $this->pdo?->commit();
                    return false;
                }

                $this->pdo?->commit();
                return $this->unserializeJob($job);
            }

            $this->pdo?->commit();
            return false;

        } catch (PDOException $e) {
            $this->pdo?->rollBack();
            throw new RuntimeException('Failed to get next job: ' . $e->getMessage());
        }
    }

    public function work(
        bool $once = false,
        int $timeout = 3600,
        int $sleep = 3,
        int $delay = 5,
        int $tries = 3,
        array|string $queue = 'default'
    ): void {
        $ranJobs = 0;
        $failedJobs = 0;
        $startedAt = microtime(true);
        $startedMemory = memory_get_usage(true);

        $queueNames = is_array($queue) ? implode(', ', $queue) : $queue;
        $this->message("Queue worker started for queue(s): $queueNames");

        sleep(rand(0, $sleep));
        $this->recoverStaleJobs();

        do {
            if ((microtime(true) - $startedAt) >= $timeout) {
                $this->message('Queue worker timeout reached. Shutting down...');
                break;
            }

            $job = $this->getNextJob($queue);

            if (!$job) {
                $once === false && sleep($sleep);
                continue;
            }

            $jobId = $job->getId();
            $attempts = $job->getMetadata('attempts', 0);

            $this->message(
                sprintf(
                    'Processing job #%d (%s) - Attempt %d/%d',
                    $jobId,
                    $job->getDisplayName(),
                    $attempts + 1,
                    $tries,
                ),
            );

            try {
                $this->updateJobStatus($jobId, 'processing', $attempts + 1);

                $job->handle();

                if ($job->isRepeated()) {
                    $nextRun = now()->modify('+' . $job->getRepeat());
                    $this->rescheduleJob($jobId, $nextRun);

                    $this->message(
                        sprintf(
                            'Job #%d completed and rescheduled for %s',
                            $jobId,
                            $nextRun
                        ),
                    );
                } else {
                    $this->removeJobById((int) $jobId);
                    $this->message("Job #$jobId completed successfully");
                }

                $ranJobs++;
            } catch (\Throwable $e) {
                $newAttempts = $attempts + 1;

                $this->message(
                    sprintf('Job #%d failed: %s', $jobId, $e->getMessage()),
                );

                if ($newAttempts >= $tries) {
                    $this->markJobAsFailed($jobId, $e, $newAttempts);
                    $this->callFailedHandler($job, $e->getPrevious() ?? $e);

                    $this->message(
                        sprintf(
                            'Job #%d failed permanently after %d attempts',
                            $jobId,
                            $newAttempts
                        ),
                    );
                } else {
                    $retryTime = now()->addSeconds($delay);
                    $this->retryJob($jobId, $retryTime, $newAttempts);

                    $this->message(
                        sprintf(
                            'Job #%d will be retried at %s',
                            $jobId,
                            $retryTime
                        ),
                    );
                }

                $failedJobs++;
            }

            if ($once) {
                break;
            }
        } while (true);

        $timeUsed = microtime(true) - $startedAt;
        $memoryUsed = memory_get_usage(true) - $startedMemory;

        if ($ranJobs > 0 || $failedJobs > 0) {
            $this->addQueueLog($timeUsed, $memoryUsed, $ranJobs, $failedJobs);
        }

        $this->message(
            sprintf('Queue worker finished. Ran %d job(s), %d failed', $ranJobs, $failedJobs),
        );
    }

    /**
     * Calls the failed handler for a job, if it exists.
     */
    private function callFailedHandler(JobContract $job, \Throwable $exception): void
    {
        try {
            $job->failed($exception);
        } catch (\Throwable $failedException) {
            $this->message(
                sprintf(
                    'Failed handler for job #%s threw an exception: %s',
                    $job->getId() ?? 'unknown',
                    $failedException->getMessage()
                )
            );
        }
    }

    private function message(string $message): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }

    private function updateJobStatus(int $jobId, string $status, int $attempts): void
    {
        if ($this->isRedis()) {
            $this->updateJobStatusRedis($jobId, $status, $attempts);
            return;
        }

        $statement = $this->pdo?->prepare(
            "UPDATE jobs SET status = :status, attempts = :attempts, reserved_at = :reserved_at WHERE id = :id"
        );

        $statement?->execute([
            ':status' => $status,
            ':attempts' => $attempts,
            ':reserved_at' => $status === 'processing' ? now() : null,
            ':id' => $jobId,
        ]);
    }

    private function rescheduleJob(int $jobId, Carbon $nextRun): void
    {
        if ($this->isRedis()) {
            $this->rescheduleJobRedis($jobId, $nextRun);
            return;
        }

        $statement = $this->pdo?->prepare(
            "UPDATE jobs SET scheduled_time = :scheduled_time, status = 'pending', attempts = 0, reserved_at = NULL WHERE id = :id"
        );

        $statement?->execute([
            ':scheduled_time' => $nextRun,
            ':id' => $jobId,
        ]);
    }

    private function markJobAsFailed(int $jobId, \Throwable $exception, int $attempts): void
    {
        if ($this->isRedis()) {
            $this->markJobAsFailedRedis($jobId, $exception, $attempts);
            return;
        }

        try {
            $this->pdo?->beginTransaction();

            $statement = $this->pdo?->prepare("UPDATE jobs SET status = 'failed', attempts = :attempts WHERE id = :id");
            $statement?->execute([
                ':attempts' => $attempts,
                ':id' => $jobId,
            ]);

            $statement = $this->pdo?->prepare(
                "INSERT INTO failed_jobs (job_id, failed_at, exception, attempts) VALUES (:job_id, :failed_at, :exception, :attempts)"
            );

            $stackTraceString = $exception->getPrevious()?->getTraceAsString() ?? $exception->getTraceAsString();

            $statement?->execute([
                ':job_id' => $jobId,
                ':failed_at' => now(),
                ':exception' => sprintf('%s: %s\nStack trace:\n%s', get_class($exception), $exception->getMessage(), $stackTraceString),
                ':attempts' => $attempts,
            ]);

            $this->pdo?->commit();
        } catch (PDOException $e) {
            $this->pdo?->rollBack();
            throw new RuntimeException('Failed to mark job as failed: ' . $e->getMessage());
        }
    }

    private function retryJob(int $jobId, Carbon $retryTime, int $attempts): void
    {
        if ($this->isRedis()) {
            $this->retryJobRedis($jobId, $retryTime, $attempts);
            return;
        }

        $statement = $this->pdo?->prepare(
            "UPDATE jobs SET scheduled_time = :scheduled_time, status = 'pending', attempts = :attempts, reserved_at = NULL WHERE id = :id"
        );

        $statement?->execute([
            ':scheduled_time' => $retryTime,
            ':attempts' => $attempts,
            ':id' => $jobId,
        ]);
    }

    private function recoverStaleJobs(int $timeout = 3600): int
    {
        if ($this->isRedis()) {
            return $this->recoverStaleJobsRedis($timeout);
        }

        try {
            $staleTime = now()->subSeconds($timeout);

            $statement = $this->pdo?->prepare(
                "UPDATE jobs SET status = 'pending', reserved_at = NULL " .
                "WHERE status IN ('processing', 'reserved') " .
                "AND reserved_at IS NOT NULL " .
                "AND reserved_at < :stale_time"
            );

            $statement?->execute([':stale_time' => $staleTime]);

            $recovered = (int) ($statement?->rowCount() ?? 0);

            if ($recovered > 0) {
                Prompt::message(
                    sprintf("Recovered <bold>%d</bold> stale job(s)", $recovered),
                    'warning'
                );
            }

            return $recovered;
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to recover stale jobs: ' . $e->getMessage());
        }
    }

    public function getJobs(
        array|string|null $queue = null,
        array|string|null $status = null,
        int $from = 0,
        int $to = 500,
    ): array {
        if ($this->isRedis()) {
            return $this->getJobsRedis($queue, $status, $from, $to);
        }

        try {
            $whereParts = [];
            $params = [];
            if (!empty($queue)) {
                $queueClause = $this->sqliteInClause($queue, 'jobs_queue');
                if ($queueClause['sql'] !== '') {
                    $whereParts[] = "queue IN({$queueClause['sql']})";
                    $params = [...$params, ...$queueClause['params']];
                }
            }

            if (!empty($status)) {
                $statusClause = $this->sqliteInClause($status, 'jobs_status');
                if ($statusClause['sql'] !== '') {
                    $whereParts[] = "status IN({$statusClause['sql']})";
                    $params = [...$params, ...$statusClause['params']];
                }
            }

            if ($whereParts !== []) {
                $query = sprintf('SELECT * FROM jobs WHERE %s LIMIT :from, :to', implode(' AND ', $whereParts));
            } else {
                $query = 'SELECT * FROM jobs LIMIT :from, :to';
            }

            $statement = $this->pdo?->prepare($query);
            $statement?->execute([
                ':from' => $from,
                ':to' => $to,
                ...$params,
            ]);
            $jobs = [];

            while ($row = $statement?->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $jobs[] = $this->unserializeJob($row);
                }
            }

            return $jobs;
        } catch (PDOException $e) {
            throw new FailedToLoadJobsException('Failed to load jobs from the queue: ' . $e->getMessage());
        }
    }

    public function getFailedJobs(int $from = 0, int $to = 500): array
    {
        if ($this->isRedis()) {
            return $this->getFailedJobsRedis($from, $to);
        }

        try {
            $statement = $this->pdo?->prepare(
                "SELECT fj.id AS failed_job_id, fj.job_id AS id, fj.failed_at, fj.exception, fj.attempts, " .
                "j.payload, j.queue, j.scheduled_time, j.created_at, j.repeat, j.status " .
                "FROM failed_jobs fj " .
                "JOIN jobs j ON fj.job_id = j.id " .
                "ORDER BY fj.failed_at DESC " .
                "LIMIT :from, :to"
            );

            $statement?->execute([':from' => $from, ':to' => $to]);
            $jobs = [];

            while ($row = $statement?->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $jobs[] = $this->unserializeJob($row);
                }
            }

            return $jobs;
        } catch (PDOException $e) {
            throw new FailedToLoadJobsException('Failed to load failed jobs from the queue: ' . $e->getMessage());
        }
    }

    public function retryFailedJobs(): void
    {
        if ($this->isRedis()) {
            $this->retryFailedJobsRedis();
            return;
        }

        try {
            $this->pdo?->beginTransaction();

            $statement = $this->pdo?->prepare('SELECT job_id FROM failed_jobs');
            $statement?->execute();
            $failedJobIds = $statement?->fetchAll(PDO::FETCH_COLUMN);

            if (empty($failedJobIds)) {
                $this->pdo?->commit();
                return;
            }

            $inClause = implode(',', array_fill(0, count($failedJobIds), '?'));
            $statement = $this->pdo?->prepare("UPDATE jobs SET status = 'pending', attempts = 0, reserved_at = NULL WHERE id IN ($inClause)");
            $statement?->execute($failedJobIds);
            $this->pdo?->exec('DELETE FROM failed_jobs');
            $this->pdo?->commit();
        } catch (PDOException $e) {
            $this->pdo?->rollBack();
            throw new RuntimeException('Failed to retry failed jobs: ' . $e->getMessage());
        }
    }

    private function serializeJob(JobContract $job): array
    {
        return [
            'callback' => $job->getCallback(),
            'parameters' => $job->getParameters(),
            'scheduledTime' => (string) $job->getScheduledTime(),
            'repeat' => $job->getRepeat(),
            'metadata' => $job->getMetadata(),
        ];
    }

    private function unserializeJob(array $job): JobContract
    {
        $payload = json_decode((string) ($job['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        return new Job(
            callback: $payload['callback'] ?? null,
            parameters: $payload['parameters'] ?? [],
            scheduledTime: new Carbon($job['scheduled_time'] ?? now()),
            repeat: $job['repeat'] ?? null,
            metadata: [
                'id' => $job['id'] ?? null,
                'queue' => $job['queue'] ?? 'default',
                'attempts' => $job['attempts'] ?? 0,
                'created_at' => $job['created_at'] ?? null,
                'status' => $job['status'] ?? 'pending',
                'failed_job_id' => $job['failed_job_id'] ?? null,
                'failed_at' => $job['failed_at'] ?? null,
                'reserved_at' => $job['reserved_at'] ?? null,
                'exception' => $job['exception'] ?? null,
            ],
        );
    }

    private function sqliteInClause(array|string|null $values, string $paramPrefix): array
    {
        if (empty($values)) {
            return [
                'sql' => '',
                'params' => [],
            ];
        }

        if (is_string($values)) {
            $values = explode(',', $values);
        }

        $values = array_map('trim', (array) $values);
        $values = array_filter($values);
        if ($values === []) {
            return [
                'sql' => '',
                'params' => [],
            ];
        }

        $values = array_values($values);
        $placeholders = [];
        $params = [];

        foreach ($values as $index => $value) {
            $param = sprintf(':%s_%d', $paramPrefix, $index);
            $placeholders[] = $param;
            $params[$param] = (string) $value;
        }

        return [
            'sql' => implode(',', $placeholders),
            'params' => $params,
        ];
    }

    private function addQueueLog(float $timeUsed, int $memoryUsed, int $ranJobs, int $failedJobs): void
    {
        if (empty($this->log)) {
            return;
        }

        $logEntry = sprintf(
            "[%s] Finished running %d success and %d failed job(s) in %.4f seconds, using %.2f MB of memory.\n",
            date('Y-m-d H:i:s'),
            $ranJobs,
            $failedJobs,
            $timeUsed,
            $memoryUsed / 1024 / 1024
        );

        file_put_contents($this->log, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * ------------------------------
     * Redis implementations
     * ------------------------------
     */
    private function redisKey(string $key): string
    {
        return $this->redisPrefix . ':' . ltrim($key, ':');
    }

    private function redisJobsSetKey(): string
    {
        return $this->redisKey('jobs');
    }

    private function redisQueueJobsSetKey(string $queue): string
    {
        return $this->redisKey("jobs:queue:$queue");
    }

    private function redisQueuesSetKey(): string
    {
        return $this->redisKey('queues');
    }

    private function redisPendingSetKey(string $queue): string
    {
        return $this->redisKey("pending:$queue");
    }

    private function redisReservedSetKey(string $queue): string
    {
        return $this->redisKey("reserved:$queue");
    }

    private function redisJobHashKey(int $id): string
    {
        return $this->redisKey("job:$id");
    }

    private function redisFailedSetKey(): string
    {
        return $this->redisKey('failed');
    }

    private function redisNextIdKey(): string
    {
        return $this->redisKey('next_id');
    }

    private function redisFingerprintKey(string $queue, array $payload, ?string $repeat): string
    {
        $fingerprint = sha1(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $this->redisKey("dupe:$queue:$repeat:$fingerprint");
    }

    private function redisNormalizeValue(mixed $value): string
    {
        return match (true) {
            $value === null => self::REDIS_NULL,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    private function redisToValue(string $value): ?string
    {
        return $value === self::REDIS_NULL ? null : $value;
    }

    private function redisToIntValue(string $value): int
    {
        return (int) ($value === self::REDIS_NULL ? 0 : $value);
    }

    private function redisToTimestamp(null|string $value): int
    {
        if ($value === null || $value === self::REDIS_NULL || $value === '') {
            return time();
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? time() : $timestamp;
    }

    private function nextRedisId(): int
    {
        return (int) ($this->redis?->incr($this->redisNextIdKey()) ?? 1);
    }

    private function getRedisJob(int $id): ?array
    {
        if (!$this->redis) {
            return null;
        }

        $row = $this->redis->hGetAll($this->redisJobHashKey($id));
        if ($row === []) {
            return null;
        }

        return $row;
    }

    private function redisNullIfMissing(?string $value): ?string
    {
        return ($value === self::REDIS_NULL || $value === '') ? null : $value;
    }

    private function pushRedis(JobContract $job, string $queue, ?int $jobId = null): void
    {
        $payload = $this->serializeJob($job);
        $jobId = $jobId ?? $this->nextRedisId();
        $payloadData = json_encode([
            'callback' => $payload['callback'],
            'parameters' => $payload['parameters'],
        ], JSON_UNESCAPED_UNICODE);

        $row = [
            'id' => (string) $jobId,
            'payload' => $payloadData,
            'queue' => $queue,
            'scheduled_time' => $payload['scheduledTime'],
            'created_at' => (string) now(),
            'repeat' => $payload['repeat'] ?? self::REDIS_NULL,
            'status' => 'pending',
            'attempts' => '0',
            'reserved_at' => self::REDIS_NULL,
            'exception' => self::REDIS_NULL,
            'failed_at' => self::REDIS_NULL,
        ];

        $jobKey = $this->redisJobHashKey($jobId);

        $this->redis?->hMSet($jobKey, array_map($this->redisNormalizeValue(...), $row));
        $this->redis?->sAdd($this->redisJobsSetKey(), $jobId);
        $this->redis?->sAdd($this->redisQueueJobsSetKey($queue), $jobId);
        $this->redis?->sAdd($this->redisQueuesSetKey(), $queue);
        $this->redis?->zAdd($this->redisPendingSetKey($queue), $this->redisToTimestamp((string) $payload['scheduledTime']), (string) $jobId);

        $dupeKey = $this->redisFingerprintKey($queue, [
            'callback' => $payload['callback'],
            'parameters' => $payload['parameters'],
        ], $payload['repeat'] ?? '');

        $this->redis?->set($dupeKey, (string) $jobId);
    }

    private function pushOnceRedis(JobContract $job, string $queue): void
    {
        $payload = $this->serializeJob($job);
        $dupeKey = $this->redisFingerprintKey($queue, [
            'callback' => $payload['callback'],
            'parameters' => $payload['parameters'],
        ], $payload['repeat'] ?? '');

        $existing = $this->redis?->get($dupeKey);
        if (is_string($existing) && $existing !== '') {
            $existingId = (int) $existing;
            $existingJob = $this->getRedisJob($existingId);
            if ($existingJob !== null && $this->redisToValue($existingJob['status'] ?? '') !== null) {
                return;
            }

            $this->redis?->del($dupeKey);
        }

        $jobId = $this->nextRedisId();

        if ($this->redis?->setnx($dupeKey, (string) $jobId) !== true) {
            return;
        }

        try {
            $this->pushRedis($job, $queue, $jobId);
        } catch (\Throwable $e) {
            $this->redis?->del($dupeKey);
            throw new FailedToSaveJobsException('Failed to add job to the queue: ' . $e->getMessage());
        }
    }

    private function getNextJobRedis(array|string $queue = 'default'): false|JobContract
    {
        $queues = $this->toQueueList($queue);
        if ($queues === []) {
            return false;
        }

        $bestQueue = null;
        $bestId = null;
        $bestScore = null;

        $now = time();

        foreach ($queues as $queueName) {
            $jobs = $this->redis?->zRangeByScore(
                $this->redisPendingSetKey($queueName),
                '-inf',
                $now,
                ['withscores' => true, 'limit' => [0, 1]]
            );

            if (!$jobs || count($jobs) === 0) {
                continue;
            }

            $jobId = (int) array_key_first($jobs);
            $score = (int) (array_values($jobs)[0] ?? 0);

            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $bestQueue = $queueName;
                $bestId = $jobId;
            }
        }

        if ($bestQueue === null || $bestId === null) {
            return false;
        }

        if (($this->redis?->zRem($this->redisPendingSetKey($bestQueue), (string) $bestId) ?? 0) === 0) {
            return false;
        }

        $row = $this->getRedisJob($bestId);
        if (!$row) {
            return false;
        }

        $this->updateJobStatusRedis($bestId, 'reserved', $this->redisToIntValue($row['attempts'] ?? '0'));

        return $this->unserializeJob([
            'id' => $bestId,
            'payload' => $row['payload'] ?? '{}',
            'queue' => $row['queue'] ?? $bestQueue,
            'scheduled_time' => $row['scheduled_time'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'repeat' => $this->redisNullIfMissing($row['repeat'] ?? null),
            'status' => $row['status'] ?? 'pending',
            'attempts' => $this->redisToIntValue($row['attempts'] ?? '0'),
            'reserved_at' => $this->redisNullIfMissing($row['reserved_at'] ?? null),
            'exception' => $this->redisNullIfMissing($row['exception'] ?? null),
            'failed_at' => $this->redisNullIfMissing($row['failed_at'] ?? null),
        ]);
    }

    private function clearAllJobsRedis(): void
    {
        $allIds = $this->redis?->sMembers($this->redisJobsSetKey()) ?: [];
        if (!is_array($allIds)) {
            return;
        }

        foreach ($allIds as $id) {
            $this->removeJobByIdRedis((int) $id);
        }

        $this->redis?->del($this->redisJobsSetKey(), $this->redisFailedSetKey(), $this->redisQueuesSetKey(), $this->redisNextIdKey());
        $this->deleteNamespaceMetaKeys();
    }

    private function clearJobsByFilterRedis(callable $filter): void
    {
        $allIds = $this->redis?->sMembers($this->redisJobsSetKey()) ?: [];
        if (!is_array($allIds)) {
            return;
        }

        foreach ($allIds as $id) {
            $row = $this->getRedisJob((int) $id);
            if (!$row) {
                continue;
            }

            if ($filter($this->normalizeRedisRow((int) $id, $row))) {
                $this->removeJobByIdRedis((int) $id);
            }
        }
    }

    private function normalizeRedisRow(int $id, array $row): array
    {
        return [
            'id' => $id,
            'payload' => $row['payload'] ?? '{}',
            'queue' => $this->redisNullIfMissing((string) ($row['queue'] ?? 'default')),
            'scheduled_time' => $this->redisNullIfMissing((string) ($row['scheduled_time'] ?? null)),
            'created_at' => $this->redisNullIfMissing((string) ($row['created_at'] ?? null)),
            'repeat' => $this->redisNullIfMissing((string) ($row['repeat'] ?? null)),
            'status' => $this->redisNullIfMissing((string) ($row['status'] ?? 'pending')),
            'attempts' => $this->redisToIntValue((string) ($row['attempts'] ?? '0')),
            'reserved_at' => $this->redisNullIfMissing((string) ($row['reserved_at'] ?? null)),
            'exception' => $this->redisNullIfMissing((string) ($row['exception'] ?? null)),
            'failed_at' => $this->redisNullIfMissing((string) ($row['failed_at'] ?? null)),
        ];
    }

    private function removeJobByIdRedis(int $id): bool
    {
        $row = $this->getRedisJob($id);
        if (!$row) {
            return false;
        }

        $queue = $this->redisNullIfMissing((string) ($row['queue'] ?? 'default'));

        $this->redis?->sRem($this->redisJobsSetKey(), $id);
        if ($queue !== null) {
            $this->redis?->sRem($this->redisQueueJobsSetKey($queue), $id);
            $this->redis?->zRem($this->redisPendingSetKey($queue), (string) $id);
            $this->redis?->zRem($this->redisReservedSetKey($queue), (string) $id);
        }

        $this->redis?->zRem($this->redisFailedSetKey(), (string) $id);
        $this->redis?->del($this->redisJobHashKey($id));

        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        if (is_array($payload)) {
            $repeat = $this->redisNullIfMissing((string) ($row['repeat'] ?? null)) ?? '';
            $dupe = $this->redisFingerprintKey($queue ?? 'default', $payload, $repeat);
            $this->redis?->del($dupe);
        }

        return true;
    }

    private function removeQueueRedis(string $name): bool
    {
        $ids = $this->redis?->sMembers($this->redisQueueJobsSetKey($name)) ?: [];
        $removed = false;

        if (is_array($ids)) {
            foreach ($ids as $id) {
                $removed = $this->removeJobByIdRedis((int) $id) || $removed;
            }
        }

        $this->redis?->del(
            $this->redisQueueJobsSetKey($name),
            $this->redisPendingSetKey($name),
            $this->redisReservedSetKey($name)
        );

        if ($removed) {
            $this->redis?->sRem($this->redisQueuesSetKey(), $name);
        }

        return $removed;
    }

    private function getJobsRedis(
        array|string|null $queue = null,
        array|string|null $status = null,
        int $from = 0,
        int $to = 500,
    ): array {
        $ids = [];

        if ($queue === null) {
            $ids = $this->redis?->sMembers($this->redisJobsSetKey()) ?: [];
        } else {
            $queues = $this->toQueueList($queue);
            $ids = [];
            foreach ($queues as $name) {
                $queueIds = $this->redis?->sMembers($this->redisQueueJobsSetKey($name));
                if (is_array($queueIds)) {
                    $ids = array_merge($ids, $queueIds);
                }
            }
            $ids = array_unique(array_map('intval', $ids));
        }

        if (!is_array($ids)) {
            return [];
        }

        $statusList = $this->toStatusList($status);
        $jobs = [];

        foreach ($ids as $id) {
            $row = $this->getRedisJob((int) $id);
            if (!$row) {
                continue;
            }

            $statusValue = $this->redisNullIfMissing((string) ($row['status'] ?? 'pending'));
            if ($statusList !== [] && !in_array($statusValue, $statusList, true)) {
                continue;
            }

            $jobs[] = $this->unserializeJob($this->normalizeRedisRow((int) $id, $row));
        }

        usort($jobs, static fn(JobContract $a, JobContract $b) => $a->getScheduledTime()->timestamp <=> $b->getScheduledTime()->timestamp);

        $jobs = array_slice($jobs, $from, max(0, $to));
        return $jobs;
    }

    private function getFailedJobsRedis(int $from = 0, int $to = 500): array
    {
        $ids = $this->redis?->zRevRange($this->redisFailedSetKey(), $from, $from + max(0, $to - 1)) ?: [];
        if (!is_array($ids)) {
            return [];
        }

        $jobs = [];
        foreach ($ids as $id) {
            $row = $this->getRedisJob((int) $id);
            if (!$row) {
                continue;
            }

            $jobs[] = $this->unserializeJob($this->normalizeRedisRow((int) $id, $row));
        }

        return $jobs;
    }

    private function retryFailedJobsRedis(): void
    {
        $ids = $this->redis?->zRevRange($this->redisFailedSetKey(), 0, -1) ?: [];
        if (!is_array($ids)) {
            return;
        }

        foreach ($ids as $id) {
            $job = $this->getRedisJob((int) $id);
            if (!$job) {
                continue;
            }

            $row = $this->normalizeRedisRow((int) $id, $job);
            $this->redis?->hMSet($this->redisJobHashKey((int) $id), [
                'status' => 'pending',
                'attempts' => '0',
                'reserved_at' => self::REDIS_NULL,
                'failed_at' => self::REDIS_NULL,
                'exception' => self::REDIS_NULL,
            ]);

            $this->redis?->zAdd($this->redisPendingSetKey($row['queue'] ?? 'default'), $this->redisToTimestamp($row['scheduled_time'] ?? null), (string) $id);
            $this->redis?->zRem($this->redisFailedSetKey(), (string) $id);
        }
    }

    private function updateJobStatusRedis(int $jobId, string $status, int $attempts): void
    {
        if (!$this->redis) {
            return;
        }

        $job = $this->getRedisJob($jobId);
        if (!$job) {
            return;
        }

        $queue = $this->redisNullIfMissing((string) ($job['queue'] ?? 'default')) ?? 'default';
        $scheduled = $this->redisToTimestamp($job['scheduled_time'] ?? null);

        $pendingSet = $this->redisPendingSetKey($queue);
        $reservedSet = $this->redisReservedSetKey($queue);

        $this->redis->zRem($pendingSet, (string) $jobId);
        $this->redis->zRem($reservedSet, (string) $jobId);

        $payload = [
            'status' => $status,
            'attempts' => (string) max(0, $attempts),
            'reserved_at' => self::REDIS_NULL,
            'failed_at' => $job['failed_at'] ?? self::REDIS_NULL,
            'exception' => $job['exception'] ?? self::REDIS_NULL,
        ];

        if (in_array($status, ['reserved', 'processing'], true)) {
            $payload['reserved_at'] = (string) now()->timestamp;
            $this->redis->zAdd($reservedSet, now()->timestamp, (string) $jobId);
        } elseif ($status === 'pending') {
            $this->redis->zAdd($pendingSet, $scheduled, (string) $jobId);
        }

        $this->redis->hMSet($this->redisJobHashKey($jobId), array_map($this->redisNormalizeValue(...), $payload));
    }

    private function rescheduleJobRedis(int $jobId, Carbon $nextRun): void
    {
        if (!$this->redis) {
            return;
        }

        $job = $this->getRedisJob($jobId);
        if (!$job) {
            return;
        }

        $queue = $this->redisNullIfMissing((string) ($job['queue'] ?? 'default')) ?? 'default';
        $this->redis->hMSet($this->redisJobHashKey($jobId), [
            'scheduled_time' => (string) $nextRun,
            'status' => 'pending',
            'attempts' => '0',
            'reserved_at' => self::REDIS_NULL,
        ]);

        $this->redis->zAdd($this->redisPendingSetKey($queue), $nextRun->timestamp, (string) $jobId);
        $this->redis->zRem($this->redisReservedSetKey($queue), (string) $jobId);
    }

    private function retryJobRedis(int $jobId, Carbon $retryTime, int $attempts): void
    {
        if (!$this->redis) {
            return;
        }

        $job = $this->getRedisJob($jobId);
        if (!$job) {
            return;
        }

        $queue = $this->redisNullIfMissing((string) ($job['queue'] ?? 'default')) ?? 'default';
        $this->redis->hMSet($this->redisJobHashKey($jobId), [
            'scheduled_time' => (string) $retryTime,
            'status' => 'pending',
            'attempts' => (string) $attempts,
            'reserved_at' => self::REDIS_NULL,
        ]);

        $this->redis->zAdd($this->redisPendingSetKey($queue), $retryTime->timestamp, (string) $jobId);
        $this->redis->zRem($this->redisReservedSetKey($queue), (string) $jobId);
        $this->redis->zRem($this->redisFailedSetKey(), (string) $jobId);
    }

    private function markJobAsFailedRedis(int $jobId, \Throwable $exception, int $attempts): void
    {
        if (!$this->redis) {
            return;
        }

        $row = $this->getRedisJob($jobId);
        if (!$row) {
            return;
        }

        $queue = $this->redisNullIfMissing((string) ($row['queue'] ?? 'default')) ?? 'default';

        $stack = $exception->getPrevious()?->getTraceAsString() ?? $exception->getTraceAsString();
        $exceptionText = sprintf('%s: %s\nStack trace:\n%s', get_class($exception), $exception->getMessage(), $stack);
        $failedAt = now();

        $this->redis->hMSet($this->redisJobHashKey($jobId), [
            'status' => 'failed',
            'attempts' => (string) $attempts,
            'reserved_at' => self::REDIS_NULL,
            'failed_at' => (string) $failedAt,
            'exception' => $exceptionText,
        ]);

        $this->redis->zRem($this->redisReservedSetKey($queue), (string) $jobId);
        $this->redis->zAdd($this->redisFailedSetKey(), $failedAt->timestamp, (string) $jobId);
    }

    private function recoverStaleJobsRedis(int $timeout = 3600): int
    {
        if (!$this->redis) {
            return 0;
        }

        $staleBefore = time() - $timeout;
        $queues = $this->redis->sMembers($this->redisQueuesSetKey()) ?: [];
        if (!is_array($queues)) {
            return 0;
        }

        $recovered = 0;

        foreach ($queues as $queue) {
            $reserved = $this->redis->zRangeByScore($this->redisReservedSetKey((string) $queue), '-inf', $staleBefore, ['withscores' => true]);
            if (!is_array($reserved)) {
                continue;
            }

            foreach (array_keys($reserved) as $id) {
                $row = $this->getRedisJob((int) $id);
                if (!$row) {
                    $this->redis->zRem($this->redisReservedSetKey((string) $queue), (string) $id);
                    continue;
                }

                $status = $this->redisNullIfMissing((string) ($row['status'] ?? ''));
                if ($status !== 'processing' && $status !== 'reserved') {
                    $this->redis->zRem($this->redisReservedSetKey((string) $queue), (string) $id);
                    continue;
                }

                $this->updateJobStatusRedis((int) $id, 'pending', (int) $this->redisToIntValue($row['attempts'] ?? '0'));
                $recovered++;
            }
        }

        if ($recovered > 0) {
            Prompt::message(
                sprintf('Recovered <bold>%d</bold> stale job(s)', $recovered),
                'warning'
            );
        }

        return $recovered;
    }

    private function toQueueList(array|string $queues): array
    {
        if (is_string($queues)) {
            return array_filter(array_map('trim', explode(',', $queues)));
        }

        return array_unique(array_values(array_filter((array) $queues, static fn($q): bool => is_string($q) && trim($q) !== '')));
    }

    private function toStatusList(array|string|null $status): array
    {
        if ($status === null) {
            return [];
        }

        if (is_string($status)) {
            return array_filter(array_map('trim', explode(',', $status)));
        }

        return array_map('trim', array_filter((array) $status, static fn($value): bool => is_string($value) && trim($value) !== ''));
    }

    private function deleteNamespaceMetaKeys(): void
    {
        if (!$this->redis) {
            return;
        }

        $cursor = 0;
        $dupeKeys = [];
        do {
            $keys = $this->redis->scan($cursor, $this->redisPrefix . ':dupe:*');
            if ($keys !== false) {
                foreach ((array) $keys as $key) {
                    $dupeKeys[] = (string) $key;
                }
            }
        } while ($cursor > 0);

        if ($dupeKeys === []) {
            return;
        }

        foreach ($dupeKeys as $dupeKey) {
            $jobId = $this->redis->get($dupeKey);
            if (!is_string($jobId) || $jobId === '') {
                $this->redis->del($dupeKey);
                continue;
            }

            $row = $this->getRedisJob((int) $jobId);
            if (!$row) {
                $this->redis->del($dupeKey);
            }
        }
    }
}
