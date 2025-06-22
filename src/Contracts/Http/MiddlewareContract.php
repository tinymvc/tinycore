<?php

namespace Spark\Contracts\Http;

use Spark\Container;
use Spark\Http\Request;

/**
 * Interface MiddlewareContract
 * 
 * Defines the contract for a middleware component in the system.
 * Provides methods for registering, queuing, and processing middleware.
 */
interface MiddlewareContract
{
    /**
     * Registers a middleware class.
     * 
     * If $concrete is not given, the $abstract will be used as the class name.
     * 
     * @param string $abstract The middleware key.
     * @param callable|string|null $concrete Optional. The middleware class name.
     * @return self
     */
    public function register(string $abstract, callable|string|null $concrete = null): self;

    /**
     * Adds middleware keys to the execution stack.
     * 
     * @param array|string $abstract The middleware keys to queue for execution.
     * @return self
     */
    public function queue(array|string $abstract): self;

    /**
     * Executes the middleware stack and returns the first response
     * from a middleware or null if no response was returned.
     * 
     * @param Container $container The service container.
     * @param Request $request The request being processed.
     * @return mixed The response from the middleware or null.
     */
    public function process(Container $container, Request $request): mixed;
}