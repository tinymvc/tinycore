<?php

namespace Spark\Http;

use ArrayIterator;
use InvalidArgumentException;
use Spark\Contracts\Http\RequestContract;
use Spark\Helpers\RequestErrors;
use Spark\Http\Input\Sanitizer;
use Spark\Http\Input\Validator;
use Spark\Support\Traits\Macroable;

/**
 * Class Request
 * 
 * Handles and manages HTTP request data for the application, including
 * query parameters, POST data, file uploads, and server variables.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Request implements RequestContract, \ArrayAccess, \IteratorAggregate
{
    use Macroable;

    /**
     * HTTP request method (e.g., GET, POST).
     * @var string
     */
    private string $method;

    /**
     * Requested URI path.
     * @var string
     */
    private string $path;

    /**
     * Root URL of the application (protocol and host).
     * @var string
     */
    private string $rootUrl;

    /**
     * Full URL of the current request.
     * @var string
     */
    private string $url;

    /**
     * Query parameters from the URL.
     * @var array
     */
    private array $queryParams;

    /**
     * Parameters from POST data.
     * @var array
     */
    private array $postParams;

    /**
     * Uploaded files data.
     * @var array
     */
    private array $fileUploads;

    /**
     * Server parameters, including headers.
     * @var array
     */
    private array $serverParams;

    /**
     * Additional route parameters.
     * @var array
     */
    private array $routeParams;

    /**
     * The error object.
     *
     * This property contains the error messages
     * when the validation fails.
     *
     * @var object
     */
    private object $errorObject;

    /**
     * request constructor.
     * 
     * Initializes request properties based on global server data.
     */
    public function __construct()
    {
        $this->method = $this->parseRequestMethod();
        $this->path = $this->parsePath();
        $this->rootUrl = $this->parseRootUrl();
        $this->url = $this->parseUrl();
        $this->serverParams = $_SERVER;
        $this->fileUploads = $_FILES;
        $this->queryParams = $_GET;
        $this->postParams = $_POST;
        $this->postParams = array_merge($this->postParams, $this->parsePhpInput());
    }

    /**
     * Parses the request method based on $_SERVER['REQUEST_METHOD'].
     * 
     * @return string The request method as an uppercase string.
     */
    private function parseRequestMethod(): string
    {
        // Get the request method from $_SERVER['REQUEST_METHOD']
        // or default to 'GET' if not set.
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // If the request method is POST, check if an HTTP method override
        // is set either in the X-HTTP-METHOD-OVERRIDE header or
        // in the '_method' POST parameter.
        if ($method === 'POST') {
            if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
                $overrideMethod = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
            } elseif (isset($_POST['_method'])) {
                $overrideMethod = strtoupper($_POST['_method']);
            } else {
                $overrideMethod = null;
            }

            // If an override method is set and is one of the
            // PUT, PATCH, or DELETE methods, return that override
            // method instead of the original POST method.
            if ($overrideMethod && in_array($overrideMethod, ['PUT', 'PATCH', 'DELETE'])) {
                return $overrideMethod;
            }
        }

        // Return the request method.
        return $method;
    }

    /**
     * Parses raw POST input data in JSON format.
     * 
     * @return array Parsed JSON data as an associative array.
     */
    private function parsePhpInput(): array
    {
        if (in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE']) && empty($this->postParams)) {
            // Get the raw POST data from the php://input stream.
            $params = file_get_contents('php://input');

            // If the raw POST data is not empty, attempt to decode it
            // as JSON data.
            if (!empty($params)) {
                // Decode the raw POST data as JSON data.
                $params = json_decode($params, true);

                // If the JSON decoding was successful, return the decoded
                // JSON data as an associative array.
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $params;
                }
            }
        }

        // Return an empty array if no valid JSON data is found.
        return [];
    }

    /**
     * Parses the requested URI path, excluding query parameters.
     * 
     * @return string The URI path of the request.
     */
    private function parsePath(): string
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';

        // Remove query parameters
        $position = strpos($path, '?');
        if ($position !== false) {
            $path = substr($path, 0, $position);
        }

        // URL decode the path to handle encoded characters
        $path = urldecode($path);

        // Ensure path starts with /
        return '/' . ltrim($path, '/');
    }

    /**
     * Builds the root URL (protocol and host).
     * 
     * @return string Root URL of the application.
     */
    private function parseRootUrl(): string
    {
        // Reliable HTTPS detection
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') ||
            (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        $protocol = $isHttps ? 'https://' : 'http://';

        // Validate and sanitize host
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

        // Basic host validation to prevent header injection
        if (!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]+)?$/', $host)) {
            throw new InvalidArgumentException('Invalid host header detected');
        }

        return "$protocol$host";
    }

    /**
     * Builds the full URL of the current request.
     * 
     * @return string Full URL including protocol, host, and path.
     */
    private function parseUrl(): string
    {
        $rootUrl = $this->parseRootUrl();
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

        return "$rootUrl$requestUri";
    }

    /**
     * Sets the route parameters associated with the current request.
     * 
     * Route parameters are typically set by the router when a route is matched.
     * They can also be set manually using this method.
     * 
     * @param array<string, mixed> $params Associative array of route parameters.
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * Retrieves a route parameter value by key.
     *
     * @param string $key The key of the route parameter.
     * @param ?string $default The default value to return if the key does not exist.
     *
     * @return ?string The value associated with the given key, or the default value if the key does not exist.
     */
    public function getRouteParam(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Checks if a route parameter exists.
     * 
     * @param string $key The key of the route parameter to check.
     * @return bool True if the route parameter exists, false otherwise.
     */
    public function hasRouteParam(string $key): bool
    {
        return isset($this->routeParams[$key]);
    }

    /**
     * Retrieves all route parameters associated with the current request.
     * 
     * Route parameters are typically set by the router when a route is matched.
     * They can also be set manually using setRouteParams.
     * 
     * @return array An associative array of all route parameters.
     */
    public function getRouteParams(): array
    {
        return $this->routeParams ?? [];
    }

    /**
     * Alias for getRouteParam to maintain consistency with other parameter retrieval methods.
     * 
     * @param string $key The key of the route parameter.
     * @param ?string $default The default value to return if the key does not exist.
     * @return ?string The value associated with the given key, or the default value if the key does not exist.
     */
    public function routeParam(string $key, ?string $default = null): ?string
    {
        return $this->getRouteParam($key, $default);
    }

    /**
     * Retrieves the HTTP request method.
     * 
     * @return string The request method in uppercase.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Checks if the current request is a POST, PUT, PATCH, or DELETE request.
     * 
     * @return bool True if the request is a POST, PUT, PATCH, or DELETE request, false otherwise.
     */
    public function isPostBack(): bool
    {
        // Check if the current request method is in the list of supported methods
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE']);
    }

    /**
     * Checks if the current request is a GET request.
     * 
     * @return bool True if the request is a GET request, false otherwise.
     */
    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Checks if the current request is a POST request.
     * 
     * @return bool True if the request is a POST request, false otherwise.
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Checks if the current request is a PUT request.
     * 
     * @return bool True if the request is a PUT request, false otherwise.
     */
    public function isPut(): bool
    {
        return $this->method === 'PUT';
    }

    /**
     * Checks if the current request is a DELETE request.
     * 
     * @return bool True if the request is a DELETE request, false otherwise.
     */
    public function isDelete(): bool
    {
        return $this->method === 'DELETE';
    }

    /**
     * Checks if the current request method matches any of the specified methods.
     * 
     * @param string|array $methods The method or array of methods to check against.
     * @return bool True if the current request method is in the specified methods, false otherwise.
     */
    public function isMethod(string|array $methods): bool
    {
        // Convert the methods to an array of uppercase strings
        $methods = array_map('strtoupper', (array) $methods);

        // Check if the current request method is in the list of methods
        return in_array($this->method, $methods);
    }

    /**
     * Checks if the current request is an AJAX request.
     * 
     * AJAX requests contain the 'X-Requested-With' header with the value 'xmlhttpRequest'.
     * This method checks for the presence of this header to determine if the current
     * request is an AJAX request.
     * 
     * @return bool True if the current request is an AJAX request, false otherwise.
     */
    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with', '')) === 'xmlhttprequest';
    }

    /**
     * Checks if the current request's path matches the given path.
     * 
     * The current request's path is compared to the given path after both
     * paths are trimmed of leading and trailing slashes.
     * 
     * @param string $path The path to check against.
     * @return bool True if the current request's path matches the given path, false otherwise.
     */
    public function is(string $path): bool
    {
        return trim($this->path, '/') === trim($path, '/');
    }

    /**
     * Retrieves the URI path of the current request.
     * 
     * @return string The request URI path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Retrieves the full URL of the current request.
     *
     * @return string The full URL including protocol, host, and path.
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Retrieves the URI path without the query string.
     * 
     * This method returns the URI path of the current request, excluding any
     * query parameters that may be present in the URL.
     * 
     * @return string The URI path of the request.
     */
    public function getUri(): string
    {
        // Returns the URI path without the query string.
        return $this->server('request-uri', '/');
    }

    /**
     * Retrieves the root URL of the application.
     * 
     * @return string The root URL including protocol and host.
     */
    public function getRootUrl(): string
    {
        return $this->rootUrl;
    }

    /**
     * Retrieves a query parameter value.
     * 
     * @param ?string $key The parameter key.
     * @param mixed $default Default value if key does not exist.
     * @return mixed The parameter value or default.
     */
    public function query(?string $key = null, $default = null): mixed
    {
        if (func_num_args() === 0) {
            return new Sanitizer($this->queryParams);
        }
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Checks if a query parameter exists.
     * 
     * @param string $key The parameter key.
     * @return bool True if the query parameter exists, false otherwise.
     */
    public function hasQuery(string $key): bool
    {
        return isset($this->queryParams[$key]);
    }

    /**
     * Retrieves a POST parameter value.
     * 
     * @param ?string $key The parameter key.
     * @param mixed $default Default value if key does not exist.
     * @return mixed The parameter value or default.
     */
    public function post(?string $key = null, $default = null): mixed
    {
        if (func_num_args() === 0) {
            return new Sanitizer($this->postParams);
        }
        return $this->postParams[$key] ?? $default;
    }

    /**
     * Checks if a POST parameter exists.
     * 
     * @param string $key The parameter key.
     * @return bool True if the POST parameter exists, false otherwise.
     */
    public function hasPost(string $key): bool
    {
        return isset($this->postParams[$key]);
    }

    /**
     * Retrieves an uploaded file by key.
     * 
     * @param string $key The file key.
     * @param mixed $default Default value if key does not exist.
     * @return mixed The file array or default.
     */
    public function file(string $key, $default = null): mixed
    {
        return $this->fileUploads[$key] ?? $default;
    }

    /**
     * Check if one or more file uploads are available for the given key.
     *
     * @param string $key The key to check in the file uploads array.
     * @return bool True if at least one file exists and has a valid size, false otherwise.
     */
    public function hasFile(string $key): bool
    {
        if (!isset($this->fileUploads[$key]['size'])) {
            return false;
        }

        $size = $this->fileUploads[$key]['size'];

        // Handle multiple files (array of sizes) or a single file (integer size)
        if (is_array($size)) {
            foreach ($size as $fileSize) {
                if ($fileSize > 0) {
                    return true;
                }
            }
            return false;
        }

        return $size > 0;
    }

    /**
     * Retrieves all request data (query, post, files) optionally filtered.
     * 
     * @param array $filter Optional list of keys to filter by.
     * @return array Merged array of request data.
     */
    public function all(array $filter = []): array
    {
        // Prepare all inputs from $_GET, $_POST, and $_FILES.
        $output = array_merge($this->queryParams, $this->postParams, $this->fileUploads);

        // Filter inputs if needed.
        if (!empty($filter)) {
            $output = array_intersect_key($output, array_flip($filter));
            foreach ($filter as $filterKey) {
                if (!array_key_exists($filterKey, $output)) {
                    $output[$filterKey] = null;
                }
            }
        }

        // Returns all input items.
        return $output;
    }

    /**
     * Retrieves only the specified request data.
     *
     * @param array|string $filter An array of keys to include in the result.
     * @return array The filtered request data.
     */
    public function only(array|string $filter): array
    {
        $filter = is_array($filter) ? $filter : func_get_args();
        return $this->all($filter);
    }

    /**
     * Retrieves all request data except the specified keys.
     *
     * @param array|string $filter An array of keys to exclude from the result.
     * @return array The filtered request data.
     */
    public function except(array|string $filter): array
    {
        $filter = is_array($filter) ? $filter : func_get_args();
        return array_diff_key($this->all(), array_flip($filter));
    }

    /**
     * Retrieves a server parameter by key.
     * 
     * @param string $key The parameter key.
     * @param mixed $default Default value if key does not exist.
     * @return ?string The server parameter or default.
     */
    public function server(string $key, $default = null): ?string
    {
        $key = str_replace('-', '_', strtoupper($key));
        return $this->serverParams[$key] ?? $default;
    }

    /**
     * Retrieves a request header by name.
     * 
     * @param string $name Header name.
     * @param mixed $defaultValue Default if header is not set.
     * @return ?string The header value or default.
     */
    public function header(string $name, $defaultValue = null): ?string
    {
        $name = "HTTP_$name";
        return $this->server($name, $defaultValue);
    }

    /**
     * Checks if the 'Accept' header contains a specific content type.
     * 
     * @param string $contentType The content type to check.
     * @return bool True if content type is accepted, otherwise false.
     */
    public function accept(string $contentType): bool
    {
        return stripos($this->header('accept', ''), $contentType) !== false;
    }

    /**
     * Get the IP address of the current request.
     *
     * @return false|string The client's IP address, or false if no valid IP found.
     */
    public function ip(): false|string
    {
        $headersToCheck = [
            'client-ip',
            'x-forwarded-for',
            'x-forwarded',
            'forwarded-for',
            'forwarded',
            'cf-connecting-ip',
        ];

        $ip = '';

        // Check headers for IP
        foreach ($headersToCheck as $header) {
            $value = $this->header($header);
            if (!empty($value)) {
                $ip = $value;
                break;
            }
        }

        // If no IP from headers, fallback to server remote address
        if (empty($ip)) {
            $ip = $this->server('remote-addr');
        }

        // Extract first IP from comma-separated list
        if (!empty($ip)) {
            $ip = explode(',', $ip)[0];
            $ip = trim($ip);
        }

        // Validate and return IP, or false if invalid
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : false;
    }

    /**
     * Get the User-Agent string from the request headers.
     * 
     * @return ?string The User-Agent string, or null if not present.
     */
    public function useragent(): ?string
    {
        return $this->header('user-agent');
    }

    /**
     * Determines if the request is made by a bot.
     * 
     * This method checks the User-Agent header against a list of common bot signatures.
     *
     * @return bool True if the request is from a bot, false otherwise.
     */
    public function isBot(): bool
    {
        $userAgent = $this->useragent();
        if (!$userAgent) {
            return false;
        }

        $botSignatures = ['bot', 'crawl', 'slurp', 'spider', 'mediapartners', 'google', 'bing', 'yahoo', 'baidu', 'yandex', 'sogou', 'exabot', 'facebot', 'ia_archiver'];

        $pattern = '/' . implode('|', array_map('preg_quote', $botSignatures)) . '/i';

        return preg_match($pattern, $userAgent) === 1;
    }

    /**
     * Get the referer URL from the request headers.
     * 
     * @return ?string The referer URL, or null if not present.
     */
    public function referer(): ?string
    {
        return $this->header('referer');
    }

    /**
     * Set a server parameter.
     * 
     * @param string $key Server parameter name.
     * @param string $value Server parameter value.
     * @return void
     */
    public function setServerParam(string $key, string $value): void
    {
        $this->serverParams[$key] = $value;
    }

    /**
     * Sets a query parameter by key.
     * 
     * This method adds or updates a query parameter in the current request.
     *
     * @param string $key The name of the query parameter.
     * @param string $value The value to set for the query parameter.
     * @return void
     */
    public function setQueryParam(string $key, string $value): void
    {
        $this->queryParams[$key] = $value;
    }

    /**
     * Sets a post parameter by key.
     * 
     * This method adds or updates a post parameter in the current request.
     *
     * @param string $key The name of the post parameter.
     * @param string $value The value to set for the post parameter.
     * @return void
     */
    public function setPostParam(string $key, string $value): void
    {
        $this->postParams[$key] = $value;
    }

    /**
     * Merge the server parameters with additional key-value pairs.
     * 
     * @param array $params Associative array of server parameters to extend.
     * @return void
     */
    public function mergeServerParams(array $params): void
    {
        $this->serverParams = array_merge($this->serverParams, $params);
    }

    /**
     * Merge the query parameters with additional key-value pairs.
     * 
     * @param array $params Associative array of query parameters to extend.
     * @return void
     */
    public function mergeQueryParams(array $params): void
    {
        $this->queryParams = array_merge($this->queryParams, $params);
    }

    /**
     * Merge the POST parameters with additional key-value pairs.
     * 
     * @param array $params Associative array of parameters to add.
     * @return void
     */
    public function mergePostParams(array $params): void
    {
        $this->postParams = array_merge($this->postParams, $params);
    }

    /**
     * Return the associative array of POST parameters.
     * 
     * @return array POST parameters for the current request.
     */
    public function getPostParams(): array
    {
        return $this->postParams;
    }

    /**
     * Get the associative array of query parameters.
     *
     * @return array Query parameters for the current request.
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Get the associative array of server parameters.
     *
     * @return array Server parameters for the current request.
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * Return the array of file uploads.
     * 
     * @return array Array of file uploads.
     */
    public function getFileUploads(): array
    {
        return $this->fileUploads;
    }

    /**
     * Determines if the request is made by a Fireline agent.
     *
     * This method checks if the request is an AJAX request, accepts JSON,
     * and contains the 'x-fireline-agent' header.
     *
     * @return bool True if the request is from a Fireline agent, false otherwise.
     */
    public function isFirelineRequest(): bool
    {
        return $this->isAjax() && $this->accept('application/json') && $this->header('x-fireline-agent');
    }

    /**
     * Checks if the request is an AJAX request, accepts JSON or if the path contains '/api/'.
     * 
     * @return bool True if the request is an AJAX request, accepts JSON or contains '/api/', false otherwise.
     */
    public function expectsJson(): bool
    {
        return $this->accept('application/json') &&
            ($this->isAjax() || strpos($this->getPath(), '/api/') === 0);
    }

    /**
     * Creates an Sanitizer instance with the current request data.
     * 
     * @param string|array $filter Optional filter to apply to the input data.
     * @param mixed $default Default value to return if the filter is a string and the key does not exist.
     * @return Sanitizer|mixed An instance of Sanitizer with the request data.
     */
    public function input(string|array $filter = [], $default = null): mixed
    {
        $input = $this->all((array) $filter);
        if (is_string($filter)) {
            return $input[$filter] ?? $default;
        }

        return new Sanitizer($input);
    }

    /**
     * Magic Method: Retrieves a request value by key.
     * 
     * @param string $name The key to retrieve the value for.
     * 
     * @return mixed The retrieved value, or null if the key does not exist.
     */
    public function __get($name): mixed
    {
        return match (true) {
            $this->hasQuery($name) => $this->query($name),
            $this->hasPost($name) => $this->post($name),
            $this->hasFile($name) => $this->file($name),
            $this->hasRouteParam($name) => $this->getRouteParam($name),
            default => null
        };
    }

    /**
     * Magic Method: Sets a request value by key.
     * 
     * @param string $name The key to set the value for.
     * @param mixed $value The value to set for the key.
     */
    public function __set($name, $value): void
    {
        $this->offsetSet($name, $value);
    }

    /**
     * Magic Method: Checks if a request value exists by key.
     *
     * @param string $name The key to check for existence.
     *
     * @return bool True if the key exists, false otherwise.
     */
    public function __isset($name): bool
    {
        return $this->offsetExists($name);
    }

    /**
     * Magic Method: Unsets a request value by key.
     * @param string $name The key to unset.
     * @return void
     *
     * This method allows unsetting a request value by key, which can be useful
     * for removing query parameters, post parameters, file uploads, or route parameters.
     * It uses the offsetUnset method to perform the actual unsetting operation.
     */
    public function __unset($name): void
    {
        $this->offsetUnset($name);
    }

    /**
     * Checks if a request value exists by key.
     *
     * @param string $name The key to check for existence.
     *
     * @return bool True if the key exists, false otherwise.
     */
    public function offsetExists($name): bool
    {
        return $this->{$name} !== null && $this->{$name} !== '';
    }

    /**
     * Unsets a request value by key.
     * 
     * @param string $name The key to unset.
     * @return void
     */
    public function offsetUnset($name): void
    {
        match (true) {
            $this->hasQuery($name) => $this->setQueryParam($name, null),
            $this->hasPost($name) => $this->setPostParam($name, null),
            $this->hasFile($name) => $this->fileUploads[$name] = null,
            $this->hasRouteParam($name) => $this->routeParams[$name] = null,
        };
    }

    /**
     * Retrieves a request value by key.
     * @param string $name The key to retrieve the value for.
     *
     * @return mixed The retrieved value, or null if the key does not exist.
     */
    public function offsetGet($name): mixed
    {
        return $this->{$name};
    }

    /**
     * Sets a request value by key.
     * 
     * @param string $name The key to set the value for.
     * @param mixed $value The value to set for the key.
     *
     * This method allows setting values for query parameters, post parameters,
     * file uploads, or route parameters based on the key provided.
     *
     * If the key corresponds to a query parameter, it will be set using
     * setQueryParam. If it corresponds to a post parameter, it will be set
     * using setPostParam. If it corresponds to a file upload, it will be added
     * to the fileUploads array. If it corresponds to a route parameter, it will
     * be added to the routeParams array.
     *
     * @return void
     */
    public function offsetSet($name, $value): void
    {
        match (true) {
            $this->hasQuery($name) => $this->setQueryParam($name, $value),
            $this->hasPost($name) => $this->setPostParam($name, $value),
            $this->hasFile($name) => $this->fileUploads[$name] = $value,
            $this->hasRouteParam($name) => $this->routeParams[$name] = $value,
        };
    }

    /**
     * Get an iterator for the items.
     * 
     * This method allows the model to be iterated over like an array.
     * 
     * @template TKey of array-key
     *
     * @template-covariant TValue
     *
     * @implements \ArrayAccess<TKey, TValue>
     *
     * @return ArrayIterator<TKey, TValue>
     */
    public function getIterator(): \Traversable
    {
        return new ArrayIterator($this->all());
    }

    /**
     * Checks if a specific key exists in the request.
     * 
     * This method checks if the specified key exists in the request data
     * (query parameters, post parameters, file uploads, or route parameters).
     *
     * @param string $key The key to check for existence.
     * @return bool True if the key exists, false otherwise.
     */
    public function has(string $key): bool
    {
        return $this->offsetExists($key);
    }

    /**
     * Retrieves a request value by key, returning a default value if the key does not exist.
     * 
     * This method retrieves the value associated with the specified key from the request data.
     * If the key does not exist, it returns the provided default value.
     *
     * @param string $key The key to retrieve the value for.
     * @param mixed $default The default value to return if the key does not exist.
     * @return mixed The retrieved value or the default value if the key does not exist.
     */
    public function get(string $key, $default = null): mixed
    {
        if ($this->has($key) === false) {
            return $default;
        }

        return $this->offsetGet($key);
    }

    /**
     * Checks if any of the specified keys exist in the request.
     * 
     * This method checks if at least one of the provided keys exists in the
     * request data (query parameters, post parameters, file uploads, or route parameters).
     *
     * @param string|array $keys The key or array of keys to check for existence.
     * @return bool True if any of the keys exist, false otherwise.
     */
    public function hasAny(string|array $keys): bool
    {
        foreach ((array) $keys as $key) {
            if ($this->offsetExists($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if all of the specified keys exist in the request.
     * 
     * This method checks if all of the provided keys exist in the
     * request data (query parameters, post parameters, file uploads, or route parameters).
     *
     * @param string|array $keys The key or array of keys to check for existence.
     * @return bool True if all of the keys exist, false otherwise.
     */
    public function hasAll(string|array $keys): bool
    {
        foreach ((array) $keys as $key) {
            if (!$this->offsetExists($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validates the request with given rules.
     * 
     * The method gets the input attributes from the current request and
     * validates them with the given rules. If the validation fails, the
     * method redirects the user back to the previous page with the
     * validation errors. If the request wants a JSON response, the method
     * returns the validation errors as a JSON response.
     * 
     * @param array $rules
     *   The validation rules.
     *
     * @return Sanitizer
     *   Returns the validated attributes as an Sanitizer instance.
     */
    public function validate(array $rules): Sanitizer
    {
        $attributes = $this->all(array_keys($rules)); // Get the attributes from the current request
        $validator = new Validator(); // Get the validator instance

        if (!$validator->validate($rules, $this->all())) { // Validate the request
            $errors = $validator->getErrors(); // Get the errors as an array

            // If the request wants a JSON response
            if ($this->isFirelineRequest()) {
                $errorHtml = '<ul>' // Build the error HTML
                    . collect(array_merge(...array_values($errors)))
                        ->map(fn($error) => "<li>{$error}</li>")
                        ->join('') // Join the errors into a string
                    . '</ul>';

                // Return the errors as a JSON response
                json(['status' => 'error', 'message' => $errorHtml])->send();
            } elseif ($this->expectsJson()) {
                // Validate error message
                $message = $validator->getFirstError()
                    . (count($errors) > 1 ? ' (and ' . count($errors) . ' more errors)' : '');

                // Return the errors as a JSON response
                json(['message' => $message, 'errors' => $errors], 422)->send();
            } else {
                // Store the errors in the session flash data
                back()
                    ->withErrors($errors)
                    ->withInput($attributes)
                    ->send(); // Redirect the user back to the previous page
            }
            exit; // Exit the script to prevent further execution
        }

        return new Sanitizer($attributes); // Return the validated attributes
    }

    /**
     * Get the error object.
     *
     * The method returns the error object from the session flash data.
     * The error object contains the error messages and attributes from the previous request.
     *
     * @return \Spark\Helpers\RequestErrors
     *   The error object.
     */
    public function getErrorObject(): RequestErrors
    {
        return $this->errorObject ??= app(RequestErrors::class);
    }

    /**
     * Get the errors from the current request.
     *
     * @param null|array|string $field The field name to retrieve the error messages for.
     *                                  If null, all error object will be returned.
     * @return object|bool An object containing the error messages from the current request.
     */
    public function errors(null|array|string $field = null): mixed
    {
        if ($field !== null) {
            foreach ((array) $field as $name) {
                if ($this->getErrorObject()->has($name)) {
                    return true;
                }
            }
            return false;
        }

        return $this->getErrorObject();
    }

    /**
     * Get the value of a field from the previous request using the old input.
     *
     * @param string $field The name of the field to retrieve.
     * @param ?string $default The default value to return if the field is not found.
     * @return string|null The value of the field from the previous request, or the default value if not found.
     */
    public function old(string $field, ?string $default = null): ?string
    {
        return $this->getErrorObject()->getOld($field, $default);
    }

    /**
     * Get the session instance.
     *
     * This method retrieves the session instance from the application container.
     *
     * @param ?string $key The session key to retrieve (optional).
     * @param mixed $default The default value to return if the key does not exist (optional).
     * @return \Spark\Http\Session|mixed The session instance or the value associated with the given key.
     */
    public function session(?string $key = null, $default = null): mixed
    {
        /** @var \Spark\Http\Session $session */
        $session = app(Session::class);

        if (func_num_args() > 0) {
            return $session->get($key, $default);
        }

        return $session;
    }

    /**
     * Get the Auth service instance.
     *
     * This method retrieves the Auth service instance from the application container.
     *
     * @return \Spark\Http\Auth The Auth service instance.
     */
    public function auth(): Auth
    {
        return app(Auth::class);
    }

    /**
     * Retrieves the currently authenticated user or a specific attribute of the user.
     *
     * This method utilizes the Auth service to fetch the currently authenticated user.
     * If a key is provided, it returns the value of that specific attribute from the user object.
     * If no key is provided, it returns the entire user object. If no user is authenticated,
     * it returns null or the specified default value.
     *
     * @param ?string $key The specific attribute of the user to retrieve (optional).
     * @param mixed $default The default value to return if no user is authenticated or the key does not exist (optional).
     *
     * @return mixed The authenticated user object, a specific attribute value, or the default value.
     */
    public function user(?string $key = null, $default = null)
    {
        return $this->auth()->user($key, $default);
    }

    /**
     * Checks if the current user is authenticated.
     *
     * This method checks if there is a currently authenticated user
     * by utilizing the Auth service. It returns true if a user is logged in,
     * and false if no user is authenticated.
     *
     * @return bool True if the user is authenticated, false otherwise.
     */
    public function isAuthenticated(): bool
    {
        return $this->auth()->isLoggedIn();
    }

    /**
     * Checks if the current user is not authenticated.
     *
     * This method checks if there is no currently authenticated user
     * by utilizing the Auth service. It returns true if no user is logged in,
     * and false if a user is authenticated.
     *
     * @return bool True if the user is not authenticated, false otherwise.
     */
    public function isNotAuthenticated(): bool
    {
        return $this->auth()->isGuest();
    }
}
