<?php

namespace Spark;

use Spark\Contracts\EventDispatcherContract;
use Spark\Exceptions\InvalidEventCallbackException;
use Spark\Foundation\Application;
use Spark\Support\Traits\Macroable;

/**
 * Class EventDispatcher
 *
 * This class is responsible for dispatching events and invoking their respective listeners.
 *
 * @package Spark\Utils
 */
class EventDispatcher implements EventDispatcherContract
{
    use Macroable;

    /**
     * Constructor for the EventDispatcher class.
     *
     * Initializes the EventDispatcher with an optional array of listeners.
     *
     * @param array $listeners
     *   An associative array where keys are event names and values are arrays
     *   of callables to be executed when the event is dispatched.
     */
    public function __construct(private array $listeners = [])
    {
    }

    /**
     * Registers a listener for a specific event.
     *
     * @param string   $eventName The name of the event.
     * @param callable $listener  The listener callback.
     * @param int      $priority  The priority of the listener (default is 0).
     */
    public function addListener(string $eventName, string|array|callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventName][] = ['callback' => $listener, 'priority' => $priority];
    }

    /**
     * Retrieves all registered event listeners.
     *
     * @return array
     *   An associative array where keys are event names and values are arrays
     *   of callables registered as listeners for the events.
     */
    public function getListeners(): array
    {
        return $this->listeners;
    }

    /**
     * Removes all registered event listeners.
     *
     * This method is useful in scenarios where you need to reset the event
     * listeners to a clean state, such as when your application is
     * bootstrapped or when you want to remove all listeners before adding
     * new ones.
     * 
     * @return void
     */
    public function clearListeners(): void
    {
        $this->listeners = [];
    }

    /**
     * Dispatches an event, invoking all registered listeners.
     *
     * @param string $eventName The name of the event.
     * @param mixed  ...$args   Arguments to pass to the event listeners.
     * @return void
     * 
     * @throws InvalidEventCallbackException If the event callback is invalid.
     */
    public function dispatch(string $eventName, ...$args): void
    {
        // Check if there are any listeners registered for the event
        if (isset($this->listeners[$eventName])) {
            // Iterate over each listener for the event
            $eventListeners = collect($this->listeners[$eventName])
                ->sortByDesc('priority')
                ->all();

            foreach ($eventListeners as $listener) {
                // Invoke the callback with the provided arguments
                Application::$app->container->call($listener['callback'], $args);
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
}