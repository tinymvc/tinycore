<?php

namespace Spark\Utils;

use Throwable;

/**
 * Class tracer
 * 
 * Enabled debugging mode and logs messages of various types.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Tracer
{
    /**
     * tracer constructor.
     * 
     * Sets up custom error, exception, and shutdown handlers for the application.
     * This ensures that errors and exceptions are logged and handled consistently.
     * 
     * @return void
     */
    public function __construct()
    {
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
    public static function trace(): void
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
    private function renderError(string $type, string $message, string $file, int $line, array $trace = []): void
    {
        // Clear any previous output
        if (ob_get_length()) {
            ob_end_clean();
        }

        // Set HTTP response code to 500 for server error.
        if (!headers_sent()) {
            http_response_code(500);
        }

        // Detailed error output with stack trace if debug mode is enabled.
        echo <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>{$type}: {$message}</title>
                <style>* {margin: 0;padding: 0;}body { font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0; }.container { padding: 20px; }.error-box { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 20px; }.trace-box { background-color: #f1f1f1; padding: 10px; border: 1px solid #ccc; margin-top: 10px; margin-top: 20px; }.trace-box pre { margin: 0; }h1 { font-size: 24px; margin: 0 0 10px; }p { margin: 0 0 10px; }.file { font-weight: bold; }.line { font-weight: bold; color: #d9534f; }</style>
            </head>
            <body>
                <div class="container">
                    <div class="error-box">
                        <h1>{$type}: {$message}</h1>
                        <p><span class="file">{$file}</span> at line <span class="line">{$line}</span></p>
                    </div>
                    {$this->parseTraceHtml($trace)}
                </div>
            </body>
            </html>
        HTML;

        // End the script to prevent further execution
        exit;
    }

    /**
     * Prase/Formats the stack trace into an HTML format for display.
     * 
     * @param array $trace Array of stack trace details.
     * @return string Formatted HTML representation of the trace.
     */
    private function parseTraceHtml(array $trace): string
    {
        // Holds the error trace html markup.
        $traceHtml = '';

        // Convert to a error trace (string) from a exception array.
        foreach ($trace as $index => $frame) {
            $file = $frame['file'] ?? '[internal function]';
            $line = $frame['line'] ?? '';
            $function = $frame['function'] ?? '';
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $args = isset($frame['args']) ? implode(
                ', ',
                array_map(
                    fn($arg) => is_object($arg) ? get_class($arg) : gettype($arg),
                    $frame['args']
                )
            ) : '';
            $traceHtml .= "#{$index} {$file}({$line}): {$class}{$type}{$function}({$args})\n";
        }

        // Add a html wrapper for all traces item.
        if (!empty($traceHtml)) {
            $traceHtml = htmlspecialchars($traceHtml, ENT_QUOTES, 'UTF-8');
            $traceHtml = <<<HTML
                <div class="trace-box">
                    <h2>Stack Trace</h2>
                    <pre>{$traceHtml}</pre>
                </div>
            HTML;
        }

        // returns error trace html.
        return $traceHtml;
    }
}
