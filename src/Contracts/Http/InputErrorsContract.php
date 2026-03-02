<?php

namespace Spark\Contracts\Http;

/**
 * Interface InputErrorsContract
 *
 * This interface defines the contract for handling HTTP request input validation errors.
 * It provides methods to retrieve old input values, check for errors, and get error messages.
 *
 * @package Spark\Contracts\Http
 */
interface InputErrorsContract
{
    /**
     * Get the old value of the given field.
     * 
     * The method returns the old value of the given field from the
     * previous request. If the field does not exist, the method
     * returns the given default value.
     * 
     * @param string $field
     *   The field name.
     * @param ?string $default
     *   The default value to return if the field does not exist.
     * 
     * @return string|null
     *   The old value of the given field.
     */
    public function getOld(string $field, ?string $default = null): ?string;

    /**
     * Get the old value of the given field.
     * 
     * This method is an alias for the getOld method.
     * 
     * @param string $field
     *   The field name.
     * @param ?string $default
     *   The default value to return if the field does not exist.
     * 
     * @return string|null
     *   The old value of the given field.
     */
    public function old(string $field, ?string $default = null): ?string;

    /**
     * Check if there are any error messages.
     *
     * @return bool
     */
    public function any(): bool;

    /**
     * Retrieve all error messages as an indexed array.
     *
     * @param bool $merge Merge all error messages into a single array
     * @return array
     */
    public function all($merge = true): array;

    /**
     * Get the error message for a specific field.
     *
     * @param string $field The field name to retrieve the error message for.
     * @return null|string|array The error messages for the given field, or null if no error exists for the field.
     */
    public function error(string $field): null|string|array;

    /**
     * Determine if an error message exists for a given field.
     *
     * @param string $field The field name to check for an error message.
     * @return bool True if an error message exists for the given field, false otherwise.
     */
    public function has(string $field): bool;

    /**
     * Get the first error message for a specific field.
     *
     * @param string $field The field name to retrieve the first error message for.
     * @return null|string The first error message for the given field, or null if no error exists for the field.
     */
    public function first(string $field): ?string;

    /**
     * Get the number of error messages.
     * 
     * @return int The number of error messages.
     */
    public function count(): int;

    /**
     * Determine if there are no error messages.
     *
     * @return bool True if there are no error messages, false otherwise.
     */
    public function isEmpty(): bool;

    /**
     * Determine if there are any error messages.
     *
     * @return bool True if there are any error messages, false otherwise.
     */
    public function isNotEmpty(): bool;

    /**
     * Clear all error messages.
     *
     * @return void
     */
    public function clearAll(): void;
}