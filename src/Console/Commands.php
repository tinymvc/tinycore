<?php

namespace Spark\Console;

use Spark\Console\Contracts\CommandsContract;
use Spark\Support\Traits\Macroable;

/**
 * Class Commands
 *
 * A class that contains all the commands in the console application.
 *
 * This class is used to register commands, retrieve commands, and
 * execute commands.
 *
 * @package Spark\Console
 */
class Commands implements CommandsContract
{
    use Macroable;

    /**
     * Constructs a new instance of the Commands class.
     *
     * @param array $commands
     *   An array of commands to register.
     */
    public function __construct(private array $commands = [])
    {
    }

    /**
     * Registers a new command in the Commands instance.
     *
     * @param string $name
     *   The name of the command.
     * @param string|array|callable $callback
     *   The callback to call when the command is executed.
     * @param string $description
     *   The description of the command.
     *
     * @return $this
     *   The Commands instance.
     */
    public function addCommand(string $name, string|array|callable $callback, string $description = ''): self
    {
        $this->commands[$name] = ['callback' => $callback, 'description' => $description];

        return $this;
    }

    /**
     * Sets the description for the most recently added command.
     *
     * This method updates the description of the last command added
     * to the commands array in the Commands instance.
     *
     * @param string $description
     *   The description to set for the command.
     *
     * @return $this
     *   The Commands instance.
     */
    public function description(string $description): self
    {
        $this->commands[array_key_last($this->commands)]['description'] = $description;

        return $this;
    }

    /**
     * Sets the help message for the most recently added command.
     *
     * This method updates the help information for the last command added
     * to the commands array in the Commands instance.
     *
     * @param string $help
     *   The help message to set for the command.
     *
     * @return $this
     *   The Commands instance.
     */
    public function help(string $help): self
    {
        $this->commands[array_key_last($this->commands)]['help'] = $help;

        return $this;
    }

    /**
     * Checks if a command is registered in the Commands instance.
     *
     * @param string $name
     *   The name of the command to check.
     *
     * @return bool
     *   TRUE if the command is registered, FALSE otherwise.
     */
    public function hasCommand(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Retrieves a command's configuration by its name.
     *
     * @param string $name
     *   The name of the command to retrieve.
     *
     * @return array
     *   An associative array containing the command's configuration, including
     *   its callback and description.
     */
    public function getCommand(string $name): array
    {
        return $this->commands[$name];
    }

    /**
     * Shows the help for a given command.
     *
     * @param Prompt $prompt
     *   The prompt instance to use for displaying messages.
     * @param string $commandName
     *   The name of the command to show help for.
     *
     * @return void
     */
    public function showCommandHelp(Prompt $prompt, string $commandName): void
    {
        $command = $this->commands[$commandName];

        $prompt->message("Command: {$commandName}", "success");
        $prompt->message("Description: " . $command['description'], "info");

        if (isset($command['help'])) {
            $prompt->message("<bold>Help:</bold> " . $command['help'], "raw");
        }
    }

    /**
     * Lists all the registered commands.
     *
     * This method iterates through the available commands and displays them
     * with their descriptions in a formatted list.
     *
     * @param Prompt $prompt
     *   The prompt instance to use for displaying messages.
     *
     * @return void
     */
    public function listCommands(Prompt $prompt): void
    {
        $maxLength = max(array_map('strlen', array_keys($this->commands))) + 4;

        ksort($this->commands);

        foreach ($this->commands as $name => $config) {
            $description = $config['description'] ?? 'No description';
            $spacing = str_repeat(' ', $maxLength - strlen($name));

            $prompt->message(
                // Format the command name and description using ANSI escape
                // sequences for color and bold formatting.
                "  <success>{$name}</success>{$spacing}{$description}",
                'raw'
            );
        }
    }
}