<?php

namespace Spark\Facades;

use Spark\Contracts\LoggerInterface;
use Spark\Utils\Tracer;

/**
 * Facade for logging messages at various severity levels.
 * 
 * This class provides static methods to log messages with different levels
 * such as info, warning, error, debug, critical, alert, emergency, and notice.
 * 
 * Each method accepts a message string and an optional context array.
 * The context array is converted to a JSON string and appended to the log message.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 * @package Spark\Facades
 * @version 1.0.0
 */
class Log implements LoggerInterface
{
    /**
     * Logs an informational message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::add('info', $message, $context);
    }

    /**
     * Logs a warning message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::add('warning', $message, $context);
    }

    /**
     * Logs an error message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::add('error', $message, $context);
    }

    /**
     * Logs a debug message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::add('debug', $message, $context);
    }

    /**
     * Logs a critical message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function critical(string $message, array $context = []): void
    {
        self::add('critical', $message, $context);
    }

    /**
     * Logs an alert message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function alert(string $message, array $context = []): void
    {
        self::add('alert', $message, $context);
    }

    /**
     * Logs an emergency message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function emergency(string $message, array $context = []): void
    {
        self::add('emergency', $message, $context);
    }

    /**
     * Logs a notice message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function notice(string $message, array $context = []): void
    {
        self::add('notice', $message, $context);
    }

    /**
     * Internal method to add a log entry.
     *
     * @param string $level The log level.
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    private static function add(string $level, string $message, array $context = []): void
    {
        if (!empty($context)) {
            $context = ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $context = '';
        }

        $level = strtoupper($level);

        Tracer::$instance->log("local.$level:$message$context");
    }
}
