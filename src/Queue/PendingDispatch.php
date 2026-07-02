<?php

namespace Spark\Queue;

use Spark\Queue\Contracts\JobContract;
use Spark\Utils\Carbon;

/**
 * Fluent pending dispatch wrapper for class-based queued jobs.
 */
class PendingDispatch
{
    private string $queue = 'default';

    private bool $dispatched = false;

    public function __construct(
        private readonly JobContract $job,
        private bool $once = false,
    ) {
    }

    /**
     * Set the queue name for this pending dispatch.
     */
    public function onQueue(string $queue = 'default'): static
    {
        $queue = trim($queue);
        $this->queue = $queue !== '' ? $queue : 'default';

        return $this;
    }

    /**
     * Push the job with duplicate protection.
     */
    public function once(bool $once = true): static
    {
        $this->once = $once;

        return $this;
    }

    /**
     * Delay the job by the given number of seconds.
     */
    public function delay(int $seconds): static
    {
        $this->job->delay($seconds);

        return $this;
    }

    /**
     * Schedule the job for a specific time.
     */
    public function schedule(string|Carbon $scheduledTime): static
    {
        $this->job->schedule($scheduledTime);

        return $this;
    }

    /**
     * Set a repeat expression or supported repeat alias.
     */
    public function repeat(string $repeat): static
    {
        $this->job->repeat($repeat);

        return $this;
    }

    /**
     * Repeat the job every given number of minutes.
     */
    public function repeatEveryMinutes(int $minutes = 1): static
    {
        $this->job->repeatEveryMinutes($minutes);

        return $this;
    }

    /**
     * Repeat the job every hour.
     */
    public function repeatHourly(): static
    {
        $this->job->repeatHourly();

        return $this;
    }

    /**
     * Repeat the job every day.
     */
    public function repeatDaily(): static
    {
        $this->job->repeatDaily();

        return $this;
    }

    /**
     * Repeat the job every week.
     */
    public function repeatWeekly(): static
    {
        $this->job->repeatWeekly();

        return $this;
    }

    /**
     * Repeat the job every month.
     */
    public function repeatMonthly(): static
    {
        $this->job->repeatMonthly();

        return $this;
    }

    /**
     * Return the wrapped queue job.
     */
    public function getJob(): JobContract
    {
        return $this->job;
    }

    /**
     * Immediately push this pending dispatch to the queue.
     */
    public function dispatch(): JobContract
    {
        return $this->send();
    }

    /**
     * Immediately push this pending dispatch to the queue.
     */
    public function send(): JobContract
    {
        if (!$this->dispatched) {
            if ($this->once) {
                $this->job->dispatchOnce($this->queue);
            } else {
                $this->job->dispatch($this->queue);
            }

            $this->dispatched = true;
        }

        return $this->job;
    }

    /**
     * Push the job when the fluent dispatch expression falls out of scope.
     */
    public function __destruct()
    {
        $this->send();
    }
}
