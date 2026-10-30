<?php

namespace Spark\Facades;

use Spark\Telemetry as CoreTelemetry;

/**
 * Telemetry Facade for Spark.
 *
 * @method static void emit(string $event, mixed ...$args)
 * @method static void emitIf(bool|\Closure $condition, string $event, mixed ...$args)
 * @method static bool isListening(string $event)
 * @method static void listen(string $event, callable|string|array $listener, int $priority = 0)
 * @method static void enable()
 * @method static void disable()
 * @method static bool isEnabled()
 *
 * @package Spark\Facades
 */
class Telemetry extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return CoreTelemetry::class;
    }
}
