<?php

namespace Spark\Contracts\Http;

/**
 * Interface InputContract
 *
 * Defines the contract for a utility that provides methods
 * to sanitize and validate different request data types.
 */
interface InputContract
{
    /**
     * Sanitizes an email address.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized email or null if invalid.
     */
    public function email(string $key): ?string;

    /**
     * Sanitizes plain text, with optional HTML tag stripping.
     *
     * @param string $key Key in the data array to sanitize.
     * @param bool $stripTags Whether to strip HTML tags from the text.
     * @return string|null Sanitized text or null if invalid.
     */
    public function text(string $key, bool $stripTags = true): ?string;

    /**
     * Sanitizes an integer value.
     *
     * @param string $key Key in the data array to sanitize.
     * @return int|null Sanitized integer or null if invalid.
     */
    public function number(string $key): ?int;

    /**
     * Sanitizes a floating-point number.
     *
     * @param string $key Key in the data array to sanitize.
     * @return float|null Sanitized float or null if invalid.
     */
    public function float(string $key): ?float;

    /**
     * Sanitizes a boolean value.
     *
     * @param string $key Key in the data array to validate.
     * @return bool|null Sanitized boolean or null if invalid.
     */
    public function boolean(string $key): ?bool;

    /**
     * Sanitizes a URL.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized URL or null if invalid.
     */
    public function url(string $key): ?string;

    /**
     * Retrieve a string value.
     *
     * @param string $key
     * @param mixed $default
     * @return string|null
     */
    public function string(string $key, $default = null): ?string;

    /**
     * Retrieve a value from the input.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null): mixed;

    /**
     * Set a value in the input.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check if a key exists in the input.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Check if any of the given keys exist in the input.
     *
     * @param array|string $keys
     * @return bool
     */
    public function hasAny(array|string $keys): bool;

    /**
     * Check if all of the given keys exist in the input.
     *
     * @param array|string $keys
     * @return bool
     */
    public function hasAll(array|string $keys): bool;

    /**
     * Get all input items with optional filtering.
     *
     * @param array $filter
     * @return array
     */
    public function all(array $filter = []): array;

    /**
     * Get a subset containing only the specified keys.
     *
     * @param array|string $keys
     * @return self
     */
    public function only(array|string $keys): self;

    /**
     * Get all items except the specified keys.
     *
     * @param array|string $keys
     * @return self
     */
    public function except(array|string $keys): self;

    /**
     * Retrieve an uploaded file or all uploaded files.
     *
     * @param string|null $key
     * @return array|null
     */
    public function file(?string $key = null): ?array;

    /**
     * Retrieve all uploaded files, optionally for a specific key.
     *
     * @param string|null $key
     * @return array
     */
    public function files(?string $key = null): array;

    /**
     * Check if an uploaded file exists.
     *
     * @param string|null $key
     * @return bool
     */
    public function hasFile(?string $key = null): bool;

    /**
     * Check if the input is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool;

    /**
     * Check if the input is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool;
}