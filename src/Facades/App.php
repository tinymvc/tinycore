<?php

namespace Spark\Facades;

use Spark\Foundation\Application;

/**
 * Facade App
 * 
 * This class provides a simple facade for the Application class, allowing for easy
 * access to the application instance and its methods.
 *
 * @method static string getPath()
 * @method static mixed getEnv(string $key, $default = null)
 * @method static void setEnv(string $key, $value)
 * @method static void mergeEnv(array $env)
 * @method static void instance(string $abstract, mixed $instance)
 * @method static void forget(string $abstract)
 * @method static bool resolved(string $abstract)
 * @method static mixed get(string $abstract)
 * @method static mixed make(string $abstract)
 * @method static mixed call(array|string|callable $abstract, array $parameters = [])
 * @method static bool has(string $abstract)
 * @method static Application withApp(callable $callback)
 * @method static Application withRouter(callable $callback)
 * @method static Application withCommands(callable $callback)
 * @method static Application withMiddleware(callable $callback)
 * @method static Application withEvents(callable $callback)
 * @method static Application withExceptions(array $exceptions)
 * @method static Application singleton(string $abstract, $concrete = null)
 * @method static Application bind(string $abstract, $concrete = null)
 * @method static Application reset(string $abstract, callable|string|null $concrete = null)
 * @method static Application when(string|array $concrete, string $needs, callable|string $give)
 * 
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class App extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Application::class;
    }
}
