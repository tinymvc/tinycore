<?php

namespace Spark\Utils;

use Spark\Console\Prompt;
use Spark\Contracts\Utils\TracerUtilContract;
use Spark\Facades\Blade;
use Spark\Support\Traits\Macroable;
use Throwable;

/**
 * Class tracer
 * 
 * Enabled debugging mode and logs messages of various types.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Tracer implements TracerUtilContract
{
    use Macroable;

    /** @var Tracer $instance */
    public static self $instance;

    /**
     * tracer constructor.
     * 
     * Sets up custom error, exception, and shutdown handlers for the application.
     * This ensures that errors and exceptions are logged and handled consistently.
     * 
     * @param string|null $logFile The path to the error log file. 
     *      Defaults to storage_dir('error.log').
     * 
     * @return void
     */
    public function __construct(private ?string $logFile = null)
    {
        // Set the tracer instance as a singleton
        self::$instance = $this;

        // Set default error log file if not provided
        $this->logFile ??= storage_dir('logs/error.log');

        // Set custom error, exception, and shutdown handlers.
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Initializes a new instance of the tracer class, setting default error handlers.
     * 
     * @return void
     */
    public static function start(): void
    {
        new self();
    }

    /**
     * Custom error handler function.
     * 
     * @param int $errno The level of the error raised.
     * @param string $errstr The error message.
     * @param string $errfile The filename where the error was raised.
     * @param int $errline The line number where the error was raised.
     */
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): void
    {
        $this->renderError('Error', $errstr, $errfile, $errline);
    }

    /**
     * Custom exception handler.
     * 
     * @param Throwable $exception The exception instance.
     */
    public function handleException(Throwable $exception): void
    {
        $this->renderError(
            'Exception',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTrace()
        );

        // Exit after rendering the exception.
        exit(0);
    }

    /**
     * Handles shutdown errors when the script ends unexpectedly.
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null) {
            $this->renderError('Shutdown Error', $error['message'], $error['file'], $error['line']);
        }
    }

    /**
     * Renders the error or exception details as an HTML response.
     * 
     * @param string $type Type of error (e.g., 'Error', 'Exception').
     * @param string $message Error message to display.
     * @param string $file File where the error occurred.
     * @param int $line Line number of the error.
     * @param array $trace Optional stack trace array.
     */
    public function renderError(string $type, string $message, string $file, int $line, array $trace = []): void
    {
        $this->log("$type: $message in $file on line $line"); // Log the error message

        if (php_sapi_name() === 'cli') {
            // Get the prompt instance
            $prompt = get(Prompt::class);

            // Format and output the error message
            $prompt->message("[$type] $message", 'danger');
            $prompt->message("File: $file(<danger>$line</danger>)");

            if (!empty($trace)) {
                $prompt->newline();
                $prompt->message('Trace:', 'info');

                // Format the trace output
                foreach ($trace as $index => $frame) {
                    $frameFile = $frame['file'] ?? '[internal function]';
                    $frameLine = $frame['line'] ?? 'n/a';
                    $frameFunction = $frame['function'] ?? 'unknown';
                    $prompt->message("#$index $frameFile(<danger>$frameLine</danger>): <warning>$frameFunction()</warning>");
                }
            }

            exit(1);
        }

        if (config('debug')) {
            // Clear any previous output
            if (ob_get_length()) {
                ob_end_clean();
            }

            // Set HTTP response code to 500 for server error.
            if (!headers_sent()) {
                http_response_code(500);
            }

            // Detailed error output with stack trace if debug mode is enabled.
            Blade::setPath(__DIR__ . '/../Foundation/resources/views');

            echo Blade::render(
                'tracer',
                compact('type', 'message', 'file', 'line', 'trace')
            );

            // End the script to prevent further execution
            exit;
        }

        abort(500, 'Internal Server Error');
    }

    /**
     * Logs a message to the error log file.
     * Rotates the log file when it reaches 10 MB.
     *
     * @param string $message The message to log.
     */
    public function log(string $message): void
    {
        $maxFileSize = 5 * 1024 * 1024; // 5 MB in bytes

        // Check if log file exists and its size and rotate if it exceeds the max size.
        if (is_file($this->logFile) && filesize($this->logFile) >= $maxFileSize) {
            rename($this->logFile, $this->logFile . '.' . date('Y-m-d_H-i-s'));
        }

        $time = date('Y-m-d H:i:s'); // Current timestamp
        error_log("[$time] $message\n", 3, $this->logFile);
    }
}
