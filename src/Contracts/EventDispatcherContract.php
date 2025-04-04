<?php

namespace Spark\Contracts;

interface EventDispatcherContract
{
    /**
     * Registers a listener for a specific event.
     *
     * @param string   $eventName The name of the event.
     * @param callable $listener  The listener callback.
     */
    public function addListener(string $eventName, callable $listener): void;

    /**
     * Dispatches an event, invoking all registered listeners.
     *
     * @param string $eventName The name of the event.
     * @param mixed  ...$args   Arguments to pass to the event listeners.
     */
    public function dispatch(string $eventName, ...$args): void;
}