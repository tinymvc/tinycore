<?php

namespace Spark\Foundation\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spark\Cache\Cache;
use Spark\Cache\CacheManager;
use Spark\Console\Commands;
use Spark\Console\Process;
use Spark\Console\Prompt;
use Spark\Foundation\Application;
use Spark\Queue\QueueManager;
use Spark\Http\Routing\Router;
use Spark\Utils\Configuration;
use Spark\Utils\DotEnv;
use Spark\Utils\File;
use function count;
use function get_class;
use function in_array;
use function is_array;
use function is_object;
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
        Prompt::comment(
            sprintf('  Press %s+C to stop the server.', windows_os() ? 'Ctrl' : '⌘'),
        );
        Prompt::newline();

        // Use the current PHP binary to avoid shell wrapper quirks on Windows.
        $process = Process::command([PHP_BINARY, '-S', "$host:$port", '-t', 'public'])
            ->path(root_dir())
            ->forever()
            ->tty()
            ->execute();

        return $process->exitCode() ?? 0;
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
     * @param Router $router
     *   An instance of the Router class containing the registered routes.
     * @param array $args
     *   Optional filters (--method=, --path=, --name=).
     */
    public function routeList(Router $router, array $args = []): void
    {
        $routes = $router->getRoutes();
        if ($routes === []) {
            Prompt::info('No routes are registered.');
            return;
        }

        $methodFilter = isset($args['method']) && is_string($args['method']) ? strtoupper(trim($args['method'])) : null;
        $pathFilter = isset($args['path']) && is_string($args['path']) ? trim($args['path']) : null;
        $nameFilter = isset($args['name']) && is_string($args['name']) ? trim($args['name']) : null;

        $rows = [];
        foreach ($routes as $name => $route) {
            $methods = array_map('strtoupper', (array) ($route['method'] ?? ['GET']));
            $path = (string) ($route['path'] ?? '/');
            $routeName = is_string($name) ? $name : '';

            if ($methodFilter !== null && $methodFilter !== '' && !in_array($methodFilter, $methods, true) && !in_array('*', $methods, true)) {
                continue;
            }
            if ($pathFilter !== null && $pathFilter !== '' && !str_contains(strtolower($path), strtolower($pathFilter))) {
                continue;
            }
            if ($nameFilter !== null && $nameFilter !== '' && !str_contains(strtolower($routeName), strtolower($nameFilter))) {
                continue;
            }

            $rows[] = [
                implode('|', $methods),
                $path,
                $routeName,
                $this->formatRouteAction($route),
                $this->formatRouteMiddleware($route),
            ];
        }

        if ($rows === []) {
            Prompt::info('No routes match the given filters.');
            return;
        }

        Prompt::table(['Method', 'URI', 'Name', 'Action', 'Middleware'], $rows);
        Prompt::comment('  Showing ' . count($rows) . ' routes.');
    }

    private function formatRouteAction(array $route): string
    {
        if (!empty($route['template'])) {
            return 'view:' . $route['template'];
        }

        $callback = $route['callback'] ?? null;
        if (is_array($callback) && count($callback) >= 2) {
            $target = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
            return "$target@$callback[1]";
        }

        if ($callback instanceof \Closure) {
            return 'Closure';
        }

        if (is_string($callback)) {
            return $callback;
        }

        if (is_object($callback)) {
            return get_class($callback) . '@__invoke';
        }

        return '-';
    }

    private function formatRouteMiddleware(array $route): string
    {
        $middlewares = array_map(
            static fn($item): string => is_string($item) ? $item : (is_object($item) ? get_class($item) : 'Closure'),
            (array) ($route['middleware'] ?? [])
        );

        return implode(', ', $middlewares);
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
     * @return int Exit status code.
     */
    public function handleHelp(Commands $commands, array $args): int
    {
        $commandName = $args['_args'][0] ?? null;

        if ($commandName !== null && $commandName !== '') {
            if ($commands->hasCommand($commandName)) {
                $commands->showCommandHelp($commandName);
                return 0;
            }

            Prompt::message("Command '{$commandName}' not found.", 'danger');
            return 1;
        }

        Prompt::section('Available commands');
        $commands->listCommands();
        Prompt::newline();
        Prompt::comment("  Use 'help <command>' or '<command> --help' to view details for a specific command.");

        return 0;
    }

    /**
     * Handles the "queue:work" command by processing pending jobs.
     *
     * @param QueueManager $queue
     *   The queue connection manager for running queue jobs.
     *  @param array $args
     *   An associative array of arguments, where the maximum number of jobs to run
     *
     * @return void
     */
    public function runQueueJobs(QueueManager $queue, array $args)
    {
        $queue->connection($args['connection'] ?? null)->work(
            once: isset($args['once']),
            timeout: (int) ($args['timeout'] ?? 3600),
            sleep: (int) ($args['sleep'] ?? 3),
            delay: (int) ($args['delay'] ?? 5),
            tries: (int) ($args['tries'] ?? 3),
            queue: $args['queue'] ?? 'default',
        ); // Run all pending queue jobs
    }

    public function restartQueueWorkers(QueueManager $queue): void
    {
        $queue->restart();
        Prompt::success('Queue restart requested. Workers will exit after their current job; a process supervisor must restart them.');
    }

    /**
     * Lists all scheduled queue jobs.
     *
     * @param QueueManager $queue
     *   The queue connection manager for managing queue jobs.
     *
     * @return void
     */
    public function listQueueJobs(QueueManager $queue, array $args)
    {
        $jobs = $queue->connection($args['connection'] ?? null)->getJobs(
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
     * @param QueueManager $queue
     *   The queue connection manager for managing queue jobs.
     * 
     * @return void
     */
    public function listFailedQueueJobs(QueueManager $queue, array $args)
    {
        $failedJobs = $queue->connection($args['connection'] ?? null)->getFailedJobs(
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
     * Clears pending, failed, or named queue jobs.
     *
     * @param QueueManager $queue
     *   The queue connection manager for clearing queue jobs.
     * @param array $args
     *
     * @return void
     */
    public function clearQueueJobs(QueueManager $queue, array $args = [])
    {
        $connection = $queue->connection($args['connection'] ?? null);
        $failed = isset($args['failed']) && in_array($args['failed'], [true, 'true', 1, '1', 'yes', ''], true);
        $queueName = isset($args['queue']) && is_string($args['queue']) ? trim($args['queue']) : null;

        if ($failed) {
            $connection->clearFailedJobs();
            Prompt::message('Failed queue jobs cleared.', 'success');
            return;
        }

        if ($queueName !== null && $queueName !== '') {
            $removed = $connection->removeQueue($queueName);
            Prompt::message(
                $removed ? "Queue [{$queueName}] cleared." : "No jobs found in queue [{$queueName}].",
                $removed ? 'success' : 'info'
            );
            return;
        }

        $connection->clearAllJobs();
        Prompt::message("Queue jobs cleared.", "success");
    }

    /**
     * Delete a single queue job by ID.
     */
    public function forgetQueueJob(QueueManager $queue, array $args = []): int
    {
        $id = $args['id'] ?? $args['_args'][0] ?? null;

        if (!is_string($id) || trim($id) === '') {
            Prompt::error('Please specify a job ID to delete.');
            return 1;
        }

        $id = trim($id);
        $removed = $queue->connection($args['connection'] ?? null)->removeJobById($id);

        if (!$removed) {
            Prompt::warning("Queue job [{$id}] was not found.");
            return 1;
        }

        Prompt::success("Queue job [{$id}] deleted.");
        return 0;
    }

    /**
     * Retries failed queue jobs.
     *
     * @param QueueManager $queue
     *   The queue connection manager for managing queue jobs.
     * 
     * @return void
     */
    public function retryFailedQueueJobs(QueueManager $queue, array $args = [])
    {
        $id = $args['id'] ?? $args['_args'][0] ?? null;
        if ($id === 'all') {
            $id = null;
        }

        $queue->connection($args['connection'] ?? null)->retryFailedJobs($id);

        if ($id !== null) {
            Prompt::message("Failed queue job [$id] retried.", "success");
        } else {
            Prompt::message("Failed queue jobs retried.", "success");
        }
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

    /** Clear cache entries, or the default cache and compiled views/configuration without releasing active locks. */
    public function clearCache(array $args = []): void
    {
        $hasStore = isset($args['store']) && is_string($args['store']) && trim($args['store']) !== '';
        $hasNamespace = isset($args['namespace']) && is_string($args['namespace']) && trim($args['namespace']) !== '';

        if ($hasStore || $hasNamespace) {
            $store = $hasStore ? trim($args['store']) : null;
            $namespace = $hasNamespace ? trim($args['namespace']) : 'default';

            app(CacheManager::class)
                ->repository($store, $namespace)
                ->flush();

            Prompt::success("Cache cleared for namespace [{$namespace}].");
            return;
        }

        Cache::make()->flush();

        $this->clearViewCaches();
        $this->clearConfigCache();

        Prompt::message('Default cache, compiled views and configuration cleared.', 'success');
    }

    /** Remove expired cache entries and expired locks for a store/namespace. */
    public function pruneCache(array $args = []): void
    {
        $store = isset($args['store']) && is_string($args['store']) && trim($args['store']) !== ''
            ? trim($args['store'])
            : null;
        $namespace = isset($args['namespace']) && is_string($args['namespace']) && trim($args['namespace']) !== ''
            ? trim($args['namespace'])
            : 'default';

        $result = app(CacheManager::class)->repository($store, $namespace)->prune();

        Prompt::success("Pruned {$result['entries']} expired cache entries and {$result['locks']} expired locks.");
    }

    /** Pre-compile .env and configuration files into bootstrap/cache/. */
    public function cacheConfig(): int
    {
        foreach ([root_dir('bootstrap/cache/config.php'), root_dir('bootstrap/cache/env.php')] as $cacheFile) {
            is_file($cacheFile) && unlink($cacheFile);
        }

        if (is_file(root_dir('.env'))) {
            DotEnv::bootstrap(root_dir());
        }

        $configDir = root_dir('config');
        if (is_dir($configDir)) {
            $compiled = Configuration::discover(
                folder: $configDir,
                cache: root_dir('bootstrap/cache/config.php'),
                env: root_dir('.env'),
                useCache: true,
            );
            Application::$app?->mergeConfig($compiled);
        }

        Prompt::success('Configuration cached successfully.');
        return 0;
    }

    /** Pre-compile all Blade templates in configured view directories. */
    public function cacheViews(): int
    {
        $blade = app(\Spark\View\Blade::class);
        $compiler = $blade->getCompiler();
        $paths = ['' => $blade->getPath(), ...$blade->getUsePath()];
        $compiled = 0;

        foreach ($paths as $namespace => $directory) {
            if (!is_string($directory) || !is_dir($directory)) {
                continue;
            }

            $base = rtrim($directory, '/\\');
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($base))), '/');
                $viewName = substr($relative, 0, -strlen('.blade.php'));
                $template = str_starts_with($viewName, 'components/')
                    ? $viewName
                    : str_replace('/', '.', $viewName);

                if (is_string($namespace) && $namespace !== '') {
                    $template = "{$namespace}::{$template}";
                }

                $compiler->compile($file->getPathname(), $compiler->getCompiledPath($template));
                $compiled++;
            }
        }

        Prompt::success("Compiled {$compiled} view template(s).");
        return 0;
    }

    /** Cache discovered package services, configuration, and views. */
    public function optimize(): void
    {
        $this->discoverServices();
        $this->cacheConfig();
        $this->cacheViews();

        Prompt::success('Application optimized successfully.');
    }

    /** Remove all compiled bootstrap and view cache files. */
    public function clearOptimization(): void
    {
        $this->clearConfigCache();
        $this->clearViewCaches();

        $servicesCache = root_dir('bootstrap/cache/services.php');
        if (is_file($servicesCache)) {
            unlink($servicesCache);
        }

        Prompt::success('Cached configuration, views, and discovered services cleared.');
    }

    /** Display concise framework and environment diagnostics. */
    public function about(): void
    {
        $configCached = is_file(root_dir('bootstrap/cache/config.php'));
        $envCached = is_file(root_dir('bootstrap/cache/env.php'));
        $servicesCached = is_file(root_dir('bootstrap/cache/services.php'));

        Prompt::section('Environment');
        Prompt::definitionList([
            'Application Name' => (string) config('app.name', 'Spark'),
            'TinyCore Version' => Application::VERSION,
            'PHP Version' => PHP_VERSION,
            'Environment' => (string) app_environment(),
            'Debug Mode' => is_debug_mode() ? '<warning>ENABLED</warning>' : 'DISABLED',
            'URL' => (string) config('app.url', ''),
        ]);

        Prompt::newline();
        Prompt::section('Cache & Drivers');
        Prompt::definitionList([
            'Config Cache' => $configCached ? '<success>CACHED</success>' : '<comment>NOT CACHED</comment>',
            'Env Cache' => $envCached ? '<success>CACHED</success>' : '<comment>NOT CACHED</comment>',
            'Services Cache' => $servicesCached ? '<success>CACHED</success>' : '<comment>NOT CACHED</comment>',
            'Database Driver' => (string) config('database.default', config('database.driver', 'mysql')),
            'Cache Store' => (string) config('cache.default', config('cache.store', config('cache.driver', 'database'))),
            'Queue Connection' => (string) config('queue.default', config('queue.driver', 'database')),
            'Session Driver' => (string) config('session.default', config('session.handler', config('session.driver', 'file'))),
        ]);
    }

    /** Generate an application key when missing, or rotate with --force, or print with --show. */
    public function generateAppKey(array $args = [])
    {
        $appKey = bin2hex(random_bytes(16));

        if (!empty($args['show'])) {
            Prompt::line($appKey);
            return;
        }

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
        $force = filter_var($args['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$force && preg_match($pattern, $envFileContent, $match)) {
            $existing = trim($match[1], " \t\"'");

            if ($existing !== '' && strlen($existing) === 32) {
                Prompt::message('An application key already exists; it was not changed.', 'info');

                return;
            }
        }

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

    /**
     * Handles the "vendor:publish" command.
     *
     * @param array $args The arguments passed to the command.
     * @param PublishManager $publisher The vendor publisher instance.
     * @return void
     * @throws \InvalidArgumentException
     */
    public function vendorPublish(array $args, PublishManager $publisher): void
    {
        $rawProvider = $args['provider'] ?? null;
        $rawTag = $args['tag'] ?? null;

        if (
            ($rawProvider !== null && (!is_string($rawProvider) || trim($rawProvider) === ''))
            || ($rawTag !== null && (!is_string($rawTag) || trim($rawTag) === ''))
        ) {
            throw new \InvalidArgumentException('--provider and --tag require a value.');
        }

        $provider = $rawProvider !== null ? $publisher->normalizeProvider($rawProvider) : null;
        $tag = $rawTag !== null ? trim($rawTag, " \t\n\r\0\x0B\"'") : null;

        if ($provider === '' || $tag === '') {
            throw new \InvalidArgumentException('--provider and --tag require a value.');
        }

        if (!empty($args['_args']) || array_diff(array_keys($args), ['_args', 'provider', 'tag', 'force', 'all'])) {
            throw new \InvalidArgumentException('Use --tag=name, --provider=class, or --all to select resources.');
        }

        foreach (['all', 'force'] as $flag) {
            if (isset($args[$flag]) && $args[$flag] !== true) {
                throw new \InvalidArgumentException("--$flag does not take a value.");
            }
        }

        if (isset($args['all']) && ($provider !== null || $tag !== null)) {
            throw new \InvalidArgumentException('Use --all alone or select resources by provider and tag.');
        }

        if ($provider === null && $tag === null && !isset($args['all'])) {
            $rows = [];
            foreach ($publisher->groups() as $class => $tags) {
                $rows[] = [$class, implode(', ', $tags)];
            }

            if ($rows === []) {
                Prompt::info('No publishable resources are registered.');
                return;
            }

            Prompt::table(['Provider', 'Tags'], $rows);
            Prompt::info('Select --tag=name, --provider=class, or --all to publish.');
            return;
        }

        $publisher->publish($provider, $tag, isset($args['force']));
    }

    /**
     * Discovers and caches package service providers from installed composer packages.
     *
     * @return void
     */
    public function discoverServices(): void
    {
        $installedFile = base_path('vendor/composer/installed.json');

        if (!is_file($installedFile) || !is_readable($installedFile)) {
            Prompt::warning('Composer installed.json file not found. Run composer install first.');
            return;
        }

        $packages = json_decode(file_get_contents($installedFile), true);

        if (!is_array($packages)) {
            Prompt::error('Failed to parse vendor/composer/installed.json.');
            return;
        }

        $packageList = $packages['packages'] ?? $packages;
        $discoveredProviders = [];
        $discoveredPackages = [];

        foreach ($packageList as $package) {
            $providers = $package['extra']['spark']['providers'] ?? $package['extra']['tinymvc']['providers'] ?? [];

            if (!empty($providers)) {
                $packageName = $package['name'] ?? 'unknown';
                $discoveredPackages[$packageName] = (array) $providers;

                foreach ((array) $providers as $provider) {
                    if (is_string($provider) && trim($provider) !== '') {
                        $discoveredProviders[] = trim($provider);
                    }
                }
            }
        }

        $discoveredProviders = array_values(array_unique($discoveredProviders));

        $cacheDir = root_dir('bootstrap/cache');
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cacheFile = dir_path("$cacheDir/services.php");
        $lines = array_map(static fn($provider) => '    ' . var_export($provider, true) . ',', $discoveredProviders);
        $content = "<?php return array(\n" . implode("\n", $lines) . ($lines !== [] ? "\n" : "") . ");\n";

        if (file_put_contents($cacheFile, $content, LOCK_EX) === false) {
            Prompt::error('Failed to write cached services file.');
            return;
        }

        foreach ($discoveredPackages as $packageName => $providers) {
            Prompt::comment("Discovered Package: <info>{$packageName}</info>");
        }

        Prompt::success('Package services discovered and cached successfully.');
    }
}
