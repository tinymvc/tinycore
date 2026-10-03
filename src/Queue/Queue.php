<?php

namespace Spark\Queue;

use PDO;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\QueueContract;
use Spark\Queue\Storage\{RedisStorage, DatabaseStorage, FileStorage};
use Spark\Support\Traits\Macroable;
use function implode;
use function in_array;
use function is_array;
use function microtime;
use function rand;
use function sleep;
use function sprintf;
use function strlen;

/**
 * Public queue manager and worker lifecycle coordinator.
 *
 * Queue storage is delegated to driver-specific storage classes so the worker
 * logic stays consistent across database, file and Redis.
 */
class Queue implements QueueContract
{
    use Macroable;

    /** The queue storage implementation used for job persistence and retrieval. */
    private \Spark\Queue\Contracts\QueueStorageContract $storage;

    public function __construct()
    {
        $connection = $this->resolveDriverConfig();
        $driver = strtolower((string) ($connection['driver'] ?? 'database'));
        $this->storage = match ($driver) {
            'redis' => new RedisStorage($connection),
            'file' => new FileStorage($connection),
            'database' => new DatabaseStorage($connection),
            default => throw new \InvalidArgumentException("Unsupported storage driver [{$driver}]. Use database, file or redis."),
        };
    }

    public function getConnection(): PDO|\Redis
    {
        return $this->storage->getConnection();
    }

    /**
     * Push a job onto the queue.
     *
     * @param JobContract $job The job to push onto the queue.
     * @param string $queue The name of the queue to push the job onto.
     */
    public function push(JobContract $job, string $queue = 'default'): void
    {
        $this->storage->push($job, $queue);
    }

    /**
     * Push a job onto the queue only if it doesn't already exist.
     *
     * @param JobContract $job The job to push onto the queue.
     * @param string $queue The name of the queue to push the job onto.
     */
    public function pushOnce(JobContract $job, string $queue = 'default'): void
    {
        $this->storage->pushOnce($job, $queue);
    }

    /**
     * Clear all jobs from the queue, including pending, processing, and failed jobs.
     */
    public function clearAllJobs(): void
    {
        $this->storage->clearAllJobs();
    }

    /**
     * Clear all jobs from the queue, including pending and processing jobs, but not failed jobs.
     */
    public function clearRepeatedJobs(): void
    {
        $this->storage->clearRepeatedJobs();
    }

    /**
     * Clear all failed jobs from the queue.
     */
    public function clearFailedJobs(): void
    {
        $this->storage->clearFailedJobs();
    }

    /**
     * Remove a job from the queue by its ID.
     *
     * @param int $id The ID of the job to remove.
     * @return bool True if the job was removed, false otherwise.
     */
    public function removeJobById(int $id): bool
    {
        return $this->storage->removeJobById($id);
    }

    /**
     * Remove a queue by its name.
     *
     * @param string $name The name of the queue to remove.
     * @return bool True if the queue was removed, false otherwise.
     */
    public function removeQueue(string $name): bool
    {
        return $this->storage->removeQueue($name);
    }

    /**
     * Start the queue worker to process jobs.
     *
     * @param bool $once Whether to process only one job and exit (default is false).
     * @param int $timeout The maximum time in seconds to run the worker (default is 3600).
     * @param int $sleep The number of seconds to sleep between job checks (default is 3).
     * @param int $delay The number of seconds to delay before retrying a failed job (default is 5).
     * @param int $tries The maximum number of attempts for a job before marking it as failed (default is 3).
     * @param array|string $queue The name(s) of the queue(s) to process (default is 'default').
     */
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

        $queueNames = is_array($queue) ? implode(', ', $queue) : $queue;
        $this->message("Processing jobs from [$queueNames].");

        sleep(rand(0, $sleep));
        $this->recoverStaleJobs();

        do {
            if ((microtime(true) - $startedAt) >= $timeout) {
                $this->message('Queue worker timeout reached. Shutting down...');
                break;
            }

            $job = $this->storage->getNextJob($queue);

            if (!$job) {
                if ($once) {
                    break;
                }

                sleep($sleep);
                continue;
            }

            $jobId = (int) $job->getId();
            $attempts = (int) $job->getMetadata('attempts', 0);
            $maxTries = $job->getTries($tries);

            $jobStartedAt = microtime(true);
            $label = sprintf(
                '%s [queue=%s, id=%d, attempt=%d/%d]',
                $job->getDisplayName(),
                $job->getMetadata('queue', 'default'),
                $jobId,
                $attempts + 1,
                $maxTries,
            );
            $this->message($label, 'RUNNING');

            try {
                $this->storage->updateJobStatus($jobId, 'processing', $attempts + 1);

                $job->handle();

                if ($job->isRepeated()) {
                    $nextRun = now()->modify('+' . $job->getRepeat());
                    $this->storage->rescheduleJob($jobId, $nextRun);

                    $this->message("$label - next run: $nextRun", 'DONE', microtime(true) - $jobStartedAt);
                } else {
                    $this->removeJobById($jobId);
                    $this->message($label, 'DONE', microtime(true) - $jobStartedAt);
                }

                $ranJobs++;
            } catch (\Throwable $e) {
                $newAttempts = $attempts + 1;

                $this->message("$label - " . $e->getMessage(), 'FAIL', microtime(true) - $jobStartedAt);

                if ($newAttempts >= $maxTries) {
                    $this->storage->markJobAsFailed($jobId, $e, $newAttempts);
                    $this->callFailedHandler($job, $e->getPrevious() ?? $e);

                    $this->message(sprintf('Job #%d exhausted %d attempts.', $jobId, $newAttempts), 'ERROR');
                } else {
                    $retryDelay = $job->getBackoff($delay, $newAttempts);
                    $retryTime = now()->addSeconds($retryDelay);
                    $this->storage->retryJob($jobId, $retryTime, $newAttempts);

                    $this->message(sprintf('Job #%d scheduled for %s', $jobId, $retryTime), 'RETRY');
                }

                $failedJobs++;
            }

            if ($once) {
                break;
            }
        } while (true);

