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
     * @return JobContract
     *     The job instance.
     */
    public function repeat(string $repeat): JobContract;

    /**
     * Sets the repeat option for the job to repeat every given number of minutes.
     *
     * @param int $minutes
     *     The number of minutes between each repetition.
     *
     * @return JobContract
     *     The job instance.
     */
    public function repeatEveryMinutes(int $minutes = 1): JobContract;

    /** Repeat the job every hour */
    public function repeatHourly(): JobContract;

    /** Repeat the job every day */
    public function repeatDaily(): JobContract;

    /* Repeat the job every week */
    public function repeatWeekly(): JobContract;

    /* Repeat the job every month */
    public function repeatMonthly(): JobContract;

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
     * @return JobContract
     *     The job instance.
     */
    public function schedule(string|Carbon $scheduledTime): JobContract;

    /**
     * Sets the delay for the job.
     *
     * The delay is the number of seconds to wait before executing the job.
     *
     * @param int $seconds
     *     The delay in seconds.
     *
     * @return JobContract
     *     The job instance.
     */
    public function delay(int $seconds): JobContract;

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

    /* Dispatches the job only if it is not already scheduled or dispatched. */
    public function dispatchOnce(string $name = 'default'): void;

    /* Checks if the job is set to be repeated. */
    public function isRepeated(): bool;

    /**
     * Gets the callback for the job.
     * 
     * The callback can be a string that represents a class method or an array that represents a callable.
     * 
     * @return string|array
     */
    public function getCallback(): string|array;

    /** Gets the repeat option for the job. */
    public function getRepeat(): null|string;

    /**
     * Get the additional parameters from database and return them as an array.
     * @return array
     */
    public function getParameters(): array;

    /**
     * Gets the metadata for the job.
     * 
     * @param null|string $key
     * @param mixed $default
     * @return null|string|array
     */
    public function getMetadata(null|string $key = null, mixed $default = null): null|string|array;

    /* Gets the display name for the job. */
    public function getDisplayName(): string;

    /* Checks if the job has failed. */
    public function isFailed(): bool;

    /* Gets the reason for the job failure. */
    public function getReasonFailed(): string;

    /* Gets the name of the queue to which the job belongs. */
    public function getQueueName(): string;

    /* Gets the unique identifier for the job. */
    public function getId(): null|string;
}