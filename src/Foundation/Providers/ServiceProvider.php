<?php

namespace Spark\Foundation\Providers;

use Spark\Contracts\ServiceProviderContract;
use Spark\Foundation\Application;
use Spark\Foundation\Console\VendorPublisher;

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
     * Register source => destination paths during boot for the vendor:publish command.
     *
     * This method allows service providers to define assets or configuration
     * files that can be published to the application's directory structure.
     *
     * @param array $paths An associative array of source and destination paths.
     * @param string $tag A tag to group the published assets (default: 'default').
     * @return void
     */
    protected function publishes(array $paths, string $tag = 'default'): void
    {
        if ($this->app->bound(VendorPublisher::class)) {
            $this->app->get(VendorPublisher::class)->register(static::class, $tag, $paths);
        }
    }

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
