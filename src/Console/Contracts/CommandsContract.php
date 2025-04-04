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
}
