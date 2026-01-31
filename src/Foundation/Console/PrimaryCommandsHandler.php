<?php

namespace Spark\Foundation\Console;

use Spark\Console\Commands;
use Spark\Console\Prompt;
use Spark\Queue\Queue;
use Spark\Routing\Router;
use Spark\Utils\FileManager;
use function in_array;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Class PrimaryCommandsHandler
 * 
 * This class contains methods that are used to handle primary commands,
 * such as "serve" and "queue:run".
 */
class PrimaryCommandsHandler
{
    /**
     * Starts a PHP built-in development server.
     *
     * This method runs a PHP built-in server on localhost using the specified port
     * from the arguments or defaults to port 8080. It provides a console message
     * indicating the server's address and start time. The server runs in the
     * foreground, allowing proper termination with Ctrl+C.
     *
     * @param array $args
     *   An associative array of arguments, where the server port can be specified
     *   using the '_args' key.
     */
    public function startDevelopmentServer(array $args)
    {
        $port = $args['_args'][0] ?? 8080;

        Prompt::message(
            sprintf(
                "<info>Info</info> Server running on <bold>[http://localhost:%s]</bold> at <bold>%s</bold>.",
                $port,
                date('Y-m-d H:i:s')
            )
        );
        Prompt::message("Press <bold>Ctrl+C</bold> to stop the server.", "warning");

        // Simple foreground execution
        passthru("php -S localhost:{$port} -t public");
    }

    /**
     * Displays a list of registered routes in the console.
     *
     * This method retrieves the registered routes from the router and displays
     * them in the console with their associated HTTP methods and (optional)
     * route names. The output is formatted to be easy to read, with the route
     * path and HTTP method(s) aligned in columns.
     *
     * @param Router $router
     *   An instance of the Router class containing the registered routes.
     */
    public function routeList(Router $router)
    {
        $maxLength = max(array_map('strlen', array_column($router->getRoutes(), 'path'))) + 4;
        Prompt::message("Available routes:", "info");

        foreach ($router->getRoutes() as $name => $route) {
            $spacing = str_repeat(' ', $maxLength - strlen($route['path']));
            $methods = implode(', ', (array) $route['method']);
            $name = is_string($name) ? " (<bold>{$name}</bold>)" : '';
            Prompt::message(
                "  URI: <success>{$route['path']}</success>{$spacing} [<info>{$methods}</info>]{$name}",
                'raw'
            );
        }
    }

    /**
     * Handles the "help" command by listing all registered commands or showing
     * help for a specific command if a command name is provided.
     *
     * @param Commands $commands
     *   An instance of the Commands class containing the registered commands.
     * @param array $args
     *   An associative array of arguments, where the command name can be
     *   specified using the '_args' key.
     *
     * @return void
     */
    public function handleHelp(Commands $commands, array $args)
    {
        $commandName = $args['_args'][0] ?? null;

        // If a command name is provided, show the help for that command.
        if ($commandName && $commands->hasCommand($commandName)) {
            $commands->showCommandHelp($commandName);
        } else {
            // Otherwise, list all the registered commands.
            Prompt::message("Available commands:", "info");
            $commands->listCommands();
            Prompt::message("\nUse 'help <command>' to view details for a specific command.", "info");
        }
    }

    /**
     * Handles the "queue:install" command by installing the queue system.
     *
     * @param Queue $queue
     *   An instance of the Queue class for managing the queue system.
     *
     * @return void
     */
    public function installQueue(Queue $queue)
    {
        $queue->install();
    }

    /**
     * Handles the "queue:run" command by running all the pending queue jobs.
     *
     * @param Queue $queue
     *   An instance of the Queue class for running queue jobs.
     *  @param array $args
     *   An associative array of arguments, where the maximum number of jobs to run
     *
     * @return void
     */
    public function runQueueJobs(Queue $queue, array $args)
    {
        $queue->work(
            once: isset($args['once']),
            timeout: $args['timeout'] ?? 3480, // 58 minutes
            sleep: $args['sleep'] ?? 4, // 4 seconds
            delay: $args['delay'] ?? 8, // 8 seconds on failure
            tries: $args['tries'] ?? 3, // 3 attempts on failure
            queue: $args['queue'] ?? 'default',
        ); // Run all pending queue jobs
    }

    /**
     * Lists all scheduled queue jobs.
     *
     * @param Queue $queue
     *   An instance of the Queue class for managing queue jobs.
     *
     * @return void
     */
    public function listQueueJobs(Queue $queue, array $args)
    {
        $jobs = $queue->getJobs(
            from: $args['from'] ?? 0,
            to: $args['to'] ?? 500,
            queue: $args['queue'] ?? null,
            status: $args['status'] ?? null
        );

        foreach ($jobs as $job) {
            Prompt::message(
                sprintf(
                    "Job <bold>#%s (%s)</bold> Scheduled <info>%s</info>%s%s",
                    $job->getDisplayName(),
                    $job->getQueueName(),
                    $job->getScheduledTime()->toDateTimeString(),
                    $job->isRepeated() ? ' <danger>(Repeats)</danger> ' : '',
                    $job->isFailed() ? ' <danger>(Failed)</danger>' : ($job->getScheduledTime()->isPast() ? ' <warning>(Ready to run)</warning>' : '')
                ),
            );
        }

        if (empty($jobs)) {
            Prompt::message("No scheduled jobs found.", "info");
        }
    }

