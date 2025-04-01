<?php

namespace Spark\Http;

use Spark\Contracts\Http\RequestContract;
use Spark\Http\Traits\ValidateRequest;

/**
 * Class Request
 * 
 * Handles and manages HTTP request data for the application, including
 * query parameters, POST data, file uploads, and server variables.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Request implements RequestContract
{
    /**
     * Use the Validator trait to validate request data. If 
     * it fails, then redirect to previous page with error messages
     */
    use ValidateRequest;

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
        if ($this->method === 'POST' && empty($this->postParams)) {
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
        $position = strpos($path, '?');
        return $position !== false ? substr($path, 0, $position) : $path;
    }

    /**
     * Builds the root URL (protocol and host).
     * 
     * @return string Root URL of the application.
     */
    private function parseRootUrl(): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        return $protocol . ($_SERVER['HTTP_HOST'] ?? '');
    }

    /**
     * Builds the full URL of the current request.
     * 
     * @return string Full URL including protocol, host, and path.
     */
    private function parseUrl(): string
    {
        return rtrim($this->rootUrl . '/' . ltrim($_SERVER['REQUEST_URI'] ?? '', '/'), '/');
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
     * @param string $key The parameter key.
     * @param mixed $default Default value if key does not exist.
     * @return ?string The parameter value or default.
     */
    public function query(string $key, $default = null): ?string
    {
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
     * @param string $key The parameter key.
     * @param mixed $default Default value if key does not exist.
     * @return mixed The parameter value or default.
     */
    public function post(string $key, $default = null): mixed
    {
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
}
