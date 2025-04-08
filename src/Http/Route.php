<?php

namespace Spark\Http;

use Spark\Contracts\Http\HttpRouteContract;
use Spark\Foundation\Application;
use Spark\Router;

/**
 * Class Route
 * 
 * This class provides a simple facade for the Router class, allowing for easy 
 * registration and dispatching of routes.
 *
 * @method static Router get(string $path, callable|string|array $callback)
 * @method static Router post(string $path, callable|string|array $callback)
 * @method static Router put(string $path, callable|string|array $callback)
 * @method static Router patch(string $path, callable|string|array $callback)
 * @method static Router delete(string $path, callable|string|array $callback)
 * @method static Router options(string $path, callable|string|array $callback)
 * @method static Router any(string $path, callable|string|array $callback)
 * @method static Router group(array $attributes, callable $callback)
 * @method static Router view(string $path, string $template)
 * 
 * @package Spark\Http
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Route implements HttpRouteContract
{
    /**
     * Registers a new GET route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function get($path, $callback): Router
    {
        return Application::$app->container->get(Router::class)->get($path, $callback);
    }

    /**
     * Registers a new POST route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function post($path, $callback): Router
    {
        return Application::$app->container->get(Router::class)->post($path, $callback);
    }

    /**
     * Registers a new PUT route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function put($path, $callback): Router
    {
        return Application::$app->container->get(Router::class)->put($path, $callback);
    }

    /**
     * Registers a new PATCH route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function patch($path, $callback): Router
    {
        return Application::$app->container->get(Router::class)->patch($path, $callback);
    }

    /**
     * Registers a new DELETE route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function delete($path, $callback): Router
    {
        return Application::$app->container->get(Router::class)->delete($path, $callback);
    }

    /**
     * Registers a new OPTIONS route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function options($path, $callback): Router
    {
        return Application::$app->container->get(Router::class)->options($path, $callback);
    }

    /**
     * Registers a new route that matches any HTTP method with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function any($path, $callback): Router
    {
        return Application::$app->container->get(Router::class)->any($path, $callback);
    }

    /**
     * Registers a route that renders a view template with the router.
     *
     * @param string $path The path for the route.
     * @param string $template The view template file name.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function view($path, $template): Router
    {
        return Application::$app->container->get(Router::class)->view($path, $template);
    }

    /**
     * Registers a group of routes with the same attributes.
     *
     * @param array $attributes The attributes to be applied to each route.
     * @param callable $callback The callback function containing the route definitions.
     *
     * @return void
     */
    public static function group($attributes, $callback): void
    {
        Application::$app->container->get(Router::class)->group($attributes, $callback);
    }
}