<?php

namespace Spark\Console\Contracts;

/**
 * Contract for interacting with the user.
 *
 * This interface provides a contract for a class that provides methods for
 * asking the user questions, printing colored messages, and reading user input.
 */
interface PromptContract
{
    /**
     * Ask the user a question and return their input.
     *
     * @param string $question
     *   The question to display to the user.
     * @param string $default
     *   The default answer if the user provides no input.
     *
     * @return string
     *   The user's input or the default value.
     */
    public static function ask(string $question, string $default = ''): string;

    /**
     * Print a message with a colored style.
     *
     * @param string $message
     *   The message to print.
     * @param string $type
     *   The type of message. Supported types are "success", "danger", "warning", "info".
     *   If not provided, the message will be printed as normal text.
     */
    public static function message(string $message, string $type = "normal"): void;

    /**
     * Ask the user a yes/no question and return their response as a boolean.
     *
     * @param string $question
     *   The question to display to the user.
     * @param bool $default
     *   The default answer if the user provides no input. Defaults to false (no).
     *
     * @return bool
     *   True if the user answered yes, false if they answered no.
     */
    public static function confirm(string $question, bool $default = false): bool;

    /**
     * Print a specified number of newlines to the console.
     *
     * @param int $count
     *   The number of newlines to print. Defaults to 1.
     */
    public static function newline(int $count = 1): void;
}
