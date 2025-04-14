<?php

namespace Spark\Console;

use Spark\Console\Contracts\ConsoleContract;
use Spark\Console\Exeptions\NotAllowedException;
use Spark\Foundation\Application;

class Console implements ConsoleContract
{
    /**
     * Constructs a new instance of the Console class.
     *
     * @param Prompt $prompt
     *   The prompt instance used for displaying messages.
     * @param Commands $commands
     *   The commands instance containing all the registered commands.
     */
    public function __construct(private Prompt $prompt, private Commands $commands)
    {
    }

    /**
     * Runs the console application.
     *
     * This method is called when the console application is started. It is
     * used to initialize the console application and execute the commands.
     *
     * If the application is not running in CLI mode, this method does nothing.
     *
     * @return void
     */
    public function run(): void
    {
        // Check if the application is running in CLI mode
        if (php_sapi_name() !== 'cli') {
            throw new NotAllowedException('This application is not running in CLI mode.');
        }

        global $argv;

        // Retrieve the command name from the command line arguments
        $commandName = $argv[1] ?? null;

        // Get the command line arguments
        $rawArgs = array_slice($argv, 2);

        // Parse the arguments
        $parsedArgs = $this->parseArguments($rawArgs);

        $this->prompt->newline(); // Add a newline

        if (!$commandName) {
            // If no command name is provided, list all available commands
            $this->prompt->message("Available commands:", "info");
            $this->commands->listCommands($this->prompt);
            return;
        }

        // Check if the command is registered
        if ($this->commands->hasCommand($commandName) === false) {
            // If the command is not found, show an error message and list available commands
            $this->prompt->message("Command '{$commandName}' not found.", "danger");
            $this->commands->listCommands($this->prompt);
            return;
        }

        // Execute the specified command
        $this->executeCommand($commandName, $parsedArgs);
    }

    /**
     * Executes a command based on the command name.
     * 
     * This method gets the command instance from the Commands class and executes it
     * with the parsed arguments using the Application::call() method.
     * 
     * @param string $name
     *   The name of the command to execute.
     * 
     * @param array $args
     *   The parsed arguments for the command.
     *   
     * @return void
     */
    public function executeCommand(string $name, array $args): void
    {
        // Get the command instance
        $command = $this->commands->getCommand($name);

        // Execute the command
        Application::$app->container->call($command['callback'], ['args' => $args]);
    }


    /**
     * Parses command line arguments into an associative array.
     *
     * This method takes an array of command line arguments and converts them
     * into a structured associative array, where long options (e.g., --option)
     * and short options (e.g., -o) are separated from positional arguments.
     *
     * @param array $args
     *   The command line arguments to parse.
     *
     * @return array
     *   An associative array containing parsed command line arguments.
     */
    public function parseArguments(array $args): array
    {
        $parsed = ['_args' => []]; // Initialize parsed arguments with positional arguments array
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                // Long option detected, split by '=' to separate key and value
                $parts = explode('=', substr($arg, 2), 2);
                $parsed[$parts[0]] = $parts[1] ?? true; // Default value to true if no value is provided
            } elseif (str_starts_with($arg, '-')) {
                // Short option detected, default value to true
                $parsed[substr($arg, 1)] = true;
            } else {
                // Positional argument detected, add to '_args' array
                $parsed['_args'][] = $arg;
            }
        }

        return $parsed;
    }
}