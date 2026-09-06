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
 * @method static mixed getConfig(string $key, $default = null)
 * @method static void setConfig(string $key, $value)
 * @method static void mergeConfig(array $config)
 * @method static void instance(string $abstract, mixed $instance)
 * @method static void forget(string $abstract)
 * @method static bool resolved(string $abstract)
 * @method static mixed get(string $abstract)
 * @method static mixed make(string $abstract, array $parameters = [])
 * @method static mixed call(array|string|callable $abstract, array $parameters = [])
 * @method static bool has(string $abstract)
 * @method static Application defer(callable $callback)
 * @method static Application withApp(null|string|array $config = null, null|array $providers = null, null|array $middlewares = null, null|callable $then = null)
 * @method static Application withRouter(null|string $web = null, null|string $api = null, null|string $webhook = null, null|string $commands = null, null|callable $then = null)
 * @method static Application withCommands(null|string $load = null, null|callable $then = null,)
 * @method static Application withMiddleware(null|string $load = null, null|array $register = null, null|string|array $queue = null, null|callable $then = null,)
 * @method static Application withEvents(null|array $listeners = null, null|callable $then = null)
 * @method static Application withExceptions(array $exceptions)
 * @method static Application withQueue(null|array $jobs = null, null|callable $then = null)
 * @method static Application on(string $event, string|array|callable $listener)
 * @method static Application off(string $event, string|array|callable $listener)
 * @method static Application singleton(string $abstract, $concrete = null)
 * @method static Application bind(string $abstract, $concrete = null)
 * @method static Application reset(string $abstract, callable|string|null $concrete = null)
 * @method static Application when(string|array $concrete, string $needs, callable|string $give)
 * @method static void alias(string $alias, string $abstract)
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
