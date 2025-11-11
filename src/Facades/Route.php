<?php

namespace Spark\Facades;

use Spark\Routing\Route as BaseRoute;
use Spark\Routing\RouteGroup;
use Spark\Routing\Router;
use Spark\Routing\RouteResource;

/**
 * Facade Route
 * 
 * This class provides a simple facade for the Router class, allowing for easy 
 * registration and dispatching of routes.
 *
 * @method static BaseRoute get(string $path, callable|string|array $callback)
 * @method static BaseRoute post(string $path, callable|string|array $callback)
 * @method static BaseRoute put(string $path, callable|string|array $callback)
 * @method static BaseRoute patch(string $path, callable|string|array $callback)
 * @method static BaseRoute delete(string $path, callable|string|array $callback)
 * @method static BaseRoute options(string $path, callable|string|array $callback)
 * @method static BaseRoute any(string $path, callable|string|array $callback)
 * @method static BaseRoute match(array $methods, string $path, callable|string|array $callback)
 * @method static BaseRoute view(string $path, string $template)
 * @method static BaseRoute fireline(string $path, string $template)
 * @method static BaseRoute redirect(string $from, string $to, int $status = 302)
 * @method static RouteResource resource(string $path,string $controller,string|null $name = null,string|array $middleware = [],string|array $withoutMiddleware = [],array $only = [],array $except = [])
 * @method static RouteGroup group(array|callable|null $attrsOrCallback = null, callable|null $callback = null)
 * @method static Router add(string $path, string|array|null $method = null, callable|string|array|null $callback = null, string|null $template = null, string|null $name = null, string|array $middleware = [], string|array $withoutMiddleware = [])
 * @method static Router fallback(callable|string|array $callback)
 * @method static bool has(string $name)
 * @method static array getRoutes()
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Route extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Router::class;
    }
}
