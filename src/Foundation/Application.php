<?php

namespace Spark\Foundation;

use Spark\Console\Commands;
use Spark\Console\Console;
use Spark\Contracts\ApplicationContract;
use Spark\Database\DB;
use Spark\Events;
use Spark\Exceptions\Http\AuthorizationException;
use Spark\Exceptions\NotFoundException;
use Spark\Foundation\Exceptions\InvalidCsrfTokenException;
use Spark\Foundation\Exceptions\TooManyRequests;
use Spark\Hash;
use Spark\Http\Auth;
use Spark\Http\InputErrors;
use Spark\Http\Middleware;
use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Queue\Queue;
use Spark\Routing\Exceptions\RouteNotFoundException;
use Spark\Routing\Router;
use Spark\Support\ItemNotFoundException;
use Spark\Support\Traits\Macroable;
use Spark\Translator;
use Spark\Http\Gate;
use Spark\Http\Session;
use Spark\Utils\Tracer;
use Spark\Utils\Vite;
use Spark\View\Blade;
use Throwable;
use function get_class;
use function is_array;
use function is_string;

/**
 * The Application class is the main entry point to the framework.
 * 
 * It provides a way to register and resolve services and dependencies, 
 * initialize the application, and setup the environment.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Application extends \Spark\Container implements ApplicationContract
{
    use Macroable;

    /** @var Application Singleton instance of the application. */
    public static Application $app;

    /** @var array Array to store configuration values. */
    private array $config = [];

    /** @var array Array to store exception handlers. */
    private array $exceptions = [];

    /**
     * Application constructor.
     * 
     * Initializes the application by setting up the application instance statically, 
     * initializing the dependency injection container, registering core services, 
     * and binding core services to the container.
     * 
     * @param string $path The path to the application.
     */
    public function __construct(private string $path)
    {
        // Set the application instance statically.
        self::$app = $this;

        Tracer::start(); // Initialize the tracer

        // Load environment variables from .env file
        \Spark\DotEnv::loadFrom(
            envPath: dir_path($this->path . '/.env'),
            compilePath: dir_path($this->path . '/bootstrap/cache/env.php')
        );

        // Register core services for global use
        $this->singleton(Translator::class);
        $this->singleton(DB::class);
        $this->singleton(Hash::class);
        $this->singleton(Blade::class);
        $this->singleton(Queue::class);
        $this->singleton(Router::class);
        $this->singleton(Middleware::class);
        $this->singleton(Events::class);

        // Bind core services to the container for Http Client
        if (is_web()) {
            $this->singleton(Session::class);
            $this->singleton(InputErrors::class);
            $this->singleton(Request::class);
            $this->singleton(Response::class);
            $this->singleton(Gate::class);
            $this->singleton(Auth::class);
            $this->singleton(Vite::class);
        }
    }

    /**
     * Creates a new instance of the application.
     *
     * @param string $path The path to the root directory of the application.
     * @param null|string|array $config An optional array of configuration values.
     * @param null|array $providers An optional array of service provider classes to register.
     *
     * @return self A new instance of the application.
     */
    public static function create(string $path, null|string|array $config = null, null|array $providers = null): self
    {
        $app = new self($path);

        return $app->withApp(config: $config, providers: $providers);
    }

    /**
     * Returns the root path of the application.
     *
     * @return string The root path of the application.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Retrieves a configuration value.
     *
     * @param string $key The name of the configuration value.
     * @param mixed $default The default value to return if the configuration value is not set.
     * 
     * @return mixed The value of the configuration value, or the default value if not set.
     */
    public function getConfig(string $key, $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * Sets the value of a configuration value.
     *
     * This method allows updating or creating a configuration value
     * with the given key and value.
     *
     * @param string $key The name of the configuration value to set.
     * @param mixed $value The value to assign to the configuration value.
     * @return void
     */
    public function setConfig(string $key, mixed $value): void
    {
        data_set($this->config, $key, $value);
    }

    /**
     * Merges the provided configuration values with the existing ones.
     *
     * @param array $config An associative array of configuration values to merge.
     *
     * @return void
     */
    public function mergeConfig(array $config): void
    {
        $this->config = [...$this->config, ...$config];
    }

    /**
     * Checks if the application is running in debug mode.
     *
     * This method checks the 'debug' key in the configuration values
     * to determine if the application is in debug mode.
     *
     * @return bool True if the application is in debug mode, false otherwise.
     */
    public function isDebugMode(): bool
    {
        return (bool) ($this->config['app']['debug'] ?? false);
    }

    /**
     * Applies a callback to the application's container.
     *
     * This method takes a callback function, which receives the container,
     * allowing custom logic to be executed on the application's dependency
     * injection container.
     *
     * @param null|string|array $config An array of configuration values to set.
     * @param null|array $providers An array of service providers to register.
     * @param null|array $middlewares An array of middlewares to apply.
     * @param null|callable $then The callback to be applied to the container.
     * @return self
     */
    public function withApp(
        null|string|array $config = null,
        null|array $providers = null,
        null|array $middlewares = null,
        null|callable $then = null
    ): self {

        $config && is_array($config) && $this->mergeConfig($config);

        $config && is_string($config) && $this->mergeConfig($this->discoverConfig(
            folder: $config,
            cache: $this->path . '/bootstrap/cache/config.php'
        ));

        $middlewares && $this->withMiddleware(register: $middlewares);

        $providers && array_map(
            fn($provider) => $this->addServiceProvider(new $provider),
            $providers
        );

        $then && $then($this);

        return $this;
    }

    /**
     * Applies a callback to the application's router.
     *
     * This method takes a callback function, which receives the router
     * from the dependency injection container, allowing custom router
     * logic to be executed.
     *
     * @param null|string $web The path to the web routes file.
     * @param null|string $api The path to the API routes file.
     * @param null|string $webhook The path to the webhook routes file.
     * @param null|string $commands The path to the commands routes file.
     * @param null|callable $then The callback to be applied to the router.
     * @return self
     */
    public function withRouting(
        null|string $web = null,
        null|string $api = null,
        null|string $webhook = null,
        null|string $commands = null,
        null|callable $then = null
    ): self {
        /** @var Router $router */
        $router = $this->get(Router::class);

        $api && $router->group(
            ['prefix' => 'api', 'middleware' => ['cors'], 'withoutMiddleware' => ['csrf']],
            fn() => require $api
        );

        $webhook && $router->group(
            ['prefix' => 'webhook', 'withoutMiddleware' => ['csrf']],
            fn() => require $webhook
        );

        $web && require $web;

        $commands && require $commands;

        $then && $then($router);

        return $this;
    }

    /**
     * Applies a callback to the application's command manager.
     *
     * This method takes a callback function, which receives the command
     * manager from the dependency injection container, allowing custom
     * command logic to be executed.
     *
     * @param null|string $load The path to the commands file to load.
     * @param null|callable $then The callback to be applied to the command manager.
     * @return self
     */
    public function withCommands(
        null|string $load = null,
        null|callable $then = null,
    ): self {
        $load && require $load;

        $then && $then($this->get(Commands::class));

        return $this;
    }

    /**
     * Applies a middleware to the application.
     *
     * This method takes a callback function which receives the middleware
     * manager from the dependency injection container, allowing custom
     * middleware logic to be executed.
     *
     * @param null|string $load The path to the middleware file to load.
     * @param null|array $register An array of middleware to register.
     * @param null|string|array $queue A middleware or array of middleware to queue.
     * @param null|callable $then The callback to be applied to the middleware manager
     * @return self
     */
    public function withMiddleware(
        null|string $load = null,
        null|array $register = null,
        null|string|array $queue = null,
        null|callable $then = null,
    ): self {
        /** @var Middleware $middleware */
        $middleware = $this->get(Middleware::class);

        $register && $middleware->registerMany($register);

        $load && $middleware->registerMany(require $load);

        $queue && $middleware->queue($queue);

        $then && $then($middleware);

        return $this;
    }

    /**
     * Applies a callback to the application's event dispatcher.
     *
     * This method takes a callback function, which receives the event
     * dispatcher from the dependency injection container, allowing custom
     * event logic to be executed.
     *
     * @param null|array $listeners An array of event listeners to register.
     * @param null|callable $then The callback to be applied to the event dispatcher
     * @return self
     */
    public function withEvents(
        null|array $listeners = null,
        null|callable $then = null
    ): self {
        /** @var Events $event */
        $event = $this->get(Events::class);

        $listeners && $event->subscribe($listeners);

        $then && $then($event);

        return $this;
    }

    /**
     * Adds exception handlers to the application.
     *
     * This method allows you to register custom exception handlers for specific
     * exception types. The handlers will be called when the corresponding
     * exception is thrown.
     *
     * @param array $exceptions An associative array of exception types and their handlers.
     * @return self
     */
    public function withExceptions(array $exceptions): self
    {
        foreach ($exceptions as $exception => $handler) {
            $this->exceptions[$exception] = $handler;
        }

        return $this;
    }

    /**
     * Configures the application's queue system.
     *
     * This method allows you to set up the queue system by providing an array of jobs to be queued,
     * a logging option, and an optional callback for additional configuration.
     *
     * @param null|array $jobs An array of jobs to be added to the queue.
     * @param bool|string $log A boolean or string indicating whether to log queue activity, or the log file path.
     * @param null|callable $then An optional callback for additional configuration of the queue.
     * @return self
     */
    public function withQueue(null|array $jobs = null, bool|string $log = true, null|callable $then = null): self
    {
        $this->singleton(Queue::class, function () use ($jobs, $log, $then) {
            $queue = new Queue($log);

            $jobs && array_map($queue->pushOnce(...), $jobs);

            $then && $then($queue);

            return $queue;
        });

        return $this;
    }

    /**
     * Registers an event listener for a specific event.
     *
     * This method allows you to register a listener callback for a specific
     * event. The listener will be called when the event is dispatched.
     *
     * @param string $event The name of the event to listen for.
     * @param callable $listener The listener callback to be called when the event is dispatched.
     * @return self
     */
    public function on(string $event, callable $listener): self
    {
        $this->events()->addListener($event, $listener);
        return $this;
    }

    /**
     * Removes an event listener for a specific event.
     *
     * This method allows you to remove a listener callback for a specific
     * event. The listener will no longer be called when the event is dispatched.
     *
     * @param string $event The name of the event to remove the listener from.
     * @param callable $listener The listener callback to be removed.
     * @return self
     */
    public function off(string $event, callable $listener): self
    {
        $this->events()->removeListener($event, $listener);
        return $this;
    }

    /**
     * Retrieves the event dispatcher from the application's container.
     *
     * The event dispatcher is responsible for managing and dispatching events
     * throughout the application.
     *
     * @return Events The event dispatcher instance.
     */
    public function events(): Events
    {
        return $this->get(Events::class);
    }

    /**
     * Dispatches an event with the given arguments.
     *
     * This method allows you to dispatch an event by its name, along with
     * any additional arguments that should be passed to the event listeners.
     *
     * @param string $event The name of the event to dispatch.
     * @param mixed ...$args Additional arguments to be passed to the event listeners.
     * @return void
     */
    public function dispatch(string $event, mixed ...$args): void
    {
        $this->events()->dispatch($event, ...$args);
    }

    /**
     * Discovers configuration files in a specified folder and caches them.
     *
     * This method checks if a cached configuration file exists. If it does, 
     * it loads the configuration from the cache. Otherwise, it scans the 
     * specified folder for PHP files, loads their contents as configuration, 
     * and returns the combined configuration array.
     *
     * @param string $folder The path to the folder containing configuration files.
     * @param string $cache The path to the cached configuration file.
     * @return array The combined configuration array.
     */
    protected function discoverConfig(string $folder, string $cache): array
    {
        if (is_file($cache)) {
            return require $cache;
        }

        $config = [];

        foreach (glob("$folder/*.php") as $file) {
            $key = basename($file, '.php');
            $config[$key] = require $file;
        }

        return $config;
    }

    /**
     * Runs the application.
     *
     * Bootstraps the application by registering providers and calling the `boot` method
     * on each provider. Then, it loads the routes from the routes file and adds them to
     * the router. Finally, it dispatches the current request and sends the response.
     *
     * @return void
     */
    public function run(): void
    {
        $this->isDebugMode() && event('app:booting');
        try {
            $this->bootServiceProviders();

            $this->isDebugMode() && event('app:booted');

            $this->get(Router::class)
                ->dispatch(
                    $this->get(Request::class)
                )
                ->send();

            $this->isDebugMode() && event('app:terminated');
        } catch (RouteNotFoundException) {
            abort(error: 404, message: 'Route not found');
        } catch (ItemNotFoundException) {
            abort(error: 404, message: 'Item not found');
        } catch (NotFoundException) {
            abort(error: 404, message: 'Not found');
        } catch (AuthorizationException) {
            abort(error: 403, message: 'Forbidden');
        } catch (InvalidCsrfTokenException) {
            abort(error: 419, message: 'Page Expired');
        } catch (TooManyRequests) {
            abort(error: 429, message: 'Too many requests');
        } catch (Throwable $e) {
            if (isset($this->exceptions[get_class($e)])) {
                $this->exceptions[get_class($e)]($e);
            }

            Tracer::$instance->handleException($e);
        }
    }

    /**
     * Runs the command line interface.
     *
     * Bootstraps the application by registering providers and calling the `boot` method
     * on each provider. Then, it runs the command line interface.
     *
     * @return void
     */
    public function handleCommand(): void
    {
        $this->bootServiceProviders();

        $console = $this->get(Console::class);
        $console->run();
    }
}
