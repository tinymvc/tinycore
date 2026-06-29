<?php

namespace Spark;

use Spark\Contracts\EventDispatcherContract;
use Spark\Foundation\Application;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function count;
use function is_array;
use function is_object;
use function is_string;

/**
 * Class EventDispatcher
 *
 * This class is responsible for dispatching events and invoking their respective listeners.
 *
 * @package Spark\Utils
 */
class Events implements EventDispatcherContract
{
    use Macroable, Conditionable;

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
     * @param string|array|callable $listener  The listener callback.
     * @param int      $priority  The priority of the listener (default is 0).
     */
    public function addListener(string $eventName, string|array|callable $listener, int $priority = 0): void
    {
        if (!$this->isValidListener($listener)) {
            throw new \InvalidArgumentException('Event listener must be callable or container resolvable.');
        }

        $this->listeners[$eventName][] = ['callback' => $listener, 'priority' => $priority];
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
     * Retrieves all registered event listeners.
     *
     * @return array
     *   An associative array where keys are event names and values are arrays
     *   of callables registered as listeners for the events.
     */
    public function getListeners(?string $eventName = null): array
    {
        if ($eventName !== null) {
            return $this->listeners[$eventName] ?? [];
        }

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
    public function clearListeners(?string $eventName = null): void
    {
        if ($eventName !== null) {
            unset($this->listeners[$eventName]);
            return;
        }

        $this->listeners = [];
    }

    /**
     * Dispatches an event, invoking all registered listeners.
     *
     * @param string $eventName The name of the event.
     * @param mixed  ...$args   Arguments to pass to the event listeners.
     * @return void
     * 
     * @throws \InvalidArgumentException If the event callback is invalid.
     */
    public function dispatch(string $eventName, ...$args): void
    {
        $this->shouldHalt = false;

        // Check if there are any listeners registered for the event
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->getSortedEventListeners($eventName) as $listener) {
            $result = $this->callListener($listener['callback'], $args, $eventName);

            if ($result === false || $this->shouldHalt) {
                break;
            }
        }
    }

    /**
     * Dispatches an event and returns all listener responses.
     *
     * @param string $eventName The name of the event.
     * @param mixed  ...$args   Arguments to pass to the event listeners.
     * @return array<int, mixed>
     */
    public function dispatchWithResponse(string $eventName, ...$args): array
    {
        $this->shouldHalt = false;

        if (!isset($this->listeners[$eventName])) {
            return [];
        }

        $responses = [];

        foreach ($this->getSortedEventListeners($eventName) as $listener) {
            $result = $this->callListener($listener['callback'], $args, $eventName);
            $responses[] = $result;

            if ($result === false || $this->shouldHalt) {
                break;
            }
        }

        return $responses;
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

            return Application::$app->call($listener, $args);
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

        foreach ($this->getSortedEventListeners($eventName) as $listener) {
            $result = $this->callListener($listener['callback'], $args, $eventName);

            if ($result !== null) {
                return $result;
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

    /**
     * Resolve event listeners ordered by descending priority.
     *
     * @param string $eventName
     * @return array<int, array{callback: string|array|callable, priority: int}>
     */
    protected function getSortedEventListeners(string $eventName): array
    {
        return collect($this->listeners[$eventName] ?? [])
            ->sortByDesc('priority')
            ->all();
    }

    /**
     * Resolve and invoke a listener.
     */
    protected function callListener(string|array|callable $listener, array $args, string $eventName): mixed
    {
        try {
            if (is_callable($listener)) {
                return $listener(...$args);
            }

            return Application::$app->call($listener, $args);
        } catch (\Throwable $e) {
            tracer_log("Event listener failed for [{$eventName}]: " . $e->getMessage());

            if (is_debug_mode()) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Determine whether a listener can be resolved by the dispatcher.
     */
    protected function isValidListener(string|array|callable $listener): bool
    {
        if (is_callable($listener)) {
            return true;
        }

        if (is_string($listener)) {
            if (str_contains($listener, '@')) {
                $segments = explode('@', $listener, 2);
                return count($segments) === 2
                    && $segments[1] !== ''
                    && class_exists($segments[0])
                    && method_exists($segments[0], $segments[1]);
            }

            return class_exists($listener) && method_exists($listener, '__invoke');
        }

        if (is_array($listener) && array_is_list($listener) && count($listener) === 2) {
            [$target, $method] = $listener;

            if (is_string($method) && is_string($target) && class_exists($target)) {
                return method_exists($target, $method);
            }

            if (is_string($method) && is_object($target)) {
                return method_exists($target, $method);
            }
        }

        return false;
    }
}
