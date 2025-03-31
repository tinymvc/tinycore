<?php

namespace Spark\Http;

use Exception;
use Spark\Container;
use Spark\Http\Request;
use Spark\Http\Response;

/**
 * Class Middleware
 * 
 * Manages the registration and execution of middleware in a stack-based approach.
 */
class Middleware
{
    /**
     * Constructor for the middleware class.
     * 
     * Initializes the middleware with an optional list of registered middlewares 
     * and an execution stack.
     * 
     * @param array $registeredMiddlewares An array of middleware keys and their classes.
     * @param array $stack An array of middleware keys to execute in the stack.
     */
    public function __construct(private array $registeredMiddlewares = [], private array $stack = [])
    {
    }

    /**
     * Registers a middleware class.
     * 
     * If $concrete is not given, the $abstract will be used as the class name.
     * 
     * @param string $abstract The middleware key.
     * @param callable|string|null $concrete Optional. The middleware class name.
     * @return self
     */
    public function register(string $abstract, callable|string|null $concrete = null): self
    {
        $this->registeredMiddlewares[$abstract] = $concrete ?? $abstract;
        return $this;
    }

    /**
     * Adds middleware keys to the execution stack.
     * 
     * @param array|string $abstract The middleware keys to queue for execution.
     * @return self
     */
    public function queue(array|string $abstract): self
    {
        foreach ((array) $abstract as $key) {
            if (!in_array($key, $this->stack)) {
                $this->stack[] = $key;
            }
        }
        return $this;
    }

    /**
     * Merges multiple middleware into the registered list.
     * 
     * @param array $middlewares Associative array of middleware keys and their classes.
     * @return self
     */
    public function merge(array $middlewares): self
    {
        $this->registeredMiddlewares = array_merge($this->registeredMiddlewares, $middlewares);
        return $this;
    }


    /**
     * Executes the middleware stack and returns the first response
     * from a middleware or null if no response was returned.
     * 
     * @param Container $container The service container.
     * @param Request $request The request being processed.
     * @return ?Response The response from the middleware or null.
     */
    public function process(Container $container, Request $request): ?Response
    {
        foreach ($this->stack as $abstract) {
            // If the middleware key doesn't exist in the registered list, throw an exception.
            if (!isset($this->registeredMiddlewares[$abstract])) {
                throw new Exception("Middleware '{$abstract}' not found.");
            }

            // Resolve the middleware class from the container.
            $middleware = $container->get($this->registeredMiddlewares[$abstract]);

            // Execute the middleware and handle the result.
            $result = $middleware->handle($request);

            // If the middleware returns a response, return it and break the loop.
            if ($result instanceof Response) {
                return $result;
            }
        }

        // If no middleware returns a response, return null.
        return null;
    }
}
