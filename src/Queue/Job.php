<?php

namespace Spark\Queue;

use DateTime;
use Spark\Queue\Contracts\JobContract;
use Spark\Foundation\Application;
use Spark\Queue\Exceptions\FailedToResolveJobError;
use Spark\Support\Traits\Macroable;
use Throwable;

/**
 * Class Job
 *
 * This class represents a job in the queue. It has a closure, 
 * a scheduled time, and an optional repeat.
 *
 * @package Spark\Queue
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Job implements JobContract
{
    use Macroable;

    /**
     * Constructor.
     *
     * Creates a new Job instance.
     *
     * @param string|array|Closure|callable $callback
     *   The Callback to run when the job is processed.
     *
     * @param string|DateTime|null $scheduledTime
     *   The time at which the job should be processed. If a string is
     *   given, it is converted to a DateTime object. If no time is
     *   given, the current time is used.
     *
     * @param string|null $repeat
     *   The repeat interval to use when requeueing the job. If no
     *   repeat is given, the job is not requeued after it is processed.
     *
     * @param int $priority
     *   The priority to use for the job. Lower numbers are processed
     *   first.
     */
    public function __construct(
        private $callback,
        private null|string|DateTime $scheduledTime = null,
        private null|string $repeat = null,
        private int $priority = 0,
        private $onFailed = null,
    ) {
        // If the scheduled time is a string, convert it to a DateTime object.
        if (is_string($scheduledTime)) {
            $this->scheduledTime = new DateTime($scheduledTime);
        }

        // If the scheduled time is not provided, set it to the current time.
        $this->scheduledTime ??= new DateTime();
    }

    /**
     * Sets the repeat interval for the job.
     *
     * This method sets the interval string that
     * determines how often the job should be repeated.
     *
     * @param string $repeat
     *   The interval string for repeating the job.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function repeat(string $repeat): self
    {
        $repeatSteps = [
            'daily' => '1 day',
            'hourly' => '1 hour',
            'weekly' => '1 week',
            'biweekly' => '2 weeks',
            'monthly' => '1 month',
            'quarterly' => '3 months',
            'yearly' => '1 year',
        ];

        $this->repeat = $repeatSteps[$repeat] ?? $repeat;

        return $this;
    }

    /**
     * Sets the job to repeat every hour.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function repeatHourly(): self
    {
        $this->repeat = '1 hour';
        return $this;
    }

    /**
     * Sets the job to repeat every day.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function repeatDaily(): self
    {
        $this->repeat = '1 day';
        return $this;
    }

    /**
     * Sets the job to repeat every week.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function repeatWeekly(): self
    {
        $this->repeat = '1 week';
        return $this;
    }

    /**
     * Sets the job to repeat every month.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function repeatMonthly(): self
    {
        $this->repeat = '1 month';
        return $this;
    }

    /**
     * Sets the priority for the job.
     *
     * This method sets the priority level for the job. Lower numbers are
     * processed first. If no priority is given, the default priority
     * of 0 is used.
     *
     * @param int $priority
     *   The priority level for the job.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Schedules the job for a specific time.
     *
     * This method sets the time at which the job should be processed.
     * If a string representation of the time is provided, it will be
     * converted to a DateTime object.
     *
     * @param string|DateTime $scheduledTime
     *   The time at which the job should be scheduled. This can be a
     *   DateTime object or a string that can be parsed into a DateTime.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function schedule(string|DateTime $scheduledTime): self
    {
        // Convert the string to a DateTime object if necessary.
        if (is_string($scheduledTime)) {
            $scheduledTime = new DateTime($scheduledTime);
        }

        // Set the scheduled time for the job.
        $this->scheduledTime = $scheduledTime;

        return $this;
    }

    /**
     * Sets the failure callback for the job.
     *
     * This method sets the callback function that will be executed
     * if the job fails during processing.
     *
     * @param string|array|callable $callback
     *   The callback function to execute on job failure.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function catch(string|array|callable $callback): self
    {
        $this->onFailed = $callback;
        return $this;
    }

    /**
     * Executes the job's closure function.
     *
     * This method calls the closure function associated with the job
     * to process the job. The closure should contain the logic that
     * needs to be executed when the job is processed.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            // Execute the job's closure function.
            Application::$app->resolve($this->callback, ['job' => $this]);
        } catch (Throwable $e) {
            // Call the 'error' event listeners to execute any code that needs to be run when an error occurs during the job processing.
            if (isset($this->onFailed)) {
                Application::$app->resolve($this->onFailed, ['error' => $e, 'job' => $this]);
            }

            // Re-throw the exception as a FailedToResolveJobError
            throw new FailedToResolveJobError("Failed to resolve job callback: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Checks if the job is repeated.
     *
     * This method checks if the job should be repeated at a specific interval.
     * It returns true if the job should be repeated, or false otherwise.
     *
     * @return bool
     *   True if the job should be repeated, or false otherwise.
     */
    public function isRepeated(): bool
    {
        return isset($this->repeat) && !empty($this->repeat);
    }

    /**
     * Returns the callback function associated with the job.
     *
     * @return string|array|callable
     *   The callback function associated with the job.
     */
    public function getCallback(): string|array|callable
    {
        return $this->callback;
    }

    /**
     * Returns the error callback function associated with the job.
     *
     * @return null|string|array|callable
     *   The error callback function associated with the job.
     */
    public function getErrorCallback(): null|string|array|callable
    {
        return $this->onFailed;
    }

    /**
     * Returns the repeat string as a CRON expression.
     *
     * If the repeat string is not a valid CRON expression, it is prefixed with a "+" sign.
     * This is to indicate to the queue that the job should be executed at the specified time
     * interval.
     *
     * @return ?string
     *   The repeat string as a CRON expression.
     */
    public function getRepeat(): ?string
    {
        return $this->repeat;
    }

    /**
     * Returns the scheduled time when the job should be executed.
     *
     * This method returns the DateTime object that was set when the job was created.
     *
     * @return DateTime
     *   The scheduled time when the job should be executed.
     */
    public function getScheduledTime(): DateTime
    {
        return $this->scheduledTime;
    }

    /**
     * Returns the priority of the job.
     *
     * The priority of the job is used to determine the order in which
     * jobs are executed. Jobs with a lower priority are executed first.
     *
     * @return int
     *   The priority of the job.
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Dispatches the job to the queue.
     *
     * This method will add the job to the queue. It is typically used
     * when the job is created and should be executed at a later time.
     *
     * @param ?string $id
     *   An optional identifier for the job. If provided, it will be used
     *   to identify the job in the queue.
     * @return void
     */
    public function dispatch(?string $id = null): void
    {
        /** @var \Spark\Queue\Queue $queue */
        $queue = Application::$app->make(Queue::class);
        $queue->addJob($this, $id);
    }

    /**
     * Create a copy of the job instance.
     *
     * @return self A new instance that is a copy of the current instance.
     */
    public function copy(): self
    {
        return clone $this;
    }
}
