<?php

namespace Spark\Facades;

use Spark\Http\Gate as BaseGate;

/**
 * Facade Gate
 * 
 * This class serves as a facade for the Gate system, providing a static interface to the underlying Gate class.
 * It allows easy access to authorization methods such as checking permissions and abilities
 * without needing to instantiate the Gate class directly.
 * 
 * @method static bool allows(string $ability, mixed ...$arguments)
 * @method static bool denies(string $ability, mixed ...$arguments)
 * @method static void before(string|array|callable $callback)
 * @method static void define(string $ability, string|array|callable $callback)
 * @method static void authorize(string $ability, mixed ...$arguments)
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Gate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseGate::class;
    }
}
