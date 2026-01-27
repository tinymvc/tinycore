<?php

namespace Spark\Contracts;

/**
 * Interface LoggerInterface
 *
 * Defines a contract for logging messages at various severity levels.
 * 
 * @package Spark\Contracts
 */
interface LoggerInterface
{
    /**
     * Logs an informational message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function info(string $message, array $context = []): void;

    /**
     * Logs a warning message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function warning(string $message, array $context = []): void;

    /**
     * Logs an error message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function error(string $message, array $context = []): void;

    /**
     * Logs a debug message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function debug(string $message, array $context = []): void;

    /**
     * Logs a critical message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function critical(string $message, array $context = []): void;

    /**
     * Logs an alert message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function alert(string $message, array $context = []): void;

    /**
     * Logs an emergency message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function emergency(string $message, array $context = []): void;

    /**
     * Logs a notice message.
     *
     * @param string $message The log message.
     * @param array $context Optional context array.
     * @return void
     */
    public static function notice(string $message, array $context = []): void;
}