<?php

namespace Spark\Http\Client;

use Spark\Http\Client\Contracts\HttpPoolContract;
use Spark\Http\Client\Contracts\HttpRequestContract;
use Spark\Http\Client\Contracts\HttpResponseContract;
use Spark\Http\Client\Exceptions\HttpException;
use Spark\Support\Traits\Macroable;
use function array_key_exists;
use function count;
use function is_object;
use function is_resource;
use function spl_object_id;

/**
 * Class HttpPool
 * 
 * Helper class for managing concurrent HTTP requests.
 * This class allows you to send multiple HTTP requests in parallel using curl_multi.
 * 
 * @package Spark\Http\Client
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class HttpPool implements HttpPoolContract
{
    use Macroable;

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
     * @return HttpRequestContract
     */
    public function get(string $url, array $params = []): HttpRequestContract
    {
        return $this->addRequest('GET', $url, $params);
    }

    /**
     * Add a POST request to the pool.
     * 
     * @param string $url The target URL
     * @param string|array $data The POST data
     * @return HttpRequestContract
     */
    public function post(string $url, string|array $data = []): HttpRequestContract
    {
        return $this->addRequest('POST', $url, [], $data);
    }

    /**
     * Add a PUT request to the pool.
     * 
     * @param string $url The target URL
     * @param string|array $data The PUT data
     * @return HttpRequestContract
     */
    public function put(string $url, string|array $data = []): HttpRequestContract
    {
        return $this->addRequest('PUT', $url, [], $data);
    }

    /**
     * Add a PATCH request to the pool.
     * 
     * @param string $url The target URL
     * @param string|array $data The PATCH data
     * @return HttpRequestContract
     */
    public function patch(string $url, string|array $data = []): HttpRequestContract
    {
        return $this->addRequest('PATCH', $url, [], $data);
    }

    /**
     * Add a DELETE request to the pool.
     * 
     * @param string $url The target URL
     * @param string|array $data The DELETE data
     * @return HttpRequestContract
     */
    public function delete(string $url, string|array $data = []): HttpRequestContract
    {
        return $this->addRequest('DELETE', $url, [], $data);
    }

    /**
     * Add a request to the pool.
     * 
     * @param string $method HTTP method
     * @param string $url Target URL
     * @param array $params Query parameters
     * @param string|array $data POST/PUT/PATCH data
     * @return HttpRequestContract
     */
    private function addRequest(string $method, string $url, array $params = [], string|array $data = []): HttpRequestContract
    {
        $key = $this->nextKey ?? count($this->pendingRequests);
        if ($this->nextKey === null) {
            while (array_key_exists($key, $this->pendingRequests)) {
                $key++;
            }
        } elseif (array_key_exists($key, $this->pendingRequests)) {
            throw new HttpException("Duplicate pool request key: {$key}");
        }

        $this->nextKey = null;

        $request = HttpRequest::make($method, $url, $params, $data, $key);
        $this->pendingRequests[$key] = $request;

        return $request;
    }

    /**
     * Get all pending requests.
     * 
     * @return array<string|int,HttpRequestContract> Array of HttpPendingRequest objects
     */
    public function getPendingRequests(): array
    {
        return $this->pendingRequests;
    }

    /**
     * Clear all pending requests.
     */
    public function clearPendingRequests(): void
    {
        $this->pendingRequests = [];
        $this->nextKey = null;
    }

    /**
     * Set the pending requests.
     * 
     * @param array<string|int,HttpRequestContract> $requests Array of HttpPendingRequest objects
     */
    public function setPendingRequests(array $requests): void
    {
        $this->pendingRequests = $requests;
    }

    /**
     * Execute all pending requests concurrently.
     * 
     * @return array<string|int,HttpResponseContract> Array of HttpResponse objects
     * @throws HttpException
     */
    public function executePendingRequests(): array
    {
        return $this->execute($this->pendingRequests);
    }

    /**
     * Execute all pending requests concurrently.
     * 
     * @param array<string|int,HttpRequestContract> $requests Array of HttpPendingRequest objects
     * @return array<string|int,HttpResponseContract> Array of HttpResponse objects
     * @throws HttpException
     */
    public function execute(array $requests): array
    {
        if (empty($requests)) {
            return [];
        }

        $requestKeys = [];
        foreach ($requests as $request) {
            if (!$request instanceof HttpRequestContract) {
                throw new HttpException('Every pool entry must implement HttpRequestContract.');
            }
            $key = $request->getKey();
            if (array_key_exists($key, $requestKeys)) {
                throw new HttpException("Duplicate pool request key: {$key}");
            }
            $requestKeys[$key] = true;
        }

        $multiHandle = curl_multi_init();
        if (!$multiHandle) {
            throw new HttpException('Failed to initialize curl_multi.');
        }

        $handles = [];
        $requestMap = [];
        $keys = [];

        try {
            foreach ($requests as $request) {
                $curl = $request->buildCurlHandle();

                $handleId = is_object($curl) ? spl_object_id($curl) : (int) $curl;
                $handles[$handleId] = $curl;
                $requestMap[$handleId] = $request;
                $keys[$handleId] = $request->getKey();
                $status = curl_multi_add_handle($multiHandle, $curl);
                if ($status !== CURLM_OK) {
                    throw new HttpException('Unable to add cURL handle: ' . curl_multi_strerror($status));
                }
            }

            if (empty($handles)) {
                return [];
            }

            $running = null;
            do {
                do {
                    $status = curl_multi_exec($multiHandle, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                if ($status !== CURLM_OK) {
                    throw new HttpException('cURL multi execution failed: ' . curl_multi_strerror($status));
                }

                if ($running > 0) {
                    $selectResult = curl_multi_select($multiHandle, 1.0);
                    if ($selectResult === -1) {
                        usleep(100000);
                    }
                }
            } while ($running > 0);

            while ($completed = curl_multi_info_read($multiHandle)) {
                if ($completed['result'] !== CURLE_OK) {
                    throw new HttpException('cURL Error: ' . curl_strerror($completed['result']));
                }
            }

            $responses = [];
            foreach ($handles as $handleId => $curl) {
                if (curl_errno($curl)) {
                    throw new HttpException('cURL Error: ' . curl_error($curl));
                }

                $body = curl_multi_getcontent($curl);
                if ($body === false) {
                    $body = '';
                }

                $responses[$keys[$handleId]] = new HttpResponse(
                    body: (string) $body,
                    status: (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
                    lastUrl: (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
                    length: (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD),
                    headers: (method_exists($requestMap[$handleId], 'getResponseHeaders')
                        ? $requestMap[$handleId]->getResponseHeaders()
                        : []),
                );
            }

            return $responses;
        } finally {
            foreach ($handles as $handleId => $curl) {
                if ($multiHandle !== null) {
                    @curl_multi_remove_handle($multiHandle, $curl);
                }

                if ($curl !== null) {
                    $this->closeCurlHandle($curl);
                }
            }

            if ($multiHandle !== null) {
                $this->closeCurlMultiHandle($multiHandle);
            }
        }
    }

    /**
     * Safely close a cURL handle across supported PHP versions.
     *
     * @param mixed $handle
     */
    private function closeCurlHandle(mixed &$handle): void
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
     * Safely close a multi cURL handle across supported PHP versions.
     *
     * @param mixed $multiHandle
     */
    private function closeCurlMultiHandle(mixed &$multiHandle): void
    {
        if ($multiHandle === null) {
            return;
        }

        if (is_object($multiHandle) && method_exists($multiHandle, 'close')) {
            $multiHandle->close();
            $multiHandle = null;

            return;
        }

        if (is_object($multiHandle)) {
            $multiHandle = null;

            return;
        }

        if (is_resource($multiHandle)) {
            $multiHandle = null;
        }
    }
}
