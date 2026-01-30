<?php

namespace Spark\Queue\Contracts;

use Spark\Utils\Carbon;

/**
 * Interface for jobs in the queue.
 *
 * A job is an instance of an object that is going to be executed on the queue.
 * This interface is used to define the minimum requirements for a job.
 */
interface JobContract
{
    /**
     * Sets the repeat option for the job.
     *
     * The repeat option can be set to a string that represents the interval
     * when the job should be repeated. If set to null, the job won't be repeated.
     *
     * @param string $repeat
     *     The repeat option.
     *
     * @return self
     *     The job instance.
     */
    public function repeat(string $repeat): self;

    /**
     * Sets the schedule for the job.
     *
     * The schedule can be set to a string that represents the date and time
     * when the job should be executed. If set to null, the job will be executed
     * immediately.
     *
     * @param string|Carbon $scheduledTime
     *     The schedule for the job.
     *
     * @return self
     *     The job instance.
     */
    public function schedule(string|Carbon $scheduledTime): self;

    /**
     * Handles the job.
     *
     * This method will execute the job and all the before and after closures.
     *
     * @return void
     */
    public function handle(): void;

    /**
     * Gets the scheduled time of the job.
     *
     * If the job has no schedule, a new Carbon instance with the current
     * time will be returned.
     *
     * @return Carbon
     *     The scheduled time of the job.
     */
    public function getScheduledTime(): Carbon;

    /**
     * Dispatches the job.
     *
     * This method will handle the job and dispatch it to the queue.
     * 
     * @param string $name The name of the queue to which the job should be dispatched.
     * @return void
     */
    public function dispatch(string $name = 'default'): void;
}