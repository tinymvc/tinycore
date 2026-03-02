<?php

namespace Spark\Ping\Contracts;

/**
 * HttpResponseContract defines the contract for handling HTTP responses.
 * It specifies the methods that any HTTP response class should implement
 * to provide a consistent interface for accessing response data.
 * 
 * This contract includes methods for retrieving the response body, status code,
 * headers, and other relevant information about the HTTP response.
 * 
 * @author Shahin Moyshan <shahin.mosyahn2@gmail.com.>
 */
interface HttpResponseContract
{
    /**
     * Get the raw body of the response.
     *
     * @return string The raw response body.
     */
    public function body(): string;

    /**
     * Get the HTTP status code of the response.
     * @return int The HTTP status code.
     */
    public function status(): int;

    /**
     * Get the last URL after redirects.
     *
     * @return string The last URL.
     */
    public function lastUrl(): string;

    /**
     * Get the content length of the response.
     *
     * @return int The content length.
     */
    public function length(): int;

    /**
     * Get the response headers.
     *
     * @return array The response headers.
     */
    public function headers(): array;

    /**
     * Get a specific header value by key.
     *
     * @param string $key The header key.
     * @param mixed $default The default value to return if the header is not found.
     * @return mixed The header value or the default value.
     */
    public function header(string $key, $default = null): mixed;

    /**
     * Get the JSON-decoded response body.
     *
     * @return array The JSON-decoded response body.
     */
    public function json(): array;

    /**
     * Get the response body as a string.
     *
     * @return string The response body as a string.
     */
    public function text(): string;

    /**
     * Get the response body as a string (alias for text()).
     *
     * @return string The response body as a string.
     */
    public function has(string $key): bool;

    /**
     * Get a specific value from the JSON-decoded response body by key.
     *
     * @param string $key The key to retrieve from the JSON-decoded response body.
     * @param mixed $default The default value to return if the key is not found.
     * @return mixed The value associated with the key or the default value.
     */
    public function get(string $key, $default = null): mixed;

    /**
     * Determine if the response has a successful status code (2xx).
     *
     * @return bool True if the response is successful, false otherwise.
     */
    public function isOk(): bool;

    /** Aliases for isOk() */
    public function ok(): bool;

    /* Aliases for isOk() */
    public function isSuccess(): bool;

    /* Aliases for isOk() */
    public function isSuccessful(): bool;

    /* Aliases for isOk() */
    public function success(): bool;

    /* Aliases for isOk() */
    public function successful(): bool;

    /**
     * Determine if the response has a client error status code (4xx).
     * @return bool True if the response has a client error status code, false otherwise.
     */
    public function isClientError(): bool;

    /**
     * Determine if the response has a failed status code (4xx or 5xx).
     * @return bool True if the response has a failed status code, false otherwise.
     */
    public function failed(): bool;

    /**
     * Determine if the response has a server error status code (5xx).
     * @return bool True if the response has a server error status code, false otherwise.
     */
    public function isServerError(): bool;

    /**
     * Determine if the response has a redirection status code (3xx).
     * @return bool True if the response has a redirection status code, false otherwise.
     */
    public function isRedirect(): bool;
}