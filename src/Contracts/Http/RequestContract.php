<?php

namespace Spark\Contracts\Http;

use Spark\Http\Input;

/**
 * Interface defining the contract for the Request class.
 *
 * This interface provides methods for retrieving and manipulating the
 * properties of an HTTP request.
 */
interface RequestContract
{
    /** Retrieves the request body as a PSR-7 StreamInterface. */
    public function getBody(): \Psr\Http\Message\StreamInterface;

    /** Retrieves the raw request body as a string. */
    public function getRawBody(): string;

    /** Converts the request to a PSR-7 ServerRequestInterface. */
    public function toPsrRequest(): \Psr\Http\Message\ServerRequestInterface;

    /**
     * Retrieves the HTTP request method.
     *
     * @return string The request method as a string (e.g., 'GET', 'POST').
     */
    public function getMethod(): string;

    /**
     * Checks if the request method matches the given method(s).
     *
     * @param array|string $method
     * @return bool
     */
    public function isMethod(array|string $method): bool;

    /**
     * Retrieves the request path.
     *
     * @return string The path of the request.
     */
    public function getPath(): string;

    /**
     * Determine if the current request path matches a given pattern.
     *
     * @param string $path
     * @return bool
     */
    public function is(string $path): bool;

    /**
     * Retrieves the full request URL.
     *
     * @return string The complete URL of the request.
     */
    public function getUrl(): string;

    /**
     * Retrieves the root URL (protocol and host).
     *
     * @return string The root URL of the application.
     */
    public function getRootUrl(): string;

    /**
     * Retrieves a route parameter value by key.
     *
     * @param string $key The key of the route parameter.
     * @param ?string $default The default value to return if the key does not exist.
     * @return ?string The value associated with the given key, or the default value if the key does not exist.
     */
    public function route(string $key, ?string $default = null): ?string;

    /**
     * Retrieves a query parameter value by key.
     *
     * @param string $key The key of the query parameter.
     * @param mixed $default The default value to return if the key does not exist.
     * @return mixed The value associated with the given key, or the default value if the key does not exist.
     */
    public function query(?string $key = null, $default = null): mixed;

    /**
     * Retrieves a POST data value by key.
     *
     * @param ?string $key The key of the POST data.
     * @param mixed $default The default value to return if the key does not exist.
     * @return mixed The value associated with the given key, or the default value if the key does not exist.
     */
    public function post(?string $key = null, $default = null): mixed;

    /**
     * Retrieve an input item from the request (merged query and post).
     *
     * @param array|string|null $filter
     * @param mixed $default
     * @return mixed
     */
    public function input(array|string|null $filter = null, $default = null): mixed;

    /**
     * Determine if the request contains a given input key.
     *
     * @param array|string $key
     * @return bool
     */
    public function has(array|string $key): bool;

    /**
     * Determine if the request contains a non-empty value for an input key.
     *
     * @param array|string $key
     * @return bool
     */
    public function filled(array|string $key): bool;

    /**
     * Get a subset containing only the specified keys from the input data.
     *
     * @param array|string $keys
     * @return array
     */
    public function only(array|string $keys): array;

    /**
     * Get all input except for a specified array of keys.
     *
     * @param array|string $keys
     * @return array
     */
    public function except(array|string $keys): array;

    /**
     * Retrieves a file from the request by key.
     *
     * @param string $key The key of the file.
     * @param mixed $default The default value to return if the key does not exist.
     * @return mixed The file associated with the given key, or the default value if the key does not exist.
     */
    public function file(string $key, $default = null): mixed;

    /**
     * Checks if a file exists in the request by key.
     *
     * @param string $key The key of the file.
     * @return bool True if the file exists, false otherwise.
     */
    public function hasFile(string $key): bool;

    /**
     * Retrieves all request data with optional filtering.
     *
     * @param array $filter Optional array of keys to filter the retrieved data.
     * @return array The filtered request data.
     */
    public function all(array $filter = []): array;

    /**
     * Retrieves a server variable value by key.
     *
     * @param string $key The key of the server variable.
     * @param mixed $default The default value to return if the key does not exist.
     * @return ?string The value associated with the given key, or the default value if the key does not exist.
     */
    public function server(string $key, $default = null): ?string;

    /**
     * Retrieves a header value by name.
     *
     * @param string $name The name of the header.
     * @param mixed $defaultValue The default value to return if the header does not exist.
     * @return ?string The value of the header, or the default value if the header does not exist.
     */
    public function header(string $name, $defaultValue = null): ?string;

    /**
     * Get the client IP address.
     *
     * @return string|false
     */
    public function ip(): string|false;

    /**
     * Get the User-Agent header string.
     *
     * @return string|null
     */
    public function useragent(): ?string;

    /**
     * Get the bearer token from the Authorization request header.
     *
     * @return string|null
     */
    public function bearerToken(): ?string;

    /**
     * Determine if the request expects a JSON response.
     *
     * @return bool
     */
    public function expectsJson(): bool;

    /**
     * Determine if the request wants a JSON response.
     *
     * @return bool
     */
    public function wantsJson(): bool;

    /**
     * Determine if the request is an AJAX request.
     *
     * @return bool
     */
    public function isAjax(): bool;

    /**
     * Validate the request against the given validation rules.
     *
     * @param array $rules
     * @param array|null $attributes
     * @param array|null $messages
     * @return Input
     */
    public function validate(array $rules, ?array $attributes = null, ?array $messages = null): Input;

    /**
     * Get the validated data or a single validated input value.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function validated(?string $key = null, $default = null): mixed;

    /**
     * Get the authenticated user for the request.
     *
     * @param string|null $key
     * @param mixed $default
     * @param string|null $guard
     * @return mixed
     */
    public function user(?string $key = null, $default = null, ?string $guard = null): mixed;

    /**
     * Get the session instance or a session value by key.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function session(?string $key = null, $default = null): mixed;
}
