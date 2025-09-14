<?php

namespace Spark\Contracts;

use Spark\Container;
use Spark\Http\Middleware;
use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Routing\Route;
use Spark\Routing\RouteGroup;

/**
 * Interface for the router that defines the methods for registering
 * routes and dispatching to the appropriate route handler.
 */
interface RouterContract
{
    /**
     * Registers a new GET route with the router.
     * 
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     * 
     * @return \Spark\Routing\Route The router instance to allow method chaining.
     */
    public function get(string $path, callable|string|array $callback): Route;

    /**
     * Registers a new POST route with the router.
     * 
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     * 
     * @return \Spark\Routing\Route The router instance to allow method chaining.
     */
    public function post(string $path, callable|string|array $callback): Route;

    /**
     * Registers a new PUT route with the router.
     * 
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     * 
     * @return \Spark\Routing\Route The router instance to allow method chaining.
     */
    public function put(string $path, callable|string|array $callback): Route;

    /**
     * Registers a new PATCH route with the router.
     * 
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     * 
     * @return \Spark\Routing\Route The router instance to allow method chaining.
     */
    public function patch(string $path, callable|string|array $callback): Route;

    /**
     * Registers a new DELETE route with the router.
     * 
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     * 
     * @return \Spark\Routing\Route The router instance to allow method chaining.
     */
    public function delete(string $path, callable|string|array $callback): Route;

    /**
     * Registers a new OPTIONS route with the router.
     * 
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     * 
     * @return \Spark\Routing\Route The router instance to allow method chaining.
     */
    public function options(string $path, callable|string|array $callback): Route;

    /**
     * Registers a new route that matches any HTTP method with the router.
     * 
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     * 
     * @return \Spark\Routing\Route The router instance to allow method chaining.
     */
    public function any(string $path, callable|string|array $callback): Route;

    /**
     * Registers a new route with a view with the router.
     * 
     * @param string $path The path for the route.
     * @param string $template The template to use for the route.
     * 
     * @return \Spark\Routing\Route The router instance to allow method chaining.
     */
    public function view(string $path, string $template): Route;

    /**
     * Adds a group of routes to the router with shared attributes.
     * 
     * The passed callback is called immediately. Any routes defined within the
     * callback will have the given attributes applied to them.
     * 
     * @param array|callable|null $attrsOrCallback Array of shared attributes or the callback function.
     * @param callable|null $callback Optional callback when first parameter is attributes array.
     * 
     * @return \Spark\Routing\RouteGroup Returns a RouteGroup instance for method chaining when no callback is provided, or null when the callback is executed.
     */
    public function group(array|callable|null $attrsOrCallback = null, callable|null $callback = null): RouteGroup;

    /**
     * Gets the URL path for a named route.
     * 
     * @param string $name The name of the route.
     * @param string|null|array $context Optional context parameter for dynamic segments.
     * 
     * @return string Returns the route's path.
     * 
     * @throws \Spark\Exceptions\Routing\InvalidNamedRouteException if the route does not exist.
     */
    public function route(string $name, null|string|array $context = null): string;

    /**
     * Dispatches the incoming HTTP request to the appropriate route handler.
     * 
     * Iterates through the defined routes to find a match for the request path and method.
     * If a route matches, it queues any route-specific middleware and processes the middleware stack.
     * The response is returned if the middleware halts the request. Otherwise, it handles template
     * rendering or resolves the callback for the matched route, returning the callback's response.
     * If no route matches, a 404 'Not Found' response is returned.
     * 
     * @param Container $container The dependency injection container.
     * @param Middleware $middleware The middleware stack to be processed.
     * @param Request $request The HTTP request instance.
     * 
     * @return Response The HTTP response object.
     */
    public function dispatch(Container $container, Middleware $middleware, Request $request): Response;
}