<?php

namespace Spark\Contracts;

interface EventDispatcherContract extends \Psr\EventDispatcher\EventDispatcherInterface
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
     * Registers a listener for an event or multiple events (alias of addListener).
     *
     * @param string|array $eventName
     * @param string|array|callable $listener
     * @param int $priority
     * @return void
     */
    public function listen(string|array $eventName, string|array|callable $listener, int $priority = 0): void;

    /**
     * Registers a one-time listener for a specific event.
     *
     * @param string $eventName The name of the event.
     * @param string|array|callable $listener The listener callback.
     * @param int $priority The priority of the listener.
     */
    public function once(string $eventName, callable|string|array $listener, int $priority = 0): void;

    /**
     * Removes a registered listener from an event.
     *
     * @param string $eventName The name of the event.
     * @param string|array|callable $listener The listener callback to remove.
     */
    public function removeListener(string $eventName, callable|string|array $listener): void;

    /**
     * Checks if any listeners are registered for an event.
     *
     * @param string $eventName The name of the event.
     * @return bool
     */
    public function hasListeners(string $eventName): bool;

    /**
     * Retrieves all listeners, optionally filtered by event name.
     *
     * @param string|null $eventName The name of the event.
     * @return array
     */
    public function getListeners(?string $eventName = null): array;

    /**
     * Clears listeners for a given event, or all listeners if no event is specified.
     *
     * @param string|null $eventName The name of the event.
     * @return void
     */
    public function clearListeners(?string $eventName = null): void;

    /**
     * Dispatches an event, invoking all registered listeners.
     *
     * @param object|string $eventName An event object or native event name.
     * @param mixed ...$args Arguments for native string events.
     * @return object|null
     */
    public function dispatch(object|string $eventName, ...$args): mixed;

    /**
     * Dispatches an event and collects return values from all listeners.
     *
     * @param string $eventName The name of the event.
     * @param mixed ...$args Arguments to pass to the event listeners.
     * @return array
     */
    public function dispatchWithResponse(string $eventName, ...$args): array;

    /**
     * Dispatches an event until the first non-null response is returned.
     *
     * @param string $eventName The name of the event.
     * @param mixed ...$args Arguments to pass to the event listeners.
     * @return mixed
     */
    public function until(string $eventName, ...$args): mixed;

    /**
     * Flushes all registered event listeners.
     *
     * @return void
     */
    public function flush(): void;
}