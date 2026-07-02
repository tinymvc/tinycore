<?php

namespace Spark;

use Spark\Console\Prompt;
use Spark\Contracts\Utils\TracerUtilContract;
use Spark\Facades\Blade;
use Spark\Support\Traits\Macroable;
use Throwable;
use function in_array;

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

    private const LOG_FILE_MAX_SIZE = 10_485_760;

    /** @var array<int, string> */
    private const ERROR_TYPES = [
        E_ERROR => 'Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated',
    ];

    private const FATAL_ERROR_TYPES = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
        E_RECOVERABLE_ERROR,
    ];

    /**
     * tracer constructor.
     * 
     * Sets up custom error, exception, and shutdown handlers for the application.
     * This ensures that errors and exceptions are logged and handled consistently.
     * 
     * @param string|null $logFile The path to the error log file. 
     *      Defaults to storage_dir('logs/spark.log').
     * 
     * @return void
     */
    public function __construct(private ?string $logFile = null)
    {
        // Set the tracer instance as a singleton
        self::$instance = $this;

        // Enable error reporting
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

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
     *
     * @return bool Whether to continue with internal PHP error handler.
     */
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $type = self::ERROR_TYPES[$errno] ?? 'Error';

        if (!is_debug_mode() && !in_array($errno, self::FATAL_ERROR_TYPES, true)) {
            $this->log("$type: $errstr in $errfile on line $errline");
            return true;
        }

        $this->renderError($type, $errstr, $errfile, $errline);

        return true;
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
     *
     * This handler focuses on fatal errors to avoid duplicate handling for
     * non-fatal notices and warnings.
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatalErrors = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ];

        if (!in_array($error['type'], $fatalErrors, true)) {
            return;
        }

        $this->renderError('Fatal Error', $error['message'], $error['file'], $error['line']);
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
        // Log the error message unless it's from Tinker context
        !$this->isFromTinkerContext($file) && $this->log("$type: $message in $file on line $line"); // Log the error message

        if (is_cli()) {
            // Format and output the error message
            Prompt::message("[$type] $message", 'danger');
            Prompt::message("File: $file(<danger>$line</danger>)");

            if (!empty($trace)) {
                Prompt::newline();
                Prompt::message('Trace:', 'info');

                // Format the trace output
                foreach ($trace as $index => $frame) {
                    $frameFile = $frame['file'] ?? '[internal function]';
                    $frameLine = $frame['line'] ?? 'n/a';
                    $frameFunction = $frame['function'] ?? 'unknown';
                    Prompt::message("#$index $frameFile(<danger>$frameLine</danger>): <warning>$frameFunction()</warning>");
                }
            }

            if ($this->isFromTinkerContext($file)) {
                return; // Skip further output in Tinker context
            }

            exit(1);
        }

        if (is_debug_mode()) {
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
     * Checks if the error originated from the Tinker context.
     * 
     * @param string $file The file path where the error occurred.
     * 
     * @return bool True if the error is from Tinker context, false otherwise.
     */
    private function isFromTinkerContext(string $file): bool
    {
        return is_cli() && str_contains($file, dir_path('src/Tinker.php'));
    }

    /**
     * Logs a message to the error log file.
     * Rotates the log file when it reaches 10 MB.
     *
     * @param string $message The message to log.
     */
    public function log(string $message): void
    {
        // Set default error log file if not provided
        $logFile = $this->logFile ?? storage_dir('logs/spark.log');
        $logDirectory = dirname($logFile);

        if (!is_dir($logDirectory)) {
            @mkdir($logDirectory, 0775, true);
        }

        if (is_file($logFile) && !is_writable($logFile)) {
            return;
        }

        if (is_file($logFile) && filesize($logFile) >= self::LOG_FILE_MAX_SIZE) {
            @rename($logFile, "$logFile." . date('YmdHis'));
        }

        if (!is_writable(dirname($logFile))) {
            return; // Skip logging if the directory is not writable
        }

        $time = date('Y-m-d H:i:s'); // Current timestamp
        error_log("[$time] $message\n", 3, $logFile);
    }
}
