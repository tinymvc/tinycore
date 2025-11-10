<?php

namespace Spark\Http;

use Spark\Contracts\Http\GateContract;
use Spark\Exceptions\Http\AuthorizationException;
use Spark\Foundation\Application;
use Spark\Support\Traits\Macroable;

/**
 * Class Gate
 *
 * This class provides a simple access control system (a.k.a. authorization system). It is used to check if a user
 * has a certain ability. Abilities are defined as closures that receive parameters (the user and any extra arguments).
 *
 * @package Spark\Utils
 */
class Gate implements GateContract
{
    use Macroable;

    /**
     * Array of callbacks to run before an ability check.
     * If any before callback returns non-null, its boolean value is used as the result.
     *
     * @var array<int, callable>
     */
    private array $beforeCallbacks = [];

    /**
     * Array of callbacks to run after an ability check.
     * These run after the normal ability check but can override the result.
     *
     * @var array<int, callable>
     */
    private array $afterCallbacks = [];

    /**
     * Array of defined abilities.
     * Each ability is associated with a closure that receives parameters (the user and any extra arguments).
     *
     * @var array<string, callable>
     */
    public function __construct(private array $definitions = [])
    {
    }

    /**
     * Define a new ability.
     *
     * @param string   $ability  The ability name.
     * @param string|array|callable $callback A closure to determine authorization. 
     *                           The closure should accept at least the user (can be optional) as the first parameter.
     */
    public function define(string $ability, string|array|callable $callback): void
    {
        $this->definitions[$ability] = $callback;
    }

    /**
     * Register a callback to run before all ability checks.
     * If any before callback returns a non-null value, that value (cast to bool) will override the normal check.
     *
     * @param string|array|callable $callback
     */
    public function before(string|array|callable $callback): void
    {
        $this->beforeCallbacks[] = $callback;
    }

    /**
     * Register a callback to run after all ability checks.
     * If any after callback returns a non-null value, that value (cast to bool) will override the result.
     *
     * @param string|array|callable $callback
     */
    public function after(string|array|callable $callback): void
    {
        $this->afterCallbacks[] = $callback;
    }

    /**
     * Determine if the given ability is allowed.
     *
     * @param string $ability
     * @param mixed  ...$arguments  Additional arguments to pass to the ability callback.
     *
     * @return bool
     */
    public function allows(string $ability, mixed ...$arguments): bool
    {
        // Run "before" callbacks; if any return a non-null result, use that.
        foreach ($this->beforeCallbacks as $callback) {
            $result = Application::$app->resolve($callback, $arguments);
            if ($result !== null) {
                return (bool) $result;
            }
        }

        // If the ability is not defined, deny by default.
        if (!isset($this->definitions[$ability])) {
            $result = false;
        } else {
            // Call the ability callback with spread arguments.
            $result = (bool) Application::$app->resolve($this->definitions[$ability], $arguments);
        }

        // Run "after" callbacks; if any return a non-null result, use that.
        foreach ($this->afterCallbacks as $callback) {
            $afterResult = Application::$app->resolve($callback, array_merge([$ability, $result], $arguments));
            if ($afterResult !== null) {
                return (bool) $afterResult;
            }
        }

        return $result;
    }

    /**
     * Determine if the given ability is denied.
     *
     * @param string $ability
     * @param mixed  ...$arguments
     *
     * @return bool
     */
    public function denies(string $ability, mixed ...$arguments): bool
    {
        return !$this->allows($ability, ...$arguments);
    }

    /**
     * Authorize the given ability.
     * Throws an AuthorizationException if the check fails.
     *
     * @param string $ability
     * @param mixed  ...$arguments
     * 
     * @throws AuthorizationException
     */
    public function authorize(string $ability, mixed ...$arguments): void
    {
        if (!$this->allows($ability, ...$arguments)) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    /**
     * Determine if any of the given abilities are allowed.
     *
     * @param iterable|string $abilities
     * @param mixed  ...$arguments
     *
     * @return bool
     */
    public function any(iterable|string $abilities, mixed ...$arguments): bool
    {
        $abilities = is_string($abilities) ? [$abilities] : $abilities;

        foreach ($abilities as $ability) {
            if ($this->allows($ability, ...$arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if all of the given abilities are denied.
     *
     * @param iterable|string $abilities
     * @param mixed  ...$arguments
     *
     * @return bool
     */
    public function none(iterable|string $abilities, mixed ...$arguments): bool
    {
        return !$this->any($abilities, ...$arguments);
    }

    /**
     * Determine if the given ability has been defined.
     *
     * @param string $ability
     *
     * @return bool
     */
    public function has(string $ability): bool
    {
        return isset($this->definitions[$ability]);
    }

    /**
     * Get all of the defined abilities.
     *
     * @return array<string, callable>
     */
    public function abilities(): array
    {
        return $this->definitions;
    }
}