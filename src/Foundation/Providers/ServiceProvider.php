<?php

namespace Spark\Foundation\Providers;

use Spark\Contracts\ServiceProviderContract;
use Spark\Foundation\Application;
use Spark\Foundation\Console\PublishManager;

/**
 * Base service provider class.
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
     * @return void
     */
    abstract public function register(): void;

    /**
     * Register source => destination paths for the vendor:publish command.
     *
     * @param array<string, string> $paths An associative array of source and destination paths.
     * @param array<string>|string|null $groups A tag or array of tags to group the published assets.
     * @return void
     */
    protected function publishes(array $paths, array|string|null $groups = null): void
    {
        if (!$this->app->bound(PublishManager::class)) {
            return;
        }

        $this->app->get(PublishManager::class)->register(static::class, $groups, $paths);
    }

    /**
     * Boots the service provider.
     *
     * @return void
     */
    public function boot(): void
    {
    }
}