    /**
     * Lists all failed queue jobs.
     *
     * @param Queue $queue
     *   An instance of the Queue class for managing queue jobs.
     * 
     * @return void
     */
    public function listFailedQueueJobs(Queue $queue, array $args)
    {
        $failedJobs = $queue->getFailedJobs(
            from: $args['from'] ?? 0,
            to: $args['to'] ?? 500,
        );

        foreach ($failedJobs as $job) {
            Prompt::message(
                sprintf(
                    "Failed Job <bold>#%s (%s)</bold> Failed At <danger>%s</danger> Reason: <danger>%s</danger>",
                    $job->getDisplayName(),
                    $job->getQueueName(),
                    carbon($job->getMetadata('failed_at', ''))->toDateTimeString(),
                    $job->getReasonFailed()
                ),
            );
        }

        if (empty($failedJobs)) {
            Prompt::message("No failed jobs found.", "info");
        }
    }

    /**
     * Clears all the pending jobs in the queue.
     *
     * @param Queue $queue
     *   An instance of the Queue class for clearing queue jobs.
     * 
     * @return void
     */
    public function clearQueueJobs(Queue $queue)
    {
        $queue->clearAllJobs();
        Prompt::message("Queue jobs cleared.", "success");
    }

    /**
     * Lists all failed queue jobs.
     *
     * @param Queue $queue
     *   An instance of the Queue class for managing queue jobs.
     * 
     * @return void
     */
    public function clearFailedQueueJobs(Queue $queue)
    {
        $queue->clearFailedJobs();
        Prompt::message("Failed queue jobs cleared.", "success");
    }

    /**
     * Retries all failed queue jobs.
     *
     * @param Queue $queue
     *   An instance of the Queue class for managing queue jobs.
     * 
     * @return void
     */
    public function retryFailedQueueJobs(Queue $queue)
    {
        $queue->retryFailedJobs();
        Prompt::message("Failed queue jobs retried.", "success");
    }

    /**
     * Clears the view caches.
     *
     * This method clears the cached views in the application, ensuring that
     * the latest view files are used when rendering views.
     *
     * @return void
     */
    public function clearViewCaches()
    {
        view()->clearCache();

        Prompt::message("View caches cleared.", "success");
    }

    /**
     * Clears all cache files in the application.
     *
     * This method removes all cache files from the storage/cache directory,
     * except for the .gitignore file. It provides feedback on the success
     * or failure of the operation.
     *
     * @return void
     */
    public function clearCache()
    {
        $this->clearViewCaches(); // Also clear view caches

        $cacheDir = storage_dir('cache');

        if (is_dir($cacheDir)) {
            foreach (scandir($cacheDir) as $item) {
                if (in_array($item, ['.', '..', '.gitignore'])) {
                    continue;
                }

                $item = dir_path("$cacheDir/$item");

                if (is_dir($item)) {
                    FileManager::deleteDirectory($item); // Delete directory
                } else {
                    FileManager::delete($item); // Delete file
                }
            }
        } else {
            Prompt::message("Cache directory does not exist.", "warning");
        }

        Prompt::message("All cache contents cleared.", "success");
    }

    /**
     * Generates a new application key and updates the environment file.
     *
     * This method generates a random application key and replaces the placeholder
     * in the environment file with the new key. It ensures the environment file is 
     * writable before making any changes. If the file is not writable, a warning message
     * is displayed. Once the key is updated, a success message is shown in the console.
     * 
     * @return void
     */
    public function generateAppKey()
    {
        $envFile = root_dir('env.php');

        if (!is_writable($envFile) && !chmod($envFile, 0666)) {
            Prompt::message("<danger>Error</danger> Environment file is not writable.", "warning");
            return;
        }

        $envFileContent = file_get_contents($envFile);
        $envFileContent = str_replace(
            '{APP_KEY}',
            bin2hex(random_bytes(16)),
            $envFileContent
        );

        file_put_contents($envFile, $envFileContent);

        Prompt::message("<info>Info</info> Application key has been updated.");
    }

    /**
     * Creates a symbolic link for the uploads directory.
     *
     * This method creates a symbolic link from the storage uploads directory
     * to the public uploads directory. It first checks if the symbolic link
     * or directory already exists. If it does, an informational message is displayed.
     * If not, it attempts to create the symbolic link and provides feedback
     * on whether the operation was successful or not.
     *
     * @return void
     */
    public function createSymbolicLinkForUploads()
    {
        $storageUploadsDir = storage_dir('uploads');
        $publicUploadsDir = root_dir('public/uploads');

        // Check if the symbolic link already exists
        if (FileManager::isLink($publicUploadsDir)) {
            Prompt::message("The symbolic link is already exists.", "warning");
            return;
        }

        // Attempt to create the symbolic link
        if (!FileManager::link($storageUploadsDir, $publicUploadsDir)) {
            Prompt::message("Failed to create symbolic link. Please check permissions and paths.", 'danger');
        }

        Prompt::message("<info>Success</info> Symbolic link created successfully: $storageUploadsDir → $publicUploadsDir");
    }
}
