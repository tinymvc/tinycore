<?php

namespace Spark\Http\Client\Contracts;

/**
 * Interface defining the contract for the Http utility class.
 *
 * The Ping utility class provides a simple way to make HTTP requests from
 * your application. It uses the GuzzleHttp\Client class under the hood.
 */
interface HttpContract
{
    /**
     * Sets a single cURL option.
     *
     * @param int $key The cURL option constant.
     * @param mixed $value The value for the option.
     * @return self
     */
    public function option(int $key, mixed $value): self;

    /**
     * Sets multiple cURL options at once.
     *
     * @param array $options An associative array of cURL options, where the key is the option 
     *                      constant and the value is the option value.
     * @return self
     */
    public function options(array $options): self;

    /**
     * Sets a single header for the request.
     *
     * @param string $key The header name.
     * @param string $value The header value.
     * @return self
     */
    public function header(string $key, string $value): self;

    /**
     * Sets multiple headers for the request.
     *
     * @param array $headers An associative array of headers, where the key is the header name
     *                       and the value is the header value.
     * @return self
     */
    public function headers(array $headers): self;

    /**
     * Sets the User-Agent header for the request.
     *
     * @param string $useragent The User-Agent string to use.
     * @return self
     */
    public function useragent(string $useragent): self;

    /**
     * Sets the Content-Type header for the request.
     *
     * @param string $type The content type to set (e.g., 'application/json').
     * @return self
     */
    public function contentType(string $type): self;

    /**
     * Sets the Accept header for the request.
     *
     * @param string $type The accept type to set (e.g., 'application/json').
     * @return self
     */
    public function accept(string $type): self;

    /**
     * Sends a request to the specified URL with optional parameters.
     *
     * @param string $url The target URL.
     * @param array $params Optional query parameters to include in the request URL.
     * @return HttpResponseContract The response data, including body, status code, final URL, and content length.
     */
    public function send(string $url, array $params = []): HttpResponseContract;

    /**
     * Executes multiple HTTP requests concurrently using a pool.
     *
     * @param callable $callback A callback function that receives a Pool instance to add requests to.
     * @return array An array of responses from the executed requests.
     */
    public function pool(callable $callback): array;

    /**
     * Sets the HTTP method for the request.
     *
     * @param string $method The HTTP method to use (e.g., 'GET', 'POST', 'PUT', 'DELETE').
     * @return self
     */
    public function method(string $method): self;

    /**
     * Sets the request body for the HTTP request.
     *
     * @param string|array $body The body content to send with the request. Can be a string or an array (which will be JSON-encoded).
     * @return self
     */
    public function cookieJar(string $cookieJar): self;

    /**
     * Sets the proxy settings for the request.
     *
     * @param string $proxy The proxy URL (e.g., 'http://proxy.example.com:8080').
     * @param string $proxyAuth Optional proxy authentication credentials (e.g., 'username:password').
     * @return self
     */
    public function proxy(string $proxy, string $proxyAuth = ''): self;

    /**
     * Sets the download location for the response body.
     *
     * @param string $location The file path where the response body should be saved.
     * @param bool $force Whether to overwrite the file if it already exists (default: false).
     * @return self
     */
    public function download(string $location, bool $force = false): self;

    /**
     * Sets the timeout for the HTTP request.
     *
     * @param int $seconds The number of seconds to wait before timing out the request.
     * @return self
     */
    public function timeout(int $seconds): self;

    /**
     * Sets the fields to be sent in a POST request.
     *
     * @param array|string $fields The fields to send in the POST request. Can be an associative array or a string.
     * @param string|null $contentType Optional content type for the POST data (e.g., 'application/json').
     * @return self
     */
    public function postFields(array|string $fields, null|string $contentType = null): self;

    /**
     * Sets the fields to be sent in a PUT request.
     *
     * @param array|string $fields The fields to send in the PUT request. Can be an associative array or a string.
     * @param string|null $contentType Optional content type for the PUT data (e.g., 'application/json').
     * @return self
     */
    public function get(string $url, array $params = []): HttpResponseContract;

    /**
     * Sets the fields to be sent in a PATCH request.
     *
     * @param array|string $fields The fields to send in the PATCH request. Can be an associative array or a string.
     * @param string|null $contentType Optional content type for the PATCH data (e.g., 'application/json').
     * @return self
     */
    public function post(string $url, array|string $data = []): HttpResponseContract;

    /**
     * Sets the fields to be sent in a DELETE request.
     *
     * @param array|string $fields The fields to send in the DELETE request. Can be an associative array or a string.
     * @param string|null $contentType Optional content type for the DELETE data (e.g., 'application/json').
     * @return self
     */
    public function put(string $url, array|string $data = []): HttpResponseContract;

    /**
     * Sets the fields to be sent in a PATCH request.
     *
     * @param array|string $fields The fields to send in the PATCH request. Can be an associative array or a string.
     * @param string|null $contentType Optional content type for the PATCH data (e.g., 'application/json').
     * @return self
     */
    public function patch(string $url, array|string $data = []): HttpResponseContract;

    /**
     * Sets the fields to be sent in a DELETE request.
     *
     * @param array|string $fields The fields to send in the DELETE request. Can be an associative array or a string.
     * @param string|null $contentType Optional content type for the DELETE data (e.g., 'application/json').
     * @return self
     */
    public function delete(string $url, array|string $data = []): HttpResponseContract;
}