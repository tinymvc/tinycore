<?php

namespace Spark\Helpers;

use ArrayAccess;

/**
 * Route object implementation with optimized ArrayAccess and magic methods
 * 
 * This class provides a flexible interface for accessing route data through
 * multiple access patterns while maintaining performance through direct
 * array access and minimal overhead.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class RouteObject implements ArrayAccess
{
    /**
     * Internal route data storage
     * 
     * @var array<string, mixed>
     */
    private array $route;

    /**
     * Initialize route object with data
     * 
     * @param array<string, mixed> $route Route configuration and computed data
     */
    public function __construct(array $route)
    {
        $this->route = $route;
    }

    /**
     * Magic getter for property-style access
     * 
     * Provides fallback behavior: returns property name if key doesn't exist
     * This maintains backward compatibility with the original implementation
     * 
     * @param string $name Property name to retrieve
     * @return mixed Property value or property name as fallback
     */
    public function __get(string $name): mixed
    {
        return $this->route[$name] ?? $name;
    }

    /**
     * Check if property exists
     * 
     * @param string $name Property name to check
     * @return bool True if property exists
     */
    public function __isset(string $name): bool
    {
        return isset($this->route[$name]);
    }

    /**
     * Set property value
     * 
     * @param string $name Property name
     * @param mixed $value Property value
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->route[$name] = $value;
    }

    /**
     * Unset property
     * 
     * @param string $name Property name to remove
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->route[$name]);
    }

    /**
     * ArrayAccess: Check if offset exists
     * 
     * @param mixed $offset Array key to check
     * @return bool True if offset exists
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->route[$offset]);
    }

    /**
     * ArrayAccess: Get value by offset
     * 
     * @param mixed $offset Array key to retrieve
     * @return mixed Value at offset or null if not found
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->route[$offset] ?? null;
    }

    /**
     * ArrayAccess: Set value at offset
     * 
     * @param mixed $offset Array key to set
     * @param mixed $value Value to set
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->route[] = $value;
        } else {
            $this->route[$offset] = $value;
        }
    }

    /**
     * ArrayAccess: Unset offset
     * 
     * @param mixed $offset Array key to remove
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->route[$offset]);
    }

    /**
     * Convert route object to string representation
     * 
     * Returns the absolute URL for convenient string usage in templates
     * and redirects
     * 
     * @return string The absolute URL of the route
     */
    public function __toString(): string
    {
        return $this->route['absoluteUrl'] ?? '';
    }

    /**
     * Get the relative URL (without domain)
     * 
     * Useful for internal redirects and form actions where you don't need
     * the full absolute URL
     * 
     * @return string The relative path of the route
     */
    public function getPath(): string
    {
        return $this->route['parsedPath'] ?? '';
    }

    /**
     * Get the absolute URL
     * 
     * Explicit method for getting the full URL, same as __toString()
     * but more descriptive for code readability
     * 
     * @return string The absolute URL of the route
     */
    public function getUrl(): string
    {
        return $this->route['absoluteUrl'] ?? '';
    }

    /**
     * Add query parameters to the route URL
     * 
     * Appends query string parameters to the existing route URL
     * 
     * @param array<string, mixed> $params Associative array of query parameters
     * @param bool $merge Whether to merge with existing query params (default: true)
     * @return self Returns new instance with modified URL
     * 
     * @example
     * $route = route('user.profile', ['id' => 123]);
     * $routeWithQuery = $route->withQuery(['tab' => 'settings', 'edit' => 1]);
     * echo $routeWithQuery; // /user/123?tab=settings&edit=1
     */
    public function withQuery(array $params, bool $merge = true): self
    {
        $url = $this->route['parsedPath'] ?? '';
        $existingQuery = [];

        // Parse existing query parameters if merging
        if ($merge && str_contains($url, '?')) {
            [$path, $queryString] = explode('?', $url, 2);
            parse_str($queryString, $existingQuery);
            $url = $path;
        }

        // Merge or replace parameters
        $finalParams = $merge ? array_merge($existingQuery, $params) : $params;

        // Build new URL with query string
        $newPath = $url . ($finalParams ? '?' . http_build_query($finalParams) : '');

        // Create new instance with modified data
        $newRoute = $this->route;
        $newRoute['parsedPath'] = $newPath;
        $newRoute['absoluteUrl'] = home_url($newPath);

        return new static($newRoute);
    }

    /**
     * Add URL fragment/hash to the route
     * 
     * @param string $fragment The fragment identifier (without #)
     * @return self Returns new instance with fragment added
     * 
     * @example
     * $route = route('page.docs')->withFragment('section-api');
     * echo $route; // /docs#section-api
     */
    public function withFragment(string $fragment): self
    {
        $newRoute = $this->route;
        $newRoute['parsedPath'] = ($this->route['parsedPath'] ?? '') . '#' . ltrim($fragment, '#');
        $newRoute['absoluteUrl'] = home_url($newRoute['parsedPath']);

        return new static($newRoute);
    }

    /**
     * Get route parameters that were substituted
     * 
     * Returns the parameters that were used to generate this route instance
     * 
     * @return array<string, mixed> Route parameters
     */
    public function getParameters(): array
    {
        return $this->route['parameters'] ?? [];
    }

    /**
     * Get a specific route parameter
     * 
     * @param string $key Parameter name
     * @param mixed $default Default value if parameter doesn't exist
     * @return mixed Parameter value or default
     */
    public function getParameter(string $key, mixed $default = null): mixed
    {
        return $this->getParameters()[$key] ?? $default;
    }

    /**
     * Check if route has specific parameter
     * 
     * @param string $key Parameter name to check
     * @return bool True if parameter exists
     */
    public function hasParameter(string $key): bool
    {
        return isset($this->getParameters()[$key]);
    }

    /**
     * Get route HTTP methods
     * 
     * @return array<string> Array of HTTP methods (GET, POST, etc.)
     */
    public function getMethods(): array
    {
        return $this->route['methods'] ?? ['GET'];
    }

    /**
     * Check if route supports a specific HTTP method
     * 
     * @param string $method HTTP method to check (case-insensitive)
     * @return bool True if method is supported
     */
    public function supportsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->getMethods(), true);
    }

    /**
     * Get route middleware
     * 
     * @return array<string> Array of middleware names
     */
    public function getMiddleware(): array
    {
        return $this->route['middleware'] ?? [];
    }

    /**
     * Check if route has specific middleware
     * 
     * @param string $middleware Middleware name to check
     * @return bool True if middleware is applied to this route
     */
    public function hasMiddleware(string $middleware): bool
    {
        return in_array($middleware, $this->getMiddleware(), true);
    }

    /**
     * Convert route data to array
     * 
     * Useful for debugging, logging, or API responses
     * 
     * @return array<string, mixed> All route data
     */
    public function toArray(): array
    {
        return $this->route;
    }

    /**
     * Generate cache key for this route
     * 
     * Useful for caching route-specific data
     * 
     * @param string $suffix Optional suffix for the cache key
     * @return string Cache key
     */
    public function getCacheKey(string $suffix = ''): string
    {
        $key = 'route:' . md5($this->getUrl());
        return $suffix ? "$key:$suffix" : $key;
    }

    /**
     * Check if the given path matches this route
     * 
     * Compares the route's path and URL against the provided path,
     * allowing for flexible matching without leading or trailing slashes.
     * 
     * @param string $path Path to check against the route
     * @return bool True if the route matches the given path
     */
    public function is(string $path): bool
    {
        // Check if the route matches the given path
        return trim($this->getPath(), '/') === trim($path, '/');
    }

    /**
     * Check if the given path matches this route
     * 
     * Compares the route's path and URL against the provided path,
     * allowing for flexible matching without leading or trailing slashes.
     * 
     * @param string $path Path to check against the route
     * @return bool True if the route matches the given path
     */
    public function matches(string $path): bool
    {
        // Get route path for comparison
        $routePath = $this->getPath();

        // Normalize paths by removing leading/trailing slashes for comparison
        $normalizedPath = trim($path, '/');
        $normalizedRoutePath = trim($routePath, '/');

        // Handle wildcard patterns
        if (strpos($path, '*') !== false || strpos($path, '?') !== false) {
            return $this->matchesWildcard($normalizedRoutePath, $normalizedPath);
        }

        // Exact path matching (case-insensitive for flexibility)
        if (strcasecmp($normalizedRoutePath, $normalizedPath) === 0) {
            return true;
        }

        // Check if route path starts with the given path (prefix matching)
        // This handles cases like checking if '/admin' matches route '/admin/users'
        if ($normalizedPath !== '' && strpos($normalizedRoutePath . '/', $normalizedPath . '/') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Check if a route path matches a wildcard pattern
     * 
     * Supports:
     * - * matches any number of characters (including path separators)
     * - ? matches exactly one character
     * - Multiple wildcards in a single pattern
     * - Escaped wildcards (\* and \?) for literal matching
     * 
     * @param string $routePath The normalized route path to test
     * @param string $pattern The wildcard pattern to match against
     * @return bool True if the route path matches the pattern
     */
    private function matchesWildcard(string $routePath, string $pattern): bool
    {
        // Handle empty cases
        if ($pattern === '' && $routePath === '') {
            return true;
        }

        if ($pattern === '') {
            return false;
        }

        // Convert wildcard pattern to regex
        $regex = $this->wildcardToRegex($pattern);

        // Perform case-insensitive matching
        return (bool) preg_match($regex, $routePath);
    }

    /**
     * Convert wildcard pattern to regular expression
     * 
     * Transforms shell-style wildcards into regex patterns while handling
     * escaped characters and special regex characters properly.
     * 
     * @param string $pattern Wildcard pattern
     * @return string Regex pattern with delimiters and flags
     */
    private function wildcardToRegex(string $pattern): string
    {
        // Escape regex special characters, but preserve our wildcards temporarily
        $pattern = str_replace(['\\*', '\\?'], ['__ESCAPED_STAR__', '__ESCAPED_QUESTION__'], $pattern);
        $pattern = preg_quote($pattern, '/');

        // Convert wildcards to regex equivalents
        $pattern = str_replace(
            ['__ESCAPED_STAR__', '__ESCAPED_QUESTION__', '\\*', '\\?'],
            ['\\*', '\\?', '.*', '.'],
            $pattern
        );

        // Return complete regex with case-insensitive flag
        return '/^' . $pattern . '$/i';
    }

    /**
     * Check if route matches any of the provided patterns
     * 
     * Convenience method for checking multiple patterns at once
     * 
     * @param array<string> $patterns Array of paths/patterns to check
     * @return bool True if route matches any of the patterns
     * 
     * @example
     * $route->matchesAny(['/admin/*', '/user/profile', 'api.*']);
     */
    public function matchesAny(array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matches($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if route matches all of the provided patterns
     * 
     * Useful for complex matching requirements
     * 
     * @param array<string> $patterns Array of paths/patterns to check
     * @return bool True if route matches all patterns
     */
    public function matchesAll(array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (!$this->matches($pattern)) {
                return false;
            }
        }

        return !empty($patterns); // Return false if no patterns provided
    }
}