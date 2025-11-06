<?php

namespace Spark\Utils;

use Spark\Exceptions\Utils\PingUtilException;
use Spark\Helpers\PendingRequest;
use Spark\Helpers\HttpResponse;

/**
 * Class HttpPool
 * 
 * Helper class for managing concurrent HTTP requests.
 * This class allows you to send multiple HTTP requests in parallel using curl_multi.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class HttpPool
{
    /** @var array Pending requests to be executed */
    private array $pendingRequests = [];

    /** @var string|null Custom key for the next request */
    private ?string $nextKey = null;

    /**
     * Set a custom key for the next request.
     * 
     * @param string $key The custom key to use
     * @return self
     */
    public function as(string $key): self
    {
        $this->nextKey = $key;
        return $this;
    }

    /**
     * Add a GET request to the pool.
     * 
     * @param string $url The target URL
     * @param array $params Optional query parameters
     * @return PendingRequest
     */
    public function get(string $url, array $params = []): PendingRequest
    {
        return $this->addRequest('GET', $url, $params);
    }

    /**
     * Add a POST request to the pool.
     * 
     * @param string $url The target URL
     * @param array $data The POST data
     * @return PendingRequest
     */
    public function post(string $url, array $data = []): PendingRequest
    {
        return $this->addRequest('POST', $url, [], $data);
    }

    /**
     * Add a PUT request to the pool.
     * 
     * @param string $url The target URL
     * @param array $data The PUT data
     * @return PendingRequest
     */
    public function put(string $url, array $data = []): PendingRequest
    {
        return $this->addRequest('PUT', $url, [], $data);
    }

    /**
     * Add a PATCH request to the pool.
     * 
     * @param string $url The target URL
     * @param array $data The PATCH data
     * @return PendingRequest
     */
    public function patch(string $url, array $data = []): PendingRequest
    {
        return $this->addRequest('PATCH', $url, [], $data);
    }

    /**
     * Add a DELETE request to the pool.
     * 
     * @param string $url The target URL
     * @param array $params Optional query parameters
     * @return PendingRequest
     */
    public function delete(string $url, array $params = []): PendingRequest
    {
        return $this->addRequest('DELETE', $url, $params);
    }

    /**
     * Add a request to the pool.
     * 
     * @param string $method HTTP method
     * @param string $url Target URL
     * @param array $params Query parameters
     * @param array $data POST/PUT/PATCH data
     * @return PendingRequest
     */
    private function addRequest(string $method, string $url, array $params = [], array $data = []): PendingRequest
    {
        $key = $this->nextKey ?? count($this->pendingRequests);
        $this->nextKey = null;

        $request = new PendingRequest($method, $url, $params, $data, $key);
        $this->pendingRequests[$key] = $request;

        return $request;
    }

    /**
     * Execute all pending requests concurrently.
     * 
     * @param array $requests Array of HttpPendingRequest objects
     * @return array Array of HttpResponse objects
     * @throws PingUtilException
     */
    public function execute(array $requests): array
    {
        if (empty($requests)) {
            return [];
        }

        $multiHandle = curl_multi_init();
        if ($multiHandle === false) {
            throw new PingUtilException('Failed to initialize curl_multi.');
        }

        $handles = [];
        $keys = [];

        // Add all requests to the multi handle
        foreach ($requests as $request) {
            if (!$request instanceof PendingRequest) {
                continue;
            }

            $curl = $request->buildCurlHandle();
            curl_multi_add_handle($multiHandle, $curl);

            $handleId = (int) $curl;
            $handles[$handleId] = $curl;
            $keys[$handleId] = $request->getKey();
        }

        // Execute all requests concurrently
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle, 0.1);
        } while ($running > 0);

        // Collect responses
        $responses = [];
        foreach ($handles as $handleId => $curl) {
            $key = $keys[$handleId];

            // Get response data
            $body = curl_multi_getcontent($curl);
            $response = [
                'body' => $body ?: '',
                'status' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
                'last_url' => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
                'length' => curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD),
                'headers' => curl_getinfo($curl, CURLINFO_HEADER_OUT),
            ];

            $responses[$key] = new HttpResponse($response);

            // Clean up
            curl_multi_remove_handle($multiHandle, $curl);
        }

        curl_multi_close($multiHandle);

        return $responses;
    }
}