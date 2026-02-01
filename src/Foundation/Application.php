<?php

namespace Spark\Foundation;

use Spark\Console\Commands;
use Spark\Console\Console;
use Spark\Contracts\ApplicationContract;
use Spark\Database\DB;
use Spark\EventDispatcher;
use Spark\Exceptions\Http\AuthorizationException;
use Spark\Exceptions\NotFoundException;
use Spark\Foundation\Exceptions\InvalidCsrfTokenException;
use Spark\Foundation\Exceptions\TooManyRequests;
use Spark\Hash;
use Spark\Http\Auth;
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

/**
 * The Application class is the main entry point to the framework.
 * 
 * It provides a way to register and resolve services and dependencies, 
 * initialize the application, and setup the environment.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Application extends \Spark\Container implements ApplicationContract, \ArrayAccess
{
    use Macroable;

    /** @var Application Singleton instance of the application. */
    public static Application $app;

    /** @var array Array to store exception handlers. */
    private array $exceptions = [];

    /** @var array Array to store application variables. */
    private array $vars = [];

    /**
     * Application constructor.
     * 
     * Initializes the application by setting up the application instance statically, 
     * initializing the dependency injection container, registering core services, 
     * and binding core services to the container.
     * 
     * @param string $path The path to the application.
     * @param array  $env Environment variables.
     */
    public function __construct(private string $path, private array $env = [])
    {
        // Set the application instance statically.
        self::$app = $this;

        Tracer::start(); // Initialize the tracer

        // Register core services for global use
        $this->singleton(Translator::class);
        $this->singleton(DB::class);
        $this->singleton(Hash::class);
        $this->singleton(Blade::class);
        $this->singleton(Queue::class);
        $this->singleton(Router::class);
        $this->singleton(Middleware::class);
        $this->singleton(EventDispatcher::class);

        // Bind core services to the container for Http Client
        if (is_web()) {
            $this->singleton(Session::class);
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
     * @param array $env An optional array of environment variables.
     * @param array $providers An optional array of service provider classes to register.
     *
     * @return self A new instance of the application.
     */
    public static function create(string $path, array $env = [], array $providers = []): self
    {
        $app = new self($path, $env);

        foreach ($providers as $provider) {
            $app->addServiceProvider(new $provider);
        }

        return $app;
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
     * Retrieves an environment variable's value.
     *
     * @param string $key The name of the environment variable.
     * @param mixed $default The default value to return if the environment variable is not set.
     * 
     * @return mixed The value of the environment variable, or the default value if not set.
     */
    public function getEnv(string $key, $default = null): mixed
    {
        return data_get($this->env, $key, $default);
    }

    /**
     * Sets the value of an environment variable.
     *
     * This method allows updating or creating an environment variable
     * with the given key and value.
     *
     * @param string $key The name of the environment variable to set.
     * @param mixed $value The value to assign to the environment variable.
     * @return void
     */
    public function setEnv(string $key, mixed $value): void
    {
        data_set($this->env, $key, $value);
    }

    /**
     * Merges the provided environment variables with the existing ones.
     *
     * @param array $env An associative array of environment variables to merge.
     *
     * @return void
     */
    public function mergeEnv(array $env): void
    {
        $this->env = [...$this->env, ...$env];
    }

    /**
     * Checks if the application is running in debug mode.
     *
     * This method checks the 'debug' key in the environment variables
     * to determine if the application is in debug mode.
     *
     * @return bool True if the application is in debug mode, false otherwise.
     */
    public function isDebugMode(): bool
    {
        return (bool) ($this->env['debug'] ?? false);
    }

    /**
     * Applies a callback to the application's container.
     *
     * This method takes a callback function, which receives the container,
     * allowing custom logic to be executed on the application's dependency
     * injection container.
     *
     * @param null|array $env An array of environment variables to set.
     * @param null|array $providers An array of service providers to register.
     * @param null|array $middlewares An array of middlewares to apply.
     * @param null|callable $then The callback to be applied to the container.
     * @return self
     */
    public function withApp(
        null|array $env = null,
        null|array $providers = null,
        null|array $middlewares = null,
        null|callable $then = null
    ): self {

        $env && $this->mergeEnv($env);

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
        /** @var EventDispatcher $event */
        $event = $this->get(EventDispatcher::class);

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
     * @return EventDispatcher The event dispatcher instance.
     */
    public function events(): EventDispatcher
    {
        return $this->get(EventDispatcher::class);
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

    /**
     * Magic method to check if a variable is set in the application.
     *
     * @param mixed $name The name of the variable to check.
     * @return bool True if the variable is set, false otherwise.
     */
    public function __isset(mixed $name): bool
    {
        return isset($this->vars[$name]);
    }

    /**
     * Magic method to get a variable from the application.
     *
     * @param mixed $name The name of the variable to get.
     * @return mixed The value of the variable, or null if not set.
     */
    public function __get(mixed $name): mixed
    {
        return $this->vars[$name] ?? null;
    }

    /**
     * Magic method to set a variable in the application.
     *
     * @param mixed $name The name of the variable to set.
     * @param mixed $value The value to set the variable to.
     * @return void
     */
    public function __set(mixed $name, mixed $value): void
    {
        $this->vars[$name] = $value;
    }

    /**
     * Magic method to unset a variable in the application.
     *
     * @param mixed $name The name of the variable to unset.
     * @return void
     */
    public function __unset(mixed $name): void
    {
        unset($this->vars[$name]);
    }

    /**
     * Checks if a variable exists in the application.
     *
     * @param mixed $offset The name of the variable to check.
     * @return bool True if the variable exists, false otherwise.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->vars[$offset]);
    }

    /**
     * Gets a variable from the application.
     *
     * @param mixed $offset The name of the variable to get.
     * @return mixed The value of the variable, or null if not set.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->vars[$offset] ?? null;
    }

    /**
     * Sets a variable in the application.
     *
     * @param mixed $offset The name of the variable to set.
     * @param mixed $value The value to set the variable to.
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->vars[] = $value;
        } else {
            $this->vars[$offset] = $value;
        }
    }

    /**
     * Unsets a variable in the application.
     *
     * @param mixed $offset The name of the variable to unset.
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->vars[$offset]);
    }
}
