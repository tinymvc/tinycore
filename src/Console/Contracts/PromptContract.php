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
     * Print a plain line.
     *
     * @param string $message
     */
    public static function line(string $message = ''): void;

    /**
     * Print an info message.
     *
     * @param string $message
     */
    public static function info(string $message): void;

    /**
     * Print a success message.
     *
     * @param string $message
     */
    public static function success(string $message): void;

    /**
     * Print a warning message.
     *
     * @param string $message
     */
    public static function warning(string $message): void;

    /**
     * Print an error message.
     *
     * @param string $message
     */
    public static function error(string $message): void;

    /**
     * Print a muted comment message.
     *
     * @param string $message
     */
    public static function comment(string $message): void;

    /**
     * Print an alert message.
     *
     * @param string $message
     */
    public static function alert(string $message): void;

    /**
     * Print a formatted title.
     *
     * @param string $title
     */
    public static function title(string $title): void;

    /**
     * Print a section heading.
     *
     * @param string $title
     */
    public static function section(string $title): void;

    /**
     * Print a table in console output.
     *
     * @param array $headers
     * @param array $rows
     */
    public static function table(array $headers, array $rows): void;

    /**
     * Print a definition list.
     *
     * @param array $items
     */
    public static function definitionList(array $items): void;

    /**
     * Ask an option choice from an option list.
     *
     * @param string $question
     * @param array $options
     * @param mixed $default
     */
    public static function choice(string $question, array $options, mixed $default = null): mixed;

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
     * Show output using <warn> style as backward-compatible alias.
     *
     * @param string $message
     */
    public static function warn(string $message): void;

    /**
     * Parse CLI arguments.
     *
     * @param array $args
     * @return array
     */
    public static function parseArguments(array $args): array;

    /**
     * Print a specified number of newlines to the console.
     *
     * @param int $count
     *   The number of newlines to print. Defaults to 1.
     */
    public static function newline(int $count = 1): void;
}
