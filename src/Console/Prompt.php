<?php

namespace Spark\Console;

use Spark\Console\Contracts\PromptContract;
use Spark\Support\Traits\Macroable;
use function array_key_exists;
use function array_key_first;
use function array_pad;
use function count;
use function implode;
use function is_array;
use function is_scalar;
use function is_string;
use function max;
use function str_replace;
use function strlen;
use function str_starts_with;
use function str_split;

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

    /** @var array<string,string> ANSI color map for message styles. */
    private const STYLES = [
        'comment' => "\033[90m",
        'success' => "\033[32m",
        'danger' => "\033[31m",
        'warning' => "\033[33m",
        'info' => "\033[36m",
        'raw' => "",
        'bold' => "\033[1m",
    ];

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
        // Append default value to the question if provided.
        $defaultText = $default ? " [{$default}]" : '';

        // Display the question to the user with a blue color.
        echo "\033[34m{$question}{$defaultText}:\033[0m ";

        // Read and trim the user's input from standard input.
        $input = fgets(STDIN);
        if (!is_string($input)) {
            return $default;
        }

        $input = trim($input);

        // Return the user's input or default value if input is empty.
        return $input !== '' ? $input : $default;
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
    public static function message(string $message, string $type = 'normal'): void
    {
        $message = self::replaceTags($message);

        $color = self::STYLES[$type] ?? '';
        $reset = $color ? "\033[0m" : '';

        echo "$color$message$reset" . PHP_EOL;
    }

    /**
     * Prints a plain line.
     *
     * @param string $message
     */
    public static function line(string $message = ''): void
    {
        self::message($message);
    }

    /**
     * Prints an informational message.
     *
     * @param string $message
     */
    public static function info(string $message): void
    {
        self::message($message, 'info');
    }

    /**
     * Prints a success message.
     *
     * @param string $message
     */
    public static function success(string $message): void
    {
        self::message($message, 'success');
    }

    /**
     * Prints a warning message.
     *
     * @param string $message
     */
    public static function warning(string $message): void
    {
        self::message($message, 'warning');
    }

    /**
     * Prints a warning message.
     *
     * @param string $message
     * @deprecated Use warning() instead.
     */
    public static function warn(string $message): void
    {
        self::warning($message);
    }

    /**
     * Prints an error message.
     *
     * @param string $message
     */
    public static function error(string $message): void
    {
        self::message($message, 'danger');
    }

    /**
     * Prints a muted comment line.
     *
     * @param string $message
     */
    public static function comment(string $message): void
    {
        self::message($message, 'comment');
    }

    /**
     * Prints an alert-style message with spacing.
     *
     * @param string $message
     */
    public static function alert(string $message): void
    {
        self::line();
        self::message("<warning>!</warning> {$message}", 'warning');
        self::line();
    }

    /**
     * Prints a title-like heading.
     *
     * @param string $title
     */
    public static function title(string $title): void
    {
        $line = str_repeat('=', strlen($title));
        self::newline();
        self::message("<bold>{$title}</bold>");
        self::message($line);
        self::newline();
    }

    /**
     * Prints a section-like heading.
     *
     * @param string $title
     */
    public static function section(string $title): void
    {
        self::message("<bold>{$title}</bold>");
        self::line(str_repeat('-', strlen($title)));
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
        $response = strtolower(trim(self::ask("{$question} ({$defaultText})", $default ? 'Y' : 'N')));

        return match ($response) {
            'y',
            'yes',
            '1',
            'true' => true,
            'n',
            'no',
            '0',
            'false' => false,
            '' => $default,
            default => str_starts_with($response, 'y'),
        };
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
        $count = max(0, $count);
        echo str_repeat(PHP_EOL, $count);
    }

    /**
     * Prints a table with headers and rows.
     *
     * @param array $headers
     *   Header list.
     * @param array $rows
     *   A list of row arrays.
     */
    public static function table(array $headers, array $rows): void
    {
        if ($rows === []) {
            self::comment('No records found.');
            return;
        }

        if ($headers === []) {
            $firstRow = $rows[array_key_first($rows)] ?? [];
            if (is_array($firstRow)) {
                $headers = array_is_list($firstRow) ? array_map('strval', range(0, count($firstRow) - 1)) : array_keys($firstRow);
            } else {
                $headers = ['Value'];
            }
        }

        $headerCount = count($headers);
        $normalizedRows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $encoded = json_encode($row);
                $normalizedRows[] = [is_scalar($row) ? (string) $row : ($encoded === false ? '' : $encoded)];
                continue;
            }

            if (array_is_list($row)) {
                $values = $row;
            } else {
                $values = [];
                foreach ($headers as $header) {
                    $values[] = array_key_exists($header, $row)
                        ? (is_scalar($row[$header]) ? (string) $row[$header] : (($encoded = json_encode($row[$header])) === false ? '' : $encoded))
                        : '';
                }
            }

            $normalizedRows[] = array_pad($values, $headerCount, '');
        }

        $columnWidths = array_fill(0, $headerCount, 0);
        foreach ([$headers, ...$normalizedRows] as $row) {
            for ($i = 0; $i < $headerCount; $i++) {
                $value = is_scalar($row[$i] ?? null)
                    ? (string) ($row[$i] ?? '')
                    : (($encoded = json_encode($row[$i] ?? '')) === false ? '' : $encoded);
                $columnWidths[$i] = max($columnWidths[$i], strlen($value));
            }
        }

        $divider = '+' . implode('+', array_map(fn(int $width): string => str_repeat('-', $width + 2), $columnWidths)) . '+';

        $formatRow = function (array $values) use ($columnWidths): string {
            $parts = [];
            for ($i = 0; $i < count($columnWidths); $i++) {
                $value = (string) ($values[$i] ?? '');
                $parts[] = ' ' . str_pad($value, $columnWidths[$i]) . ' ';
            }

            return '|' . implode('|', $parts) . '|';
        };

        self::line($divider);
        self::line($formatRow($headers));
        self::line($divider);
        foreach ($normalizedRows as $row) {
            self::line($formatRow($row));
        }
        self::line($divider);
    }

    /**
     * Prints list items in a compact two-column format.
     *
     * @param array $items
     *   Label/value associative pairs.
     */
    public static function definitionList(array $items): void
    {
        if ($items === []) {
            self::comment('No data found.');
            return;
        }

        $maxLength = 0;
        foreach ($items as $label => $value) {
            $maxLength = max($maxLength, strlen((string) $label));
        }

        foreach ($items as $label => $value) {
            $label = str_pad((string) $label, $maxLength, ' ');
            self::line("  <comment>{$label}</comment> : {$value}");
        }
    }

    /**
     * Asks the user to choose from a list of options.
     *
     * @param string $question
     * @param array $options
     * @param mixed $default
     * @return mixed
     */
    public static function choice(string $question, array $options, mixed $default = null): mixed
    {
        if ($options === []) {
            throw new \InvalidArgumentException('Choice options cannot be empty.');
        }

        $index = 1;
        foreach ($options as $option) {
            self::line("  <comment>{$index}.</comment> {$option}");
            $index++;
        }

        $answer = self::ask($question, (string) ($default ?? ''));
        $selected = (int) $answer;

        if ($selected >= 1 && isset($options[$selected - 1])) {
            return $options[$selected - 1];
        }

        return $default;
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
        $parsed = ['_args' => []];

        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];

            if (!str_starts_with($arg, '-')) {
                $parsed['_args'][] = $arg;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                $arg = substr($arg, 2);
                $parts = explode('=', $arg, 2);

                if (count($parts) === 2) {
                    $parsed[$parts[0]] = $parts[1];
                    continue;
                }

                $next = $args[$i + 1] ?? null;
                if ($next !== null && !str_starts_with($next, '-')) {
                    $parsed[$parts[0]] = $next;
                    $i++;
                } else {
                    $parsed[$parts[0]] = true;
                }
                continue;
            }

            $flags = substr($arg, 1);
            if (strlen($flags) > 1) {
                // grouped short flags: -abc => a=true,b=true,c=true
                foreach (str_split($flags) as $key) {
                    $parsed[$key] = true;
                }
                continue;
            }

            $parsed[$flags] = true;
        }

        return $parsed;
    }

    /**
     * Replace custom style tags in message.
     *
     * @param string $message
     * @return string
     */
    private static function replaceTags(string $message): string
    {
        $result = $message;
        foreach (self::STYLES as $tag => $code) {
            $result = str_replace("<{$tag}>", $code, $result);
            $resetCode = $tag === 'bold' ? "\033[22m" : "\033[0m";
            $result = str_replace("</{$tag}>", $resetCode, $result);
        }

        return $result;
    }
}
