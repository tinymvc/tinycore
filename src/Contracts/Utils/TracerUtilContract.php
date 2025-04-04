<?php

namespace Spark\Contracts\Utils;
/**
 * Interface that defines the contract for a Tracer utility class.
 * 
 * This interface should be implemented by any class that provides a Tracer utility.
 */
interface TracerUtilContract
{
    /**
     * Initializes a new instance of the Tracer utility class,
     * setting default error handlers.
     * 
     * @return void
     */
    public static function start(): void;

    /**
     * Renders the error or exception details as an HTML response.
     * 
     * @param string $type Type of error (e.g., 'Error', 'Exception').
     * @param string $message Error message to display.
     * @param string $file File where the error occurred.
     * @param int $line Line number of the error.
     * @param array $trace Optional stack trace array.
     */
    public function renderError(string $type, string $message, string $file, int $line, array $trace = []): void;
}