<?php

namespace Spark\Foundation\Console;

use Spark\Console\Commands;
use Spark\Console\Prompt;
use Spark\Queue\Queue;
use Spark\Router;

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
     * @param Prompt $prompt
     *   An instance of the Prompt class for displaying messages in the console.
     * @param array $args
     *   An associative array of arguments, where the server port can be specified
     *   using the '_args' key.
     */
    public function startDevelopmentServer(Prompt $prompt, array $args)
    {
        $port = $args['_args'][0] ?? 8080;

        $prompt->message(
            sprintf(
                "<info>Info</info> Server running on <bold>[http://localhost:%s]</bold> at <bold>%s</bold>.",
                $port,
                date('Y-m-d H:i:s')
            )
        );
        $prompt->message("Press <bold>Ctrl+C</bold> to stop the server.", "warning");

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
     * @param Prompt $prompt
     *   An instance of the Prompt class for displaying messages in the console.
     * @param Router $router
     *   An instance of the Router class containing the registered routes.
     */
    public function routeList(Prompt $prompt, Router $router)
    {
        $maxLength = max(array_map('strlen', array_column($router->getRoutes(), 'path'))) + 4;
        $prompt->message("Available routes:", "info");

        foreach ($router->getRoutes() as $name => $route) {
            $spacing = str_repeat(' ', $maxLength - strlen($route['path']));
            $methods = implode(', ', (array) $route['method']);
            $name = is_string($name) ? " (<bold>{$name}</bold>)" : '';
            $prompt->message(
                "  URI: <success>{$route['path']}</success>{$spacing} [<info>{$methods}</info>]{$name}",
                'raw'
            );
        }
    }

    /**
     * Handles the "help" command by listing all registered commands or showing
     * help for a specific command if a command name is provided.
     *
     * @param Prompt $prompt
     *   An instance of the Prompt class for displaying messages in the console.
     * @param Commands $commands
     *   An instance of the Commands class containing the registered commands.
     * @param array $args
     *   An associative array of arguments, where the command name can be
     *   specified using the '_args' key.
     *
     * @return void
     */
    public function handleHelp(Prompt $prompt, Commands $commands, array $args)
    {
        $commandName = $args['_args'][0] ?? null;

        // If a command name is provided, show the help for that command.
        if ($commandName && $commands->hasCommand($commandName)) {
            $commands->showCommandHelp($prompt, $commandName);
        } else {
            // Otherwise, list all the registered commands.
            $prompt->message("Available commands:", "info");
            $commands->listCommands($prompt);
            $prompt->message("\nUse 'help <command>' to view details for a specific command.", "info");
        }
    }

    /**
     * Handles the "queue:run" command by running all the pending queue jobs.
     *
     * @param Prompt $prompt
     *   An instance of the Prompt class for displaying messages in the console.
     * @param Queue $queue
     *   An instance of the Queue class for running queue jobs.
     *
     * @return void
     */
    public function runQueueJobs(Prompt $prompt, Queue $queue)
    {
        $start = microtime(true);
        $prompt->message("Running queue jobs...", "info");

        $queue->run(); // Run all pending queue jobs

        $prompt->message(
            "Queue jobs completed in " . round(microtime(true) - $start, 2) . " seconds.",
            "success"
        );
    }

    /**
     * Clears all the pending jobs in the queue.
     *
     * @param Prompt $prompt
     *   An instance of the Prompt class for displaying messages in the console.
     * @param Queue $queue
     *   An instance of the Queue class for clearing queue jobs.
     * 
     * @return void
     */
    public function clearQueueJobs(Prompt $prompt, Queue $queue)
    {
        $queue->clearAllJobs();
        $prompt->message("Queue jobs cleared.", "success");
    }

    /**
     * Generates a new application key and updates the environment file.
     *
     * This method generates a random application key and replaces the placeholder
     * in the environment file with the new key. It ensures the environment file is 
     * writable before making any changes. If the file is not writable, a warning message
     * is displayed. Once the key is updated, a success message is shown in the console.
     *
     * @param Prompt $prompt
     *   An instance of the Prompt class for displaying messages in the console.
     * 
     * @return void
     */
    public function generateAppKey(Prompt $prompt)
    {
        $envFile = root_dir('env.php');

        if (!is_writable($envFile) && !chmod($envFile, 0666)) {
            $prompt->message("<danger>Error</danger> Environment file is not writable.", "warning");
            return;
        }

        $envFileContent = file_get_contents($envFile);
        $envFileContent = str_replace(
            '{APP_KEY}',
            bin2hex(random_bytes(16)),
            $envFileContent
        );

        file_put_contents($envFile, $envFileContent);

        $prompt->message("<info>Info</info> Application key has been updated.");
    }
}