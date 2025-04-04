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
     * @param callable $callback A closure to determine authorization. 
     *                           The closure should accept at least the user (can be optional) as the first parameter.
     */
    public function define(string $ability, callable $callback): void;

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
     * @throws AuthorizationException
     */
    public function authorize(string $ability, mixed ...$arguments): void;

}