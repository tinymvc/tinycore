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

    /** @var array<string> Temporary upload files that should be cleaned up. */
    protected array $temporaryUploadFiles = [];

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
        if ($fields === '' || $fields === []) {
            return $this; // No fields to set
        }

        if ($contentType === null && is_array($fields)) {
            $contentType = 'application/json';
        }

        if ($contentType === null && is_string($fields)) {
            $decoded = json_decode($fields, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $contentType = 'application/json';
            }
        }

        $contentType ??= 'application/x-www-form-urlencoded';

        $this->withContentType($contentType);

        $postFields = $contentType === 'application/json'
            ? (is_array($fields)
                ? json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $fields)
            : (is_array($fields)
                ? http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
                : $fields);

        if ($postFields === false) {
            throw new HttpException('Invalid POST field payload for the selected content type.');
        }

        return $this->withOptions([CURLOPT_POSTFIELDS => $postFields]);
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
        $this->isMultipart = true;

        if (is_string($contents) && is_file($contents)) {
            if (!is_readable($contents)) {
                throw new HttpException("Unable to read attachment file: {$contents}");
            }

            $mimeType = $headers['Content-Type'] ?? (mime_content_type($contents) ?: 'application/octet-stream');
            $filename ??= basename($contents);
            $this->attachments[$name] = new \CURLFile($contents, $mimeType, $filename);

            return $this;
        }

        if (!is_resource($contents) && !is_string($contents)) {
            throw new HttpException('Upload content must be a string, resource, or existing file path.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'spark_upload_');
        if (!is_string($tempFile)) {
            throw new HttpException('Failed to create temporary upload file.');
        }

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
        $this->temporaryUploadFiles[] = $tempFile;
        $this->attachments[$name] = new \CURLFile($tempFile, $mimeType, $filename);

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
        $url = $this->url;
        if (!empty($this->params)) {
            $url .= (!str_contains($url, '?') ? '?' : '&') . http_build_query($this->params);
        }
        return $url;
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
        foreach ($this->temporaryUploadFiles as $tempFile) {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }

        $this->temporaryUploadFiles = [];
    }

    /**
     * Build the cURL handle for this request.
     * 
     * @return resource|\CurlHandle The cURL handle
     * @throws \Spark\Http\Client\Exceptions\HttpException
     */
    public function buildCurlHandle()
    {
        $this->responseHeaders = [];
        $curl = curl_init();
        if ($curl === false) {
            throw new HttpException('Failed to initialize cURL.');
        }

        $method = strtoupper($this->method);
        $userHeaderCallback = null;

        if (array_key_exists(CURLOPT_HEADERFUNCTION, $this->options)) {
            $userHeaderCallback = $this->options[CURLOPT_HEADERFUNCTION];
            unset($this->options[CURLOPT_HEADERFUNCTION]);
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
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADERFUNCTION => function (resource|\CurlHandle $curlHandle, string $headerLine) use ($userHeaderCallback): int {
                $this->parseHeaderLine($headerLine);

                if (is_callable($userHeaderCallback)) {
                    $consumed = (int) $userHeaderCallback($curlHandle, $headerLine);
                    return $consumed > 0 ? $consumed : strlen($headerLine);
                }

                return strlen($headerLine);
            },
        ];

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if ($this->isMultipart || !empty($this->attachments)) {
                $postFields = $this->attachments;

                if (!empty($this->data) && is_array($this->data)) {
                    $postFields = [...$this->flattenArray($this->data), ...$postFields];
                }

                $this->setOption(CURLOPT_POSTFIELDS, $postFields);
            } elseif (!empty($this->data)) {
                $this->withPostFields($this->data);
            }
        }

        if (!empty($this->headers)) {
            $defaultOptions[CURLOPT_HTTPHEADER] = $this->headers;
        }

        curl_setopt_array($curl, array_replace($defaultOptions, $this->options));

        return $curl;
    }

    /**
     * Execute the HTTP request.
     * 
     * @return \Spark\Http\Client\Contracts\HttpResponseContract
     * @throws \Spark\Http\Client\Exceptions\HttpException
     */
    public function execute(): HttpResponseContract
    {
        $curl = $this->buildCurlHandle();

        try {
            $body = curl_exec($curl);

            if ($body === false && curl_errno($curl)) {
                throw new HttpException('cURL error: ' . curl_error($curl));
            }

            if (curl_errno($curl)) {
                throw new HttpException('cURL Error: ' . curl_error($curl));
            }

            return new HttpResponse(
                body: (string) $body,
                status: (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
                lastUrl: (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
                length: (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD),
                headers: $this->responseHeaders,
            );
        } finally {
            if ($curl !== null) {
                $this->closeCurlHandle($curl);
            }

            $this->clearTemporaryUploadFiles();
        }
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
        if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
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
            $newKey = $prefix === '' ? $key : "{$prefix}[{$key}]";

            if (is_array($value)) {
                $result = [...$result, ...$this->flattenArray($value, $newKey)];
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }
}
