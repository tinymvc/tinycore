<?php

namespace Spark\Foundation\Providers;

use Spark\Contracts\ServiceProviderContract;
use Spark\Foundation\Application;

/**
 * Base service provider class.
 * 
 * This abstract class serves as the base for all service providers in the
 * Spark framework. It provides a structure for registering and booting
 * services within the application.
 * 
 * @package Spark\Foundation\Providers
 */
abstract class ServiceProvider implements ServiceProviderContract
{
    /** @var Application $app The application instance */
    protected Application $app;

    /** Construct the service provider with the application instance. */
    public function __construct()
    {
        $this->app = Application::$app;
    }

    /**
     * Registers services within the application.
     *
     * This method should be implemented by subclasses to register their
     * specific services.
     *
     * @return void
     */
    abstract public function register(): void;

    /**
     * Boots the service provider.
     *
     * This method is called after all the service providers have been
     * registered. It can be overridden by subclasses to perform any necessary
     * setup or bootstrapping of the application.
     *
     * @return void
     */
    public function boot(): void
    {
        // Optional boot method for service providers.
    }
}