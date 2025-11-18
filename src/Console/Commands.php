<?php

namespace Spark\Console;

use Spark\Console\Contracts\CommandsContract;
use Spark\Support\Traits\Macroable;
use function is_array;
use function strlen;

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

    /** @param array<string, bool> The commands are disabled to run */
    private static array $disabledCommands = [];

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
     * Registers multiple commands in the Commands instance.
     *
     * @param array $commands
     *   An associative array where the key is the command name and the value
     *   is an array containing the command's configuration (callback, description, etc.).
     *
     * @return $this
     *   The Commands instance.
     */
    public function addCommands(array $commands): self
    {
        foreach ($commands as $name => $config) {
            $this->commands[$name] = $config;
        }

        return $this;
    }

    /**
     * Retrieves all registered commands.
     *
     * @return array
     *   An associative array of all registered commands.
     */
    public function getAllCommands(): array
    {
        return $this->commands;
    }

    /**
     * Removes a command from the Commands instance.
     *
     * This method allows you to remove a command by its name.
     *
     * @param array|string ...$commands
     *   The name of the command to remove.
     *
     * @return $this
     *   The Commands instance.
     */
    public function removeCommand(array|string ...$commands): self
    {
        $commands = is_array($commands[0]) ? $commands[0] : $commands;

        foreach ($commands as $name) {
            unset($this->commands[$name]);
        }

        return $this;
    }

    /**
     * Disables a command by adding it to the disabled commands list.
     *
     * This method allows you to disable a command by its name, preventing it
     * from being executed.
     *
     * @param array|string ...$commands
     *   The name(s) of the command(s) to disable.
     *
     * @return $this
     *   The Commands instance.
     */
    public static function disable(array|string ...$commands): void
    {
        $commands = is_array($commands[0]) ? $commands[0] : $commands;

        foreach ($commands as $name) {
            self::$disabledCommands[$name] = true;
        }
    }

    /**
     * Check if the specific command is disabled.
     * 
     * This method checks if a command is disabled by looking it up in the
     * static::$disabledCommands array.
     * 
     * @param string $name
     *   The name of the command to check.
     * @return bool
     */
    public function isDisabled(string $name): bool
    {
        return isset(self::$disabledCommands[$name]) && self::$disabledCommands[$name];
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
     * @param string $commandName
     *   The name of the command to show help for.
     *
     * @return void
     */
    public function showCommandHelp(string $commandName): void
    {
        $command = $this->commands[$commandName];

        Prompt::message("Command: {$commandName}", "success");
        Prompt::message("Description: " . $command['description'], "info");

        if (isset($command['help'])) {
            Prompt::message("<bold>Help:</bold> " . $command['help'], "raw");
        }
    }

    /**
     * Lists all the registered commands.
     *
     * This method iterates through the available commands and displays them
     * with their descriptions in a formatted list.
     *
     * @return void
     */
    public function listCommands(): void
    {
        $maxLength = max(array_map('strlen', array_keys($this->commands))) + 4;

        ksort($this->commands);

        foreach ($this->commands as $name => $config) {
            $description = $config['description'] ?? 'No description';
            $spacing = str_repeat(' ', $maxLength - strlen($name));

            // Check if the command is disabled and set the appropriate message
            $isDisabled = $this->isDisabled($name) ? ' <warning>(disabled)</warning>' : '';

            Prompt::message(
                // Format the command name and description using ANSI escape
                // sequences for color and bold formatting.
                "  <success>{$name}</success>{$spacing}{$description}{$isDisabled}",
                'raw'
            );
        }
    }
}