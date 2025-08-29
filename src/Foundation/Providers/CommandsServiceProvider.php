<?php

namespace Spark\Foundation\Providers;

use Spark\Console\Commands;
use Spark\Console\Console;
use Spark\Container;
use Spark\Database\Migration;
use Spark\Foundation\Console\PrimaryCommandsHandler;
use Spark\Foundation\Console\MakeStubCommandsHandler;

/**
 * Registers services for CLI commands.
 * 
 * This service provider is used to register services for CLI commands, such as
 * creating a new migration, running migrations, and creating a new database
 * seed file.
 * 
 */
class CommandsServiceProvider
{
    /**
     * Registers services for CLI commands. 
     *
     * @param Container $container
     *   The application container.
     *
     * @return void
     */
    public function register(Container $container): void
    {
        // Register the console singleton
        $container->singleton(Console::class);

        // Register the commands singleton
        $container->singleton(Commands::class);

        // Register the migration singleton
        $container->singleton(Migration::class);
    }

    /**
     * Boots the service provider.
     *
     * This method is called after all the service providers have been
     * registered. It is used to perform any necessary setup or bootstrapping
     * of the application.
     *
     * @param Container $container
     *   The application container.
     *
     * @return void
     */
    public function boot(Container $container): void
    {
        // Get the commands instance
        $commands = $container->get(Commands::class);

        // Add the serve command
        $commands->addCommand('serve', [PrimaryCommandsHandler::class, 'startDevelopmentServer'])
            ->description('Start the development server');

        // Add the help command
        $commands->addCommand('help', [PrimaryCommandsHandler::class, 'handleHelp'])
            ->description('Show command help');

        // Add the help command
        $commands->addCommand('route:list', [PrimaryCommandsHandler::class, 'routeList'])
            ->description('Show route list');

        // Add the queue:run command
        $commands->addCommand('queue:run', [PrimaryCommandsHandler::class, 'runQueueJobs'])
            ->description('Run the queue jobs');

        // Add the queue:clear command
        $commands->addCommand('queue:clear', [PrimaryCommandsHandler::class, 'clearQueueJobs'])
            ->description('Clear all queue jobs');

        // Add the queue:list command
        $commands->addCommand('queue:list', [PrimaryCommandsHandler::class, 'listQueueJobs'])
            ->description('Display all queue jobs');

        // Add the migrate command
        $commands->addCommand('migrate:run', [Migration::class, 'up'])
            ->description('Run the database migrations');

        // Clear the view caches
        $commands->addCommand('view:clear', [PrimaryCommandsHandler::class, 'clearViewCaches'])
            ->description('Clear the view caches');

        // Clear all cache files
        $commands->addCommand('cache:clear', [PrimaryCommandsHandler::class, 'clearCache'])
            ->description('Clear all cache files');

        // Add the migrate:rollback command
        $commands->addCommand('migrate:rollback', [Migration::class, 'down'])
            ->description('Rollback the last database migration');

        // Add the migrate:fresh command
        $commands->addCommand('migrate:fresh', [Migration::class, 'refresh'])
            ->description('Rollback all database migrations and re-run them');

        // Add the key:generate command
        $commands->addCommand('key:generate', [PrimaryCommandsHandler::class, 'generateAppKey'])
            ->description('Generate a new encryption key');

        // Add the storage:link command
        $commands->addCommand('storage:link', [PrimaryCommandsHandler::class, 'createSymbolicLinkForUploads'])
            ->description('Create a Symbolic link for uploads directory.');

        // Make a Service Provider
        $commands->addCommand('make:provider', [MakeStubCommandsHandler::class, 'makeProvider'])
            ->description('Create a new service provider class');

        // Make a Migration file
        $commands->addCommand('make:migration', [MakeStubCommandsHandler::class, 'makeMigration'])
            ->description('Create a new database migration file');

        // Make a Seeder file
        $commands->addCommand('make:seeder', [MakeStubCommandsHandler::class, 'makeSeeder'])
            ->description('Create a new database seeder file');

        // Make a Model file
        $commands->addCommand('make:model', [MakeStubCommandsHandler::class, 'makeModel'])
            ->description('Create a new model class');

        // Make a Controller class file
        $commands->addCommand('make:controller', [MakeStubCommandsHandler::class, 'makeController'])
            ->description('Create a new controller class');

        // Make a middleware class file
        $commands->addCommand('make:middleware', [MakeStubCommandsHandler::class, 'makeMiddleware'])
            ->description('Create a new middleware class');

        // Make a view file
        $commands->addCommand('make:view', [MakeStubCommandsHandler::class, 'makeView'])
            ->description('Create a new view file');

        // Make a view component file
        $commands->addCommand('make:component', [MakeStubCommandsHandler::class, 'makeViewComponent'])
            ->description('Create a new view component file');
    }
}