<?php

namespace Spark\Queue\Contracts;

/**
 * Interface defining the contract for a queue.
 */
interface QueueContract
{
    /**
     * Gets the PDO connection used by the queue.
     *
     * @return \PDO The PDO connection instance.
     */
    public function getPdoConnection(): \PDO;

    /**
     * Adds a job to the queue.
     *
     * @param JobContract $job The job to be added.
     * @param string $queue The name of the queue to add the job to.
     * @return void
     */
    public function push(JobContract $job, string $queue = 'default'): void;

    /**
     * Adds a job to the queue only if it does not already exist.
     *
     * @param JobContract $job The job to be added.
     * @param string $queue The name of the queue to add the job to.
     * @return void
     */
    public function pushOnce(JobContract $job, string $queue = 'default'): void;

    /**
     * Enables or disables logging for the queue.
     *
     * @param bool|string $log Whether to enable logging or the log file path.
     * @return void
     */
    public function logging(bool|string $log = true): void;

    /**
     * Runs the jobs in the queue.
     *
     * This method will execute the jobs that are scheduled and ready to run.
     *
     * @param bool $once Whether to run the queue only once.
     * @param int $timeout The maximum time in seconds to allow a job to run.
     * @param int $sleep The number of seconds to sleep between job checks.
     * @param int $delay The delay in seconds before retrying a failed job.
     * @param int $tries The maximum number of attempts for a job.
     * @param array|string $queue The name(s) of the queue(s) to run
     * @return void
     */
    public function work(
        bool $once = false,
        int $timeout = 60,
        int $sleep = 3,
        int $delay = 5,
        int $tries = 3,
        array|string $queue = 'default'
    ): void;

    /**
     * Clears all jobs from the queue, including pending, running, and failed jobs.
     *
     * @return void
     */
    public function clearAllJobs(): void;

    /**
     * Clears all pending jobs from the queue.
     *
     * @return void
     */
    public function clearRepeatedJobs(): void;

    /**
     * Clears all failed jobs from the queue.
     *
     * @return void
     */
    public function clearFailedJobs(): void;

    /**
     * Removes a job from the queue by its ID.
     *
     * @param int $id The ID of the job to be removed.
     * @return bool True if the job was successfully removed, false otherwise.
     */
    public function removeJobById(int $id): bool;

    /**
     * Removes a queue by its name.
     *
     * @param string $name The name of the queue to be removed.
     * @return bool True if the queue was successfully removed, false otherwise.
     */
    public function removeQueue(string $name): bool;

    /**
     * Retrieves jobs from the queue based on specified criteria.
     *
     * @param array|string|null $queue The name(s) of the queue(s) to filter by, or null for all queues.
     * @param array|string|null $status The status(es) of the jobs to filter by (e.g., 'pending', 'running', 'failed'), or null for all statuses.
     * @param int $from The starting index for pagination (default is 0).
     * @param int $to The ending index for pagination (default is 500).
     * @return array An array of jobs matching the specified criteria.
     */
    public function getJobs(
        array|string|null $queue = null,
        array|string|null $status = null,
        int $from = 0,
        int $to = 500,
    ): array;

    /**
     * Retrieves failed jobs from the queue with pagination.
     *
     * @param int $from The starting index for pagination (default is 0).
     * @param int $to The ending index for pagination (default is 500).
     * @return array An array of failed jobs within the specified range.
     */
    public function getFailedJobs(int $from = 0, int $to = 500): array;

    /**
     * Retries all failed jobs in the queue.
     * @return void
     */
    public function retryFailedJobs(): void;
}