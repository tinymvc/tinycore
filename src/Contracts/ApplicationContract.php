<?php

namespace Spark\Contracts;

/**
 * Interface defining the contract for the Application class.
 *
 * This interface defines the main entry point to the framework.
 * It provides a way to register and resolve services and dependencies,
 * initialize the application, and setup the environment.
 */
interface ApplicationContract
{
    /**
     * Creates a new instance of the application.
     *
     * This method takes the root path of the application and an optional array of
     * environment variables.
     *
     * @param string $path The path to the root directory of the application.
     * @param array $env An optional array of environment variables.
     *
     * @return self A new instance of the application.
     */
    public static function create(string $path, array $env = []): self;

    /**
     * Returns the root path of the application.
     *
     * This method returns the root path of the application.
     * The root path is the path to the root directory of the application.
     *
     * @return string The root path of the application.
     */
    public function getPath(): string;

    /**
     * Retrieves an environment variable's value.
     *
     * This method takes the name of the environment variable and an optional default value.
     * If the environment variable is not set, the default value is returned.
     *
     * @param string $key The name of the environment variable.
     * @param mixed $default The default value to return if the environment variable is not set.
     *
     * @return mixed The value of the environment variable, or the default value if not set.
     */
    public function getEnv(string $key, $default = null): mixed;

    /**
     * Applies a callback to the application instance.
     * 
     * This method takes a callback function which receives the application
     * instance, allowing custom application logic to be executed.
     * 
     * @param null|array $env An array of environment variables to set.
     * @param null|array $providers An array of service providers to register.
     * @param null|array $middlewares An array of middlewares to apply.
     * @param null|callable $then The callback to be applied to the application.
     * @return self
     */
    public function withApp(
        null|array $env = null,
        null|array $providers = null,
        null|array $middlewares = null,
        null|callable $then = null
    ): self;

    /**
     * Applies a callback to the application's router.
     *
     * This method takes a callback function which receives the router
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
    ): self;

    /**
     * Applies a middleware to the application.
     *
     * This method takes a callback function which receives the middleware
     * manager from the dependency injection container, allowing custom
     * middleware logic to be executed.
     *
     * @param null|string $load A middleware or path to load middleware from.
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
    ): self;

    /**
     * Applies a callback to the application's event system.
     *
     * This method takes a callback function which receives the event
     * manager from the dependency injection container, allowing custom
     * event logic to be executed.
     *
     * @param null|array $listeners An array of event listeners to register.
     * @param null|callable $then The callback to be applied to the event dispatcher
     * @return self
     */
    public function withEvents(
        null|array $listeners = null,
        null|callable $then = null
    ): self;

    /**
     * Specifies exceptions that should not be reported.
     *
     * This method takes an array of exception class names that should
     * be excluded from reporting.
     *
     * @param array $exceptions An array of exception class names.
     * @return self
     */
    public function withExceptions(array $exceptions): self;

    /**
     * Runs the application.
     *
     * This method takes no arguments and returns no value.
     * It is used to run the application.
     *
     * @return void
     */
    public function run(): void;
}