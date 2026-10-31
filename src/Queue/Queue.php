<?php

namespace Spark\Queue;

use Spark\Console\Prompt;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\QueueContract;
use Spark\Queue\Storage\{RedisStorage, DatabaseStorage, FileStorage};
use Spark\Support\Traits\Macroable;
use Spark\Telemetry;
use function implode;
use function in_array;
use function is_array;
use function microtime;
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

    public function __construct(?array $config = null, private ?string $connectionName = null)
    {
        $connection = $config ?? QueueManager::resolveConfig((array) config('queue', []));
        $driver = strtolower((string) ($connection['driver'] ?? 'database'));
        $this->storage = match ($driver) {
            'redis' => new RedisStorage($connection),
            'file' => new FileStorage($connection),
            'database' => new DatabaseStorage($connection),
            default => throw new \InvalidArgumentException("Unsupported storage driver [{$driver}]. Use database, file or redis."),
        };
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
        Telemetry::emit('job.pushed', fn() => ['job' => $job, 'queue' => $queue]);
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
        Telemetry::emit('job.pushed', fn() => ['job' => $job, 'queue' => $queue]);
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

    /** Signal running workers to exit after their current job finishes. */
    public function restart(): void
    {
        QueueManager::restart();
    }

    /**
     * Start the queue worker to process jobs.
     *
     * @param bool $once Whether to process only one job and exit (default is false).
     * @param int $timeout Worker lifetime in seconds, checked between jobs; 0 runs indefinitely.
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
        if ($timeout < 0 || $sleep < 0 || $delay < 0 || $tries < 1) {
            throw new \InvalidArgumentException('Worker timeout, sleep and delay must be non-negative; tries must be at least 1.');
        }

        $ranJobs = 0;
        $failedJobs = 0;
        $startedAt = microtime(true);
        $restartSignal = new RestartSignal(RestartSignal::configuredPath());

        $queueNames = is_array($queue) ? implode(', ', $queue) : $queue;
        $this->message("Processing jobs from [$queueNames].");

        usleep(1000 * rand(100, 500)); // Random micro-delay to reduce race conditions on startup.

        $this->recoverStaleJobs();
        echo PHP_EOL;

        do {
            if ($restartSignal->requested()) {
                $this->message('Worker restart requested. Shutting down.');
                break;
            }

            if ($timeout > 0 && (microtime(true) - $startedAt) >= $timeout) {
                $this->message('Worker timeout reached. Shutting down.');
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
            $maxTries = $tries;
            $retryDelay = $delay;

            $jobStartedAt = microtime(true);
            $label = $job->getDisplayName();

            try {
                // Resolving retry properties may construct the application job and throw.
                $maxTries = $job->getTries($tries);
                $retryDelay = $job->getBackoff($delay, $attempts + 1);
                $label = sprintf(
                    '%s  #%d · %d/%d',
                    $label,
                    $jobId,
                    $attempts + 1,
                    $maxTries,
                );
                if (is_array($queue) && count($queue) > 1) {
                    $label .= ' · ' . $job->getMetadata('queue', 'default');
                }
                $this->message($label, 'RUNNING');
                $this->storage->updateJobStatus($jobId, 'processing', $attempts + 1);

                Telemetry::emit('job.processing', fn() => [
                    'job' => $job,
                    'queue' => is_array($queue) ? ($job->getMetadata('queue', 'default')) : $queue,
                    'id' => $jobId,
                    'attempts' => $attempts + 1,
                ]);

                $job->handle();

                if ($job->isRepeated()) {
                    $nextRun = now()->modify('+' . $job->getRepeat());
                    $this->storage->rescheduleJob($jobId, $nextRun);

                    $this->message($label, 'DONE', microtime(true) - $jobStartedAt);
                    $this->message(sprintf('Next run: %s', $nextRun), 'SCHEDULED');
                } else {
                    $this->removeJobById($jobId);
                    $this->message($label, 'DONE', microtime(true) - $jobStartedAt);
                }

                Telemetry::emit('job.processed', fn() => [
                    'job' => $job,
                    'queue' => is_array($queue) ? ($job->getMetadata('queue', 'default')) : $queue,
                    'id' => $jobId,
                    'duration_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
                ]);

                $ranJobs++;
            } catch (\Throwable $e) {
                $newAttempts = $attempts + 1;

                Telemetry::emit('job.failed', fn() => [
                    'job' => $job,
                    'queue' => is_array($queue) ? ($job->getMetadata('queue', 'default')) : $queue,
                    'id' => $jobId,
                    'duration_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
                    'exception' => $e,
                    'attempts' => $newAttempts,
                ]);

                $this->message($label, 'FAIL', microtime(true) - $jobStartedAt);
                $this->message($e->getMessage(), 'ERROR');

                if ($newAttempts >= $maxTries) {
                    $this->storage->markJobAsFailed($jobId, $e, $newAttempts);
                    $this->callFailedHandler($job, $e->getPrevious() ?? $e);

                    $this->message(sprintf('Job #%d exhausted %d attempts.', $jobId, $newAttempts), 'ERROR');
                } else {
                    $retryTime = now()->addSeconds($retryDelay);
                    $this->storage->retryJob($jobId, $retryTime, $newAttempts);

                    $this->message(sprintf('Retry at: %s', $retryTime), 'RETRY');
                }

                $failedJobs++;
            }

            echo PHP_EOL;

            if ($once) {
                break;
            }
        } while (true);

        $this->message(sprintf(
            'Stopped · %d completed · %d failed %s',
            $ranJobs,
            $failedJobs,
            $failedJobs === 1 ? 'attempt' : 'attempts',
        ));
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
        $colors = Prompt::supportsColors();

        // Strip terminal escape sequences, then flatten control characters in job/error text.
        $message = preg_replace('/\x1B(?:\][^\x07\x1B]*(?:\x07|\x1B\\\\)|\[[0-?]*[ -\/]*[@-~]|[@-_])/', '', $message) ?? $message;
        $message = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? $message);
        $timestamp = date('Y-m-d H:i:s');
        $line = "$timestamp  $message";
        $elapsed = $duration === null ? '' : ($duration >= 1
            ? sprintf('%.2fs ', $duration)
            : sprintf('%.2fms ', max(0, $duration) * 1000));

        $width = max(40, min(200, (int) (getenv('COLUMNS') ?: 80)));
        $lineWidth = function_exists('mb_strwidth') ? mb_strwidth($line, 'UTF-8') : (preg_match_all('/./us', $line) ?: strlen($line));
        $separator = ' ' . str_repeat('.', max(2, $width - $lineWidth - strlen($elapsed) - strlen($status) - 2)) . ' ';

        $color = match ($status) {
            'DONE' => '32',
            'FAIL', 'ERROR' => '31',
            'RUNNING', 'RETRY', 'WARN' => '33',
            default => '36',
        };
        $badge = $colors ? "\033[{$color}m$status\033[0m" : $status;

        if (in_array($status, ['RETRY', 'SCHEDULED'], true)) {
            $arrow = $colors ? "\033[{$color}m↳\033[0m" : '↳';
            echo str_repeat(' ', strlen($timestamp) + 2) . "$arrow $message" . PHP_EOL;
            return;
        }

        echo in_array($status, ['RUNNING', 'DONE', 'FAIL'], true)
            ? "$line$separator$elapsed$badge" . PHP_EOL
            : "$timestamp  $badge  $message" . PHP_EOL;
    }

    public function getConnectionName(): ?string
    {
        return $this->connectionName;
    }
}
