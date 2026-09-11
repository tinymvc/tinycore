<?php

namespace Spark\Http\Client;

use Spark\Http\Client\Contracts\HttpContract;
use Spark\Http\Client\Contracts\HttpResponseContract;
use Spark\Http\Client\Exceptions\HttpException;
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
class Http extends HttpRequest implements HttpContract
{
    use Macroable;

    /**
     * File path to download response to.
     *
     * @var null|string
     */
    private ?string $download = null;

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
     * @throws HttpException If cURL extension is not loaded
     */
    public function __construct(
        string $method = 'GET',
        string $url = '',
        array $params = [],
        string|array $data = []
    ) {
        // Check if cURL extension is loaded
        if (!extension_loaded('curl')) {
            throw new HttpException('cURL extension is not loaded.');
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
     */
    public function reset(string $method, string $url, array $params = [], string|array $data = []): void
    {
        $this->clearTemporaryUploadFiles();

        $this->attachments = [];
        $this->isMultipart = false;
        $this->responseHeaders = [];
        $this->retryTimes = 2;
        $this->retryDelayMs = 200;

        $this->setMethod($method);
        $this->setUrl($url);
        $this->setParams($params);
        $this->setData($data);

        $this->download = null; // Reset download file
        $this->options = []; // Reset cURL options
        $this->postFieldData = null;
        $this->headers = []; // Reset headers
    }

    /**
     * Sends an HTTP request to the specified URL with optional parameters.
     *
     * Retries automatically on cURL failure (see withRetry()/retry()). Never
     * throws for request failures — after all attempts are exhausted, a
     * HttpResponse with status 0 is returned instead.
     *
     * @param string $url The target URL.
     * @param array $params Optional query parameters to include in the request URL.
     * @return HttpResponseContract The response data, including body, status code, final URL, and content length.
     * @throws HttpException If the download target is invalid.
     */
    public function send(string $url, array $params = []): HttpResponseContract
    {
        $this->setUrl($url);
        $this->setParams($params);

        $startedAt = microtime(true);
        $downloadPath = $this->download;
        $downloadMode = is_string($downloadPath) && $downloadPath !== '';

        $temporaryDownload = null;
        try {
            if ($downloadMode) {
                $this->prepareDownloadTarget($downloadPath);
                $temporaryDownload = tempnam(dirname($downloadPath), '.spark_download_');
                if ($temporaryDownload === false) {
                    throw new HttpException('Unable to create temporary download file.');
                }
            }

            $data = $this->retry(function () use ($temporaryDownload, $downloadMode): array {
                $curl = $this->buildCurlHandle();
                $downloadStream = null;

                try {
                    if ($downloadMode) {
                        $downloadStream = $this->openDownloadStream($temporaryDownload);
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
                        if (!curl_setopt($curl, CURLOPT_FILE, $downloadStream)) {
                            throw new HttpException('Unable to configure download stream.');
                        }
                    }

                    return $this->executeCurlHandle($curl, $downloadMode);
                } finally {
                    $this->closeCurlHandle($curl);
                    if (is_resource($downloadStream)) {
                        fclose($downloadStream);
                    }
                }
            });

            if ($downloadMode && $data['status'] !== 0 && @rename($temporaryDownload, $downloadPath) === false) {
                throw new HttpException("Unable to save download file: {$downloadPath}");
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
                $data,
                $startedAt
            );

            return new HttpResponse(...$data);
        } finally {
            if (is_string($temporaryDownload) && is_file($temporaryDownload)) {
                @unlink($temporaryDownload);
            }
            $this->download = null;
        }
    }

    /**
     * Validate and prepare the download target before any attempt runs.
     *
     * @param string $downloadPath
     * @throws HttpException
     */
    private function prepareDownloadTarget(string $downloadPath): void
    {
        $directory = dirname($downloadPath);
        if ($directory !== '' && !is_dir($directory)) {
            throw new HttpException("Download directory does not exist: {$directory}");
        }

        if (file_exists($downloadPath)) {
            throw new HttpException("Download target already exists: {$downloadPath}");
        }
    }

    /**
     * Open a fresh writable stream for the download target (reopened per attempt).
     *
     * @param string $downloadPath
     * @return resource
     * @throws HttpException
     */
    private function openDownloadStream(string $downloadPath): mixed
    {
        $stream = @fopen($downloadPath, 'wb');
        if (!is_resource($stream)) {
            throw new HttpException("Unable to open download file: {$downloadPath}");
        }

        return $stream;
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
     * 
     * @throws HttpException If the callback does not return an array of requests
     */
    public static function pool(callable $callback): array
    {
        $pool = new HttpPool();
        $requests = $callback($pool);

        if (!is_array($requests)) {
            throw new HttpException('Pool callback must return an array of requests.');
        }

        return $pool->execute($requests);
    }

    /**
     * Sets a single cURL option.
     * 
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
     * @param bool $force Whether to overwrite the file if it already exists (default: false).
     * @return self
     */
    public function download(string $location, bool $force = false): self
    {
        if (is_file($location) && !$force) {
            throw new \RuntimeException("File already exists at {$location}. Use force=true to overwrite.");
        }

        $directory = dirname($location);
        if ($directory !== '' && !is_dir($directory)) {
            throw new HttpException("Download directory does not exist: {$directory}");
        }

        $this->download = $location;
        return $this;
    }

    /**
     * Sets the timeout for the request in seconds.
     * 
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
     * @return HttpResponseContract The response data, including body, status code, final URL, and content length.
     */
    public function get(string $url, array $params = []): HttpResponseContract
    {
        $this->setMethod('GET');
        $this->setData([]);
        return $this->send($url, $params);
    }

    /**
     * Send a POST request.
     * 
     * @param string $url Target URL
     * @param array|string $data POST data
     * @return HttpResponseContract The response data, including body, status code, final URL, and content length.
     */
    public function post(string $url, array|string $data = []): HttpResponseContract
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
     * @return HttpResponseContract The response data, including body, status code, final URL, and content length.
     */
    public function put(string $url, array|string $data = []): HttpResponseContract
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
     * @return HttpResponseContract The response data, including body, status code, final URL, and content length.
     */
    public function patch(string $url, array|string $data = []): HttpResponseContract
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
     * @return HttpResponseContract The response data, including body, status code, final URL, and content length.
     */
    public function delete(string $url, array|string $data = []): HttpResponseContract
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
