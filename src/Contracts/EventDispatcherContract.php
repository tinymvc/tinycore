<?php

namespace Spark\Contracts;

interface EventDispatcherContract
{
    /**
     * Registers a listener for a specific event.
     *
     * @param string   $eventName The name of the event.
     * @param string|array|callable $listener  The listener callback.
     * @param int      $priority  The priority of the listener (default is 0).
     */
    public function addListener(string $eventName, string|array|callable $listener, int $priority = 0): void;

    /**
     * Dispatches an event, invoking all registered listeners.
     *
     * @param string $eventName The name of the event.
     * @param mixed  ...$args   Arguments to pass to the event listeners.
     */
    public function dispatch(string $eventName, ...$args): void;
}