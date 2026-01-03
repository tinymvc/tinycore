<?php

namespace Spark\Facades;

use Spark\Facades\Facade;
use Spark\Helpers\HttpResponse;
use Spark\Utils\Http as BaseHttp;

/**
 * Class Http
 * 
 * Facade for the Http utility class.
 * 
 * @method static HttpResponse get(string $url, array $params = [])
 * @method static HttpResponse post(string $url, array $data = [])
 * @method static HttpResponse put(string $url, array $data = [])
 * @method static HttpResponse patch(string $url, array $data = [])
 * @method static HttpResponse delete(string $url, array $data = [])
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
 * @method static BaseHttp withHeaders(array $headers)
 * @method static BaseHttp withContentType(string $type)
 * @method static BaseHttp withAccept(string $type)
 * @method static BaseHttp withToken(string $token)
 * @method static BaseHttp withBasicAuth(string $username, string $password)
 * @method static BaseHttp withCookies(array $cookies)
 * @method static BaseHttp withOptions(array $options)
 * @method static BaseHttp withPostFields(array|string $fields, null|string $contentType = null)
 * @method static array pool(callable $callback)
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
}
