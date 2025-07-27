<?php

namespace Spark\Foundation;

use Spark\Console\Commands;
use Spark\Console\Console;
use Spark\Container;
use Spark\Contracts\Foundation\ApplicationContract;
use Spark\Database\DB;
use Spark\Database\QueryBuilder;
use Spark\EventDispatcher;
use Spark\Exceptions\Http\AuthorizationException;
use Spark\Exceptions\NotFoundException;
use Spark\Exceptions\Routing\RouteNotFoundException;
use Spark\Foundation\Exceptions\InvalidCsrfTokenException;
use Spark\Hash;
use Spark\Http\Auth;
use Spark\Http\Middleware;
use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Queue\Queue;
use Spark\Router;
use Spark\Support\Traits\Macroable;
use Spark\Translator;
use Spark\Http\Gate;
use Spark\Http\Session;
use Spark\Utils\Tracer;
use Spark\Utils\Vite;
use Spark\View\View;
use Throwable;

/**
 * The Application class is the main entry point to the framework.
 * 
 * It provides a way to register and resolve services and dependencies, 
 * initialize the application, and setup the environment.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Application implements ApplicationContract
{
    use Macroable;

    /** @var Application Singleton instance of the application */
    public static Application $app;

    /**
     * Dependency injection container.
     * 
     * Manages the application's services and dependencies by providing a way to register and resolve them.
     * 
     * @var Container
     */
    private Container $container;

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

        // Initialize the tracer if debug mode is enabled
        if (isset($env['debug']) && $env['debug']) {
            Tracer::start();
        }

        // Initialize the dependency injection container
        $this->container = new Container;

        // Register core services
        $this->container->singleton(Session::class);
        $this->container->singleton(Request::class);
        $this->container->singleton(Response::class);
        $this->container->singleton(Middleware::class);
        $this->container->singleton(Router::class);
        $this->container->singleton(Translator::class);
        $this->container->singleton(DB::class);
        $this->container->singleton(View::class);
        $this->container->singleton(Vite::class);
        $this->container->singleton(Hash::class);
        $this->container->singleton(EventDispatcher::class);
        $this->container->singleton(Gate::class);
        $this->container->singleton(Queue::class);
        $this->container->singleton(
            Auth::class,
            fn() => new Auth(session: $this->container->get(Session::class), userModel: \App\Models\User::class)
        );
        $this->container->bind(QueryBuilder::class);
    }

    /**
     * Creates a new instance of the application.
     *
     * @param string $path The path to the root directory of the application.
     * @param array $env An optional array of environment variables.
     *
     * @return self A new instance of the application.
     */
    public static function make(string $path, array $env = []): self
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
    public function setEnv(string $key, $value): void
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
     * Retrieves the application's dependency injection container.
     *
     * This container manages the application's services and dependencies,
     * providing a way to register and resolve them.
     *
     * @return Container The dependency injection container instance.
     */
    public function getContainer(): Container
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
        try {
            $this->container->bootServiceProviders();

            $this->container
                ->get(Router::class)
                ->dispatch(
                    $this->container,
                    $this->container->get(Middleware::class),
                    $this->container->get(Request::class),
                )
                ->send();
        } catch (RouteNotFoundException) {
            abort(error: 404, message: 'Route not found');
        } catch (NotFoundException) {
            abort(error: 404, message: 'Not found');
        } catch (AuthorizationException) {
            abort(error: 403, message: 'Forbidden');
        } catch (InvalidCsrfTokenException) {
            abort(error: 419, message: 'Page Expired');
        } catch (Throwable $e) {
            if (config('debug')) {
                Tracer::$instance->handleException($e);
            }

            abort(error: 500, message: 'Internal Server Error');
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
}
