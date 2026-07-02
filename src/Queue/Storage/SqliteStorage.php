<?php

namespace Spark\Queue\Storage;

use PDO;
use PDOException;
use RuntimeException;
use Spark\Carbon;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\QueueStorageContract;
use Spark\Queue\Exceptions\FailedToLoadJobsException;
use Spark\Queue\Exceptions\FailedToSaveJobsException;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function dirname;
use function explode;
use function get_class;
use function is_array;
use function is_dir;
use function is_string;
use function mkdir;
use function pathinfo;
use function rtrim;
use function sprintf;
use function str_ends_with;

class SqliteStorage implements QueueStorageContract
{
    use SerializesJobs;

    private PDO $pdo;

    public function __construct(private readonly array $config)
    {
        try {
            $path = $this->sqliteQueuePath($config);
            $this->pdo = new PDO("sqlite:$path");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA cache_size = 10000');
            $this->pdo->exec('PRAGMA temp_store = MEMORY');
            $this->createJobsTableIfNotExists();
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to connect to the SQLite database: ' . $e->getMessage(), previous: $e);
        }
    }

    public function getPdoConnection(): ?PDO
    {
        return $this->pdo;
    }

    public function push(JobContract $job, string $queue = 'default'): void
    {
        $payload = $this->serializeJob($job);

        try {
            $this->pdo->prepare(
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
            throw new FailedToSaveJobsException('Failed to add job to the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    public function pushOnce(JobContract $job, string $queue = 'default'): void
    {
        $payload = json_encode([
            'callback' => $job->getCallback(),
            'parameters' => $job->getParameters(),
        ]);

        $repeatCondition = $job->isRepeated() ? '= :repeat' : 'IS NULL';
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM jobs WHERE payload = :payload AND queue = :queue AND repeat $repeatCondition"
        );

        $params = [
            ':payload' => $payload,
            ':queue' => $queue,
        ];

        if ($job->isRepeated()) {
            $params[':repeat'] = $job->getRepeat();
        }

        $stmt->execute($params);

        if ((int) $stmt->fetchColumn() === 0) {
            $this->push($job, $queue);
        }
    }

    public function clearAllJobs(): void
    {
        $this->pdo->exec('DELETE FROM jobs');
    }

    public function clearRepeatedJobs(): void
    {
        $this->pdo->exec('DELETE FROM jobs WHERE repeat IS NOT NULL');
    }

    public function clearFailedJobs(): void
    {
        $this->pdo->exec("DELETE FROM jobs WHERE status = 'failed'");
    }

    public function removeJobById(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM jobs WHERE id = :id');
        $statement->execute([':id' => $id]);

        return $statement->rowCount() > 0;
    }

    public function removeQueue(string $name): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM jobs WHERE queue = :queue');
        $statement->execute([':queue' => $name]);

        return $statement->rowCount() > 0;
    }

    public function getNextJob(array|string $queue = 'default'): false|JobContract
    {
        try {
            $this->pdo->beginTransaction();

            $queueClause = $this->sqliteInClause($queue, 'queue');
            if ($queueClause['sql'] === '') {
                $this->pdo->commit();
                return false;
            }

            $statement = $this->pdo->prepare(
                "SELECT * FROM jobs WHERE queue IN({$queueClause['sql']}) AND status = 'pending' " .
                'AND (scheduled_time IS NULL OR scheduled_time <= :now) ' .
                'ORDER BY scheduled_time ASC LIMIT 1'
            );

            $statement->execute([
                ...$queueClause['params'],
                ':now' => now(),
            ]);
            $job = $statement->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                $this->pdo->commit();
                return false;
            }

            $updateStmt = $this->pdo->prepare(
                "UPDATE jobs SET status = 'reserved', reserved_at = :reserved_at WHERE id = :id AND status = 'pending'"
            );

            $updateStmt->execute([
                ':reserved_at' => now(),
                ':id' => $job['id'],
            ]);

            if ($updateStmt->rowCount() === 0) {
                $this->pdo->commit();
                return false;
            }

            $this->pdo->commit();
            return $this->unserializeJob($job);
        } catch (PDOException $e) {
            $this->rollBack();
            throw new RuntimeException('Failed to get next job: ' . $e->getMessage(), previous: $e);
        }
    }

    public function updateJobStatus(int $jobId, string $status, int $attempts): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE jobs SET status = :status, attempts = :attempts, reserved_at = :reserved_at WHERE id = :id'
        );

