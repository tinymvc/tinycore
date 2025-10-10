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
     * @return void
     */
    public function addJob(Job $job): void;

    /**
     * Runs the jobs in the queue.
     *
     * This method will execute the jobs that are scheduled and ready to run.
     *
     * @param int $maxJobs The maximum number of jobs to run in this execution.
     * @return void
     */
    public function run(int $maxJobs): void;
}