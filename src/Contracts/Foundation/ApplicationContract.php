<?php

namespace Spark\Contracts\Foundation;

use Spark\Container;

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
     * Returns the container instance.
     *
     * This method returns the container instance.
     * The container is a dependency injection container that resolves
     * services and dependencies.
     *
     * @return Container The container instance.
     */
    public function getContainer(): Container;

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
     * Applies a callback to the application's container.
     *
     * This method takes a callback function which receives the container
     * from the dependency injection container, allowing custom container
     * logic to be executed.
     *
     * @param callable $callback The callback to be applied to the container.
     * @return self
     */
    public function withContainer(callable $callback): self;

    /**
     * Applies a callback to the application's router.
     *
     * This method takes a callback function which receives the router
     * from the dependency injection container, allowing custom router
     * logic to be executed.
     *
     * @param callable $callback The callback to be applied to the router.
     * @return self
     */
    public function withRouter(callable $callback): self;

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
    public function withMiddleware(callable $callback): self;

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