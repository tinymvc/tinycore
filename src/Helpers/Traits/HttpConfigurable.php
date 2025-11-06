<?php

namespace Spark\Helpers\Traits;

/**
 * Trait HttpConfigurable
 * 
 * Provides common HTTP configuration methods for request builders.
 * This trait is shared between Http and PendingRequest classes to avoid code duplication.
 * 
 * @package Spark\Helpers
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
trait HttpConfigurable
{
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
     * Abstract method to add a header (implemented by using class).
     * 
     * @param string $header Header string in "Key: Value" format
     * @return void
     */
    abstract protected function addHeader(string $header): void;

    /**
     * Abstract method to set a cURL option (implemented by using class).
     * 
     * @param int $option cURL option constant
     * @param mixed $value Option value
     * @return void
     */
    abstract protected function setOption(int $option, mixed $value): void;
}
