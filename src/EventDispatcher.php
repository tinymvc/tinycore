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
     * Flag to halt event propagation.
     *
     * @var bool
     */
    private bool $shouldHalt = false;

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
     * Alias for addListener method to register a listener for a specific event.
     * 
     * @param string   $eventName The name of the event.
     * @param callable $listener  The listener callback.
     * @param int      $priority  The priority of the listener (default is 0).
     * @return void
     */
    public function add(string $eventName, string|array|callable $listener, int $priority = 0): void
    {
        $this->addListener($eventName, $listener, $priority);
    }

    /**
     * Alias for addListener method to register a listener for a specific event.
     * 
     * @param string   $eventName The name of the event.
     * @param callable $listener  The listener callback.
     * @param int      $priority  The priority of the listener (default is 0).
     * @return void
     */
    public function listen(string $eventName, string|array|callable $listener, int $priority = 0): void
    {
        $this->addListener($eventName, $listener, $priority);
    }

    /**
     * Checks if there are any listeners registered for a specific event.
     *
     * @param string $eventName The name of the event.
     * @return bool True if there are listeners registered, false otherwise.
     */
    public function hasListeners(string $eventName): bool
    {
        return isset($this->listeners[$eventName]) && !empty($this->listeners[$eventName]);
    }

    /**
     * Alias for hasListeners method to check if there are any listeners registered for a specific event.
     * 
     * @param string $eventName The name of the event.
     * @return bool True if there are listeners registered, false otherwise.
     */
    public function has(string $eventName): bool
    {
        return $this->hasListeners($eventName);
    }

    /**
     * Retrieves all registered event listeners.
     *
     * @return array
     *   An associative array where keys are event names and values are arrays
     *   of callables registered as listeners for the events.
     */
    public function getListeners(?string $eventName = null): array
    {
        if (func_num_args() === 1) {
            return $this->listeners[$eventName] ?? [];
        }

        return $this->listeners;
    }

    /**
     * Alias for getListeners method to retrieve all registered event listeners.
     * 
     * @return array
     *   An associative array where keys are event names and values are arrays
     *   of callables registered as listeners for the events.
     */
    public function get(?string $eventName = null): array
    {
        return $this->getListeners($eventName);
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
    public function clearListeners(?string $eventName = null): void
    {
        if (func_num_args() === 1) {
            unset($this->listeners[$eventName]);
            return;
        }

        $this->listeners = [];
    }

    /**
     * Alias for clearListeners method to remove all registered event listeners.
     * 
     * @return void
     */
    public function clear(?string $eventName = null): void
    {
        $this->clearListeners($eventName);
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
        $this->shouldHalt = false;

        // Check if there are any listeners registered for the event
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        // Sort listeners by priority (highest first)
        $eventListeners = collect($this->listeners[$eventName])
            ->sortByDesc('priority')
            ->all();

        foreach ($eventListeners as $listener) {
            try {
                // Resolve and invoke the callback
                $result = Application::$app->resolve($listener['callback'], $args);

                // If listener returns false or shouldHalt is true, stop propagation
                if ($result === false || $this->shouldHalt) {
                    break;
                }
            } catch (\Throwable $e) {
                // Log the error but continue with other listeners
                tracer_log("Event listener failed for [{$eventName}]: " . $e->getMessage());

                // Re-throw if in debug mode
                if (env('debug')) {
                    throw $e;
                }
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
     * Register a one-time listener for an event.
     *
     * @param string $eventName The name of the event.
     * @param callable|string|array $listener The listener callback.
     * @param int $priority The priority of the listener.
     * @return void
     */
    public function once(string $eventName, callable|string|array $listener, int $priority = 0): void
    {
        $wrapper = function (...$args) use ($eventName, $listener, &$wrapper) {
            $this->removeListener($eventName, $wrapper);

            if (is_callable($listener)) {
                return $listener(...$args);
            }

            return Application::$app->resolve($listener, $args);
        };

        $this->addListener($eventName, $wrapper, $priority);
    }

    /**
     * Remove a specific listener from an event.
     *
     * @param string $eventName The name of the event.
     * @param callable|string|array $listener The listener to remove.
     * @return void
     */
    public function removeListener(string $eventName, callable|string|array $listener): void
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $index => $registered) {
            if ($registered['callback'] === $listener) {
                unset($this->listeners[$eventName][$index]);
            }
        }

        // Re-index array
        $this->listeners[$eventName] = array_values($this->listeners[$eventName]);

        // Remove event key if no listeners remain
        if (empty($this->listeners[$eventName])) {
            unset($this->listeners[$eventName]);
        }
    }

    /**
     * Alias for removeListener.
     *
     * @param string $eventName The name of the event.
     * @param callable|string|array $listener The listener to remove.
     * @return void
     */
    public function forget(string $eventName, callable|string|array $listener): void
    {
        $this->removeListener($eventName, $listener);
    }

    /**
     * Dispatch an event until the first non-null response is returned.
     *
     * @param string $eventName The name of the event.
     * @param mixed ...$args Arguments to pass to listeners.
     * @return mixed The first non-null response.
     */
    public function until(string $eventName, ...$args): mixed
    {
        if (!isset($this->listeners[$eventName])) {
            return null;
        }

        $eventListeners = collect($this->listeners[$eventName])
            ->sortByDesc('priority')
            ->all();

        foreach ($eventListeners as $listener) {
            try {
                $result = Application::$app->resolve($listener['callback'], $args);

                if ($result !== null) {
                    return $result;
                }
            } catch (\Throwable $e) {
                if (function_exists('logger')) {
                    logger()->error("Event listener failed for [{$eventName}]: " . $e->getMessage());
                }

                if (env('debug')) {
                    throw $e;
                }
            }
        }

        return null;
    }

    /**
     * Stop event propagation.
     *
     * @return void
     */
    public function halt(): void
    {
        $this->shouldHalt = true;
    }

    /**
     * Get the count of listeners for a specific event or all events.
     *
     * @param string|null $eventName The event name, or null for all.
     * @return int The count of listeners.
     */
    public function countListeners(?string $eventName = null): int
    {
        if ($eventName !== null) {
            return isset($this->listeners[$eventName]) ? count($this->listeners[$eventName]) : 0;
        }

        return array_sum(array_map('count', $this->listeners));
    }

    /**
     * Get all event names that have listeners.
     *
     * @return array List of event names.
     */
    public function getEventNames(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * Check if an event has any listeners.
     *
     * @param string $eventName The event name.
     * @return bool True if listeners exist.
     */
    public function hasEvent(string $eventName): bool
    {
        return isset($this->listeners[$eventName]);
    }

    /**
     * Subscribe multiple events at once.
     *
     * @param array $events Associative array of event => listener pairs.
     * @param int $priority Default priority for all listeners.
     * @return void
     */
    public function subscribe(array $events, int $priority = 0): void
    {
        foreach ($events as $eventName => $listener) {
            $this->addListener($eventName, $listener, $priority);
        }
    }

    /**
     * Flush all listeners for all events.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->listeners = [];
    }
}
