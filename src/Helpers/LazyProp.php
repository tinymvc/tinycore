<?php

namespace Spark\Helpers;

use Closure;
use Spark\Contracts\Support\Arrayable;
use Stringable;

/**
 * LazyProp
 *
 * A helper class that allows for lazy evaluation of properties. It accepts a 
 * callback that is executed when the value is accessed, enabling deferred 
 * computation and improved performance.
 *
 * @since 2.2.0
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class LazyProp implements Stringable, Arrayable
{
    /**
     * Create a new lazy prop instance.
     *
     * @param Closure $callback The callback that returns the prop value when evaluated.
     */
    public function __construct(protected Closure $callback)
    {
    }

    /**
     * Evaluate the lazy prop and return its value.
     *
     * @return mixed The resolved value from the callback.
     */
    public function resolve(): mixed
    {
        return ($this->callback)();
    }

    /**
     * Allow the lazy prop to be invoked as a function, returning its resolved value.
     *
     * @return mixed The resolved value from the callback.
     */
    public function __invoke(): mixed
    {
        return $this->resolve();
    }

    /**
     * Convert the lazy prop to a string.
     *
     * @return string The resolved value as a string.
     */
    public function __toString(): string
    {
        return (string) $this->resolve();
    }

    /**
     * Convert the lazy prop to an array.
     *
     * @return array The resolved value as an array.
     */
    public function toArray(): array
    {
        return (array) $this->resolve();
    }
}
