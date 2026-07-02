<?php

namespace Spark\Queue;

use PDO;
use Spark\Console\Prompt;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\QueueContract;
use Spark\Queue\Exceptions\InvalidStorageFileException;
use Spark\Queue\Storage\RedisStorage;
use Spark\Queue\Storage\SqliteStorage;
use Spark\Support\Traits\Macroable;
use function file_put_contents;
use function implode;
use function is_array;
use function is_file;
use function is_writable;
use function memory_get_usage;
use function microtime;
use function rand;
use function sleep;
use function sprintf;

/**
 * Public queue manager and worker lifecycle coordinator.
 *
 * Queue storage is delegated to driver-specific storage classes so the worker
 * logic stays consistent across SQLite and Redis.
 */
class Queue implements QueueContract
{
    use Macroable;

    private bool|string $log;

    /** The queue storage implementation used for job persistence and retrieval. */
    private \Spark\Queue\Contracts\QueueStorageContract $storage;

    public function __construct(bool|string $log = false)
    {
        $connection = $this->resolveDriverConfig();
        $driver = strtolower((string) ($connection['driver'] ?? 'sqlite'));
        $this->storage = $driver === 'redis'
            ? new RedisStorage($connection)
            : new SqliteStorage($connection);

        $this->logging($log);
    }

    public function getPdoConnection(): ?PDO
    {
        return $this->storage->getPdoConnection();
    }

    public function logging(bool|string $log = true): void
    {
        $this->log = $log === true ? storage_dir('logs/queue.log') : $log;

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

            $job = $this->storage->getNextJob($queue);

            if (!$job) {
                $once === false && sleep($sleep);
                continue;
            }

            $jobId = (int) $job->getId();
            $attempts = (int) $job->getMetadata('attempts', 0);
            $maxTries = $job->getTries($tries);

            $this->message(
                sprintf(
                    'Processing job #%d (%s) - Attempt %d/%d',
                    $jobId,
                    $job->getDisplayName(),
                    $attempts + 1,
                    $maxTries,
                ),
            );

            try {
                $this->storage->updateJobStatus($jobId, 'processing', $attempts + 1);

                $job->handle();

                if ($job->isRepeated()) {
                    $nextRun = now()->modify('+' . $job->getRepeat());
                    $this->storage->rescheduleJob($jobId, $nextRun);

                    $this->message(sprintf('Job #%d completed and rescheduled for %s', $jobId, $nextRun));
                } else {
                    $this->removeJobById($jobId);
                    $this->message("Job #$jobId completed successfully");
                }

                $ranJobs++;
            } catch (\Throwable $e) {
                $newAttempts = $attempts + 1;

                $this->message(sprintf('Job #%d failed: %s', $jobId, $e->getMessage()));

                if ($newAttempts >= $maxTries) {
                    $this->storage->markJobAsFailed($jobId, $e, $newAttempts);
                    $this->callFailedHandler($job, $e->getPrevious() ?? $e);

                    $this->message(sprintf('Job #%d failed permanently after %d attempts', $jobId, $newAttempts));
                } else {
                    $retryDelay = $job->getBackoff($delay, $newAttempts);
                    $retryTime = now()->addSeconds($retryDelay);
                    $this->storage->retryJob($jobId, $retryTime, $newAttempts);

                    $this->message(sprintf('Job #%d will be retried at %s', $jobId, $retryTime));
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

        $this->message(sprintf('Queue worker finished. Ran %d job(s), %d failed', $ranJobs, $failedJobs));
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
            Prompt::message(
                sprintf('Recovered <bold>%d</bold> stale job(s)', $recovered),
                'warning'
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
                )
            );
        }
    }

    private function message(string $message): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
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

    private function resolveDriverConfig(): array
    {
        $queueConfig = (array) config('queue', []);
        $driver = strtolower((string) ($queueConfig['driver'] ?? 'sqlite'));
        $connections = (array) ($queueConfig['connections'] ?? []);
        $connection = is_array($connections[$driver] ?? null) ? $connections[$driver] : [];

        return [
            ...$connection,
            'driver' => $driver,
        ];
    }
}
