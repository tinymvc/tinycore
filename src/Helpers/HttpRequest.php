<?php

namespace Spark\Helpers;

use Spark\Exceptions\Utils\PingUtilException;
use Spark\Support\Traits\Macroable;

/**
 * Class PendingRequest
 * 
 * Represents a pending HTTP request in the pool.
 * 
 * @package Spark\Helpers
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class HttpRequest
{
    use Macroable;

    /** @var array Request headers */
    private array $headers = [];

    /** @var array cURL options */
    private array $options = [];

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
        private string $method = 'GET',
        private string $url = '',
        private array $params = [],
        private string|array $data = [],
        private string|int $key = 0
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
        $cookieStrings = array_map(
            fn($key, $value) => "$key=$value",
            array_keys($cookies),
            $cookies
        );

        return $this->withHeader('Cookie', implode('; ', $cookieStrings));
    }

    /**
     * Set a cookie jar file path for storing cookies.
     * 
     * @param string $cookieJar File path to the cookie jar
     * @return self
     */
    public function withCookieJar(string $cookieJar): self
    {
        if (!is_file($cookieJar)) {
            touch($cookieJar);
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
        $this->setOption(CURLOPT_SSL_VERIFYHOST, false);
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
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($this->params);
        }
        return $url;
    }

    /**
     * Build the cURL handle for this request.
     * 
     * @return resource|\CurlHandle The cURL handle
     * @throws PingUtilException
     */
    public function buildCurlHandle()
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new PingUtilException('Failed to initialize cURL.');
        }

        // Default options
        $defaultOptions = [
            CURLOPT_URL => $this->getFullUrl(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => 'utf-8',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $this->method,
        ];

        // Handle POST/PUT/PATCH/DELETE data
        if (!empty($this->data) && in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $this->withPostFields($this->data);
        }

        // Set headers
        if (!empty($this->headers)) {
            $defaultOptions[CURLOPT_HTTPHEADER] = $this->headers;
        }

        // Merge with custom options
        curl_setopt_array($curl, $defaultOptions);
        curl_setopt_array($curl, $this->options);

        return $curl;
    }

    /**
     * Execute the HTTP request.
     * 
     * @return HttpResponse
     * @throws PingUtilException
     */
    public function execute(): HttpResponse
    {
        $curl = $this->buildCurlHandle();
        $body = curl_exec($curl);

        if ($body === false) {
            throw new PingUtilException('cURL error: ' . curl_error($curl));
        }

        // Check for cURL errors
        if (curl_errno($curl)) {
            throw new PingUtilException('cURL Error: ' . curl_error($curl));
        }

        $response = [
            'body' => $body,
            'status' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'last_url' => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
            'length' => curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD),
            'headers' => curl_getinfo($curl, CURLINFO_HEADER_OUT),
        ];

        return new HttpResponse($response);
    }
}