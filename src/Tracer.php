<?php

namespace Spark;

use Spark\Console\Prompt;
use Spark\Contracts\TracerContract;
use Spark\Foundation\Application;
use Spark\Http\Request;
use Spark\Support\Traits\Macroable;
use Spark\Facades\Blade;
use Throwable;
use function in_array;
use function sprintf;

/**
 * Class tracer
 * 
 * Enabled debugging mode and logs messages of various types.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Tracer implements TracerContract
{
    use Macroable;

    /** @var Tracer $instance */
    public static ?self $instance = null;

    /** @var bool Weather the tracer is currently rendering an error. */
    private bool $rendering = false;

    /** @var int */
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

    /** @var array<int, string> */
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
     * @param bool $registerHandlers Leave false when a test runner owns PHP error handling.
     * 
     * @return void
     */
    public function __construct(private ?string $logFile = null, bool $registerHandlers = true)
    {
        // Set the tracer instance as a singleton
        self::$instance = $this;

        if (!$registerHandlers) {
            return;
        }

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

        if ($this->rendering) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        }

        $type = self::ERROR_TYPES[$errno] ?? 'Error';

        if (!$this->debugEnabled() && !in_array($errno, self::FATAL_ERROR_TYPES, true)) {
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

        if (!in_array($error['type'], self::FATAL_ERROR_TYPES, true)) {
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
        if ($this->rendering) {
            $this->renderFallback($message);

            return;
        }

        $this->rendering = true;

        try {
            $this->renderErrorResponse($type, $message, $file, $line, $trace);
        } catch (Throwable) {
            // Error pages must not depend on a working container or template compiler.
            $this->renderFallback($message);
        } finally {
            $this->rendering = false;
        }
    }

    private function renderErrorResponse(string $type, string $message, string $file, int $line, array $trace): void
    {
        // Log the error message unless it's from Tinker context
        if (!($isFromTinker = $this->isFromTinkerContext($file))) {
            $this->log("$type: $message in $file on line $line" . $this->traceString($trace));
        }

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

            if ($isFromTinker) {
                return; // Skip further output in Tinker context
            }

            exit(1);
        }

        $debug = $this->debugEnabled();
        $json = $this->expectsJson();

        $this->prepareResponse($json);

        if ($json) {
            $data = ['message' => $debug ? "$type: $message" : 'Internal Server Error'];

            if ($debug) {
                $data += [
                    'file' => '@' . remove_root_dir($file, root_dir()),
                    'line' => $line,
                    'trace' => array_map(
                        static fn(array $frame): string => sprintf(
                            '%s(%s): %s()',
                            '@' . remove_root_dir($frame['file'] ?? '[internal function]', root_dir()),
                            $frame['line'] ?? 'n/a',
                            $frame['function'] ?? 'unknown',
                        ),
                        $trace,
                    ),
                ];
            }

            echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        } else {
            Blade::setPath(__DIR__ . '/Foundation/resources/views');

            if ($debug) {
                echo Blade::render('tracer', compact('type', 'message', 'file', 'line', 'trace'));
            } else {
                echo Blade::render('errors/500', ['message' => 'Internal Server Error']);
            }
        }

        exit(1);
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
     * Rotates the log file when it reaches 10 MB and the directory is writable.
     *
     * @param string $message The message to log.
     */
    public function log(string $message): void
    {
        $entry = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
        $logFile = $this->logFile;

        try {
            $logFile = dir_path($this->logFile ??= storage_dir('logs/spark.log'));

            if ($this->appendLogEntry($logFile, $entry)) {
                return;
            }
        } catch (Throwable) {
            // Logging must not replace the original error with a filesystem error.
        }

        @error_log("[Spark] Unable to write log file '$logFile'. $entry");
    }

    private function debugEnabled(): bool
    {
        try {
            return Application::$app->isDebugMode();
        } catch (Throwable) {
            return false;
        }
    }

    private function expectsJson(): bool
    {
        try {
            $app = Application::$app;
            $request = $app->resolved(Request::class) ? $app->get(Request::class) : new Request();

            return $request->expectsJson();
        } catch (Throwable) {
            return str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'json');
        }
    }

    private function prepareResponse(bool $json): void
    {
        // Discard nested partial views without closing non-removable server buffers.
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                @ob_clean();
                break;
            }
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=UTF-8');
        }
    }

    /** Render a basic response when Blade is unavailable or fails. */
    private function renderFallback(string $message): void
    {
        if (is_cli()) {
            Prompt::error($message);
            Prompt::newline();
            exit(1);
        }

        $message = $this->debugEnabled() ? $message : 'Internal Server Error';

        $this->prepareResponse($json = $this->expectsJson());

        if ($json) {
            echo json_encode(['message' => $message], JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            echo '<div style="font:16px/1.5 system-ui,sans-serif;max-width:480px;margin:18vh auto;padding:0 24px;text-align:center;color:#111827">'
                . '<div style="font-size:80px;font-weight:700;letter-spacing:-.04em;color:#e5e7eb">500</div>'
                . '<h1 style="margin:0 0 8px;font-size:20px;font-weight:600">Internal Server Error</h1>'
                . '<p style="margin:0;font-size:14px;color:#6b7280">' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div>';
        }

        exit(1);
    }

    /**
     * Permission checks are advisory; always check the actual write as well.
     */
    private function appendLogEntry(string $logFile, string $entry): bool
    {
        $logDirectory = dirname($logFile);
        clearstatcache();

        // Another request may create the directory between the check and mkdir.
        if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
            return false;
        }

        if (is_file($logFile)) {
            if (!is_writable($logFile)) {
                return false;
            }

            // Appending only needs a writable file; rotation also needs its directory.
            if (is_writable($logDirectory) && @filesize($logFile) >= self::LOG_FILE_MAX_SIZE) {
                $archive = "$logFile." . date('YmdHis');
                @rename($logFile, $archive);
            }
        } elseif (file_exists($logFile) || !is_writable($logDirectory)) {
            return false;
        }

        // Suppress filesystem warnings to avoid re-entering the tracer's error handler.
        return @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX) === strlen($entry);
    }

    /**
     * Converts a stack trace array into a formatted string.
     *
     * @param array $trace The stack trace array.
     * 
     * @return string Formatted stack trace string.
     */
    private function traceString(array $trace): string
    {
        if (empty($trace)) {
            return '';
        }

        $traceOutput = "\n[Stack trace]:\n";
        foreach ($trace as $index => $frame) {
            $frameFile = $frame['file'] ?? '[internal function]';
            $frameLine = $frame['line'] ?? 'n/a';
            $frameFunction = $frame['function'] ?? 'unknown';
            $traceOutput .= "#$index $frameFile($frameLine): $frameFunction()\n";
        }

        return $traceOutput;
    }
}
