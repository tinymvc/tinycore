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
            Application::$app->call($this->callback, $this->parameters);
        } catch (\Throwable $e) {
            if (
                is_array($this->callback) &&
                method_exists($this->callback[0], 'failed')
            ) {
                Application::$app->call(
                    [$this->callback[0], 'failed'],
                    ['error' => $e]
                );
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
     * @return array
     *   The metadata associated with the job.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Dispatches the job to the queue.
     *
     * This method will add the job to the queue. It is typically used
     * when the job is created and should be executed at a later time.
     *
     * @param null|string $name
     *   An optional identifier for the job. If provided, it will be used
     *   to identify the job in the queue.
     * @return void
     */
    public function dispatch(null|string $name = null): void
    {
        /** @var \Spark\Queue\Queue $queue */
        $queue = Application::$app->get(Queue::class);
        $queue->push($this, $name);
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
     * Create a copy of the job instance.
     *
     * @return self A new instance that is a copy of the current instance.
     */
    public function copy(): self
    {
        return clone $this;
    }
}
