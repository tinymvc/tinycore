<?php

namespace Spark\Foundation;

use Spark\Console\Commands;
use Spark\Console\Console;
use Spark\Container;
use Spark\Contracts\ApplicationContract;
use Spark\Contracts\ContainerContract;
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

/**
 * The Application class is the main entry point to the framework.
 * 
 * It provides a way to register and resolve services and dependencies, 
 * initialize the application, and setup the environment.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Application implements ApplicationContract, \ArrayAccess
{
    use Macroable;

    /** @var Application Singleton instance of the application. */
    public static Application $app;

    /** @var ContainerContract Dependency injection container. */
    private ContainerContract $container;

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

        // Initialize the dependency injection container
        $this->container = new Container;

        // Register core services for global use
        $this->container->singleton(Translator::class);
        $this->container->singleton(DB::class);
        $this->container->singleton(Hash::class);
        $this->container->singleton(EventDispatcher::class);
        $this->container->singleton(Gate::class);
        $this->container->singleton(Queue::class);
        $this->container->singleton(Router::class);

        // Bind core services to the container for Http Client
        if (!is_cli()) {
            $this->container->singleton(Session::class);
            $this->container->singleton(Request::class);
            $this->container->singleton(Response::class);
            $this->container->singleton(Middleware::class);
            $this->container->singleton(Blade::class);
            $this->container->singleton(Vite::class);
            $this->container->singleton(
                Auth::class,
                fn() => new Auth(session: $this->container->get(Session::class), userModel: \App\Models\User::class)
            );
        }
    }

    /**
     * Creates a new instance of the application.
     *
     * @param string $path The path to the root directory of the application.
     * @param array $env An optional array of environment variables.
     *
     * @return self A new instance of the application.
     */
    public static function create(string $path, array $env = []): self
    {
        return new self($path, $env);
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
        $this->env = array_merge($this->env, $env);
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
     * Retrieves the application's dependency injection container.
     *
     * This container manages the application's services and dependencies,
     * providing a way to register and resolve them.
     *
     * @return ContainerContract The dependency injection container instance.
     */
    public function getContainer(): ContainerContract
    {
        return $this->container;
    }

    /**
     * Registers a singleton service provider with the application's dependency injection container.
     *
     * Singleton services are registered with the container and returned on each request.
     *
     * @param string $abstract The abstract name or class name of the service to be resolved.
     * @param mixed $concrete The concrete value of the service to be resolved.
     * @return self
     */
    public function singleton(string $abstract, $concrete = null): self
    {
        $this->container->singleton($abstract, $concrete);
        return $this;
    }

    /**
     * Registers a service provider with the application's dependency injection container.
     *
     * Bindings are registered with the container and returned on each request.
     *
     * @param string $abstract The abstract name or class name of the service to be resolved.
     * @param mixed $concrete The concrete value of the service to be resolved.
     * @return self
     */
    public function bind(string $abstract, $concrete = null): self
    {
        $this->container->bind($abstract, $concrete);
        return $this;
    }

    /**
     * Resolves a service or a value from the dependency injection container.
     *
     * @param string $abstract The abstract name or class name of the service or value to be resolved.
     * @return mixed The resolved service or value.
     */
    public function get(string $abstract): mixed
    {
        return $this->container->get($abstract);
    }

    /**
     * Alias for the `get` method to resolve a service or value from the container.
     *
     * This method serves as an alias for the `get` method, providing a more
     * intuitive way to resolve services or values from the dependency injection
     * container.
     *
     * @param string $abstract The abstract name or class name of the service or value to be resolved.
     * @return mixed The resolved service or value.
     */
    public function make(string $abstract): mixed
    {
        return $this->container->get($abstract);
    }

    /**
     * Resolves a service or a value from the dependency injection container,
     * by calling the given abstract as a function.
     *
     * This method takes an abstract name or class name and resolves it by
     * calling it as a function. The resolved value is then returned.
     *
     * @param array|string|callable $abstract The abstract name or class name of the service or value to be resolved.
     * @param array $parameters An array of parameters to be passed to the resolved service or value.
     * @return mixed The resolved service or value.
     */
    public function resolve(array|string|callable $abstract, array $parameters = []): mixed
    {
        return $this->container->call($abstract, $parameters);
    }

    /**
     * Calls a service or a value from the dependency injection container.
     *
     * This method takes an abstract name or class name and resolves it by
     * calling it as a function. The resolved value is then returned.
     *
     * @param array|string|callable $abstract The abstract name or class name of the service or value to be resolved.
     * @param array $parameters An array of parameters to be passed to the resolved service or value.
     * @return mixed The resolved service or value.
     */
    public function call(array|string|callable $abstract, array $parameters = []): mixed
    {
        return $this->container->call($abstract, $parameters);
    }

    /**
     * Checks if a given abstract has a binding in the container.
     *
     * @param string $abstract The abstract name or class name of the service or value to be checked.
     * @return bool True if the abstract has a binding, false otherwise.
     */
    public function has(string $abstract): bool
    {
        return $this->container->has($abstract);
    }

    /**
     * Applies a callback to the application's container.
     *
     * This method takes a callback function, which receives the container,
     * allowing custom logic to be executed on the application's dependency
     * injection container.
     *
     * @param callable $callback The callback to be applied to the container.
     * @return self
     */
    public function withContainer(callable $callback): self
    {
        $callback($this->container);
        return $this;
    }

    /**
     * Applies a callback to the application's router.
     *
     * This method takes a callback function, which receives the router
     * from the dependency injection container, allowing custom router
     * logic to be executed.
     *
     * @param callable $callback The callback to be applied to the router.
     * @return self
     */
    public function withRouter(callable $callback): self
    {
        $callback($this->container->get(Router::class));
        return $this;
    }

    /**
     * Applies a callback to the application's command manager.
     *
     * This method takes a callback function, which receives the command
     * manager from the dependency injection container, allowing custom
     * command logic to be executed.
     *
     * @param callable $callback The callback to be applied to the command manager.
     * @return self
     */
    public function withCommands(callable $callback): self
    {
        $callback($this->container->get(Commands::class));
        return $this;
    }

    /**
     * Applies a middleware to the application.
     *
     * This method takes a callback function which receives the middleware
     * manager from the dependency injection container, allowing custom
     * middleware logic to be executed.
     *
     * @param callable $callback The callback to be applied to the middleware manager.
     * @return self
     */
    public function withMiddleware(callable $callback): self
    {
        $callback($this->container->get(Middleware::class));
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
            $this->container->bootServiceProviders();

            $this->isDebugMode() && event('app:booted');

            $this->container
                ->get(Router::class)
                ->dispatch(
                    $this->container,
                    $this->container->get(Middleware::class),
                    $this->container->get(Request::class),
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
        $this->container->bootServiceProviders();

        $console = $this->get(Console::class);
        $console->run();
    }

    /**
     * Magic method to check if a variable is set in the application.
     *
     * @param string $name The name of the variable to check.
     * @return bool True if the variable is set, false otherwise.
     */
    public function __isset($name): bool
    {
        return isset($this->vars[$name]);
    }

    /**
     * Magic method to get a variable from the application.
     *
     * @param string $name The name of the variable to get.
     * @return mixed The value of the variable, or null if not set.
     */
    public function __get($name): mixed
    {
        return $this->vars[$name] ?? null;
    }

    /**
     * Magic method to set a variable in the application.
     *
     * @param string $name The name of the variable to set.
     * @param mixed $value The value to set the variable to.
     * @return void
     */
    public function __set($name, $value): void
    {
        $this->vars[$name] = $value;
    }

    /**
     * Magic method to unset a variable in the application.
     *
     * @param string $name The name of the variable to unset.
     * @return void
     */
    public function __unset($name): void
    {
        unset($this->vars[$name]);
    }

    /**
     * Checks if a variable exists in the application.
     *
     * @param mixed $offset The name of the variable to check.
     * @return bool True if the variable exists, false otherwise.
     */
    public function offsetExists($offset): bool
    {
        return isset($this->vars[$offset]);
    }

    /**
     * Gets a variable from the application.
     *
     * @param mixed $offset The name of the variable to get.
     * @return mixed The value of the variable, or null if not set.
     */
    public function offsetGet($offset): mixed
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
    public function offsetSet($offset, $value): void
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
    public function offsetUnset($offset): void
    {
        unset($this->vars[$offset]);
    }
}
