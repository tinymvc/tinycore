<?php

namespace Spark;

use Closure;
use Spark\Foundation\Application;

/**
 * Lightweight telemetry dispatcher for Spark framework events.
 *
 * Telemetry allows the framework engine and application to record runtime metrics,
 * execution traces, database queries, and lifecycle activities only when
 * an observer (like Spark Pulse or debug mode) is actively listening.
 *
 * @package Spark
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Telemetry
{
    /** @var bool Global telemetry enable/disable switch */
    private static bool $enabled = true;

    /** Observer work must not recursively instrument its own exports or logging. */
    private static bool $emitting = false;

    /**
     * Determine if telemetry is active and listening for the specified event.
     *
     * Returns true if telemetry is enabled AND (debug mode is on OR listeners exist).
     *
     * @param string $event Event identifier (e.g. 'db.queryExecuted', 'request.handled')
     * @return bool
     */
    public static function isListening(string $event): bool
    {
        if (!self::$enabled || self::$emitting) {
            return false;
        }

        $app = Application::$app;
        if ($app === null || !$app->bound(Events::class)) {
            return false;
        }

        $eventName = str_starts_with($event, 'app:') ? $event : "app:$event";

        /** @var Events $events */
        $events = $app->get(Events::class);

        return $app->isDebugMode() || $events->hasListeners($eventName);
    }

    /**
     * Emit a telemetry event, resolving lazy arguments only when active.
     *
     * If any argument is a Closure, it will ONLY be invoked when someone is actively listening,
     * Avoid expensive payload construction outside these closures.
     *
     * @param string $event Event name (e.g. 'db.queryExecuted', 'request.handled')
     * @param mixed ...$args Payloads or lazy Closures returning payloads
     * @return void
     */
    public static function emit(string $event, mixed ...$args): void
    {
        if (!self::isListening($event)) {
            return;
        }

        $app = Application::$app;
        if ($app === null || !$app->bound(Events::class)) {
            return;
        }

        $eventName = str_starts_with($event, 'app:') ? $event : "app:$event";

        self::$emitting = true;
        try {
            $resolvedArgs = [];
            foreach ($args as $arg) {
                $resolvedArgs[] = $arg instanceof Closure ? $arg() : $arg;
            }

            $app->get(Events::class)->dispatch($eventName, ...$resolvedArgs);
        } catch (\Throwable $exception) {
            // Observation must never turn a completed write into a retry or hide an error.
            try {
                tracer_log("Telemetry listener failed for [$eventName]: " . $exception->getMessage());
            } catch (\Throwable) {
                // Error reporting can itself be unavailable during bootstrap/shutdown.
            }
        } finally {
            self::$emitting = false;
        }
    }

    /**
     * Conditionally emit a telemetry event.
     *
     * @param bool|Closure $condition
     * @param string $event
     * @param mixed ...$args
     * @return void
     */
    public static function emitIf(bool|Closure $condition, string $event, mixed ...$args): void
    {
        if (!self::isListening($event)) {
            return;
        }

        $shouldEmit = $condition instanceof Closure ? $condition() : $condition;
        if ($shouldEmit) {
            self::emit($event, ...$args);
        }
    }

    /**
     * Register a listener for a telemetry event.
     *
     * @param string $event
     * @param callable|string|array $listener
     * @param int $priority
     * @return void
     */
    public static function listen(string $event, callable|string|array $listener, int $priority = 0): void
    {
        $app = Application::$app;
        if ($app !== null && $app->bound(Events::class)) {
            $eventName = str_starts_with($event, 'app:') ? $event : "app:$event";
            $app->get(Events::class)->addListener($eventName, $listener, $priority);
        }
    }

    /**
     * Enable telemetry event processing.
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Temporarily disable telemetry event processing.
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Check if telemetry is globally enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
}
