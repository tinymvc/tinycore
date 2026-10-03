<?php

namespace Spark\Foundation\Console;

use Spark\Cache\Cache;
use Spark\Console\Commands;
use Spark\Console\Process;
use Spark\Console\Prompt;
use Spark\Queue\Queue;
use Spark\Http\Routing\Router;
use Spark\Utils\File;
use function count;
use function is_string;
use function strlen;

/**
 * Class PrimaryCommandsHandler
 * 
 * This class contains methods that are used to handle primary commands,
 * such as "serve" and "queue:run".
 */
class PrimaryCommandsHandler
{
    /** The default host to serve the development server on. */
    private const DEFAULT_SERVE_HOST = 'localhost';
    private const DEFAULT_SERVE_PORT = 8080;

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
        try {
            [$host, $port] = $this->resolveAddress($args['_args'] ?? []);
        } catch (\InvalidArgumentException $e) {
            Prompt::error($e->getMessage());
            return 1;
        }

        Prompt::info("Server running on [http://$host:$port].");
        Prompt::comment('  Press Ctrl+C to stop the server.');
        Prompt::newline();

        // Use the current PHP binary to avoid shell wrapper quirks on Windows.
        Process::command([PHP_BINARY, '-S', "localhost:$port", '-t', 'public'])
            ->path(root_dir())
            ->forever()
            ->tty()
            ->execute();
    }

    /**
     * Resolve [host, port] from CLI arguments.
     *
     *   (none)               => localhost:8080
     *   8000                 => localhost:8000
     *   example.test         => example.test:8080
     *   example.test 8000    => example.test:8000
     *   0.0.0.0:8000         => 0.0.0.0:8000
     *   [::1]:8000           => [::1]:8000
     *
     * @return array{0: string, 1: int}
     * @throws \InvalidArgumentException
     */
    private function resolveAddress(array $args): array
    {
        $first = trim((string) ($args[0] ?? ''));
        $second = trim((string) ($args[1] ?? ''));

        $host = self::DEFAULT_SERVE_HOST;
        $port = null;

        if ($first !== '') {
            if (ctype_digit($first)) {
                // "8000" => port only
                $port = $first;
            } elseif (preg_match('/^(\[[0-9a-f:.]+\]|[^:\s]+):(\d+)$/i', $first, $m)) {
                // "host:port" or "[::1]:port"
                [, $host, $port] = $m;
            } else {
                $host = $first;
            }
        }

        // An explicit second argument is used only if no port was found yet
        $port ??= ($second !== '' ? $second : self::DEFAULT_SERVE_PORT);

        $port = filter_var($port, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if ($port === false) {
            throw new \InvalidArgumentException('Port must be a number between 1 and 65535.');
        }

        // Bare IPv6 (e.g. "::1") needs brackets in a URL
        if (str_contains($host, ':') && $host[0] !== '[') {
            $host = "[$host]";
        }

        return [$host, $port];
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
        $rows = [];

        foreach ($router->getRoutes() as $name => $route) {
            $rows[] = [
                implode('|', (array) $route['method']),
                $route['path'],
                is_string($name) ? $name : '',
            ];
        }

        if ($rows === []) {
            Prompt::info('No routes are registered.');

            return;
        }

        Prompt::table(['Method', 'URI', 'Name'], $rows);
        Prompt::comment('  Showing ' . count($rows) . ' routes.');
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
            Prompt::section('Available commands');
            $commands->listCommands();
            Prompt::newline();
            Prompt::comment("  Use 'help <command>' to view details for a specific command.");
        }
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
            timeout: $args['timeout'] ?? 3580, // 59 minutes and 40 seconds
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

        $rows = [];

        foreach ($jobs as $job) {
            $rows[] = [
                $job->getId(),
                $job->getDisplayName(),
                $job->getQueueName(),
                $job->getScheduledTime()->toDateTimeString(),
                $job->isFailed() ? 'Failed' : ($job->getScheduledTime()->isPast() ? 'Ready' : 'Scheduled'),
                $job->isRepeated() ? 'Yes' : 'No',
            ];
        }

        if ($rows === []) {
            Prompt::info('No scheduled jobs found.');

            return;
        }

        Prompt::table(['ID', 'Job', 'Queue', 'Scheduled at', 'Status', 'Repeats'], $rows);
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

        $rows = [];

        foreach ($failedJobs as $job) {
            $failedAt = $job->getMetadata('failed_at');
            $rows[] = [
                $job->getId(),
                $job->getDisplayName(),
                $job->getQueueName(),
                $failedAt ? carbon($failedAt)->toDateTimeString() : '-',
                $job->getReasonFailed(),
            ];
        }

        if ($rows === []) {
            Prompt::info('No failed jobs found.');

            return;
        }

        Prompt::table(['ID', 'Job', 'Queue', 'Failed at', 'Reason'], $rows);
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
     * Clears the configuration cache.
     *
     * This method removes the cached configuration file, allowing the application
     * to load the latest configuration settings from the config directory.
     *
     * @return void
     */
    public function clearConfigCache()
    {
        $cacheFiles = [
            root_dir('bootstrap/cache/config.php'),
            root_dir('bootstrap/cache/env.php'),
        ];

        foreach ($cacheFiles as $cacheFile) {
            is_file($cacheFile) && unlink($cacheFile);
        }

        Prompt::message("Configuration cache cleared.", "success");
    }

    /** Clear the default cache and compiled views/configuration without releasing active locks. */
    public function clearCache(): void
    {
        Cache::make()->flush();
        $this->clearViewCaches();
        $this->clearConfigCache();

        Prompt::message('Default cache, compiled views and configuration cleared.', 'success');
    }

    /** Generate an application key only when the environment has no existing key. */
    public function generateAppKey()
    {
        $envFile = root_dir('.env');

        if (!is_file($envFile) || !is_readable($envFile) || !is_writable($envFile)) {
            throw new \RuntimeException('The environment file must exist and be readable and writable.');
        }

        $envFileContent = file_get_contents($envFile);

        if ($envFileContent === false) {
            throw new \RuntimeException('Unable to read the environment file.');
        }

        // [ \t] instead of \s so the match can never cross a line break
        $pattern = '/^APP_KEY[ \t]*=[ \t]*([^\r\n]*)/m';

        if (preg_match($pattern, $envFileContent, $match)) {
            $existing = trim($match[1], " \t\"'");

            if ($existing !== '' && strlen($existing) === 32) {
                Prompt::message('An application key already exists; it was not changed.', 'info');

                return;
            }
        }

        $appKey = bin2hex(random_bytes(16));
        $line = "APP_KEY=$appKey";

        $envFileContent = preg_match($pattern, $envFileContent)
            ? preg_replace($pattern, $line, $envFileContent, 1)
            : rtrim($envFileContent) . PHP_EOL . $line . PHP_EOL;

        if (file_put_contents($envFile, $envFileContent, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to save the application key.');
        }

        $caches = [
            root_dir('bootstrap/cache/env.php'),
            root_dir('bootstrap/cache/config.php'),
        ];

        foreach ($caches as $cache) {
            is_file($cache) && unlink($cache); // Delete cache files if they exist
        }

        envs(['APP_KEY' => $appKey]); // Update the env variable in runtime

        Prompt::success('Application key set successfully.');
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
        $storageUploadsDir = storage_dir('app/public');
        $publicUploadsDir = root_dir('public/uploads');

        // Check if the symbolic link already exists
        if (File::isLink($publicUploadsDir)) {
            Prompt::message("The [{$publicUploadsDir}] link already exists.", "warning");
            return;
        }

        if (file_exists($publicUploadsDir)) {
            Prompt::error("The [{$publicUploadsDir}] path already exists.");

            return;
        }

        // Attempt to create the symbolic link
        if (!File::link($storageUploadsDir, $publicUploadsDir)) {
            Prompt::error('Failed to create symbolic link. Please check permissions and paths.');

            return;
        }

        Prompt::success("The [{$publicUploadsDir}] link has been connected to [{$storageUploadsDir}].");
    }
}
