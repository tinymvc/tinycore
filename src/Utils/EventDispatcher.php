<?php

namespace Spark\Utils;

/**
 * Class EventDispatcher
 *
 * This class is responsible for dispatching events and invoking their respective listeners.
 *
 * @package Spark\Utils
 */
class EventDispatcher
{
    /**
     * Array to hold event listeners.
     *
     * @var array
     */
    private array $listeners = [];

    /**
     * Registers a listener for a specific event.
     *
     * @param string   $eventName The name of the event.
     * @param callable $listener  The listener callback.
     */
    public function addListener(string $eventName, callable $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    /**
     * Dispatches an event, invoking all registered listeners.
     *
     * @param string $eventName The name of the event.
     * @param mixed  ...$args   Arguments to pass to the event listeners.
     */
    public function dispatch(string $eventName, ...$args): void
    {
        if (isset($this->listeners[$eventName])) {
            foreach ($this->listeners[$eventName] as $listener) {
                $listener(...$args);
            }
        }
    }

    /**
     * Dispatches an event if the specified condition is true.
     *
     * @param string $eventName The name of the event.
     * @param bool   $condition The condition to evaluate.
     * @param mixed  ...$args   Arguments to pass to the event listeners.
     */
    public function dispatchIf(string $eventName, bool $condition, ...$args): void
    {
        if ($condition) {
            $this->dispatch($eventName, ...$args);
        }
    }

    /**
     * Dispatches an event unless the specified condition is true.
     *
     * @param string $eventName The name of the event.
     * @param bool   $condition The condition to evaluate.
     * @param mixed  ...$args   Arguments to pass to the event listeners.
     */
    public function dispatchUnless(string $eventName, bool $condition, ...$args): void
    {
        if (!$condition) {
            $this->dispatch($eventName, ...$args);
        }
    }

    /**
     * Handles static calls by creating a new instance and calling the dynamic method.
     *
     * @param string $method The method name.
     * @param array  $args   The arguments for the method.
     * @return mixed The result of the method call.
     */
    public static function __callStatic($method, $args)
    {
        return get(self::class)->$method(...$args);
    }
}