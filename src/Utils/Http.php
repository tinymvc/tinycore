<?php

namespace Spark\Utils;

use Spark\Contracts\Utils\HttpUtilContract;
use Spark\Exceptions\Utils\PingUtilException;
use Spark\Helpers\HttpResponse;
use Spark\Helpers\Traits\HttpConfigurable;
use Spark\Support\Traits\Macroable;

/**
 * Class Http
 *
 * A helper class for making HTTP requests in PHP using cURL. Supports GET, POST, PUT, PATCH, and DELETE methods,
 * as well as custom headers, options, user agents, and file downloads.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Http implements HttpUtilContract
{
    use Macroable, HttpConfigurable;

    /**
     * Constructor for the ping class.
     *
     * Initializes an instance of the ping class with optional configuration settings.
     * The settings are merged with the default configuration and can be used to customize the cURL options,
     * user agent, and download behavior.
     *
     * @param array $config Optional configuration settings. See the reset method for available options.
     */
    public function __construct(private array $config = [])
    {
        // Check if cURL extension is loaded
        if (!extension_loaded('curl')) {
            throw new PingUtilException('cURL extension is not loaded.');
        }

        $this->reset($config);
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
        $curl = curl_init();
        if ($curl === false) {
            throw new PingUtilException('Failed to initialize cURL.');
        }

        $startedAt = microtime(true);

        // Build URL with query parameters
        if (!empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }

        // Default cURL options for the request
        $defaultOptions = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => 'utf-8',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => $this->config['headers'],
            CURLOPT_URL => $url
        ];

        // Set up file download if specified
        if ($this->config['download']) {
            $download = fopen($this->config['download'], 'w+');
            $defaultOptions[CURLOPT_FILE] = $download;
        }

        curl_setopt_array($curl, $defaultOptions);
        curl_setopt_array($curl, $this->config['options']);

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
            'body' => $body,
            'status' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'last_url' => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
            'length' => curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD),
            'headers' => curl_getinfo($curl, CURLINFO_HEADER_OUT),
        ];

        // Close file if download was specified
        if ($this->config['download']) {
            fclose($download);
        }

        $this->logHttpRequest($url, [
            'url' => $url,
            'method' => $this->config['options'][CURLOPT_CUSTOMREQUEST] ?? 'GET',
            'params' => $params,
            'headers' => $this->config['headers'],
        ], $response, $startedAt);

        // The response data, including body, status code, final URL, and content length.
        return new HttpResponse($response);
    }

    /**
     * Resets the current configuration.
     *
     * Resets the current configuration back to default, optionally overriding
     * certain configuration settings.
     *
     * @param array $config An associative array of configuration settings.
     */
    public function reset(array $config = []): void
    {
        $this->config = array_merge([
            'headers' => [],
            'options' => [],
            'download' => null,
        ], $config);
    }

    /**
     * Add a header to the internal headers array.
     * 
     * @param string $header Header string in "Key: Value" format
     * @return void
     */
    protected function addHeader(string $header): void
    {
        $this->config['headers'][] = $header;
    }

    /**
     * Set a cURL option in the internal options array.
     * 
     * @param int $option cURL option constant
     * @param mixed $value Option value
     * @return void
     */
    protected function setOption(int $option, mixed $value): void
    {
        $this->config['options'][$option] = $value;
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
        return $this->withOption($key, $value);
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
        return $this->withOptions($options);
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
        return $this->withUserAgent($useragent);
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
        return $this->withContentType($type);
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
        return $this->withAccept($type);
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
        return $this->withHeader($key, $value);
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
        return $this->withHeaders($headers);
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
        return $this->withCookies($cookies);
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
        return $this->withCookieJar($cookieJar);
    }

    /**
     * Sets a proxy for the request.
     * 
     * @deprecated Use withProxy() instead for consistency
     * @param string $proxy The proxy URL (e.g., 'http://proxy.example.com:8080').
     * @param string $proxyAuth Optional proxy authentication in the format 'username:password'.
     * @param array $options Additional cURL options for the proxy.
     * @return self
     */
    public function setProxy(string $proxy, string $proxyAuth = '', array $options = []): self
    {
        $this->withProxy($proxy, $proxyAuth);

        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }

        return $this;
    }

    /**
     * Sets the file path to download the response to.
     *
     * @param string $location File path for download.
     * @return self
     */
    public function download(string $location): self
    {
        $this->config['download'] = $location;
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
        if ($contentType === null && is_array($fields)) {
            $contentType = 'application/json';
        }
        // Auto-detect content type for string fields
        elseif ($contentType === null && is_string($fields)) {
            $decoded = json_decode($fields, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $contentType = 'application/json';
            }
        }

        $contentType ??= 'application/x-www-form-urlencoded';

        // Set the Content-Type header
        $this->withContentType($contentType);

        // Prepare post fields based on content type
        $postFields = $contentType === 'application/json' && is_array($fields) ? json_encode($fields) :
            (is_array($fields) && $contentType === 'application/x-www-form-urlencoded' ? http_build_query($fields) : $fields);

        return $this->withOptions([CURLOPT_POSTFIELDS => $postFields]);
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
        return $this->postFields($data)->withOptions([
            CURLOPT_POST => 1,
            CURLOPT_CUSTOMREQUEST => 'POST',
        ])->send($url);
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
        return $this->withOption(CURLOPT_CUSTOMREQUEST, 'PUT')
            ->postFields($data)->send($url);
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
        return $this->withOption(CURLOPT_CUSTOMREQUEST, 'PATCH')
            ->postFields($data)->send($url);
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
        return $this->withOption(CURLOPT_CUSTOMREQUEST, 'DELETE')
            ->postFields($data)->send($url);
    }

    /**
     * Logs the HTTP request details.
     *
     * @param string $url The request URL.
     * @param array $payload The cURL options used for the request.
     * @param array $response The response data from the request.
     * @param float $startedAt The timestamp when the request started.
     */
    private function logHttpRequest(string $url, array $payload, array $response, float $startedAt): void
    {
        if (!env('debug')) {
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
