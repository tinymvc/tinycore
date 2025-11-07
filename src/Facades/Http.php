<?php

use Spark\Exceptions\Utils\PingUtilException;
use Spark\Facades\Facade;
use Spark\Helpers\HttpResponse;
use Spark\Utils\Http as BaseHttp;
use Spark\Utils\HttpPool;

/**
 * Class Http
 * 
 * Facade for the Http utility class.
 * 
 * @method static HttpResponse get(string $url, array $params = [])
 * @method static HttpResponse post(string $url, array $params = [])
 * @method static HttpResponse put(string $url, array $params = [])
 * @method static HttpResponse patch(string $url, array $params = [])
 * @method static HttpResponse patch(string $url, array $params = [])
 * @method static HttpResponse send(string $url, array $params = [])
 * @method static BaseHttp option(int $key, mixed $value)
 * @method static BaseHttp options(array $options)
 * @method static BaseHttp useragent(string $useragent)
 * @method static BaseHttp contentType(string $type)
 * @method static BaseHttp accept(string $type)
 * @method static BaseHttp header(string $key, string $value)
 * @method static BaseHttp headers(array $headers)
 * @method static BaseHttp cookie(array $cookies)
 * @method static BaseHttp cookieJar(string $cookieJar)
 * @method static BaseHttp proxy(string $proxy, string $proxyAuth = '')
 * @method static BaseHttp download(string $location, bool $force = false)
 * @method static BaseHttp postFields(array|string $fields, null|string $contentType = null)
 * 
 * @package Spark\Facades
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Http extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseHttp::class;
    }

    /**
     * Send multiple HTTP requests concurrently (in parallel).
     * 
     * This method allows you to send multiple HTTP requests at the same time,
     * which is much faster than sending them sequentially.
     * 
     * Example:
     * ```php
     * $responses = Http::pool(fn($pool) => [
     *     $pool->get('https://api.example.com/users'),
     *     $pool->post('https://api.example.com/posts', ['title' => 'Hello']),
     *     $pool->as('custom')->get('https://api.example.com/comments'),
     * ]);
     * 
     * // Access responses by index or key
     * $users = $responses[0]->json();
     * $posts = $responses[1]->json();
     * $comments = $responses['custom']->json();
     * ```
     * 
     * @param callable $callback A callback that receives a Pool instance and returns an array of requests
     * @return array An array of HttpResponse objects, keyed by their index or custom key
     */
    public static function pool(callable $callback): array
    {
        $pool = new HttpPool();
        $requests = $callback($pool);

        if (!is_array($requests)) {
            throw new PingUtilException('Pool callback must return an array of requests.');
        }

        return $pool->execute($requests);
    }
}