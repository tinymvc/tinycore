<?php

namespace Spark\Foundation\Providers;

use Spark\Console\Commands;
use Spark\Console\Console;
use Spark\Database\Migration;
use Spark\Foundation\Console\PrimaryCommandsHandler;
use Spark\Foundation\Console\MakeStubCommandsHandler;
use Spark\Foundation\Console\PublishManager;
use Spark\Utils\Tinker;

/**
 * Registers services for CLI commands.
 * 
 * This service provider is used to register services for CLI commands, such as
 * creating a new migration, running migrations, and creating a new database
 * seed file.
 * 
 * @package Spark\Foundation\Providers
 */
class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * Registers services for CLI commands. 
     *
     * @return void
     */
    public function register(): void
    {
        // Register the migration singleton
        $this->app->singleton(Migration::class);
        $this->app->singleton(PublishManager::class);

        $this->app->alias('console', Console::class);
        $this->app->alias('commands', Commands::class);
        $this->app->alias('migration', Migration::class);
    }

    /**
     * Boots the service provider.
     *
     * This method is called after all the service providers have been
     * registered. It is used to perform any necessary setup or bootstrapping
     * of the application.
     *
     * @return void
     */
    public function boot(): void
    {
        // Get the commands instance
        $commands = $this->app->get(Commands::class);

        // Add the serve command
        $commands->addCommand('serve', [PrimaryCommandsHandler::class, 'startDevelopmentServer'])
            ->description('Start the development server');

        // Add the help command
        $commands->addCommand('help', [PrimaryCommandsHandler::class, 'handleHelp'])
            ->description('Show command help');

        // Add the about command
        $commands->addCommand('about', [PrimaryCommandsHandler::class, 'about'])
            ->description('Display basic information about the application');

        // Add the route:list command
        $commands->addCommand('route:list', [PrimaryCommandsHandler::class, 'routeList'])
            ->description('Show route list')
            ->help('Filter with --method=GET, --path=api, or --name=users.');

        // Add the queue:work command
        $commands->addCommand('queue:work', [PrimaryCommandsHandler::class, 'runQueueJobs'])
            ->description('Run the queue jobs workers')
            ->help('Options: --connection=name, --queue=default, --once, --timeout=3600, --sleep=3, --delay=5, --tries=3.');

        $commands->addCommand('queue:restart', [PrimaryCommandsHandler::class, 'restartQueueWorkers'])
            ->description('Gracefully stop queue workers so a supervisor can restart them')
            ->help('Workers finish their current job before exiting. The command and workers must share the configured cache store.');

        // Add the queue:clear command
        $commands->addCommand('queue:clear', [PrimaryCommandsHandler::class, 'clearQueueJobs'])
            ->description('Clear all queue jobs')
            ->help('Use --failed to clear failed queue jobs, --queue=name to clear one queue, and --connection=name to select a configured queue connection.');

        // Add the queue:forget command
        $commands->addCommand('queue:forget', [PrimaryCommandsHandler::class, 'forgetQueueJob'])
            ->description('Delete a single queue job by ID')
            ->help('Usage: queue:forget <id> [--connection=name]');

        // Add the queue:list command
        $commands->addCommand('queue:list', [PrimaryCommandsHandler::class, 'listQueueJobs'])
            ->description('Display all queue jobs')
            ->help('Use --connection=name to select a configured queue connection.');

        // Add the queue:failed command
        $commands->addCommand('queue:failed', [PrimaryCommandsHandler::class, 'listFailedQueueJobs'])
            ->description('Display all failed queue jobs')
            ->help('Use --connection=name to select a configured queue connection.');

        // Add the queue:retry command
        $commands->addCommand('queue:retry', [PrimaryCommandsHandler::class, 'retryFailedQueueJobs'])
            ->description('Retry failed queue jobs by ID or all')
            ->help('Usage: queue:retry [id|all] [--connection=name]');

        // Add the migrate command
        $commands->addCommand('migrate', [Migration::class, 'up'])
            ->description('Run the database migrations')
            ->help('Use --seed to also run seeders, and --force to skip confirmation in production.');

        // Add the migrate:status command
        $commands->addCommand('migrate:status', [Migration::class, 'status'])
            ->description('Show the status of each migration and seeder')
            ->help('Use --pending or --ran to filter results.');

        // Add the migrate:rollback command
        $commands->addCommand('migrate:rollback', [Migration::class, 'down'])
            ->description('Rollback the last database migration batch')
            ->help('Use --step=N to roll back N migrations, --batch=N for a specific batch, --all to roll back all, and --force in production.');

        // Add the migrate:fresh command
        $commands->addCommand('migrate:fresh', [Migration::class, 'refresh'])
            ->description('Rollback all database migrations and re-run them')
            ->help('Use --seed to also run seeders, and --force to skip confirmation in production.');

        // Add the db:seed command
        $commands->addCommand('db:seed', [Migration::class, 'seed'])
            ->description('Run database seeders')
            ->help('Usage: db:seed [name] [--class=name] [--force]');

        // Add the key:generate command
        $commands->addCommand('key:generate', [PrimaryCommandsHandler::class, 'generateAppKey'])
            ->description('Generate a new encryption key')
            ->help('Use --force to overwrite an existing key or --show to print a generated key without saving.');

        // Cache and clear views
        $commands->addCommand('view:cache', [PrimaryCommandsHandler::class, 'cacheViews'])
            ->description('Compile all Blade templates');

        $commands->addCommand('view:clear', [PrimaryCommandsHandler::class, 'clearViewCaches'])
            ->description('Clear the view caches');

        // Cache management
        $commands->addCommand('cache:clear', [PrimaryCommandsHandler::class, 'clearCache'])
            ->description('Clear cache files and entries')
            ->help('Use --namespace=name or --store=name to flush a single cache namespace without clearing compiled config/views.');

        $commands->addCommand('cache:prune', [PrimaryCommandsHandler::class, 'pruneCache'])
            ->description('Remove expired cache entries and expired locks')
            ->help('Use --namespace=name or --store=name to target a specific cache namespace.');

        // Config caching
        $commands->addCommand('config:cache', [PrimaryCommandsHandler::class, 'cacheConfig'])
            ->description('Create a cache file for faster configuration loading');

        $commands->addCommand('config:clear', [PrimaryCommandsHandler::class, 'clearConfigCache'])
            ->description('Remove the configuration cache file');

        // Optimize commands
        $commands->addCommand('optimize', [PrimaryCommandsHandler::class, 'optimize'])
            ->description('Cache framework bootstrap files, configuration, and views');

        $commands->addCommand('optimize:clear', [PrimaryCommandsHandler::class, 'clearOptimization'])
            ->description('Remove cached bootstrap files, configuration, and views');

        // Add the storage:link command
        $commands->addCommand('storage:link', [PrimaryCommandsHandler::class, 'createSymbolicLinkForUploads'])
            ->description('Create a Symbolic link for uploads directory.');

        // Make a Service Provider
        $commands->addCommand('make:provider', [MakeStubCommandsHandler::class, 'makeProvider'])
            ->description('Create a new service provider class');

        // Make a Migration file
        $commands->addCommand('make:migration', [MakeStubCommandsHandler::class, 'makeMigration'])
            ->description('Create a new database migration file')
            ->help('Use --table=name to generate a Schema::table() migration, --pivot for a pivot table, or --seed to also generate a seeder.');

        // Make a Seeder file
        $commands->addCommand('make:seeder', [MakeStubCommandsHandler::class, 'makeSeeder'])
            ->description('Create a new database seeder file');

        // Make a Model file
        $commands->addCommand('make:model', [MakeStubCommandsHandler::class, 'makeModel'])
            ->description('Create a new model class');

        // Make a Cast class file
        $commands->addCommand('make:cast', [MakeStubCommandsHandler::class, 'makeCast'])
            ->description('Create a new cast class for model attributes');

        // Make a Form Request class file
        $commands->addCommand('make:request', [MakeStubCommandsHandler::class, 'makeFormRequest'])
            ->description('Create a new form request class for validating incoming HTTP requests');

        // Make a Validation Rule class file
        $commands->addCommand('make:rule', [MakeStubCommandsHandler::class, 'makeRule'])
            ->description('Create a new validation rule class');

        // Make a JSON Resource class file
        $commands->addCommand('make:resource', [MakeStubCommandsHandler::class, 'makeJsonResource'])
            ->description('Create a new JSON resource class for API responses');

        // Make a Job class file
        $commands->addCommand('make:job', [MakeStubCommandsHandler::class, 'makeJob'])
            ->description('Create a new job class');

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

        // Add the vendor:publish command
        $commands->addCommand('vendor:publish', [PrimaryCommandsHandler::class, 'vendorPublish'])
            ->description('Publish registered package resources')
            ->help('Run without options to list resources. Select --tag=name, --provider=class, or --all. Use --force to replace existing files.');

        // Discover package services
        $commands->addCommand('package:discover', [PrimaryCommandsHandler::class, 'discoverServices'])
            ->description('Discover and cache package service providers');

        // Add the tinker command
        $commands->addCommand('tinker', Tinker::start(...))
            ->description('Start an interactive shell session');
    }
}
