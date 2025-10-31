<?php

namespace Spark\Console;

use Spark\Console\Contracts\PromptContract;
use Spark\Support\Traits\Macroable;

/**
 * Provides functionality for interacting with the user.
 *
 * This class provides methods for asking the user questions, printing
 * colored messages, and reading user input.
 * 
 * @package Spark\Console
 */
class Prompt implements PromptContract
{
    use Macroable;

    /**
     * Prompts the user with a question and returns their input.
     *
     * @param string $question
     *   The question to display to the user.
     * @param string $default
     *   The default answer if the user provides no input.
     *
     * @return string
     *   The user's input or the default value.
     */
    public static function ask(string $question, string $default = ''): string
    {
        // Append default value to the question if provided
        $defaultText = $default ? " [{$default}]" : '';

        // Display the question to the user with a blue color
        echo "\033[34m{$question}{$defaultText}:\033[0m ";

        // Read and trim the user's input from standard input
        $input = trim(fgets(STDIN));

        // Return the user's input or default value if input is empty
        return $input ?: $default;
    }

    /**
     * Prints a message with a colored style.
     *
     * @param string $message
     *   The message to print.
     * @param string $type
     *   The type of message. Supported types are "success", "danger", "warning", "info".
     *   If not provided, the message will be printed as normal text.
     */
    public static function message(string $message, string $type = "normal"): void
    {
        // Color codes
        $styles = [
            'success' => "\033[32m",
            'danger' => "\033[31m",
            'warning' => "\033[33m",
            'info' => "\033[36m",
            'raw' => "",
            'bold' => "\033[1m",
        ];

        // Replace color codes with escape sequences
        foreach ($styles as $tag => $code) {
            // Replace opening tags with color
            $message = str_replace("<{$tag}>", $code, $message); // Set color

            // Replace closing tags with reset (0m)
            // For bold, we need to reset bold specifically (22m) to avoid resetting colors
            $resetCode = $tag === 'bold' ? "\033[22m" : "\033[0m";
            $message = str_replace("</{$tag}>", $resetCode, $message); // Reset color
        }

        // Add color and reset to the message
        $color = $styles[$type] ?? '';
        $reset = $color ? "\033[0m" : '';

        echo $color . $message . $reset . PHP_EOL;
    }

    /**
     * Confirm a question with a yes/no answer.
     *
     * @param string $question
     *   The question to confirm.
     * @param bool $default
     *   The default answer (if user just hits enter).
     *   If true (default), the default answer is "Yes".
     *   If false, the default answer is "No".
     *
     * @return bool
     *   The answer to the question (true for yes, false for no).
     */
    public static function confirm(string $question, bool $default = false): bool
    {
        $defaultText = $default ? 'Y/n' : 'y/N';

        $response = self::ask("{$question} ({$defaultText})", $default ? 'Y' : 'N');

        return strtolower($response) === 'y';
    }

    /**
     * Print a newline character a specified number of times.
     *
     * @param int $count
     *   The number of times to print a newline character.
     *   If not provided, it defaults to 1.
     */
    public static function newline(int $count = 1): void
    {
        echo str_repeat(PHP_EOL, $count);
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
    public static function parseArguments(array $args): array
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