<?php

namespace Spark\Contracts\Http;

/**
 * Interface defining the contract for the Request class.
 *
 * This interface provides methods for retrieving and manipulating the
 * properties of an HTTP request.
 */
interface RequestContract
{
    /**
     * Retrieves the HTTP request method.
     *
     * @return string The request method as a string (e.g., 'GET', 'POST').
     */
    public function getMethod(): string;

    /**
     * Retrieves the request path.
     *
     * @return string The path of the request.
     */
    public function getPath(): string;

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
    public function getRouteParam(string $key, ?string $default = null): ?string;

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
}