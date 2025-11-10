<?php

namespace Spark\Contracts\Http;

/**
 * Interface GateUtilContract
 * 
 * Provides methods for managing and checking for access to certain abilities.
 */
interface GateContract
{
    /**
     * Define a new ability.
     * 
     * @param string   $ability  The ability name.
     * @param string|array|callable $callback A closure to determine authorization. 
     *                           The closure should accept at least the user (can be optional) as the first parameter.
     */
    public function define(string $ability, string|array|callable $callback): void;

    /**
     * Register a callback to run before all ability checks.
     * 
     * @param string|array|callable $callback
     */
    public function before(string|array|callable $callback): void;

    /**
     * Register a callback to run after all ability checks.
     * 
     * @param string|array|callable $callback
     */
    public function after(string|array|callable $callback): void;

    /**
     * Determine if the given ability is allowed.
     * 
     * @param string $ability
     * @param mixed  ...$arguments  Additional arguments to pass to the ability callback.
     * 
     * @return bool
     */
    public function allows(string $ability, mixed ...$arguments): bool;

    /**
     * Determine if the given ability is denied.
     * 
     * @param string $ability
     * @param mixed  ...$arguments
     * 
     * @return bool
     */
    public function denies(string $ability, mixed ...$arguments): bool;

    /**
     * Authorize the given ability.
     * 
     * Throws an AuthorizationException if the check fails.
     * 
     * @param string $ability
     * @param mixed  ...$arguments
     * 
     * @throws \Spark\Exceptions\Http\AuthorizationException
     */
    public function authorize(string $ability, mixed ...$arguments): void;

    /**
     * Determine if any of the given abilities are allowed.
     * 
     * @param iterable|string $abilities
     * @param mixed  ...$arguments
     * 
     * @return bool
     */
    public function any(iterable|string $abilities, mixed ...$arguments): bool;

    /**
     * Determine if all of the given abilities are denied.
     * 
     * @param iterable|string $abilities
     * @param mixed  ...$arguments
     * 
     * @return bool
     */
    public function none(iterable|string $abilities, mixed ...$arguments): bool;

    /**
     * Determine if the given ability has been defined.
     * 
     * @param string $ability
     * 
     * @return bool
     */
    public function has(string $ability): bool;

    /**
     * Get all of the defined abilities.
     * 
     * @return array<string, callable>
     */
    public function abilities(): array;

}