        $this->message(sprintf('Worker stopped: %d completed, %d failed attempts.', $ranJobs, $failedJobs));
    }

    /**
     * Get jobs from the queue with optional filtering by queue name and status.
     *
     * @param array|string|null $queue The name(s) of the queue(s) to filter by (default is null for all queues).
     * @param array|string|null $status The status(es) of the jobs to filter by (default is null for all statuses).
     * @param int $from The starting index for pagination (default is 0).
     * @param int $to The ending index for pagination (default is 500).
     * @return array An array of jobs matching the specified criteria.
     */
    public function getJobs(
        array|string|null $queue = null,
        array|string|null $status = null,
        int $from = 0,
        int $to = 500,
    ): array {
        return $this->storage->getJobs($queue, $status, $from, $to);
    }

    /**
     * Get failed jobs from the queue with optional pagination.
     *
     * @param int $from The starting index for pagination (default is 0).
     * @param int $to The ending index for pagination (default is 500).
     * @return array An array of failed jobs.
     */
    public function getFailedJobs(int $from = 0, int $to = 500): array
    {
        return $this->storage->getFailedJobs($from, $to);
    }

    /**
     * Retry all failed jobs in the queue.
     */
    public function retryFailedJobs(): void
    {
        $this->storage->retryFailedJobs();
    }

    private function recoverStaleJobs(int $timeout = 3600): int
    {
        $recovered = $this->storage->recoverStaleJobs($timeout);

        if ($recovered > 0) {
            $this->message(
                sprintf('Recovered %d stale job(s).', $recovered),
                'WARN'
            );
        }

        return $recovered;
    }

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
                ),
                'ERROR',
            );
        }
    }

    /** Emit complete lines: no cursor movement or terminal markup in captured logs. */
    private function message(string $message, string $status = 'INFO', ?float $duration = null): void
    {
        $terminal = \defined('STDOUT') && function_exists('stream_isatty') && stream_isatty(STDOUT);
        $colors = $terminal && getenv('TERM') !== 'dumb'
            && (getenv('NO_COLOR') === false || getenv('NO_COLOR') === '');

        // Strip terminal escape sequences, then flatten control characters in job/error text.
        $message = preg_replace('/\x1B(?:\][^\x07\x1B]*(?:\x07|\x1B\\\\)|\[[0-?]*[ -\/]*[@-~]|[@-_])/', '', $message) ?? $message;
        $message = trim(preg_replace('/[\x00-\x20\x7F]+/', ' ', $message) ?? $message);
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        $elapsed = $duration === null ? '' : ($duration >= 1
            ? sprintf('%.2fs ', $duration)
            : sprintf('%.2fms ', max(0, $duration) * 1000));

        $width = max(40, min(200, (int) (getenv('COLUMNS') ?: 80)));
        $separator = $terminal
            ? ' ' . str_repeat('.', max(2, $width - strlen($line) - strlen($elapsed) - strlen($status) - 2)) . ' '
            : ' ';

        $color = match ($status) {
            'DONE' => '32',
            'FAIL', 'ERROR' => '31',
            'RUNNING', 'RETRY', 'WARN' => '33',
            default => '36',
        };
        $badge = $colors ? "\033[{$color}m$status\033[0m" : $status;

        echo "$line$separator$elapsed$badge" . PHP_EOL;
    }

    /**
     * Resolve the configured default queue connection and its settings.
     */
    private function resolveDriverConfig(): array
    {
        $queueConfig = (array) config('queue', []);
        $name = (string) (($queueConfig['default'] ?? null) ?: ($queueConfig['driver'] ?? null) ?: 'database');
        $connection = $queueConfig['connections'][$name] ?? null;

        if (!is_array($connection)) {
            if (!in_array(strtolower($name), ['database', 'file', 'redis'], true)) {
                throw new \InvalidArgumentException("Queue connection [{$name}] is not defined in queue.connections.");
            }

            $connection = []; // Built-in driver name without an entry: use driver defaults.
        }

        $connection['driver'] ??= $name;

        return $connection;
    }
}
