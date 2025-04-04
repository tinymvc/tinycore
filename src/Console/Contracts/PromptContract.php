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
    public function ask(string $question, string $default = ''): string;

    /**
     * Print a message with a colored style.
     *
     * @param string $message
     *   The message to print.
     * @param string $type
     *   The type of message. Supported types are "success", "danger", "warning", "info".
     *   If not provided, the message will be printed as normal text.
     */
    public function message(string $message, string $type = "normal"): void;
}