        $statement->execute([
            ':status' => $status,
            ':attempts' => $attempts,
            ':reserved_at' => $status === 'processing' ? now() : null,
            ':id' => $jobId,
        ]);
    }

    public function rescheduleJob(int $jobId, Carbon $nextRun): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE jobs SET scheduled_time = :scheduled_time, status = 'pending', attempts = 0, reserved_at = NULL WHERE id = :id"
        );

        $statement->execute([
            ':scheduled_time' => $nextRun,
            ':id' => $jobId,
        ]);
    }

    public function markJobAsFailed(int $jobId, \Throwable $exception, int $attempts): void
    {
        try {
            $this->pdo->beginTransaction();

            $statement = $this->pdo->prepare("UPDATE jobs SET status = 'failed', attempts = :attempts WHERE id = :id");
            $statement->execute([
                ':attempts' => $attempts,
                ':id' => $jobId,
            ]);

            $statement = $this->pdo->prepare(
                'INSERT INTO failed_jobs (job_id, failed_at, exception, attempts) VALUES (:job_id, :failed_at, :exception, :attempts)'
            );

            $stackTraceString = $exception->getPrevious()?->getTraceAsString() ?? $exception->getTraceAsString();
            $statement->execute([
                ':job_id' => $jobId,
                ':failed_at' => now(),
                ':exception' => sprintf('%s: %s\nStack trace:\n%s', get_class($exception), $exception->getMessage(), $stackTraceString),
                ':attempts' => $attempts,
            ]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollBack();
            throw new RuntimeException('Failed to mark job as failed: ' . $e->getMessage(), previous: $e);
        }
    }

    public function retryJob(int $jobId, Carbon $retryTime, int $attempts): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE jobs SET scheduled_time = :scheduled_time, status = 'pending', attempts = :attempts, reserved_at = NULL WHERE id = :id"
        );

        $statement->execute([
            ':scheduled_time' => $retryTime,
            ':attempts' => $attempts,
            ':id' => $jobId,
        ]);
    }

    public function recoverStaleJobs(int $timeout = 3600): int
    {
        try {
            $staleTime = now()->subSeconds($timeout);
            $statement = $this->pdo->prepare(
                "UPDATE jobs SET status = 'pending', reserved_at = NULL " .
                "WHERE status IN ('processing', 'reserved') " .
                'AND reserved_at IS NOT NULL ' .
                'AND reserved_at < :stale_time'
            );

            $statement->execute([':stale_time' => $staleTime]);

            return $statement->rowCount();
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to recover stale jobs: ' . $e->getMessage(), previous: $e);
        }
    }

    public function getJobs(
        array|string|null $queue = null,
        array|string|null $status = null,
        int $from = 0,
        int $to = 500,
    ): array {
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

            $query = $whereParts !== []
                ? sprintf('SELECT * FROM jobs WHERE %s LIMIT :from, :to', implode(' AND ', $whereParts))
                : 'SELECT * FROM jobs LIMIT :from, :to';

            $statement = $this->pdo->prepare($query);
            $statement->bindValue(':from', $from, PDO::PARAM_INT);
            $statement->bindValue(':to', max(0, $to), PDO::PARAM_INT);
            foreach ($params as $key => $value) {
                $statement->bindValue($key, $value);
            }
            $statement->execute();

            $jobs = [];
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $jobs[] = $this->unserializeJob($row);
                }
            }

            return $jobs;
        } catch (PDOException $e) {
            throw new FailedToLoadJobsException('Failed to load jobs from the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    public function getFailedJobs(int $from = 0, int $to = 500): array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT fj.id AS failed_job_id, fj.job_id AS id, fj.failed_at, fj.exception, fj.attempts, ' .
                'j.payload, j.queue, j.scheduled_time, j.created_at, j.repeat, j.status ' .
                'FROM failed_jobs fj ' .
                'JOIN jobs j ON fj.job_id = j.id ' .
                'ORDER BY fj.failed_at DESC ' .
                'LIMIT :from, :to'
            );

            $statement->bindValue(':from', $from, PDO::PARAM_INT);
            $statement->bindValue(':to', max(0, $to), PDO::PARAM_INT);
            $statement->execute();

            $jobs = [];
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $jobs[] = $this->unserializeJob($row);
                }
            }

            return $jobs;
        } catch (PDOException $e) {
            throw new FailedToLoadJobsException('Failed to load failed jobs from the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    public function retryFailedJobs(): void
    {
        try {
            $this->pdo->beginTransaction();

            $statement = $this->pdo->prepare('SELECT job_id FROM failed_jobs');
            $statement->execute();
            $failedJobIds = $statement->fetchAll(PDO::FETCH_COLUMN);

            if (empty($failedJobIds)) {
                $this->pdo->commit();
                return;
            }

            $inClause = implode(',', array_fill(0, count($failedJobIds), '?'));
            $statement = $this->pdo->prepare("UPDATE jobs SET status = 'pending', attempts = 0, reserved_at = NULL WHERE id IN ($inClause)");
            $statement->execute($failedJobIds);
            $this->pdo->exec('DELETE FROM failed_jobs');
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollBack();
            throw new RuntimeException('Failed to retry failed jobs: ' . $e->getMessage(), previous: $e);
        }
    }

    private function createJobsTableIfNotExists(): void
    {
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

            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_status_scheduled ON jobs(status, scheduled_time)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_queue_status ON jobs(queue, status) WHERE queue IS NOT NULL');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_created_at ON jobs(created_at)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_reserved_at ON jobs(reserved_at) WHERE reserved_at IS NOT NULL');

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

            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_failed_jobs_job_id ON failed_jobs(job_id)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_failed_jobs_failed_at ON failed_jobs(failed_at)');
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to create jobs table: ' . $e->getMessage(), previous: $e);
        }
    }

    private function sqliteQueuePath(array $config): string
    {
        $path = (string) ($config['path'] ?? storage_dir('queue/jobs.db'));
        if ($path === '') {
            $path = storage_dir('queue/jobs.db');
        }

        if ($this->looksLikeDirectoryPath($path)) {
            $path .= DIRECTORY_SEPARATOR . 'jobs.db';
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

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
