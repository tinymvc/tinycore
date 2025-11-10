<?php

namespace Spark\Helpers;

use Spark\Contracts\Support\Arrayable;
use Spark\Http\Session;

/**
 * Class InputErrors - HTTP Request input validation errors
 *
 * This class handles HTTP request errors, providing methods to retrieve error messages,
 * old input values, and check for the existence of errors.
 *
 * @package Spark\Helpers
 */
class InputErrors implements Arrayable, \IteratorAggregate, \Stringable
{
    /**
     * Construct the error object.
     *
     * This method sets the error messages and attributes from the session
     * flash data. The messages and attributes are stored in the session
     * as an array with the keys 'messages' and 'attributes' respectively.
     *
     * @return void
     */
    public function __construct(
        /**
         * @var array Stores error messages for fields
         */
        private array $messages = [],

        /**
         * @var array Stores attributes from the previous request
         */
        private array $attributes = [],
    ) {

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
    public function old(string $field, ?string $default = null): ?string
    {
        return $this->getOld($field, $default);
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
     * Get all error messages.
     *
     * @return array
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Get all attributes from the previous request.
     *
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Set error messages.
     *
     * @param array $messages
     * @return void
     */
    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    /**
     * Set attributes from the previous request.
     *
     * @param array $attributes
     * @return void
     */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    /**
     * Add an error message for a specific field.
     *
     * @param string $field
     * @param string $message
     * @return void
     */
    public function addMessage(string $field, string $message): void
    {
        $this->messages[$field][] = $message;
    }

    /**
     * Add an attribute from the previous request.
     *
     * @param string $field
     * @param string $value
     * @return void
     */
    public function addAttribute(string $field, string $value): void
    {
        $this->attributes[$field] = $value;
    }

    /**
     * Clear all error messages.
     *
     * @return void
     */
    public function clearMessages(): void
    {
        $this->messages = [];
    }

    /**
     * Clear all attributes from the previous request.
     *
     * @return void
     */
    public function clearAttributes(): void
    {
        $this->attributes = [];
    }

    /**
     * Clear all error messages and attributes.
     *
     * @return void
     */
    public function clearAll(): void
    {
        $this->clearMessages();
        $this->clearAttributes();
    }

    /**
     * Get the number of error messages.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * Check if there are no error messages.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return !$this->any();
    }

    /**
     * Check if there are any error messages.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return $this->any();
    }

    /**
     * Convert the error object to a string.
     *
     * This method returns the first error message from the collection
     * if any errors exist, otherwise it returns an empty string.
     *
     * @return string The first error message or an empty string if no errors exist.
     */
    public function __toString(): string
    {
        return $this->any() ? $this->getFirstError() : '';
    }

    /**
     * Convert the error messages to an array.
     *
     * @return array The error messages as an array.
     */
    public function toArray(): array
    {
        return $this->all();
    }

    /**
     * Get an iterator for the items.
     * 
     * This method allows the model to be iterated over like an array.
     * 
     * @template TKey of array-key
     *
     * @template-covariant TValue
     *
     * @implements \ArrayAccess<TKey, TValue>
     *
     * @return \ArrayIterator<TKey, TValue>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->all());
    }
}