<?php

namespace Spark\Console\Contracts;

/**
 * This interface defines the contract for the Commands class.
 */
interface CommandsContract
{
    /**
     * Adds a new command to the Commands instance.
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
    public function addCommand(string $name, string|array|callable $callback, string $description = ''): self;

    /**
     * Adds multiple commands to the Commands instance.
     *
     * @param array $commands
     *   An array of commands to add, where each command is an associative array
     *   containing the command's name, callback, and description.
     *
     * @return $this
     *   The Commands instance.
     */
    public function addCommands(array $commands): self;

    /**
     * Retrieves all registered commands.
     *
     * @return array
     *   An associative array of all registered commands, where the key is the
     *   command name and the value is an array containing the command's
     *   configuration (callback, description, etc.).
     */
    public function getAllCommands(): array;

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
    public function getCommand(string $name): array;

    /**
     * Checks if a command is registered in the Commands instance.
     *
     * @param string $name
     *   The name of the command to check.
     *
     * @return bool
     *   TRUE if the command is registered, FALSE otherwise.
     */
    public function hasCommand(string $name): bool;

    /**
     * Removes a command from the Commands instance.
     *
     * @param array|string $command
     *   The name of the command to remove, or an array of command names to remove.
     *
     * @return $this
     *   The Commands instance.
     */
    public function removeCommand(array|string $command): self;

    /**
     * Disables a command in the Commands instance.
     *
     * @param array|string $command
     *   The name of the command to disable, or an array of command names to disable.
     *
     * @return $this
     *   The Commands instance.
     */
    public function isDisabled(string $name): bool;

}
