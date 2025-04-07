<?php

namespace Spark\Contracts\Queue;

use Closure;
use DateTime;

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
     * @param string|null $repeat
     *     The repeat option.
     *
     * @return self
     *     The job instance.
     */
    public function repeat(?string $repeat): self;

    /**
     * Sets the priority for the job.
     *
     * The priority is used to determine when the job should be executed. A
     * higher priority means that the job will be executed before jobs with a
     * lower priority.
     *
     * @param int $priority
     *     The priority of the job.
     *
     * @return self
     *     The job instance.
     */
    public function priority(int $priority): self;

    /**
     * Sets the schedule for the job.
     *
     * The schedule can be set to a string that represents the date and time
     * when the job should be executed. If set to null, the job will be executed
     * immediately.
     *
     * @param string|DateTime $scheduledTime
     *     The schedule for the job.
     *
     * @return self
     *     The job instance.
     */
    public function schedule(string|DateTime $scheduledTime): self;

    /**
     * Sets the catch option for the job.
     *
     * The catch option is a closure that will be executed when the job fails.
     *
     * @param string|array|callable $callback
     *     The catch callback.
     *
     * @return self
     *     The job instance.
     */
    public function catch(string|array|callable $callback): self;

    /**
     * Sets the before option for the job.
     *
     * The before option is a closure that will be executed before the job is
     * executed.
     *
     * @param string|array|callable $callback
     *     The before callback.
     *
     * @return self
     *     The job instance.
     */
    public function before(string|array|callable $callback): self;

    /**
     * Sets the after option for the job.
     *
     * The after option is a closure that will be executed after the job is
     * executed.
     *
     * @param string|array|callable $callback
     *     The after callback.
     *
     * @return self
     *     The job instance.
     */
    public function after(string|array|callable $callback): self;

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
     * If the job has no schedule, a new DateTime instance with the current
     * time will be returned.
     *
     * @return DateTime
     *     The scheduled time of the job.
     */
    public function getScheduledTime(): DateTime;

    /**
     * Dispatches the job.
     *
     * This method will handle the job and dispatch it to the queue.
     *
     * @return void
     */
    public function dispatch(): void;
}