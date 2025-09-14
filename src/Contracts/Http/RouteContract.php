<?php
namespace Spark\Contracts\Http;

use Spark\Routing\Route;
use Spark\Routing\RouteGroup;

/**
 * Interface defining the contract for the Route class.
 *
 * This interface provides methods for registering routes in the router.
 */
interface RouteContract
{
    /**
     * Registers a new GET route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered route instance.
     */
    public static function get($path, $callback): Route;

    /**
     * Registers a new POST route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered route instance.
     */
    public static function post($path, $callback): Route;

    /**
     * Registers a new PUT route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered route instance.
     */
    public static function put($path, $callback): Route;

    /**
     * Registers a new PATCH route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered route instance.
     */
    public static function patch($path, $callback): Route;

    /**
     * Registers a new DELETE route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered route instance.
     */
    public static function delete($path, $callback): Route;

    /**
     * Registers a new OPTIONS route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered route instance.
     */
    public static function options($path, $callback): Route;

    /**
     * Registers a new route that matches any HTTP method with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered route instance.
     */
    public static function any($path, $callback): Route;

    /**
     * Registers a route that renders a view template with the router.
     *
     * @param string $path The path for the route.
     * @param string $template The view template file name.
     *
     * @return \Spark\Routing\Route The registered route instance.
     */
    public static function view($path, $template): Route;

    /**
     * Registers a group of routes with the same attributes.
     *
     * @param array $attributes The attributes to be applied to each route.
     * @param callable $callback The callback function containing the route definitions.
     *
     * @return \Spark\Routing\RouteGroup
     */
    public static function group($attributes, $callback): RouteGroup;
}
