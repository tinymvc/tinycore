<?php

namespace Spark\Queue\Contracts;

use Spark\Queue\Job;

/**
 * Interface defining the contract for a queue.
 */
interface QueueContract
{
    /**
     * Adds a job to the queue.
     *
     * @param Job $job The job to be added.
     * @param string $queue The name of the queue to add the job to.
     * @return void
     */
    public function push(Job $job, string $queue = 'default'): void;

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
    public function run(
        bool $once = false,
        int $timeout = 60,
        int $sleep = 3,
        int $delay = 5,
        int $tries = 3,
        array|string $queue = 'default'
    ): void;
}