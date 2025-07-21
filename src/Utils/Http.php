<?php

namespace Spark\Utils;

use ArrayAccess;
use Spark\Contracts\Utils\HttpUtilContract;
use Spark\Exceptions\Utils\PingUtilException;
use Spark\Helpers\HttpResponse;
use Spark\Support\Traits\Macroable;

/**
 * Class Http
 *
 * A helper class for making HTTP requests in PHP using cURL. Supports GET, POST, PUT, PATCH, and DELETE methods,
 * as well as custom headers, options, user agents, and file downloads.
 * 
 * @method static ArrayAccess get(string $url, array $params = [])
 * @method static ArrayAccess post(string $url, array $params = [])
 * @method static ArrayAccess put(string $url, array $params = [])
 * @method static ArrayAccess patch(string $url, array $params = [])
 * @method static ArrayAccess delete(string $url, array $params = [])
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Http implements HttpUtilContract
{
    use Macroable {
        __call as macroCall;
        __callStatic as macroStaticCall;
    }

    /**
     * Constructor for the ping class.
     *
     * Initializes an instance of the ping class with optional configuration settings.
     * The settings are merged with the default configuration and can be used to customize the cURL options,
     * user agent, and download behavior.
     *
     * @param array $config Optional configuration settings. See the resetConfig method for available options.
     */
    public function __construct(private array $config = [])
    {
        $this->resetConfig($config);
    }

    /**
     * Sends an HTTP request to the specified URL with optional parameters.
     *
     * @param string $url The target URL.
     * @param array $params Optional query parameters to include in the request URL.
     * @return ArrayAccess The response data, including body, status code, final URL, and content length.
     * @throws PingUtilException If cURL initialization fails.
     */
    public function send(string $url, array $params = []): ArrayAccess
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new PingUtilException('Failed to initialize cURL.');
        }

        // Default cURL options for the request
        $defaultOptions = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => 'utf-8',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => $this->config['headers'],
            CURLOPT_URL => $url . (!empty($params) ? '?' . http_build_query($params) : '')
        ];

        // Set up file download if specified
        if ($this->config['download']) {
            $download = fopen($this->config['download'], 'w+');
            $defaultOptions[CURLOPT_FILE] = $download;
        }

        curl_setopt_array($curl, $defaultOptions);
        curl_setopt_array($curl, $this->config['options']);

        // Start output buffering to capture the response body
        ob_start();
        echo curl_exec($curl);
        $body = ob_get_clean(); // Get the output buffer content

        // Check if output buffering was successful
        if ($body === false) {
            throw new PingUtilException('Failed to capture cURL output.');
        }

        // Check for cURL errors
        if (curl_errno($curl)) {
            throw new PingUtilException('cURL Error: ' . curl_error($curl));
        }

        // Execute the cURL request and gather response data
        $response = [
            'body' => $body,
            'status' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'last_url' => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
            'length' => curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD),
            'headers' => curl_getinfo($curl, CURLINFO_HEADER_OUT),
        ];

        // Close file if download was specified
        if ($this->config['download']) {
            fclose($download);
        }

        curl_close($curl);

        // Reset current config.
        $this->resetConfig();

        // The response data, including body, status code, final URL, and content length.
        return new HttpResponse($response);
    }

    /**
     * Resets the current configuration.
     *
     * Resets the current configuration back to default, optionally overriding
     * certain configuration settings.
     *
     * @param array $config An associative array of configuration settings.
     */
    public function resetConfig(array $config = []): void
    {
        $this->config = array_merge([
            'headers' => [],
            'options' => [],
            'download' => null,
        ], $config);
    }

    /**
     * Sets a single cURL option.
     *
     * @param int $key The cURL option constant.
     * @param mixed $value The value for the option.
     * @return self
     */
    public function option(int $key, mixed $value): self
    {
        $this->config['options'][$key] = $value;
        return $this;
    }

    /**
     * Sets multiple cURL options at once.
     *
     * @param array $options Associative array of cURL options.
     * @return self
     */
    public function options(array $options): self
    {
        $this->config['options'] = array_replace($this->config['options'], $options);
        return $this;
    }

    /**
     * Sets the User-Agent header for the request.
     *
     * @param string $useragent The User-Agent string.
     * @return self
     */
    public function useragent(string $useragent): self
    {
        $this->config['options'][CURLOPT_USERAGENT] = $useragent;
        return $this;
    }

    /**
     * Sets the Content-Type header for the request.
     *
     * @param string $type The Content-Type string (e.g., 'application/json').
     * @return self
     */
    public function contentType(string $type): self
    {
        $this->config['headers'][] = "Content-Type: $type";
        return $this;
    }

    /**
     * Sets the Accept header for the request.
     *
     * @param string $type The Accept string (e.g., 'application/json').
     * @return self
     */
    public function accept(string $type): self
    {
        $this->config['headers'][] = "Accept: $type";
        return $this;
    }

    /**
     * Adds a custom header to the request.
     *
     * @param string $key Header name.
     * @param string $value Header value.
     * @return self
     */
    public function header(string $key, string $value): self
    {
        $this->config['headers'][] = "$key: $value";
        return $this;
    }

    /**
     * Adds multiple custom headers to the request.
     *
     * @param array $headers Associative array of headers (key => value).
     * @return self
     */
    public function headers(array $headers): self
    {
        foreach ($headers as $key => $value) {
            $this->header($key, $value);
        }
        return $this;
    }

    /**
     * Add Cookies to the request.
     * 
     * This method allows you to set cookies for the request.
     * The cookies will be sent in the "Cookie" header.
     * 
     * @param array $cookies Associative array of cookies (key => value).
     * @return self
     */
    public function cookie(array $cookies): self
    {
        $cookies = array_map(function ($key, $value) {
            return "$key=$value";
        }, array_keys($cookies), $cookies);

        $this->config['headers'][] = "Cookie: " . implode('; ', $cookies);

        return $this;
    }

    /**
     * Sets the cookie jar file path for storing cookies.
     *
     * @param string $cookieJar The file path to the cookie jar.
     * @return self
     */
    public function cookieJar(string $cookieJar): self
    {
        if (!file_exists($cookieJar)) {
            touch($cookieJar);
        }

        $this->config['options'][CURLOPT_COOKIEJAR] = $cookieJar;
        $this->config['options'][CURLOPT_COOKIEFILE] = $cookieJar;

        return $this;
    }

    /**
     * Sets a proxy for the request.
     *
     * @param string $proxy The proxy URL (e.g., 'http://proxy.example.com:8080').
     * @param string $proxyAuth Optional proxy authentication in the format 'username:password'.
     * @param array $options Additional cURL options for the proxy.
     * @return self
     */
    public function setProxy(string $proxy, string $proxyAuth = '', array $options = []): self
    {
        $this->config['options'][CURLOPT_PROXY] = $proxy;

        if (!empty($proxyAuth)) {
            $this->config['options'][CURLOPT_PROXYAUTH] = CURLAUTH_BASIC;
            $this->config['options'][CURLOPT_PROXYUSERPWD] = $proxyAuth;
        }

        foreach ($options as $option => $value) {
            $this->config['options'][$option] = $value;
        }

        return $this;
    }

    /**
     * Sets the file path to download the response to.
     *
     * @param string $location File path for download.
     * @return self
     */
    public function download(string $location): self
    {
        $this->config['download'] = $location;
        return $this;
    }

    /**
     * Sets fields for a POST request.
     *
     * @param array|string $fields The fields to include in the POST body. Can be an array or string.
     * @return self
     */
    public function postFields(array|string $fields): self
    {
        // Set the Content-Type header based on the type of fields
        if (is_array($fields)) {
            $this->contentType('application/json');
        } else {
            $this->contentType('application/x-www-form-urlencoded');
        }

        return $this->options([
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => is_array($fields) ? json_encode($fields) : $fields
        ]);
    }

    /**
     * Handles dynamic method calls for HTTP methods (GET, POST, PUT, PATCH, DELETE).
     *
     * @param string $name The HTTP method name.
     * @param array $arguments The arguments for the method.
     * @return mixed The response from the HTTP request or the result of the macro call.
     * @throws PingUtilException If the HTTP method is not supported.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (static::hasMacro($name)) {
            return $this->macroCall($name, $arguments);
        }

        $method = strtoupper($name);
        if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])) {
            $this->option(CURLOPT_CUSTOMREQUEST, $method);

            return $this->send(...$arguments);
        }

        throw new PingUtilException("Undefined Method: {$name}");
    }

    /**
     * Handles static calls by creating a new instance and calling the dynamic method.
     * specially for: get, post, put, patch, delete methods.
     *
     * @param string $name The HTTP method name.
     * @param array $arguments The arguments for the method.
     * @return mixed The response from the dynamic method call.
     */
    public static function __callStatic($name, $arguments)
    {
        if (static::hasMacro($name)) {
            return static::macroStaticCall($name, $arguments);
        }

        $ping = new static();
        return call_user_func([$ping, $name], ...$arguments);
    }
}
