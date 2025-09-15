<?php

namespace Spark\Helpers;

use ArrayAccess;

/**
 * HttpResponse class provides a structured way to handle HTTP responses.
 * It implements ArrayAccess to allow accessing properties like an array.
 * 
 * This class encapsulates the response data including body, status code,
 * headers, and other relevant information.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class HttpResponse implements ArrayAccess, \Stringable
{
    public mixed $body = ''; // The response body
    public int $status = 0; // The HTTP status code
    public string $lastUrl = ''; // The last URL after redirects
    public int $length = 0; // The content length of the response
    public array $headers = []; // The response headers
    public array $json; // The JSON-decoded response body

    /**
     * Sets the response data.
     *
     * @param array $response The response data.
     */
    public function __construct(array $response)
    {
        $this->body = $response['body'] ?? '';
        $this->status = $response['status'] ?? 0;
        $this->lastUrl = $response['last_url'] ?? '';
        $this->length = $response['length'] ?? 0;
        $this->headers = isset($response['headers']) && $response['headers'] ? $response['headers'] : [];
    }

    /**
     * Magic method to convert the object to a string.
     *
     * @return string The response body as a string.
     */
    public function __toString(): string
    {
        return $this->text();
    }

    /**
     * Get the Response body as a JSON object.
     *
     * @return array|null The JSON-decoded response body or null if decoding failed.
     */
    public function json()
    {
        return $this->json ??= arr_from_set($this->body);
    }

    /**
     * Get the Response body as a string.
     *
     * @return string The response body as a string.
     */
    public function text()
    {
        return (string) $this->body ?? '';
    }

    /**
     * Check if a key exists in the JSON-decoded response body using a dot-notated key.
     *
     * @param string $key The key to check.
     * @return bool True if the key exists, false otherwise.
     */
    public function has(string $key): bool
    {
        return data_get($this->json(), $key) !== null;
    }

    /**
     * Get a value from the JSON-decoded response body using a dot-notated key.
     *
     * @param string $key The key to retrieve.
     * @param mixed $default The default value to return if the key does not exist.
     * @return mixed The value associated with the key or the default value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->json(), $key, $default);
    }

    /**
     * Check if the response was a successful (2xx) response.
     *
     * @return bool True if successful, false otherwise.
     */
    public function isOk(): bool
    {
        return $this->status === 200;
    }

    /**
     * Check if the response was successful (2xx status code).
     *
     * @return bool True if successful, false otherwise.
     */
    public function isSuccess()
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Check if the response was a client error (4xx status code).
     *
     * @return bool True if client error, false otherwise.
     */
    public function isClientError()
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /**
     * Check if the response was a server error (5xx status code).
     *
     * @return bool True if server error, false otherwise.
     */
    public function isServerError()
    {
        return $this->status >= 500 && $this->status < 600;
    }

    /**
     * Check if the response was a redirect (3xx status code).
     *
     * @return bool True if redirect, false otherwise.
     */
    public function isRedirect()
    {
        return $this->status >= 300 && $this->status < 400;
    }

    /**
     * Check if the response was a success (2xx status code).
     *
     * @param mixed $offset The offset to check.
     * @return bool True if success, false otherwise.
     */
    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset);
    }

    /**
     * Get the value at the specified offset.
     *
     * @param mixed $offset The offset to retrieve.
     * @return mixed The value at the specified offset or null if not set.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->{$offset} ?? null;
    }

    /**
     * Set the value at the specified offset.
     *
     * @param mixed $offset The offset to set.
     * @param mixed $value The value to set at the specified offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->{$offset} = $value;
    }

    /**
     * Unset the value at the specified offset.
     *
     * @param mixed $offset The offset to unset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->{$offset});
    }
}