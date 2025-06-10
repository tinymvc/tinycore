<?php

namespace Spark\Contracts\Http;

/**
 * Interface defining the contract for the Response class.
 */
interface ResponseContract
{
    /**
     * Sets the response content to a specified string, replacing any existing content.
     *
     * @param string $content The content to set in the response body.
     * @return self Current response instance for method chaining.
     */
    public function setContent(string $content): self;

    /**
     * Appends content to the existing response body.
     *
     * @param string $content The content to append to the response body.
     * @return self Current response instance for method chaining.
     */
    public function write(string $content): self;

    /**
     * Sends a JSON response to the client.
     *
     * @param array $data The data to be encoded as JSON.
     * @param int $statusCode The HTTP status code to send with the response. Defaults to 200.
     * @param int $flags The JSON encoding flags. Defaults to 0.
     * @param int $depth Optional recursion depth. Defaults to 512.
     * @return self Current response instance for method chaining.
     */
    public function json(array $data, int $statusCode = 200, int $flags = 0, int $depth = 512): self;

    /**
     * Redirects the user to a specified URL and optionally terminates script execution.
     *
     * @param string $url The URL to redirect to.
     * @param int $httpCode Optional HTTP status code for the redirect (default is 0).
     * @return self Current response instance for method chaining.
     */
    public function redirect(string $url, int $httpCode = 0): self;

    /**
     * Sets the HTTP status code for the response.
     *
     * @param int $statusCode The HTTP status code to set (e.g., 200, 404).
     * @return self Current response instance for method chaining.
     */
    public function setStatusCode(int $statusCode): self;

    /**
     * Sets a header for the response.
     *
     * @param string $key The header name (e.g., 'Content-Type').
     * @param string $value The header value (e.g., 'application/json').
     * @return self Current response instance for method chaining.
     */
    public function setHeader(string $key, string $value): self;

    /**
     * Sends the HTTP response to the client, including headers, status code, and content.
     * Applies any output filters to the content before outputting it.
     *
     * @return void
     */
    public function send(): void;
}