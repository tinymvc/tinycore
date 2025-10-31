<?php

namespace Spark\Console;

use Spark\Console\Contracts\ConsoleContract;
use Spark\Console\Exceptions\NotAllowedException;
use Spark\Foundation\Application;
use Spark\Support\Traits\Macroable;

class Console implements ConsoleContract
{
    use Macroable;

    /**
     * Constructs a new instance of the Console class.
     *
     * @param Commands $commands
     *   The commands instance containing all the registered commands.
     */
    public function __construct(private Commands $commands)
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
        if (!is_cli()) {
            throw new NotAllowedException('This application is not running in CLI mode.');
        }

        // If application debug mode is disabled, stop further execution
        if (config('debug', false) !== true) {
            Prompt::message("Debug mode is disabled. No commands will be executed.", "warning");
            return;
        }

        global $argv;

        // Retrieve the command name from the command line arguments
        $commandName = $argv[1] ?? null;

        // Get the command line arguments
        $rawArgs = array_slice($argv, 2);

        // Parse the arguments
        $parsedArgs = Prompt::parseArguments($rawArgs);

        Prompt::newline(); // Add a newline

        if (!$commandName) {
            // If no command name is provided, list all available commands
            Prompt::message("Available commands:", "info");
            $this->commands->listCommands();
            return;
        }

        // Check if the command is registered
        if ($this->commands->hasCommand($commandName) === false) {
            // If the command is not found, show an error message and list available commands
            Prompt::message("Command '{$commandName}' not found.", "danger");
            $this->commands->listCommands();
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
        if ($this->commands->isDisabled($name)) {
            Prompt::message("Command '{$name}' is disabled.", "warning");
            return;
        }

        // Get the command instance
        $command = $this->commands->getCommand($name);

        // Execute the command
        Application::$app->resolve($command['callback'], ['args' => $args]);
    }
}