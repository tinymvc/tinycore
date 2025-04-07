<?php
namespace Spark\Contracts\Http;

use Spark\Router;

/**
 * Interface defining the contract for the Route class.
 *
 * This interface provides methods for registering routes in the router.
 */
interface HttpRouteContract
{
    /**
     * Registers a new GET route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function get($path, $callback): Router;

    /**
     * Registers a new POST route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function post($path, $callback): Router;

    /**
     * Registers a new PUT route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function put($path, $callback): Router;

    /**
     * Registers a new PATCH route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function patch($path, $callback): Router;

    /**
     * Registers a new DELETE route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function delete($path, $callback): Router;

    /**
     * Registers a new OPTIONS route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function options($path, $callback): Router;

    /**
     * Registers a new route that matches any HTTP method with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function any($path, $callback): Router;

    /**
     * Registers a route that renders a view template with the router.
     *
     * @param string $path The path for the route.
     * @param string $template The view template file name.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function view($path, $template): Router;

    /**
     * Registers a group of routes with the same attributes.
     *
     * @param array $attributes The attributes to be applied to each route.
     * @param callable $callback The callback function containing the route definitions.
     *
     * @return void
     */
    public static function group($attributes, $callback): void;
}
