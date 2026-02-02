<?php

namespace Spark\Queue;

use Spark\Queue\Contracts\JobContract;
use Spark\Foundation\Application;
use Spark\Queue\Exceptions\FailedToResolveJobError;
use Spark\Support\Traits\Macroable;
use Spark\Utils\Carbon;
use function get_class;
use function is_array;
use function is_object;
use function is_string;

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
     * @param string|array $callback
     *   The Callback to run when the job is processed.
     * 
     * @param array $parameters
     *  The parameters to pass to the callback when the job is processed.
     *
     * @param string|Carbon|null $scheduledTime
     *   The time at which the job should be processed. If a string is
     *   given, it is converted to a Carbon object. If no time is
     *   given, the current time is used.
     *
     * @param string|null $repeat
     *   The repeat interval to use when requeueing the job. If no
     *   repeat is given, the job is not requeued after it is processed.
     */
    public function __construct(
        private string|array $callback,
        private array $parameters = [],
        private null|string|Carbon $scheduledTime = null,
        private null|string $repeat = null,
        private array $metadata = [],
    ) {
        // If the scheduled time is a string, convert it to a Carbon object.
        if (is_string($scheduledTime)) {
            $this->scheduledTime = new Carbon($scheduledTime);
        }

        // If the scheduled time is not provided, set it to the current time.
        $this->scheduledTime ??= new Carbon();
    }

    /**
     * Factory method to create a new Job instance.
     *
     * @param string|array $callback
     *   The Callback to run when the job is processed.
     * 
     * @param array $parameters
     *  The parameters to pass to the callback when the job is processed.
     *
     * @param string|Carbon|null $scheduledTime
     *   The time at which the job should be processed. If a string is
     *   given, it is converted to a Carbon object. If no time is
     *   given, the current time is used.
     *
     * @param string|null $repeat
     *   The repeat interval to use when requeueing the job. If no
     *   repeat is given, the job is not requeued after it is processed.
     *
     * @return self
     *   Returns a new Job instance.
     */
    public static function make(
        string|array $callback,
        array $parameters = [],
        null|string|Carbon $scheduledTime = null,
        null|string $repeat = null,
    ): self {
        return new self(
            callback: $callback,
            parameters: $parameters,
            scheduledTime: $scheduledTime,
            repeat: $repeat,
        );
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
     * Sets the job to repeat every given number of minutes.
     *
     * @param int $minutes
     *   The number of minutes between each repetition.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function repeatEveryMinutes(int $minutes = 1): self
    {
        $this->repeat = "$minutes minutes";
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
     * Schedules the job for a specific time.
     *
     * This method sets the time at which the job should be processed.
     * If a string representation of the time is provided, it will be
     * converted to a Carbon object.
     *
     * @param string|Carbon $scheduledTime
     *   The time at which the job should be scheduled. This can be a
     *   Carbon object or a string that can be parsed into a Carbon.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function schedule(string|Carbon $scheduledTime): self
    {
        // Convert the string to a Carbon object if necessary.
        if (is_string($scheduledTime)) {
            $scheduledTime = new Carbon($scheduledTime);
        }

        // Set the scheduled time for the job.
        $this->scheduledTime = $scheduledTime;

        return $this;
    }

    /**
     * Delays the job execution by a specified number of seconds.
     *
     * This method sets the scheduled time for the job to be the current
     * time plus the specified number of seconds.
     *
     * @param int $seconds
     *   The number of seconds to delay the job execution.
     *
     * @return self
     *   Returns the current Job instance for method chaining.
     */
    public function delay(int $seconds): self
    {
        $this->scheduledTime = Carbon::now()->addSeconds($seconds);
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
        $callbackOrClass = null; // To hold the instantiated class if needed.

        try {
            // Instantiate the class if the callback is a class name.
            $callbackOrClass = is_array($this->callback) ? $this->callback[0] : $this->callback;
            if (class_exists($callbackOrClass)) {
                $callbackOrClass = new $callbackOrClass(...$this->parameters);
            }

            // Determine the appropriate callback to execute.
            if (is_object($callbackOrClass)) {
                $method = is_array($this->callback) && isset($this->callback[1])
                    ? $this->callback[1] : 'handle';
                $callback = [$callbackOrClass, $method];
            } else {
                $callback = $this->callback; // It is a function name or a static method.
            }

            Application::$app->call($callback);
        } catch (\Throwable $e) {
            // If the class has a failed method, call it with the exception.
            if (
                is_object($callbackOrClass) &&
                method_exists($callbackOrClass, 'failed')
            ) {
                $callbackOrClass->failed($e); // Call the failed method with the exception.
            }

            throw new FailedToResolveJobError(
                'Failed to execute the job: ' . $e->getMessage(),
                previous: $e
            );
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
     * @return string|array
     *   The callback function associated with the job.
     */
    public function getCallback(): string|array
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
     * @return null|string
     *   The repeat string as a CRON expression.
     */
    public function getRepeat(): null|string
    {
        return $this->repeat;
    }

    /**
     * Returns the scheduled time when the job should be executed.
     *
     * This method returns the Carbon object that was set when the job was created.
     *
     * @return Carbon
     *   The scheduled time when the job should be executed.
     */
    public function getScheduledTime(): Carbon
    {
        return $this->scheduledTime;
    }

    /**
     * Returns the parameters associated with the job.
     *
     * @return array
     *   The parameters associated with the job.
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Returns the metadata associated with the job.
     *
     * @return null|string|array
     *   The metadata associated with the job.
     */
    public function getMetadata(null|string $key = null, mixed $default = null): null|string|array
    {
        if ($key !== null) {
            return $this->metadata[$key] ?? $default;
        }

        return $this->metadata;
    }

    /**
     * Dispatches the job to the queue.
     *
     * This method will add the job to the queue. It is typically used
     * when the job is created and should be executed at a later time.
     *
     * @param string $queue The name of the queue to which the job should be dispatched.
     * @return void
     */
    public function dispatch(string $queue = 'default'): void
    {
        /** @var \Spark\Queue\Queue $queueInstance The queue instance */
        $queueInstance = Application::$app->get(Queue::class);
        $queueInstance->push($this, $queue);
    }

    /**
     * Get a display name for the job.
     *
     * This method returns a human-readable name for the job,
     * which can be useful for logging or debugging purposes.
     *
     * @return string The display name of the job.
     */
    public function getDisplayName(): string
    {
        if (is_string($this->callback)) {
            return $this->callback;
        }

        if (is_array($this->callback)) {
            if (is_object($this->callback[0])) {
                return get_class($this->callback[0]);
            }

            return $this->callback[0];
        }

        return $this->metadata['queue'] ?? 'unknown';
    }

    /**
     * Check if the job has failed.
     *
     * This method checks the job's metadata to determine if it has failed.
     *
     * @return bool True if the job has failed, false otherwise.
     */
    public function isFailed(): bool
    {
        return isset($this->metadata['status']) && $this->metadata['status'] === 'failed';
    }

    /**
     * Get the reason for the job's failure.
     *
     * This method retrieves the reason for the job's failure from its metadata.
     *
     * @return string The reason for the job's failure.
     */
    public function getReasonFailed(): string
    {
        return $this->metadata['exception'] ?? 'Unknown';
    }

    /**
     * Get the name of the queue to which the job belongs.
     *
     * @return string The name of the queue.
     */
    public function getQueueName(): string
    {
        return $this->metadata['queue'] ?? 'default';
    }

    /**
     * Get the unique identifier of the job.
     *
     * @return null|string The unique identifier of the job.
     */
    public function getId(): null|string
    {
        return $this->metadata['id'] ?? null;
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
