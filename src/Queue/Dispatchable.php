<?php

namespace Spark\Queue;

use Closure;
use Spark\Queue\Contracts\JobContract;

/**
 * Adds Laravel-style static dispatch helpers to application job classes.
 *
 * Jobs using this trait are queued as class jobs. Dispatch arguments are passed
 * to the job constructor, and the queue worker later calls the job's handle()
 * method through the application container.
 */
trait Dispatchable
{
    /**
     * Create a pending dispatch for the job.
     */
    public static function dispatch(mixed ...$arguments): PendingDispatch
    {
        return new PendingDispatch(static::makeQueuedJob($arguments));
    }

    /**
     * Create a pending dispatch that will only be pushed when not already queued.
     */
    public static function dispatchOnce(mixed ...$arguments): PendingDispatch
    {
        return new PendingDispatch(static::makeQueuedJob($arguments), once: true);
    }

    /**
     * Dispatch the job when the given condition is truthy.
     */
    public static function dispatchIf(bool|Closure $condition, mixed ...$arguments): ?PendingDispatch
    {
        return static::passesCondition($condition)
            ? static::dispatch(...$arguments)
            : null;
    }

    /**
     * Dispatch the job unless the given condition is truthy.
     */
    public static function dispatchUnless(bool|Closure $condition, mixed ...$arguments): ?PendingDispatch
    {
        return !static::passesCondition($condition)
            ? static::dispatch(...$arguments)
            : null;
    }

    /**
     * Run the job immediately in the current process.
     */
    public static function dispatchSync(mixed ...$arguments): void
    {
        static::makeQueuedJob($arguments)->handle();
    }

    /**
     * Alias for dispatchSync().
     */
    public static function dispatchNow(mixed ...$arguments): void
    {
        static::dispatchSync(...$arguments);
    }

    /**
     * Build the framework queue job wrapper for this application job.
     */
    protected static function makeQueuedJob(array $arguments): JobContract
    {
        return Job::make(static::class, $arguments);
    }

    /**
     * Resolve boolean or closure conditions used by conditional dispatch helpers.
     */
    private static function passesCondition(bool|Closure $condition): bool
    {
        return $condition instanceof Closure ? (bool) $condition() : $condition;
    }
}
