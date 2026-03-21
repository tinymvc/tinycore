<?php

namespace Spark\Http\Client\Contracts;

interface HttpRequestContract
{
    /**
     * Add a header to the request.
     * @param string $header
     * @return void
     */
    public function addHeader(string $header): void;

    /**
     * Add multiple headers to the request.
     * @param array $headers
     * @return void
     */
    public function setOption(int $option, mixed $value): void;

    /**
     * Add a header to the request, replacing any existing header with the same key.
     * @param string $key
     * @param string $value
     * @return self
     */
    public function withHeader(string $key, string $value): self;

    /**
     * Add multiple headers to the request, replacing any existing headers with the same keys.
     * @param array $headers
     * @return self
     */
    public function withHeaders(array $headers): self;

    /**
     * Set the Content-Type header for the request.
     * @param string $type
     * @return self
     */
    public function withContentType(string $type): self;

    /**
     * Set the Accept header for the request.
     * @param string $type
     * @return self
     */
    public function withAccept(string $type): self;

    /**
     * Set the User-Agent header for the request.
     * @param string $userAgent
     * @return self
     */
    public function withUserAgent(string $userAgent): self;

    /**
     * Set the Authorization header for the request using a Bearer token.
     * @param string $token
     * @return self
     */
    public function withToken(string $token): self;

    /**
     * Set the Authorization header for the request using Basic Auth.
     * @param string $username
     * @param string $password
     * @return self
     */
    public function withBasicAuth(string $username, string $password): self;

    /**
     * Set the Authorization header for the request using Digest Auth.
     * @param string $username
     * @param string $password
     * @return self
     */
    public function withCookies(array $cookies): self;

    /**
     * Set the cookie jar for the request.
     * @param string $cookieJar
     * @return self
     */
    public function withCookieJar(string $cookieJar): self;

    /**
     * Set the proxy for the request.
     * @param string $proxy
     * @param string $proxyAuth
     * @return self
     */
    public function withProxy(string $proxy, string $proxyAuth = ''): self;

    /**
     * Set a cURL option for the request.
     * @param int $option
     * @param mixed $value
     * @return self
     */
    public function withOption(int $option, mixed $value): self;

    /**
     * Set multiple cURL options for the request.
     * @param array $options
     * @return self
     */
    public function withOptions(array $options): self;

    /**
     * Set the timeout for the request.
     * @param int $seconds
     * @return self
     */
    public function withTimeout(int $seconds): self;

    /**
     * Set the connection timeout for the request.
     * @param int $seconds
     * @return self
     */
    public function withoutVerifying(): self;

    /**
     * Enable SSL certificate verification for the request.
     * @return self
     */
    public function withVerifying(): self;

    /**
     * Set the POST fields for the request.
     * @param array|string $fields
     * @param string|null $contentType
     * @return self
     */
    public function withPostFields(array|string $fields, null|string $contentType = null): self;

    /**
     * Attach a file to the request.
     * @param string $name
     * @param mixed $contents
     * @param string|null $filename
     * @param array $headers
     * @return self
     */
    public function attach(string $name, mixed $contents, ?string $filename = null, array $headers = []): self;

    /**
     * Set the request to be sent as multipart/form-data.
     * @return self
     */
    public function asMultipart(): self;

    /**
     * Set the request to be sent as application/json.
     * @return self
     */
    public function hasAttachments(): bool;

    /**
     * Get the attachments for the request.
     * @return array
     */
    public function getAttachments(): array;

    /**
     * Determine if the request is a multipart/form-data request.
     * @return bool
     */
    public function isMultipart(): bool;

    /**
     * Determine if the request is an application/json request.
     * @return bool
     */
    public function setMethod(string $method): void;

    /**
     * Set the URL for the request.
     * @param string $url
     * @return void
     */
    public function setUrl(string $url): void;

    /**
     * Set the query parameters for the request.
     * @param array $params
     * @return void
     */
    public function setParams(array $params): void;

    /**
     * Set the data for the request.
     * @param string|array $data
     * @return void
     */
    public function setData(string|array $data): void;

    /**
     * Get the HTTP method for the request.
     * @return string
     */
    public function getMethod(): string;

    /**
     * Get the URL for the request.
     * @return string
     */
    public function getUrl(): string;

    /**
     * Get the query parameters for the request.
     * @return array
     */
    public function getParams(): array;

    /**
     * Get the data for the request.
     * @return string|array|null
     */
    public function getData(): null|string|array;

    /**
     * Get the headers for the request.
     * @return array
     */
    public function getHeaders(): array;

    /**
     * Get the full URL for the request, including query parameters.
     * @return string
     */
    public function getFullUrl(): string;

    /**
     * Build the cURL handle for the request.
     * @return resource
     */
    public function buildCurlHandle();

    /**
     * Execute the request and return the response.
     * @return HttpResponseContract
     */
    public function execute(): HttpResponseContract;
}