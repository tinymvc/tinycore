<?php

namespace Spark\Http\Client\Contracts;

/**
 * Interface HttpPoolContract
 * 
 * Contract for the HttpPool class, defining the methods for managing concurrent HTTP requests.
 * This interface ensures that any implementation of an HTTP pool will have the necessary methods
 * for adding requests, executing them, and managing the pool of pending requests.
 * 
 * @package Spark\Http\Client\Contracts
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
interface HttpPoolContract
{
    /**
     * Set a custom key for the next request.
     * 
     * @param string $key The custom key to use
     * @return self
     */
    public function as(string $key): self;

    /**
     * Add a GET request to the pool.
     * 
     * @param string $url The target URL
     * @param array $params Optional query parameters
     * @return HttpRequestContract
     */
    public function get(string $url, array $params = []): HttpRequestContract;

    /**
     * Add a POST request to the pool.
     * 
     * @param string $url The target URL
     * @param string|array $data The POST data
     * @return HttpRequestContract
     */
    public function post(string $url, string|array $data = []): HttpRequestContract;

    /**
     * Add a PUT request to the pool.
     * 
     * @param string $url The target URL
     * @param string|array $data The PUT data
     * @return HttpRequestContract
     */
    public function put(string $url, string|array $data = []): HttpRequestContract;

    /**
     * Add a PATCH request to the pool.
     * 
     * @param string $url The target URL
     * @param string|array $data The PATCH data
     * @return HttpRequestContract
     */
    public function patch(string $url, string|array $data = []): HttpRequestContract;

    /**
     * Add a DELETE request to the pool.
     * 
     * @param string $url The target URL
     * @param string|array $data The DELETE data
     * @return HttpRequestContract
     */
    public function delete(string $url, string|array $data = []): HttpRequestContract;

    /**
     * Get all pending requests in the pool.
     * 
     * @return array<HttpRequestContract[]> Array of HttpPendingRequest objects
     */
    public function getPendingRequests(): array;

    /**
     * Clear all pending requests from the pool.
     */
    public function clearPendingRequests(): void;

    /**
     * Set the pending requests.
     * 
     * @param array<HttpRequestContract[]> $requests Array of HttpPendingRequest objects
     */
    public function setPendingRequests(array $requests): void;

    /**
     * Execute all pending requests concurrently.
     * 
     * @return array<HttpResponseContract[]> Array of HttpResponse objects
     * @throws \Spark\Http\Client\Exceptions\HttpException
     */
    public function executePendingRequests(): array;

    /**
     * Execute the given requests concurrently.
     * 
     * @param array<HttpRequestContract[]> $requests Array of HttpPendingRequest objects
     * @return array<HttpResponseContract[]> Array of HttpResponse objects
     * @throws \Spark\Http\Client\Exceptions\HttpException
     */
    public function execute(array $requests): array;
}