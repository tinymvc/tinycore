<?php

namespace Spark\Utils;

use Spark\Contracts\Utils\HttpUtilContract;
use Spark\Exceptions\Utils\PingUtilException;
use Spark\Helpers\HttpPool;
use Spark\Helpers\HttpResponse;
use Spark\Helpers\HttpRequest;
use Spark\Support\Traits\Macroable;
use function is_array;
use function is_resource;
use function is_string;

/**
 * Class Http
 *
 * A helper class for making HTTP requests in PHP using cURL. Supports GET, POST, PUT, PATCH, and DELETE methods,
 * as well as custom headers, options, user agents, and file downloads.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Http extends HttpRequest implements HttpUtilContract
{
    use Macroable;

    /**
     * File path to download response to.
     *
     * @var null|string|resource
     */
    private mixed $download = null;

    /**
     * The Http constructor.
     * 
     * Initializes a new HTTP request with the specified method, URL, parameters, 
     * data, download file, and key.
     * 
     * @param string $method HTTP method
     * @param string $url Target URL
     * @param array $params Query parameters
     * @param string|array $data POST/PUT/PATCH/DELETE data
     * @throws PingUtilException If cURL extension is not loaded
     */
    public function __construct(
        string $method = 'GET',
        string $url = '',
        array $params = [],
        string|array $data = []
    ) {
        // Check if cURL extension is loaded
        if (!extension_loaded('curl')) {
            throw new PingUtilException('cURL extension is not loaded.');
        }

        // Call parent constructor
        parent::__construct($method, $url, $params, $data);
    }

    /**
     * Resets the current configuration back to default, optionally overriding
     * certain configuration settings.
     * 
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $url Target URL
     * @param array $params Query parameters
     * @param string|array $data Request body data
     * @param string|int $key Request key
     */
    public function reset(string $method, string $url, array $params = [], string|array $data = []): void
    {
        $this->setMethod($method);
        $this->setUrl($url);
        $this->setParams($params);
        $this->setData($data);

        $this->download = null; // Reset download file
        $this->options = []; // Reset cURL options
        $this->headers = []; // Reset headers
    }

    /**
     * Sends an HTTP request to the specified URL with optional parameters.
     *
     * @param string $url The target URL.
     * @param array $params Optional query parameters to include in the request URL.
     * @return HttpResponse The response data, including body, status code, final URL, and content length.
     * @throws PingUtilException If cURL initialization fails.
     */
    public function send(string $url, array $params = []): HttpResponse
    {
        $this->setUrl($url);
        $this->setParams($params);

        $startedAt = microtime(true);

        $curl = $this->buildCurlHandle();

        // Set up file download if specified
        if (isset($this->download) && is_string($this->download)) {
            if (is_file($this->download)) {
                unlink($this->download); // Remove existing file if it exists
            }

            // Open the file for writing
            $this->download = fopen($this->download, 'w+');
            curl_setopt($curl, CURLOPT_FILE, $this->download);
        }

        // Start output buffering to capture the response body
        ob_start();
        echo curl_exec($curl);
        $body = ob_get_clean(); // Get the output buffer content

        // Check if output buffering was successful
        if ($body === false) {
            throw new PingUtilException('Failed to capture cURL output.');
        }

        // Check for cURL errors
        if (curl_errno($curl)) {
            throw new PingUtilException('cURL Error: ' . curl_error($curl));
        }

        // Execute the cURL request and gather response data
        $response = [
            'body' => $body ?: '',
            'status' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'lastUrl' => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
            'length' => curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD),
            'headers' => (array) curl_getinfo($curl, CURLINFO_HEADER_OUT) ?: [],
        ];

        // Close file if download was specified
        if (isset($this->download) && is_resource($this->download)) {
            fclose($this->download);
        }

        $this->triggerHttpRequestEvent(
            $this->getFullUrl(),
            [
                'method' => $this->getMethod(),
                'url' => $url,
                'params' => $this->getParams(),
                'body' => $this->getData(),
                'headers' => $this->getHeaders(),
            ],
            $response,
            $startedAt
        );

        // The response data, including body, status code, final URL, and content length.
        return new HttpResponse(...$response);
    }

    /**
     * Send multiple HTTP requests concurrently (in parallel).
     * 
     * This method allows you to send multiple HTTP requests at the same time,
     * which is much faster than sending them sequentially.
     * 
     * Example:
     * ```php
     * $responses = Http::pool(fn($pool) => [
     *     $pool->get('https://api.example.com/users'),
     *     $pool->post('https://api.example.com/posts', ['title' => 'Hello']),
     *     $pool->as('custom')->get('https://api.example.com/comments'),
     * ]);
     * 
     * // Access responses by index or key
     * $users = $responses[0]->json();
     * $posts = $responses[1]->json();
     * $comments = $responses['custom']->json();
     * ```
     * 
     * @param callable $callback A callback that receives a Pool instance and returns an array of requests
     * @return array An array of HttpResponse objects, keyed by their index or custom key
     */
    public function pool(callable $callback): array
    {
        $pool = new HttpPool();
        $requests = $callback($pool);

        if (!is_array($requests)) {
            throw new PingUtilException('Pool callback must return an array of requests.');
        }

        return $pool->execute($requests);
    }

    /**
     * Sets a single cURL option.
     * 
     * @deprecated Use withOption() instead for fluent interface
     * @param int $key The cURL option constant.
     * @param mixed $value The value for the option.
     * @return self
     */
    public function option(int $key, mixed $value): self
    {
        $this->withOption($key, $value);
        return $this;
    }

    /**
     * Sets the HTTP method for the request.
     * 
     * @deprecated Use withMethod() instead for fluent interface
     * @param string $method The HTTP method (e.g., 'GET', 'POST').
     * @return self
     */
    public function method(string $method): self
    {
        $this->setMethod($method);
        return $this;
    }

    /**
     * Sets multiple cURL options at once.
     * 
     * @deprecated Use withOptions() instead for fluent interface
     * @param array $options Associative array of cURL options.
     * @return self
     */
    public function options(array $options): self
    {
        $this->withOptions($options);
        return $this;
    }

    /**
     * Sets the User-Agent header for the request.
     * 
     * @deprecated Use withUserAgent() instead for consistency
     * @param string $useragent The User-Agent string.
     * @return self
     */
    public function useragent(string $useragent): self
    {
        $this->withUserAgent($useragent);
        return $this;
    }

    /**
     * Sets the Content-Type header for the request.
     * 
     * @deprecated Use withContentType() instead for consistency
     * @param string $type The Content-Type string (e.g., 'application/json').
     * @return self
     */
    public function contentType(string $type): self
    {
        $this->withContentType($type);
        return $this;
    }

    /**
     * Sets the Accept header for the request.
     * 
     * @deprecated Use withAccept() instead for consistency
     * @param string $type The Accept string (e.g., 'application/json').
     * @return self
     */
    public function accept(string $type): self
    {
        $this->withAccept($type);
        return $this;
    }

    /**
     * Adds a custom header to the request.
     * 
     * @deprecated Use withHeader() instead for consistency
     * @param string $key Header name.
     * @param string $value Header value.
     * @return self
     */
    public function header(string $key, string $value): self
    {
        $this->withHeader($key, $value);
        return $this;
    }

    /**
     * Adds multiple custom headers to the request.
     * 
     * @deprecated Use withHeaders() instead for consistency
     * @param array $headers Associative array of headers (key => value).
     * @return self
     */
    public function headers(array $headers): self
    {
        $this->withHeaders($headers);
        return $this;
    }

    /**
     * Add Cookies to the request.
     * 
     * @deprecated Use withCookies() instead for consistency
     * @param array $cookies Associative array of cookies (key => value).
     * @return self
     */
    public function cookie(array $cookies): self
    {
        $this->withCookies($cookies);
        return $this;
    }

    /**
     * Sets the cookie jar file path for storing cookies.
     * 
     * @deprecated Use withCookieJar() instead for consistency
     * @param string $cookieJar The file path to the cookie jar.
     * @return self
     */
    public function cookieJar(string $cookieJar): self
    {
        $this->withCookieJar($cookieJar);
        return $this;
    }

    /**
     * Sets a proxy for the request.
     * 
     * @deprecated Use withProxy() instead for consistency
     * @param string $proxy The proxy URL (e.g., 'http://proxy.example.com:8080').
     * @param string $proxyAuth Optional proxy authentication in the format 'username:password'.
     * @return self
     */
    public function proxy(string $proxy, string $proxyAuth = ''): self
    {
        $this->withProxy($proxy, $proxyAuth);
        return $this;
    }

    /**
     * Sets the file path to download the response to.
     *
     * @param string $location File path for download.
     * @param bool $force Whether to overwrite the file if it already exists.
     * @return self
     */
    public function download(string $location, bool $force = false): self
    {
        if (is_file($location) && !$force) {
            throw new \RuntimeException("File already exists at {$location}. Use force=true to overwrite.");
        }

        $this->download = $location;
        return $this;
    }

    /**
     * Sets the timeout for the request in seconds.
     * 
     * @deprecated Use withTimeout() instead for consistency
     * @param int $seconds Timeout in seconds.
     * @return self
     */
    public function timeout(int $seconds): self
    {
        $this->withTimeout($seconds);
        return $this;
    }

    /**
     * Sets fields for a POST request.
     *
     * @param array|string $fields The fields to include in the POST body. Can be an array or string.
     * @param string|null $contentType The Content-Type header value (auto-detected if null)
     * @return self
     */
    public function postFields(array|string $fields, null|string $contentType = null): self
    {
        $this->withPostFields($fields, $contentType);
        return $this;
    }

    /**
     * Send a GET request.
     * 
     * @param string $url Target URL
     * @param array $params Query parameters
     * @return HttpResponse
     */
    public function get(string $url, array $params = []): HttpResponse
    {
        $this->setMethod('GET');
        return $this->send($url, $params);
    }

    /**
     * Send a POST request.
     * 
     * @param string $url Target URL
     * @param array|string $data POST data
     * @return HttpResponse
     */
    public function post(string $url, array|string $data = []): HttpResponse
    {
        $this->setMethod('POST');
        $this->setData($data);

        return $this->send($url);
    }

    /**
     * Send a PUT request.
     * 
     * @param string $url Target URL
     * @param array|string $data PUT data
     * @return HttpResponse
     */
    public function put(string $url, array|string $data = []): HttpResponse
    {
        $this->setMethod('PUT');
        $this->setData($data);

        return $this->send($url);
    }

    /**
     * Send a PATCH request.
     * 
     * @param string $url Target URL
     * @param array|string $data PATCH data
     * @return HttpResponse
     */
    public function patch(string $url, array|string $data = []): HttpResponse
    {
        $this->setMethod('PATCH');
        $this->setData($data);

        return $this->send($url);
    }

    /**
     * Send a DELETE request.
     * 
     * @param string $url Target URL
     * @param array|string $data DELETE data
     * @return HttpResponse
     */
    public function delete(string $url, array|string $data = []): HttpResponse
    {
        $this->setMethod('DELETE');
        $this->setData($data);

        return $this->send($url);
    }

    /**
     * Logs the HTTP request details.
     *
     * @param string $url The request URL.
     * @param array $payload The cURL options used for the request.
     * @param array $response The response data from the request.
     * @param float $startedAt The timestamp when the request started.
     */
    private function triggerHttpRequestEvent(string $url, array $payload, array $response, float $startedAt): void
    {
        if (!is_debug_mode()) {
            return; // Skip logging in non-debug mode
        }

        $duration = round((microtime(true) - $startedAt) * 1000, 2); // in milliseconds

        event('app:http.request', [
            'url' => $url,
            'payload' => $payload,
            'response' => $response,
            'duration_ms' => $duration,
        ]);
    }
}
