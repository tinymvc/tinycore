<?php

namespace Spark\Queue;

use PDO;
use RuntimeException;
use Spark\Console\Prompt;
use Spark\Queue\Contracts\QueueContract;
use Spark\Queue\Exceptions\FailedToLoadJobsException;
use Spark\Queue\Exceptions\FailedToSaveJobsException;
use Spark\Queue\Exceptions\InvalidStorageFileException;
use Spark\Support\Traits\Macroable;
use Spark\Utils\Carbon;
use function count;
use function get_class;
use function is_array;
use function sprintf;

/**
 * A job queue that stores the jobs in a JSON file.
 *
 * This class uses the {@see Job} class to store the jobs in a JSON file.
 * The queue is saved to a file on the disk, and the jobs are loaded from
 * the file when the queue is constructed.
 * 
 * @package Spark\Queue
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Queue implements QueueContract
{
    use Macroable;

    /**
     * The log file path or false if logging is disabled.
     *
     * @var bool|string
     */
    private bool|string $log;

    /**
     * The PDO instance for database operations.
     *
     * @var PDO
     */
    private PDO $pdo;

    /**
     * Constructs a new instance of the queue.
     *
     * @param bool|string $log Whether to enable logging. If true, logs to
     *                         storage_dir('queue.log'). If a string is provided,
     *                        logs to that file. If false, logging is disabled.
     */
    public function __construct(bool|string $log = true)
    {
        try {
            $this->pdo = new PDO('sqlite:' . storage_dir('queue.db')); // Initialize SQLite database.
            $this->pdo->exec("PRAGMA foreign_keys = ON"); // Enable foreign key constraints.
            $this->pdo->exec("PRAGMA journal_mode = WAL"); // Write-Ahead Logging
            $this->pdo->exec("PRAGMA synchronous = NORMAL"); // Faster writes
            $this->pdo->exec("PRAGMA cache_size = 10000"); // 10MB cache
            $this->pdo->exec("PRAGMA temp_store = MEMORY"); // Temp tables in RAM
        } catch (\PDOException $e) {
            throw new RuntimeException('Failed to connect to the SQLite database: ' . $e->getMessage());
        }

        $this->logging($log); // Set up logging.
    }

    /**
     * Installs the queue database by creating necessary tables and indexes.
     *
     * @return void
     */
    public function install(): void
    {
        Prompt::message('Installing the queue database...', 'info');

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
        } catch (\PDOException $e) {
            Prompt::message('Failed to create the queue database tables: ' . $e->getMessage(), 'danger');
            return; // Exit the method on failure.
        }

        Prompt::message('Queue database installed successfully.', 'success');
    }

    /**
     * Sets up logging for the queue.
     *
     * @param bool|string $log Whether to enable logging. If true, logs to
     *                         storage_dir('queue.log'). If a string is provided,
     *                         logs to that file. If false, logging is disabled.
     *
     * @return $this The current instance of the queue.
     */
    public function logging(bool|string $log = true): self
    {
        // Set up logging based on the provided parameter.
        if ($log === true) {
            $this->log = storage_dir('logs/queue.log');
        } else {
            $this->log = $log;
        }

        // If the queue file does not exist, try to create it.
        if ($this->log) {
            if (!is_file($this->log) && !touch($this->log)) {
                throw new InvalidStorageFileException('Failed to create the queue log file.');
            }
            // If the queue file is not writable, try to make it so.
            elseif (!is_writable($this->log) && !chmod($this->log, 0666)) {
                throw new InvalidStorageFileException(
                    sprintf('The queue log file (%s) is not writable.', $this->log)
                );
            }
        }

        return $this;
    }

    /**
     * Adds a job to the queue.
     *
     * @param Job $job The job to be added.
     * @param string $queue The name of the queue to add the job to.
     */
    public function push(Job $job, string $queue = 'default'): void
    {
        $job = $this->serializeJob($job);

        try {
            $this->pdo->prepare(
                "INSERT INTO jobs (payload, queue, scheduled_time, created_at, repeat, status, attempts) 
                VALUES (:payload, :queue, :scheduled_time, :created_at, :repeat, :status, :attempts)"
            )
                ->execute([
                    ':payload' => json_encode([
                        'callback' => $job['callback'],
                        'parameters' => $job['parameters'],
                    ]),
                    ':queue' => $queue,
                    ':scheduled_time' => $job['scheduledTime'],
                    ':created_at' => now(),
                    ':repeat' => $job['repeat'],
                    ':status' => 'pending',
                    ':attempts' => 0,
                ]);
        } catch (\PDOException $e) {
            throw new FailedToSaveJobsException('Failed to add job to the queue: ' . $e->getMessage());
        }
    }

    /**
     * Clears all jobs from the queue.
     *
     * This method will remove all jobs from the queue and mark the queue as changed.
     *
     * @return void
     */
    public function clearAllJobs(): void
    {
        $this->pdo->exec("DELETE FROM jobs");
    }

    /**
     * Clears all repeated jobs from the queue.
     *
     * This method will filter out jobs that are marked to be repeated from the
     * queue and mark the queue as changed.
     *
     * @return void
     */
    public function clearRepeatedJobs(): void
    {
        $this->pdo->exec("DELETE FROM jobs WHERE repeat IS NOT NULL");
    }

    /**
     * Clears all failed jobs from the queue.
     *
     * This method will filter out jobs that have failed from the
     * queue and mark the queue as changed.
     *
     * @return void
     */
    public function clearFailedJobs(): void
    {
        $this->pdo->exec("DELETE FROM jobs WHERE status = 'failed'");
    }

    /**
     * Removes a job from the queue by its ID.
     *
     * This method will remove the job with the given ID from the queue and mark
     * the queue as changed.
     *
     * @param int $id The ID of the job to be removed.
     *
     * @return bool True if the job was removed, false otherwise.
     */
    public function removeJobById(int $id): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM jobs WHERE id = :id");
        $statement->execute([':id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Removes all jobs from a specific queue.
     *
     * This method will remove all jobs associated with the given queue name
     * from the queue and mark the queue as changed.
     *
     * @param string $name The name of the queue to be removed.
     *
     * @return bool True if any jobs were removed, false otherwise.
     */
    public function removeQueue(string $name): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM jobs WHERE queue = :queue");
        $statement->execute([':queue' => $name]);

        return $statement->rowCount() > 0;
    }

    /**
     * Returns the next job from the queue.
     *
     * This method retrieves the next job from the specified queue(s) that is
     * pending and scheduled to run at or before the current time.
     * Uses a transaction to prevent race conditions between multiple workers.
     *
     * @param array|string $queue The name(s) of the queue(s) to retrieve the job from.
     *
     * @return false|Job The next job in the queue, or false if no job is available.
     */
    private function getNextJob(array|string $queue = 'default'): false|Job
    {
        try {
            $this->pdo->beginTransaction();

            $queue = !is_array($queue) ? "'$queue'" :
                "'" . implode("','", $queue) . "'";

            // Select job and immediately mark it as reserved to prevent race conditions
            $statement = $this->pdo->prepare(
                "SELECT * FROM jobs WHERE queue IN($queue) AND status = 'pending' 
                    AND (scheduled_time IS NULL OR scheduled_time <= :now) 
                    ORDER BY scheduled_time ASC LIMIT 1"
            );

            $statement->execute([':now' => now()]);
            $job = $statement->fetch(PDO::FETCH_ASSOC);

            if ($job) {
                // Mark job as reserved immediately to prevent other workers from picking it up
                $updateStmt = $this->pdo->prepare(
                    "UPDATE jobs SET status = 'reserved', reserved_at = :reserved_at WHERE id = :id AND status = 'pending'"
                );

                $updateStmt->execute([
                    ':reserved_at' => now(),
                    ':id' => $job['id']
                ]);

                // If no rows were updated, another worker got this job
                if ($updateStmt->rowCount() === 0) {
                    $this->pdo->commit();
                    return false;
                }

                $this->pdo->commit();
                return $this->unserializeJob($job);
            }

            $this->pdo->commit();
            return false;

        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Failed to get next job: ' . $e->getMessage());
        }
    }

    /**
     * Runs the jobs in the queue.
     *
     * This method will iterate over the jobs in the queue and execute them if
     * their scheduled time is in the past. If the job is repeated, it will be
     * rescheduled for the next time. If the job is not repeated, it will be
     * removed from the queue.
     * 
     * @param bool $once Whether to run only once (process one batch) or continuously.
     * @param int $timeout Maximum execution time in seconds before stopping.
     * @param int $sleep Sleep time in seconds between queue checks when no jobs are available.
     * @param int $delay Delay time in seconds before retrying failed jobs.
     * @param int $tries Maximum number of attempts for a job before marking it as permanently failed.
     * @param array|string $queue The queue(s) to run jobs from.
     * 
     * @return void
     */
    public function work(
        bool $once = false,
        int $timeout = 3600, // 1 hour
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
        Prompt::message("Queue worker started for queue(s): <bold>$queueNames</bold>", 'info');

        // Recover stale jobs (jobs stuck in 'processing' status for too long)
        $this->recoverStaleJobs();

        do {
            // Check if timeout has been reached
            if ((microtime(true) - $startedAt) >= $timeout) {
                Prompt::message('Queue worker timeout reached. Shutting down...', 'warning');
                break;
            }

            // Get the next job from the queue
            $job = $this->getNextJob($queue);

            if (!$job) {
                $once === false && sleep($sleep); // No jobs available, sleep for a bit if not running once
                continue;
            }

            $jobId = $job->getMetadata()['id'];
            $attempts = $job->getMetadata()['attempts'];

            Prompt::message(
                sprintf(
                    "Processing job <bold>#%d</bold> (%s) - Attempt %d/%d",
                    $jobId,
                    $job->getDisplayName(),
                    $attempts + 1,
                    $tries
                ),
                'info'
            );

            try {
                // Mark job as processing
                $this->updateJobStatus($jobId, 'processing', $attempts + 1);

                // Execute the job
                $job->handle();

                // Job succeeded
                if ($job->isRepeated()) {
                    // Reschedule repeated job
                    $nextRun = now()->modify('+' . $job->getRepeat());
                    $this->rescheduleJob($jobId, $nextRun);

                    Prompt::message(
                        sprintf(
                            "Job <bold>#%d</bold> completed and rescheduled for %s",
                            $jobId,
                            $nextRun
                        ),
                        'success'
                    );
                } else {
                    // Remove one-time job
                    $this->removeJobById($jobId);

                    Prompt::message("Job <bold>#$jobId</bold> completed successfully", 'success');
                }

                $ranJobs++;

            } catch (\Throwable $e) {
                // Job failed
                $newAttempts = $attempts + 1;

                Prompt::message(
                    sprintf("Job <bold>#%d</bold> failed: %s", $jobId, $e->getMessage()),
                    'error'
                );

                if ($newAttempts >= $tries) {
                    // Max attempts reached, mark as permanently failed
                    $this->markJobAsFailed($jobId, $e, $newAttempts);
                    $failedJobs++;

                    Prompt::message(
                        sprintf(
                            "Job <bold>#%d</bold> failed permanently after %d attempts",
                            $jobId,
                            $newAttempts
                        ),
                        'danger'
                    );
                } else {
                    // Retry the job after delay
                    $retryTime = now()->addSeconds($delay);
                    $this->retryJob($jobId, $retryTime, $newAttempts);

                    Prompt::message(
                        sprintf(
                            "Job <bold>#%d</bold> will be retried at %s",
                            $jobId,
                            $retryTime
                        ),
                        'warning'
                    );
                }

                $failedJobs++;
            }

            // Check if we should stop after processing one job
            if ($once) {
                break;
            }

        } while (true);

        $timeUsed = microtime(true) - $startedAt;
        $memoryUsed = memory_get_usage(true) - $startedMemory;

        // Log the queue run summary
        if ($ranJobs > 0 || $failedJobs > 0) {
            $this->addQueueLog($timeUsed, $memoryUsed, $ranJobs, $failedJobs);
        }

        Prompt::message(
            sprintf("Queue worker finished. Ran %d job(s), %d failed", $ranJobs, $failedJobs),
            'info'
        );
    }

    /**
     * Updates the status of a job in the database.
     *
     * @param int $jobId The ID of the job to update.
     * @param string $status The new status of the job.
     * @param int $attempts The number of attempts made to run the job.
     *
     * @return void
     */
    private function updateJobStatus(int $jobId, string $status, int $attempts): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE jobs SET status = :status, attempts = :attempts, reserved_at = :reserved_at WHERE id = :id"
        );

        $statement->execute([
            ':status' => $status,
            ':attempts' => $attempts,
            ':reserved_at' => $status === 'processing' ? now() : null,
            ':id' => $jobId,
        ]);
    }

    /**
     * Reschedules a repeated job for its next execution.
     *
     * @param int $jobId The ID of the job to reschedule.
     * @param Carbon $nextRun The time for the next execution.
     *
     * @return void
     */
    private function rescheduleJob(int $jobId, Carbon $nextRun): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE jobs SET scheduled_time = :scheduled_time, status = 'pending', attempts = 0, reserved_at = NULL WHERE id = :id"
        );

        $statement->execute([
            ':scheduled_time' => $nextRun,
            ':id' => $jobId,
        ]);
    }

    /**
     * Marks a job as permanently failed and logs it to the failed_jobs table.
     *
     * @param int $jobId The ID of the job that failed.
     * @param \Throwable $exception The exception that caused the failure.
     * @param int $attempts The number of attempts made to run the job.
     *
     * @return void
     */
    private function markJobAsFailed(int $jobId, \Throwable $exception, int $attempts): void
    {
        try {
            $this->pdo->beginTransaction();

            // Update job status
            $statement = $this->pdo->prepare(
                "UPDATE jobs SET status = 'failed', attempts = :attempts WHERE id = :id"
            );

            $statement->execute([
                ':attempts' => $attempts,
                ':id' => $jobId,
            ]);

            // Log to failed_jobs table
            $statement = $this->pdo->prepare(
                "INSERT INTO failed_jobs (job_id, failed_at, exception, attempts) 
                VALUES (:job_id, :failed_at, :exception, :attempts)"
            );

            $statement->execute([
                ':job_id' => $jobId,
                ':failed_at' => now(),
                ':exception' => sprintf(
                    "%s: %s\n\nStack trace:\n%s",
                    get_class($exception),
                    $exception->getMessage(),
                    $exception->getTraceAsString()
                ),
                ':attempts' => $attempts,
            ]);

            $this->pdo->commit();

        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Failed to mark job as failed: ' . $e->getMessage());
        }
    }

    /**
     * Retries a failed job by rescheduling it for a later time.
     *
     * @param int $jobId The ID of the job to retry.
     * @param Carbon $retryTime The time to retry the job.
     * @param int $attempts The number of attempts made so far.
     *
     * @return void
     */
    private function retryJob(int $jobId, Carbon $retryTime, int $attempts): void
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

    /**
     * Recovers stale jobs that are stuck in 'processing' or 'reserved' status.
     *
     * This method finds jobs that have been reserved or processing for too long
     * (likely due to worker crashes) and resets them to pending status.
     *
     * @param int $timeout The number of seconds after which a job is considered stale (default: 3600 = 1 hour).
     *
     * @return int The number of stale jobs recovered.
     */
    public function recoverStaleJobs(int $timeout = 3600): int
    {
        try {
            $staleTime = now()->subSeconds($timeout);

            $statement = $this->pdo->prepare(
                "UPDATE jobs SET status = 'pending', reserved_at = NULL 
                WHERE status IN ('processing', 'reserved') 
                AND reserved_at IS NOT NULL 
                AND reserved_at < :stale_time"
            );

            $statement->execute([':stale_time' => $staleTime]);

            $recovered = $statement->rowCount();

            if ($recovered > 0) {
                Prompt::message(
                    sprintf("Recovered <bold>%d</bold> stale job(s)", $recovered),
                    'warning'
                );
            }

            return $recovered;

        } catch (\PDOException $e) {
            throw new RuntimeException('Failed to recover stale jobs: ' . $e->getMessage());
        }
    }

    /**
     * Returns the jobs in the queue.
     * 
     * This method retrieves jobs from the queue based on the specified
     * queue names and statuses, with pagination support.
     * 
     * @param array|string|null $queue The name(s) of the queue(s) to filter jobs by.
     * @param array|string|null $status The status(es) of the jobs to filter by.
     * @param int $from The starting index for pagination.
     * @param int $to The ending index for pagination.
     *
     * @return array<int, Job> The array of jobs in the queue.
     */
    public function getJobs(
        array|string|null $queue = null,
        array|string|null $status = null,
        int $from = 0,
        int $to = 500,
    ): array {
        try {
            $where = '';
            if (!empty($queue)) {
                $queue = !is_array($queue) ? "'$queue'" :
                    "'" . implode("','", $queue) . "'";
                $where .= !empty($where) ? " AND " : "";
                $where .= "queue IN($queue)";
            }

            if (!empty($status)) {
                $status = !is_array($status) ? "'$status'" :
                    "'" . implode("','", $status) . "'";
                $where .= !empty($where) ? " AND " : "";
                $where .= "status IN($status)";
            }

            if (!empty($where)) {
                $where = "WHERE $where"; // Add WHERE clause if needed.
            }

            $statement = $this->pdo->prepare(
                "SELECT * FROM jobs $where LIMIT :from, :to"
            );

            $statement->execute([':from' => $from, ':to' => $to]);
            $jobs = [];
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $jobs[] = $this->unserializeJob($row);
            }
        } catch (\PDOException $e) {
            throw new FailedToLoadJobsException(
                'Failed to load jobs from the queue: ' . $e->getMessage()
            );
        }

        return $jobs;
    }

    /**
     * Returns the failed jobs in the queue.
     * 
     * This method retrieves failed jobs from the queue with pagination support.
     * 
     * @param int $from The starting index for pagination.
     * @param int $to The ending index for pagination.
     *
     * @return array<int, Job> The array of failed jobs in the queue.
     */
    public function getFailedJobs(int $from = 0, int $to = 500): array
    {
        try {
            $statement = $this->pdo->prepare(
                "SELECT fj.*, j.payload, j.queue, j.scheduled_time, j.created_at, j.repeat, j.status 
                FROM failed_jobs fj 
                JOIN jobs j ON fj.job_id = j.id 
                ORDER BY fj.failed_at DESC 
                LIMIT :from, :to"
            );

            $statement->execute([':from' => $from, ':to' => $to]);
            $jobs = [];
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $jobs[] = $this->unserializeJob($row);
            }
        } catch (\PDOException $e) {
            throw new FailedToLoadJobsException(
                'Failed to load failed jobs from the queue: ' . $e->getMessage()
            );
        }

        return $jobs;
    }

    /**
     * Retries all failed jobs in the queue.
     *
     * This method will move all jobs from the failed_jobs table back to the
     * jobs table with a status of 'pending' and reset their attempt counts.
     *
     * @return void
     */
    public function retryFailedJobs(): void
    {
        try {
            $this->pdo->beginTransaction();

            // Get all failed job IDs
            $statement = $this->pdo->prepare("SELECT job_id FROM failed_jobs");
            $statement->execute();
            $failedJobIds = $statement->fetchAll(PDO::FETCH_COLUMN);

            if (empty($failedJobIds)) {
                $this->pdo->commit();
                return; // No failed jobs to retry.
            }

            // Update jobs to pending status
            $inClause = implode(',', array_fill(0, count($failedJobIds), '?'));
            $updateStatement = $this->pdo->prepare(
                "UPDATE jobs SET status = 'pending', attempts = 0, reserved_at = NULL WHERE id IN ($inClause)"
            );
            $updateStatement->execute($failedJobIds);

            // Clear failed_jobs table
            $this->pdo->exec("DELETE FROM failed_jobs");

            $this->pdo->commit();

        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Failed to retry failed jobs: ' . $e->getMessage());
        }
    }

    /**
     * Serializes a Job object into an array that can be saved to JSON.
     *
     * This method takes a Job object and returns an array of data that can be saved
     * to JSON. The callback is serialized into a string and the scheduled time is
     * saved as a string. The repeat and priority are saved as they are. The event
     * listeners are serialized into an array of arrays, where each inner array
     * contains the priority and callback of the event listener.
     *
     * @param Job $job The Job object to be serialized.
     *
     * @return array The serialized Job object as an array.
     */
    private function serializeJob(Job $job): array
    {
        return [
            'callback' => $job->getCallback(), // Serialize the callback and save it.
            'parameters' => $job->getParameters(), // Save the parameters as it is.
            'scheduledTime' => $job->getScheduledTime(), // Save the scheduled time as string.
            'repeat' => $job->getRepeat(), // Save the repeat as it is.
            'metadata' => $job->getMetadata(), // Save the metadata as it is.
        ];
    }

    /**
     * Unserializes a serialized Job object into a Job object.
     *
     * This method takes a serialized Job object and returns a Job object.
     * The callback is unserialized into a callable and the scheduled time is
     * set using the ISO 8601 string. The repeat and priority are set as they
     * are. The event listeners are unserialized into an array of arrays, where
     * each inner array contains the priority and callback of the event listener.
     *
     * @param array $job The serialized Job object to be unserialized.
     *
     * @return Job The unserialized Job object.
     */
    private function unserializeJob(array $job): Job
    {
        $payload = json_decode($job['payload'], true);

        return new Job(
            callback: $payload['callback'],
            parameters: $payload['parameters'],
            scheduledTime: new Carbon($job['scheduled_time']),
            repeat: $job['repeat'],
            metadata: [
                'id' => $job['id'] ?? null,
                'queue' => $job['queue'] ?? 'default',
                'attempts' => $job['attempts'] ?? 0,
                'created_at' => $job['created_at'] ?? null,
                'status' => $job['status'] ?? 'pending',
                'failed_at' => $job['failed_at'] ?? null,
                'reserved_at' => $job['reserved_at'] ?? null,
                'exception' => $job['exception'] ?? null,
            ],
        );
    }

    /**
     * Adds a log entry to the queue log file.
     *
     * This method will add a log entry to the queue log file with the time
     * taken to run the jobs, the memory used, and the number of jobs run.
     * The log entry is added to the beginning of the log file and only the
     * latest 5000 entries are kept.
     *
     * @param float $timeUsed The time taken to run the jobs in milliseconds.
     * @param int $memoryUsed The memory used to run the jobs in bytes.
     * @param int $ranJobs The number of jobs that were run.
     *
     * @return void
     */
    private function addQueueLog(float $timeUsed, int $memoryUsed, int $ranJobs, int $failedJobs): void
    {
        if (empty($this->log)) {
            return; // If logging is disabled, do nothing.
        }

        $maxFileSize = 5 * 1024 * 1024; // 5 MB in bytes

        // Check if log file exists and its size and rotate if it exceeds the max size.
        if (is_file($this->log) && filesize($this->log) >= $maxFileSize) {
            rename($this->log, $this->log . '.' . date('Y-m-d_H-i-s'));
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
}
