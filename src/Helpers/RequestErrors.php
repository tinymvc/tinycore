<?php

namespace Spark\Helpers;

use Spark\Http\Session;

/**
 * Class HttpRequestErrors
 *
 * This class handles HTTP request errors, providing methods to retrieve error messages,
 * old input values, and check for the existence of errors.
 *
 * @package Spark\Helpers
 */
class RequestErrors
{
    /**
     * @var array Stores error messages for fields
     */
    private array $messages = [];

    /**
     * @var array Stores attributes from the previous request
     */
    private array $attributes = [];

    /**
     * Construct the error object.
     *
     * This method sets the error messages and attributes from the session
     * flash data. The messages and attributes are stored in the session
     * as an array with the keys 'messages' and 'attributes' respectively.
     *
     * @return void
     */
    public function __construct(Session $session)
    {
        $this->messages = $session->getFlash('errors', []);
        $this->attributes = $session->getFlash('input', []);
    }

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
    public function getOld(string $field, ?string $default = null): ?string
    {
        return $this->attributes[$field] ?? $default;
    }

    /**
     * Check if there are any error messages.
     *
     * @return bool
     */
    public function any(): bool
    {
        return count($this->messages) > 0;
    }

    /**
     * Retrieve all error messages as an indexed array.
     *
     * @param bool $merge Merge all error messages into a single array
     * @return array
     */
    public function all($merge = true): array
    {
        if ($merge) {
            return array_merge(...array_values($this->messages));
        }

        return $this->messages;
    }

    /**
     * Get the error message for a specific field.
     *
     * @param string $field
     * @return array|null
     */
    public function error(string $field): ?array
    {
        return $this->messages[$field] ?? null;
    }

    /**
     * Determine if an error message exists for a given field.
     *
     * @param string $field The field name to check for an error message.
     * @return bool True if an error message exists for the field, false otherwise.
     */
    public function has(string $field): bool
    {
        return isset($this->messages[$field]);
    }

    /**
     * Get the error message for a specific field.
     * 
     * This method is an alias for the error method.
     * 
     * @param string $field The field name to retrieve the error message for.
     * 
     * @return array|null
     *   The error messages for the given field, or null if no error exists for the field.
     */
    public function get(string $field): ?array
    {
        return $this->error($field);
    }

    /**
     * Get the first error message from the given field.
     *
     * @param string $field The field name to retrieve the error message from.
     * 
     * @return string|null
     *   The first error message for the given field, or null if no errors exist for the field.
     */
    public function first(string $field): ?string
    {
        return $this->messages[$field][0] ?? null;
    }

    /**
     * Get the first error message from the collection.
     *
     * @return string|null
     */
    public function getFirstError(): ?string
    {
        return current($this->messages);
    }

    /**
     * Convert the error object to a string.
     *
     * This method returns the first error message from the collection
     * if any errors exist, otherwise it returns an empty string.
     *
     * @return string The first error message or an empty string if no errors exist.
     */
    public function __toString()
    {
        return $this->any() ? $this->getFirstError() : '';
    }
}