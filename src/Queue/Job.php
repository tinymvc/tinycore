<?php

namespace Spark\Queue;

use DateTime;
use Spark\Contracts\Queue\JobContract;
use Spark\EventDispatcher;
use Spark\Foundation\Application;
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
     * @param EventDispatcher|null $eventDispatcher
     *   The event dispatcher to use for dispatching events. If no event
     *   dispatcher is given, a new instance is created.
     *
     * @param int $priority
     *   The priority to use for the job. Lower numbers are processed
     *   first.
     */
    public function __construct(
        private $callback,
        private null|string|DateTime $scheduledTime = null,
        private ?string $repeat = null,
        private ?EventDispatcher $eventDispatcher = null,
        private int $priority = 0
    ) {
        // If the scheduled time is a string, convert it to a DateTime object.
        if (is_string($scheduledTime)) {
            $this->scheduledTime = new DateTime($scheduledTime);
        }

        // If the scheduled time is not provided, set it to the current time.
        $this->scheduledTime ??= new DateTime();

        // Create a new EventDispatcher instance.
        $this->eventDispatcher ??= new EventDispatcher();
    }

    /**
     * Sets the repeat interval for the job.
     *
     * This method sets the interval string that
     * determines how often the job should be repeated.
     *
     * @param ?string $repeat
     *   The interval string for repeating the job.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function repeat(?string $repeat): self
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
     * Sets the error handling closure for the job.
     *
     * This method allows specifying a closure that will be executed
     * if an error occurs during the job's execution. The closure
     * should contain logic to handle any exceptions or errors that
     * may arise.
     *
     * @param Closure $callback
     *   A callback to handle errors that occur during execution.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function catch(string|array|callable $callback): self
    {
        $this->eventDispatcher->addListener('error', $callback);

        return $this;
    }

    /**
     * Sets the closure to execute before the job starts.
     *
     * This method allows specifying a closure that will be executed
     * immediately before the job starts. The closure
     * should contain logic that needs to run before the job does.
     *
     * @param string|array|callable $callback
     *   A callback to execute before the job.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function before(string|array|callable $callback): self
    {
        $this->eventDispatcher->addListener('before', $callback);

        return $this;
    }

    /**
     * Sets the closure to execute after the job has been completed.
     *
     * This method allows specifying a closure that will be executed
     * immediately after the job has been completed. The closure
     * should contain logic that needs to run after the job has finished executing.
     *
     * @param string|array|callable $callback
     *   A callback to execute after the job has been completed.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function after(string|array|callable $callback): self
    {
        $this->eventDispatcher->addListener('after', $callback);

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
        // Call the 'before' event listeners to execute any code that needs to be run before the job is processed.
        $this->eventDispatcher->dispatch('before', $this);

        try {
            // Execute the job's closure function.
            Application::$app->container->call($this->callback, [$this]);
        } catch (Throwable $e) {
            // Call the 'error' event listeners to execute any code that needs to be run when an error occurs during the job processing.
            $this->eventDispatcher->dispatch('error', $e, $this);
        }

        // Call the 'after' event listeners to execute any code that needs to be run after the job has been completed.
        $this->eventDispatcher->dispatch('after', $this);
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
     * Returns the event dispatcher that was set when the job was created.
     *
     * The event dispatcher is used to dispatch events when the job is executed.
     *
     * @return EventDispatcher
     *   The event dispatcher.
     */
    public function getEventDispatcher(): EventDispatcher
    {
        return $this->eventDispatcher;
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
     * @return void
     */
    public function dispatch(): void
    {
        Application::$app->get(Queue::class)->addJob($this);
    }
}
