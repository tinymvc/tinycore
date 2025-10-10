<?php

namespace Spark\Routing\Contracts;

/**
 * Interface RouteResourceContract
 *
 * This interface defines the contract for a resource route in the application.
 * It includes methods for setting various attributes of the resource route such as path, controller, name, middleware, and resource method inclusion/exclusion.
 *
 * @package Spark\Routing\Contracts
 */
interface RouteResourceContract
{
    /**
     * Set the URL path for the resource route.
     *
     * @param string $path The URL path.
     * @return self Returns the current instance for method chaining.
     */
    public function path(string $path): self;

    /**
     * Set the controller for the resource route.
     *
     * @param string $controller The controller class name.
     * @return self Returns the current instance for method chaining.
     */
    public function controller(string $controller): self;

    /**
     * Set the name prefix for the resource route.
     *
     * @param string $name The name prefix.
     * @return self Returns the current instance for method chaining.
     */
    public function name(string $name): self;

    /**
     * Set middleware(s) for the resource route.
     *
     * @param string|array $middleware The middleware(s) to be applied.
     * @return self Returns the current instance for method chaining.
     */
    public function middleware(string|array $middleware): self;

    /**
     * Set middleware(s) to be excluded from the resource route.
     *
     * @param string|array $withoutMiddleware The middleware(s) to be excluded.
     * @return self Returns the current instance for method chaining.
     */
    public function withoutMiddleware(string|array $withoutMiddleware): self;

    /**
     * Specify which resource methods to include.
     *
     * @param string|array ...$includes The resource methods to include.
     * @return self Returns the current instance for method chaining.
     */
    public function only(string|array ...$includes): self;

    /**
     * Specify which resource methods to exclude.
     *
     * @param string|array ...$excludes The resource methods to exclude.
     * @return self Returns the current instance for method chaining.
     */
    public function except(string|array ...$excludes): self;
}