<?php

namespace Spark\Contracts\Queue;

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
     * @return void
     */
    public function run(): void;
}