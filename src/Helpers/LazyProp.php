<?php

namespace Spark\Helpers;

use Closure;

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
class LazyProp
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
}
