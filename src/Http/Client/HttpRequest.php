<?php

namespace Spark\Http\Client;

use Spark\Http\Client\Contracts\HttpRequestContract;
use Spark\Http\Client\Contracts\HttpResponseContract;
use Spark\Http\Client\Exceptions\HttpException;
use Spark\Support\Traits\Macroable;
use function array_key_exists;
use function in_array;
use function is_array;
use function is_file;
use function is_object;
use function is_resource;
use function is_string;
use function strlen;

/**
 * Class PendingRequest
 * 
 * Represents a pending HTTP request in the pool.
 * 
 * @package Spark\Http\Client
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class HttpRequest implements HttpRequestContract
{
    use Macroable;

    /** @var int Number of retry attempts after the initial try. */
    protected int $retryTimes = 2;

    /** @var int Delay between retry attempts, in milliseconds. */
    protected int $retryDelayMs = 200;

    /** @var array Request headers */
    protected array $headers = [];

    /** @var array cURL options */
    protected array $options = [];

    /** @var array File attachments for multipart requests */
    protected array $attachments = [];

    /** @var bool Whether this is a multipart request */
    protected bool $isMultipart = false;

    /** @var array Parsed response headers from the latest execution. */
    protected array $responseHeaders = [];

    /** @var array<string> Upload files retained for reuse until reset, explicit cleanup, or destruction. */
    protected array $temporaryUploadFiles = [];

    /** Original fluent body fields, retained for switching to multipart. */
    protected array|string|null $postFieldData = null;

    /**
     * Constructor.
     * 
     * @param string $method HTTP method
     * @param string $url Target URL
     * @param array $params Query parameters
     * @param string|array $data POST/PUT/PATCH data
     * @param string|int $key Request key
     */
    public function __construct(
        protected string $method = 'GET',
        protected string $url = '',
        protected array $params = [],
        protected string|array $data = [],
        protected string|int $key = 0
    ) {
        if (!extension_loaded('curl')) {
            throw new HttpException('cURL extension is not loaded.');
        }
    }

    /**
     * Create a new HttpRequest instance.
     *
     * @param string $method HTTP method
     * @param string $url Target URL
     * @param array $params Query parameters
     * @param string|array $data POST/PUT/PATCH data
     * @param string|int $key Request key
     * @return self
     */
    public static function make(
        string $method = 'GET',
        string $url = '',
        array $params = [],
        string|array $data = [],
        string|int $key = 0
    ) {
        return new static($method, $url, $params, $data, $key);
    }

    /**
     * Get the request key.
     * 
     * @return string|int
     */
    public function getKey(): string|int
    {
        return $this->key;
    }

    /**
     * Add a header to the internal headers array.
     * 
     * @param string $header Header string in "Key: Value" format
     * @return void
     */
    public function addHeader(string $header): void
    {
        $this->headers[] = $header;
    }

    /**
     * Set a cURL option in the internal options array.
     * 
     * @param int $option cURL option constant
     * @param mixed $value Option value
     * @return void
     */
    public function setOption(int $option, mixed $value): void
    {
        $this->options[$option] = $value;
        if ($option === CURLOPT_POSTFIELDS) {
            $this->postFieldData = null;
        }
    }

    /**
     * Add a header to the request.
     * 
     * @param string $key Header name
     * @param string $value Header value
     * @return self
     */
    public function withHeader(string $key, string $value): self
    {
        $this->headers = array_values(array_filter(
            $this->headers,
            fn(string $header): bool => strcasecmp(trim(explode(':', $header, 2)[0]), $key) !== 0
        ));
        $this->addHeader("$key: $value");
        return $this;
    }

    /**
     * Add multiple headers to the request.
     * 
     * @param array $headers Associative array of headers
     * @return self
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $key => $value) {
            $this->withHeader($key, $value);
        }
        return $this;
    }

    /**
     * Set the Content-Type header.
     * 
     * @param string $type Content-Type value
     * @return self
     */
    public function withContentType(string $type): self
    {
        return $this->withHeader('Content-Type', $type);
    }

    /**
     * Set the Accept header.
     * 
     * @param string $type Accept value
     * @return self
     */
    public function withAccept(string $type): self
    {
        return $this->withHeader('Accept', $type);
    }

    /**
     * Configure retry behavior for failed requests.
     *
     * By default, a request is retried DEFAULT_RETRY_TIMES times (i.e.
     * DEFAULT_RETRY_TIMES + 1 attempts total) with a 200ms
     * delay in between. Once all attempts are exhausted, execute()/send()
     * never throw — they return a HttpResponse with status 0 instead.
     *
     * @param int $times Number of retry attempts after the initial try
     * @param int $delayMs Delay between attempts, in milliseconds
     * @return self
     */
    public function withRetry(int $times, int $delayMs = 200): self
    {
        $this->retryTimes = max(0, $times);
        $this->retryDelayMs = max(0, $delayMs);

        return $this;
    }

    /**
     * Set the User-Agent header.
     * 
     * @param string $userAgent User-Agent value
     * @return self
     */
    public function withUserAgent(string $userAgent): self
    {
        $this->setOption(CURLOPT_USERAGENT, $userAgent);
        return $this;
    }

    /**
     * Set bearer token authentication.
     * 
     * @param string $token Bearer token
     * @return self
     */
    public function withToken(string $token): self
    {
        return $this->withHeader('Authorization', "Bearer $token");
    }

    /**
     * Set basic authentication credentials.
     * 
     * @param string $username Username
     * @param string $password Password
     * @return self
     */
    public function withBasicAuth(string $username, string $password): self
    {
        $this->setOption(CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        $this->setOption(CURLOPT_USERPWD, "$username:$password");
        return $this;
    }

    /**
     * Add cookies to the request.
     * 
     * @param array $cookies Associative array of cookies (key => value)
     * @return self
     */
    public function withCookies(array $cookies): self
    {
        $cookieStrings = [];
        foreach ($cookies as $name => $value) {
            $cookieStrings[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        $this->setOption(CURLOPT_COOKIE, implode('; ', $cookieStrings));

        return $this;
    }

    /**
     * Set a cookie jar file path for storing cookies.
     * 
     * @param string $cookieJar File path to the cookie jar
     * @return self
     */
    public function withCookieJar(string $cookieJar): self
    {
        $directory = dirname($cookieJar);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new HttpException("Unable to create cookie jar directory: {$directory}");
        }

        if (!is_file($cookieJar)) {
            if (touch($cookieJar) === false) {
                throw new HttpException("Unable to create cookie jar file: {$cookieJar}");
            }
        }

        if (!is_readable($cookieJar) || !is_writable($cookieJar)) {
            throw new HttpException("Cookie jar is not readable/writable: {$cookieJar}");
        }

        $this->setOption(CURLOPT_COOKIEJAR, $cookieJar);
        $this->setOption(CURLOPT_COOKIEFILE, $cookieJar);
        return $this;
    }

    /**
     * Set a proxy for the request.
     * 
     * @param string $proxy Proxy URL
     * @param string $proxyAuth Optional proxy authentication (username:password)
     * @return self
     */
    public function withProxy(string $proxy, string $proxyAuth = ''): self
    {
        $this->setOption(CURLOPT_PROXY, $proxy);

        if (!empty($proxyAuth)) {
            $this->setOption(CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
            $this->setOption(CURLOPT_PROXYUSERPWD, $proxyAuth);
        }

        return $this;
    }

    /**
     * Set a single cURL option.
     * 
     * @param int $option cURL option constant
     * @param mixed $value Option value
     * @return self
     */
    public function withOption(int $option, mixed $value): self
    {
        $this->setOption($option, $value);
        return $this;
    }

    /**
     * Set multiple cURL options.
     * 
     * @param array $options Associative array of cURL options
     * @return self
     */
    public function withOptions(array $options): self
    {
        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }
        return $this;
    }

    /**
     * Set request timeout in seconds.
     * 
     * @param int $seconds Timeout in seconds
     * @return self
     */
    public function withTimeout(int $seconds): self
    {
        return $this->withOption(CURLOPT_TIMEOUT, $seconds);
    }

    /**
     * Disable SSL verification (not recommended for production).
     * 
     * @return self
     */
    public function withoutVerifying(): self
    {
        $this->setOption(CURLOPT_SSL_VERIFYPEER, false);
        $this->setOption(CURLOPT_SSL_VERIFYHOST, 0);
        return $this;
    }

    /**
     * Enable SSL verification.
     * 
     * @return self
     */
    public function withVerifying(): self
    {
        $this->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $this->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        return $this;
    }

    /**
     * Sets fields for a POST request.
     *
     * @param array|string $fields The fields to include in the POST body. Can be an array or string.
     * @param string|null $contentType The Content-Type header value (auto-detected if null)
     * @return self
     */
    public function withPostFields(array|string $fields, null|string $contentType = null): self
    {
        [$postFields, $contentType] = $this->encodePostFields($fields, $contentType);
        $this->withContentType($contentType);
        $this->withOption(CURLOPT_POSTFIELDS, $postFields);
        $this->postFieldData = $fields;
        return $this;
    }

    /**
     * Encode a body without changing the pending request's options or headers.
     *
     * @return array{string, string} Encoded body and content type.
     */
    protected function encodePostFields(array|string $fields, ?string $contentType = null): array
    {
        if ($contentType === null && is_array($fields)) {
            $contentType = 'application/json';
        }

        if ($contentType === null && is_string($fields)) {
            json_decode($fields, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $contentType = 'application/json';
            }
        }

        $contentType ??= 'application/x-www-form-urlencoded';

        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        $postFields = ($mediaType === 'application/json' || str_ends_with($mediaType, '+json'))
            ? (is_array($fields)
                ? json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $fields)
            : (is_array($fields)
                ? http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
                : $fields);

        if ($postFields === false) {
            throw new HttpException('Invalid POST field payload for the selected content type.');
        }

        return [$postFields, $contentType];
    }

    /**
     * Attach a file to the request.
     *
     * @param string $name The form field name
     * @param string|resource $contents The file contents or file path
     * @param string|null $filename The filename to use (optional)
     * @param array $headers Additional headers for this file part (optional)
     * @return self
     */
    public function attach(string $name, mixed $contents, ?string $filename = null, array $headers = []): self
    {
        if (is_string($contents) && is_file($contents)) {
            if (!is_readable($contents)) {
                throw new HttpException("Unable to read attachment file: {$contents}");
            }

            $mimeType = $headers['Content-Type'] ?? (function_exists('mime_content_type')
                ? (mime_content_type($contents) ?: 'application/octet-stream')
                : 'application/octet-stream');
            $filename ??= basename($contents);

            $this->attachments[$name] = new \CURLFile($contents, $mimeType, $filename);
            $this->isMultipart = true;

            return $this;
        }

        if (!is_resource($contents) && !is_string($contents)) {
            throw new HttpException('Upload content must be a string, resource, or existing file path.');
        }

        if (is_resource($contents) && get_resource_type($contents) !== 'stream') {
            throw new HttpException('Upload resource must be a stream.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'spark_upload_');
        if (!is_string($tempFile)) {
            throw new HttpException('Failed to create temporary upload file.');
        }
        $this->temporaryUploadFiles[] = $tempFile;

        if (is_resource($contents)) {
            $meta = stream_get_meta_data($contents);
            if (($meta['seekable'] ?? false) === true) {
                rewind($contents);
            }

            $tempContents = stream_get_contents($contents);
            if ($tempContents === false) {
                throw new HttpException('Failed to read upload resource.');
            }

            $contents = $tempContents;
        }

        if (file_put_contents($tempFile, (string) $contents) === false) {
            throw new HttpException('Failed to write temporary upload file.');
        }

        $mimeType = $headers['Content-Type'] ?? 'application/octet-stream';
        $filename ??= 'file';

        $this->attachments[$name] = new \CURLFile($tempFile, $mimeType, $filename);
        $this->isMultipart = true;

        return $this;
    }

    /**
     * Indicate the request is a multipart form request.
     *
     * @return self
     */
    public function asMultipart(): self
    {
        $this->isMultipart = true;
        return $this;
    }

    /**
     * Check if the request has attachments.
     *
     * @return bool
     */
    public function hasAttachments(): bool
    {
        return !empty($this->attachments);
    }

    /**
     * Get the attachments.
     *
     * @return array
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * Check if this is a multipart request.
     *
     * @return bool
     */
    public function isMultipart(): bool
    {
        return $this->isMultipart;
    }

    /**
     * Set the HTTP method.
     * 
     * @param string $method
     * @return void
     */
    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    /**
     * Set the request URL.
     * 
     * @param string $url
     * @return void
     */
    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    /**
     * Set the query parameters.
     * 
     * @param array $params
     * @return void
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * Set the request data.
     * 
     * @param string|array $data
     * @return void
     */
    public function setData(string|array $data): void
    {
        $this->data = $data;
    }

    /**
     * Get the HTTP method.
     * 
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method ?? 'GET';
    }

    /**
     * Get the request URL.
     * 
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url ?? '';
    }

    /**
     * Get the query parameters.
     * 
     * @return array
     */
    public function getParams(): array
    {
        return $this->params ?? [];
    }

    /**
     * Get the request data.
     * 
     * @return null|string|array
     */
    public function getData(): null|string|array
    {
        return $this->data ?? null;
    }

    /**
     * Get the request headers.
     * 
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers ?? [];
    }

    /**
     * Get the full URL with query parameters.
     * 
     * @return string
     */
    public function getFullUrl(): string
    {
        // Build URL with query parameters
        [$url, $fragment] = array_pad(explode('#', $this->url, 2), 2, null);
        $query = http_build_query($this->params, '', '&', PHP_QUERY_RFC3986);
        if ($query !== '') {
            $separator = str_contains($url, '?') ? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&') : '?';
            $url .= "$separator$query";
        }

        return $url . ($fragment === null ? '' : "#$fragment");
    }

    /**
     * Get response headers parsed from the request.
     *
     * @return array
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /**
     * Clear temporary upload files created during attachment handling.
     */
    public function clearTemporaryUploadFiles(): void
    {
        foreach ($this->attachments as $name => $attachment) {
            if ($attachment instanceof \CURLFile && in_array($attachment->getFilename(), $this->temporaryUploadFiles, true)) {
                unset($this->attachments[$name]);
            }
        }

        foreach ($this->temporaryUploadFiles as $tempFile) {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }

        $this->temporaryUploadFiles = [];
    }

    public function __destruct()
    {
        $this->clearTemporaryUploadFiles();
    }

    /**
     * Build the cURL handle for this request.
     * 
     * @return resource|\CurlHandle The cURL handle
     * @throws HttpException
     */
    public function buildCurlHandle()
    {
        $this->responseHeaders = [];

        $method = strtoupper($this->method);
        $options = $this->options;
        $headers = $options[CURLOPT_HTTPHEADER] ?? [];

        if (!is_array($headers)) {
            throw new HttpException('The cURL HTTP headers option must be an array.');
        }

        foreach ($this->headers as $header) {
            $name = trim(explode(':', $header, 2)[0]);
            $headers = array_values(array_filter($headers, fn(string $line): bool =>
                strcasecmp(trim(explode(':', $line, 2)[0]), $name) !== 0));
        }

        $headers = [...$headers, ...$this->headers];
        unset($options[CURLOPT_HTTPHEADER]);
        $userHeaderCallback = null;

        if (array_key_exists(CURLOPT_HEADERFUNCTION, $options)) {
            $userHeaderCallback = $options[CURLOPT_HEADERFUNCTION];
            unset($options[CURLOPT_HEADERFUNCTION]);

            if ($userHeaderCallback !== null && !is_callable($userHeaderCallback)) {
                throw new HttpException('The cURL header callback must be callable.');
            }
        }

        $defaultOptions = [
            CURLOPT_URL => $this->getFullUrl(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => function ($curlHandle, string $headerLine) use ($userHeaderCallback): int {
                $this->parseHeaderLine($headerLine);

                if (is_callable($userHeaderCallback)) {
                    return (int) $userHeaderCallback($curlHandle, $headerLine);
                }

                return strlen($headerLine);
            },
        ];

        if ($method === 'POST') {
            $defaultOptions[CURLOPT_POST] = true;
        } elseif ($method === 'HEAD') {
            $defaultOptions[CURLOPT_NOBODY] = true;
        } else {
            $defaultOptions[CURLOPT_CUSTOMREQUEST] = $method;
        }

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            if ($this->isMultipart || !empty($this->attachments)) {
                $fields = $this->postFieldData ?? $options[CURLOPT_POSTFIELDS] ?? $this->data;
                if (!is_array($fields)) {
                    throw new HttpException('Multipart form fields must be an array.');
                }

                $options[CURLOPT_POSTFIELDS] = array_replace($this->flattenArray($fields), $this->attachments);
                // cURL must generate the multipart boundary and its Content-Type.
                $headers = array_values(array_filter($headers, fn(string $header): bool =>
                    strcasecmp(trim(explode(':', $header, 2)[0]), 'Content-Type') !== 0));
            } elseif (!array_key_exists(CURLOPT_POSTFIELDS, $options) && $this->data !== []) {
                $contentType = null;
                foreach ($headers as $header) {
                    if (strcasecmp(trim(explode(':', $header, 2)[0]), 'Content-Type') === 0) {
                        $contentType = trim(explode(':', $header, 2)[1] ?? '');
                    }
                }

                [$options[CURLOPT_POSTFIELDS], $detectedContentType] = $this->encodePostFields($this->data, $contentType);
                if ($contentType === null) {
                    $headers[] = "Content-Type: $detectedContentType";
                }
            }
        }

        if ($headers !== []) {
            $defaultOptions[CURLOPT_HTTPHEADER] = $headers;
        }

        $curl = curl_init();
        if ($curl === false) {
            throw new HttpException('Failed to initialize cURL.');
        }

        try {
            if (!curl_setopt_array($curl, array_replace($defaultOptions, $options))) {
                throw new HttpException('Failed to configure cURL: ' . curl_error($curl));
            }
        } catch (\Throwable $error) {
            $this->closeCurlHandle($curl);
            if ($error instanceof \ValueError || $error instanceof \TypeError) {
                throw new HttpException('Invalid cURL options: ' . $error->getMessage(), 0, $error);
            }

            throw $error;
        }

        return $curl;
    }

    /**
     * Run a single cURL exec against a built handle and normalize the result
     * into a response-data array. This is the shared core used by both
     * HttpRequest::execute() and Http::send() to avoid duplicating the
     * exec/error-check/response-building logic.
     *
     * @param resource|\CurlHandle $curl
     * @param bool $downloadMode When true, the body is omitted from the result
     *                           (it was streamed directly to disk instead).
     * @return array{body:string,status:int,lastUrl:string,length:int,headers:array}
     * @throws HttpException on cURL failure
     */
    protected function executeCurlHandle(mixed $curl, bool $downloadMode = false): array
    {
        $body = curl_exec($curl);

        if ($body === false || curl_errno($curl)) {
            throw new HttpException('cURL Error: ' . curl_error($curl));
        }

        return [
            'body' => $downloadMode ? '' : (string) $body,
            'status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'lastUrl' => (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
            'length' => (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD),
            'headers' => $this->responseHeaders,
        ];
    }

    /**
     * Run an attempt callback with retry support.
     *
     * The callback performs a single request attempt (build handle, execute,
     * clean up) and returns response-data array, or throws HttpException on
     * failure. This method never throws: once all attempts are exhausted, it
     * returns a "failed" response-data array (status 0) instead.
     *
     * @param callable():array{body:string,status:int,lastUrl:string,length:int,headers:array} $attempt
     * @return array{body:string,status:int,lastUrl:string,length:int,headers:array}
     */
    protected function retry(callable $attempt): array
    {
        $attempts = max(1, $this->retryTimes + 1);

        for ($try = 1; $try <= $attempts; $try++) {
            try {
                return $attempt();
            } catch (HttpException) {
                if ($try < $attempts && $this->retryDelayMs > 0) {
                    usleep($this->retryDelayMs * 1000);
                }
            }
        }

        return [
            'body' => '',
            'status' => 0,
            'lastUrl' => $this->getFullUrl(),
            'length' => 0,
            'headers' => $this->responseHeaders,
        ];
    }

    /**
     * Execute the HTTP request.
     *
     * Retries automatically on cURL failure (see withRetry()). Never throws:
     * if every attempt fails, a HttpResponse with status 0 is returned.
     *
     * @return HttpResponseContract
     */
    public function execute(): HttpResponseContract
    {
        $data = $this->retry(function (): array {
            $curl = $this->buildCurlHandle();

            try {
                return $this->executeCurlHandle($curl);
            } finally {
                $this->closeCurlHandle($curl);
            }
        });

        return new HttpResponse(...$data);
    }

    /**
     * Safely close a cURL handle across supported PHP versions.
     *
     * PHP 8.1+ typically represents handles as objects, while some environments
     * may still provide resource-based handles.
     *
     * @param mixed $handle
     */
    protected function closeCurlHandle(mixed &$handle): void
    {
        if ($handle === null) {
            return;
        }

        if (is_object($handle) && method_exists($handle, 'close')) {
            $handle->close();
            $handle = null;
            return;
        }

        if (is_object($handle)) {
            $handle = null;
            return;
        }

        if (is_resource($handle)) {
            $handle = null;
        }
    }

    /**
     * Parse response header lines from cURL.
     *
     * @param string $headerLine
     */
    protected function parseHeaderLine(string $headerLine): void
    {
        $trimmed = trim($headerLine);
        if (str_starts_with($trimmed, 'HTTP/')) {
            $this->responseHeaders = [];
            return;
        }
        if ($trimmed === '') {
            return;
        }

        $separator = strpos($headerLine, ':');
        if ($separator === false) {
            return;
        }

        $name = strtolower(trim(substr($headerLine, 0, $separator)));
        $value = trim(substr($headerLine, $separator + 1));

        if (!array_key_exists($name, $this->responseHeaders)) {
            $this->responseHeaders[$name] = $value;
            return;
        }

        if (!is_array($this->responseHeaders[$name])) {
            $this->responseHeaders[$name] = [$this->responseHeaders[$name]];
        }

        $this->responseHeaders[$name][] = $value;
    }

    /**
     * Flatten a multi-dimensional array for multipart form data.
     *
     * @param array $array The array to flatten
     * @param string $prefix The prefix for nested keys
     * @return array
     */
    protected function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";

            if (is_array($value)) {
                $result = array_replace($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }
}
