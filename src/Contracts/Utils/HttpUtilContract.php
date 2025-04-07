<?php

namespace Spark\Contracts\Utils;

/**
 * Interface defining the contract for the Http utility class.
 *
 * The Ping utility class provides a simple way to make HTTP requests from
 * your application. It uses the GuzzleHttp\Client class under the hood.
 */
interface HttpUtilContract
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
     * Sets a single header for the request.
     *
     * @param string $key The header name.
     * @param string $value The header value.
     * @return self
     */
    public function header(string $key, string $value): self;

    /**
     * Sends a request to the specified URL with optional parameters.
     *
     * @param string $url The target URL.
     * @param array $params Optional query parameters to include in the request URL.
     * @return array The response data, including body, status code, final URL, and content length.
     */
    public function send(string $url, array $params = []): array;
}