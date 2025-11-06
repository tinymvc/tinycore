<?php

namespace Spark\Helpers;

use Spark\Exceptions\Utils\PingUtilException;
use Spark\Helpers\Traits\HttpConfigurable;

/**
 * Class PendingRequest
 * 
 * Represents a pending HTTP request in the pool.
 * 
 * @package Spark\Helpers
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class PendingRequest
{
    use HttpConfigurable;

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
     * @param array $data POST/PUT/PATCH data
     * @param string|int $key Request key
     */
    public function __construct(
        private string $method,
        private string $url,
        private array $params = [],
        private array $data = [],
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
    protected function addHeader(string $header): void
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
    protected function setOption(int $option, mixed $value): void
    {
        $this->options[$option] = $value;
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

        // Build URL with query parameters
        $url = $this->url;
        if (!empty($this->params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($this->params);
        }

        // Default options
        $defaultOptions = [
            CURLOPT_URL => $url,
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

        // Handle POST/PUT/PATCH data
        if (!empty($this->data) && in_array($this->method, ['POST', 'PUT', 'PATCH'])) {
            $isJson = is_array($this->data);

            if ($isJson) {
                $this->headers[] = 'Content-Type: application/json';
                $defaultOptions[CURLOPT_POSTFIELDS] = json_encode($this->data);
            } else {
                $this->headers[] = 'Content-Type: application/x-www-form-urlencoded';
                $defaultOptions[CURLOPT_POSTFIELDS] = http_build_query($this->data);
            }
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
}