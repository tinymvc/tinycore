<?php

namespace Spark\Facades;

use Spark\EventDispatcher;

/**
 * Facade Event
 *
 * This class serves as a facade for the event dispatcher system, providing a static interface to the underlying EventDispatcher class.
 * It allows easy access to event management methods such as adding listeners and dispatching events
 * 
 * @method static void addListener(string $eventName, string|array|callable $listener, int $priority = 0)
 * @method static bool hasListeners(string $eventName): bool
 * @method static array getListeners(?string $eventName = null)
 * @method static void clearListeners(?string $eventName = null)
 * @method static void dispatch(string $eventName, ...$args)
 * @method static void dispatchIf(string $eventName, bool $condition, ...$args)
 * @method static void dispatchUnless(string $eventName, bool $condition, ...$args)
 * @method static void once(string $eventName, callable|string|array $listener, int $priority = 0)
 * @method static mixed until(string $eventName, ...$args)
 * @method static void halt()
 * @method static int countListeners(?string $eventName = null)
 * @method static array getEventNames()
 * @method static bool hasEvent(string $eventName)
 * @method static void subscribe(array $events, int $priority = 0)
 * @method static void flush()
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Event extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EventDispatcher::class;
    }
}
