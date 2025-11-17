<?php

namespace Spark;

use ArrayAccess;
use JsonSerializable;
use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Support\Jsonable;
use Spark\Support\Traits\Macroable;
use Stringable;
use function array_key_exists;
use function array_slice;
use function count;
use function in_array;
use function is_array;

/**
 * Class Url
 *
 * This class provides a flexible interface for accessing and manipulating URL data
 * through multiple access patterns while maintaining performance and modern PHP standards.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Url implements Arrayable, ArrayAccess, Jsonable, JsonSerializable, Stringable
{
    use Macroable;

    /** @var array Parsed URL components */
    private array $components = [];

    /** @var array Route parameters */
    private array $parameters = [];

    /** @var string The absolute URL */
    private string $absoluteUrl;

    /**
     * Constructor to initialize the URL object
     * 
     * @param string $absoluteUrl The full URL to parse
     * @param array $parameters Optional route parameters
     * @throws \InvalidArgumentException If the URL is invalid or cannot be parsed
     */
    public function __construct(string $absoluteUrl, array $parameters = [])
    {
        if (empty($absoluteUrl)) {
            throw new \InvalidArgumentException('URL cannot be empty.');
        }

        $this->absoluteUrl = $absoluteUrl;
        $parsed = parse_url($absoluteUrl);

        if ($parsed === false) {
            throw new \InvalidArgumentException("Invalid URL: {$absoluteUrl}");
        }

        $this->components = $parsed;
        $this->parameters = $parameters;
    }

    /**
     * Build the parsed path including query and fragment
     * 
     * @return string The full parsed path
     */
    private function buildParsedPath(): string
    {
        $path = $this->components['path'] ?? '/';

        if (!empty($this->components['query'])) {
            $path .= '?' . $this->components['query'];
        }

        if (!empty($this->components['fragment'])) {
            $path .= '#' . $this->components['fragment'];
        }

        return $path;
    }

    /**
     * Magic getter for property-style access
     * Provides access to URL components and parameters
     * 
     * @param string $name The property name to access
     * @return mixed The value of the requested property or parameter
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'absoluteUrl' => $this->absoluteUrl,
            'scheme' => $this->getScheme(),
            'host' => $this->getHost(),
            'port' => $this->getPort(),
            'path' => $this->getPath(),
            'query' => $this->getQuery(),
            'fragment' => $this->getFragment(),
            'parsedPath' => $this->buildParsedPath(),
            'parameters' => $this->parameters,
            default => $this->parameters[$name] ?? null
        };
    }

    /**
     * Check if property exists
     * 
     * @param string $name The property name to check
     * @return bool True if the property or parameter exists, false otherwise
     */
    public function __isset(string $name): bool
    {
        $urlProperties = ['absoluteUrl', 'scheme', 'host', 'port', 'path', 'query', 'fragment', 'parsedPath', 'parameters'];
        return in_array($name, $urlProperties) || isset($this->parameters[$name]);
    }

    /**
     * Set property value
     * 
     * This method allows setting both URL components and route parameters.
     * If 'parameters' is set, it replaces the entire parameters array.
     * Otherwise, it sets a specific parameter.
     * 
     * @param string $name The property name to set
     * @param mixed $value The value to assign to the property
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        if ($name === 'parameters') {
            $this->parameters = is_array($value) ? $value : [];
        } else {
            $this->parameters[$name] = $value;
        }
    }

    /**
     * Unset property
     * 
     * @param string $name The property name to unset
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->parameters[$name]);
    }

    /**
     * ArrayAccess: Check if offset exists
     * 
     * @param mixed $offset The offset to check
     * @return bool True if the offset exists, false otherwise
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset($offset);
    }

    /**
     * ArrayAccess: Get value by offset
     * 
     * @param mixed $offset The offset to retrieve
     * @return mixed The value at the specified offset
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get($offset);
    }

    /**
     * ArrayAccess: Set value at offset
     * 
     * This method allows setting values at specific offsets.
     * 
     * @param mixed $offset The offset to set
     * @param mixed $value The value to assign at the offset
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            // Handle array push operation
            $this->parameters[] = $value;
        } else {
            $this->__set($offset, $value);
        }
    }

    /**
     * ArrayAccess: Unset offset
     * 
     * @param mixed $offset The offset to unset
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->__unset($offset);
    }

    /**
     * Convert URL object to string representation
     * 
     * This method allows the URL object to be used as a string,
     * returning the full absolute URL.
     * 
     * @return string The full absolute URL as a string
     */
    public function __toString(): string
    {
        return $this->getUrl();
    }

    /**
     * Get the scheme (protocol)
     * 
     * This method retrieves the scheme component of the URL.
     * If the scheme is not set, it defaults to 'https'.
     * 
     * @return string The scheme part of the URL, defaulting to 'https' if not set
     */
    public function getScheme(): string
    {
        return $this->components['scheme'] ?? 'https';
    }

    /**
     * Get the host/domain
     * 
     * This method retrieves the host component of the URL.
     * If the host is not set, it returns an empty string.
     * 
     * @return string The host part of the URL, or an empty string if not set
     */
    public function getHost(): string
    {
        return $this->components['host'] ?? '';
    }

    /**
     * Get the port number
     * 
     * This method retrieves the port component of the URL.
     * If the port is not set, it returns null.
     * 
     * @return int|null The port number, or null if not set
     */
    public function getPort(): ?int
    {
        return $this->components['port'] ?? null;
    }

    /**
     * Get the path component (without query or fragment)
     * 
     * This method retrieves the path component of the URL,
     * which is the part after the host and port.
     * 
     * @return string The path part of the URL, or '/' if not set
     */
    public function getPath(): string
    {
        return $this->components['path'] ?? '/';
    }

    /**
     * Get the query string (without the ?)
     * 
     * This method retrieves the query component of the URL,
     * which is the part after the question mark (?).
     * 
     * @return string The query part of the URL, or an empty string if not set
     */
    public function getQuery(): string
    {
        return $this->components['query'] ?? '';
    }

    /**
     * Get query parameters as associative array
     * 
     * This method parses the query string and returns an associative array
     * where keys are parameter names and values are their corresponding values.
     * 
     * @return array An associative array of query parameters
     */
    public function getQueryParams(): array
    {
        $query = $this->getQuery();
        if (empty($query)) {
            return [];
        }

        parse_str($query, $params);
        return $params;
    }

    /**
     * Get the fragment/hash (without the #)
     * 
     * This method retrieves the fragment component of the URL,
     * which is the part after the hash (#).
     * 
     * @return string The fragment part of the URL, or an empty string if not set
     */
    public function getFragment(): string
    {
        return $this->components['fragment'] ?? '';
    }

    /**
     * Get the absolute URL
     * 
     * This method returns the full absolute URL as a string,
     * including the scheme, host, port (if not default), path, query, and fragment.
     * 
     * @return string The constructed absolute URL
     */
    public function getUrl(): string
    {
        return $this->absoluteUrl;
    }

    /**
     * Build absolute URL from components
     * 
     * This method constructs the absolute URL based on the current components.
     * It includes the scheme, host, port (if not default), path, query, and fragment.
     * 
     * @return string The constructed absolute URL
     */
    private function buildAbsoluteUrl(): string
    {
        $url = $this->getScheme() . '://' . $this->getHost();

        $port = $this->getPort();
        if ($port && !in_array($port, [80, 443])) {
            $url .= ":$port";
        }

        $url .= $this->getPath();

        if ($this->getQuery()) {
            $url .= '?' . $this->getQuery();
        }

        if ($this->getFragment()) {
            $url .= '#' . $this->getFragment();
        }

        return $url;
    }

    /**
     * Update internal state after URL modification
     * 
     * This method is called whenever a component of the URL is modified.
     * It rebuilds the absolute URL based on the current components.
     * 
     * @return void
     */
    private function updateUrl(): void
    {
        $this->absoluteUrl = $this->buildAbsoluteUrl();
    }

    /**
     * Add or modify query parameters
     * 
     * This method allows you to add or modify multiple query parameters in the URL.
     * It accepts an associative array of parameters and a boolean to determine if it should merge with
     * existing parameters or replace them entirely.
     * 
     * @param array $params Associative array of query parameters to add or modify
     * @param bool $merge Whether to merge with existing parameters (default: true)
     * @return self A new Url instance with the updated query parameters
     */
    public function withQuery(array $params, bool $merge = true): self
    {
        $existingParams = $this->getQueryParams();

        // Merge or replace parameters
        $finalParams = $merge ? array_merge($existingParams, $params) : $params;

        // Remove null values
        $finalParams = array_filter($finalParams, fn($value) => $value !== null);

        return $this->withQueryString(http_build_query($finalParams));
    }

    /**
     * Set query string directly
     * 
     * This method allows you to set the entire query string for the URL.
     * It replaces the existing query string with the new one, ensuring it does not start with a question mark (?).
     * 
     * @param string $queryString The query string to set for the URL
     * @return self A new Url instance with the updated query string
     */
    public function withQueryString(string $queryString): self
    {
        $new = clone $this;
        $new->components['query'] = ltrim($queryString, '?');
        $new->updateUrl();

        return $new;
    }

    /**
     * Add or modify a single query parameter
     * 
     * This method allows you to add or modify a single query parameter in the URL.
     * It accepts a key and a value, and returns a new Url instance with the updated query.
     * 
     * @param string $key The query parameter key to add or modify
     * @param mixed $value The value to set for the query parameter
     * @return self A new Url instance with the updated query parameter
     */
    public function withQueryParam(string $key, mixed $value): self
    {
        return $this->withQuery([$key => $value], true);
    }

    /**
     * Remove query parameters
     * 
     * This method allows you to remove specific query parameters from the URL.
     * It accepts either a single key or an array of keys to remove.
     * 
     * @param array|string $keys The key or keys to remove from the query parameters
     * @return self A new Url instance with the specified query parameters removed
     */
    public function withoutQuery(array|string $keys): self
    {
        $params = $this->getQueryParams();
        $keysToRemove = is_array($keys) ? $keys : [$keys];

        foreach ($keysToRemove as $key) {
            unset($params[$key]);
        }

        return $this->withQuery($params, false);
    }

    /**
     * Add URL fragment/hash to the route
     * 
     * This method allows you to set a specific fragment for the URL.
     * It replaces the existing fragment with the new one, ensuring it does not start with a hash (#).
     * 
     * @param string $fragment The fragment to set for the URL
     * @return self A new Url instance with the updated fragment
     */
    public function withFragment(string $fragment): self
    {
        $new = clone $this;
        $new->components['fragment'] = ltrim($fragment, '#');
        $new->updateUrl();

        return $new;
    }

    /**
     * Remove fragment from URL
     * 
     * This method allows you to remove the fragment component from the URL.
     * It sets the fragment to an empty string, effectively removing it.
     * 
     * @return self A new Url instance with the fragment removed
     */
    public function withoutFragment(): self
    {
        return $this->withFragment('');
    }

    /**
     * Change the path component
     * 
     * This method allows you to set a specific path for the URL.
     * It replaces the existing path with the new one, ensuring it starts with a slash.
     * 
     * @param string $path The path to set for the URL
     * @return self A new Url instance with the updated path
     */
    public function withPath(string $path): self
    {
        $new = clone $this;
        $new->components['path'] = '/' . ltrim($path, '/');
        $new->updateUrl();

        return $new;
    }

    /**
     * Change the scheme
     * 
     * This method allows you to set a specific scheme (protocol) for the URL.
     * It replaces the existing scheme with the new one.
     * 
     * @param string $scheme The scheme to set for the URL (e.g., 'http', 'https')
     * @return self A new Url instance with the updated scheme
     */
    public function withScheme(string $scheme): self
    {
        $new = clone $this;
        $new->components['scheme'] = rtrim($scheme, ':/');
        $new->updateUrl();

        return $new;
    }

    /**
     * Change the host
     * 
     * This method allows you to set a specific host for the URL.
     * It replaces the existing host with the new one.
     * 
     * @param string $host The host to set for the URL
     * @return self A new Url instance with the updated host
     */
    public function withHost(string $host): self
    {
        $new = clone $this;
        $new->components['host'] = $host;
        $new->updateUrl();

        return $new;
    }

    /**
     * Change the port
     * 
     * This method allows you to set a specific port for the URL.
     * If the port is null, it removes the port component from the URL.
     * 
     * @param int|null $port The port number to set, or null to remove the port
     * @return self A new Url instance with the updated port
     */
    public function withPort(?int $port): self
    {
        $new = clone $this;
        if ($port === null) {
            unset($new->components['port']);
        } else {
            $new->components['port'] = $port;
        }
        $new->updateUrl();

        return $new;
    }

    /**
     * Get route parameters that were substituted
     * 
     * This method retrieves all route parameters that were substituted
     * during the route matching process. It returns an associative array
     * where keys are parameter names and values are their corresponding values.
     * 
     * @return array The associative array of route parameters
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get a specific route parameter
     * 
     * This method retrieves a specific route parameter by its key.
     * If the parameter does not exist, it returns a default value.
     * 
     * @param string $key The parameter key to retrieve
     * @param mixed $default The default value to return if the parameter does not exist
     * @return mixed The value of the parameter or the default value
     */
    public function getParameter(string $key, $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }

    /**
     * Check if route has specific parameter
     * 
     * This method checks if a specific route parameter exists.
     * 
     * @param string $key The parameter key to check
     * @return bool True if the parameter exists, false otherwise
     */
    public function hasParameter(string $key): bool
    {
        return isset($this->parameters[$key]);
    }

    /**
     * Add route parameters
     * 
     * This method allows you to add or modify route parameters.
     * 
     * @param array $parameters Associative array of parameters to add or modify
     * @return self A new Url instance with the updated parameters
     */
    public function withParameters(array $parameters): self
    {
        $new = clone $this;
        $new->parameters = array_merge($this->parameters, $parameters);

        return $new;
    }

    /**
     * Convert route data to array
     * 
     * This method converts the URL object to an associative array,
     * including all components and parameters.
     * 
     * @return array The array representation of the URL
     */
    public function toArray(): array
    {
        return [
            'url' => $this->getUrl(),
            'scheme' => $this->getScheme(),
            'host' => $this->getHost(),
            'port' => $this->getPort(),
            'path' => $this->getPath(),
            'query' => $this->getQuery(),
            'queryParams' => $this->getQueryParams(),
            'fragment' => $this->getFragment(),
            'parameters' => $this->getParameters(),
        ];
    }

    /**
     * Convert route data to JSON
     * 
     * This method converts the URL object to a JSON string,
     * including all components and parameters.
     * 
     * @param int $options JSON encoding options
     * @return string The JSON representation of the URL
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * JsonSerializable implementation
     * 
     * This method allows the URL object to be serialized to JSON.
     * It returns an array representation of the URL,
     * which includes all relevant components and parameters.
     * 
     * @return array The array representation of the URL
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Generate cache key for this route
     * 
     * This method generates a unique cache key based on the route's URL.
     * It can also append a suffix for more specific caching needs.
     * 
     * @param string $suffix Optional suffix to append to the cache key
     * @return string The generated cache key
     */
    public function getCacheKey(string $suffix = ''): string
    {
        $key = 'route:' . md5($this->getUrl());
        return $suffix ? "$key:$suffix" : $key;
    }

    /**
     * Check if the given path matches this route exactly
     * 
     * This method checks if the route's path matches the given path exactly,
     * ignoring leading and trailing slashes.
     * 
     * @param string $path The path to check against the route
     * @return bool True if the route's path matches the given path, false otherwise
     */
    public function is(string $path): bool
    {
        return trim($this->getPath(), '/') === trim($path, '/');
    }

    /**
     * Check if the given path matches this route (supports wildcards)
     * 
     * This method checks if the route matches a given pattern.
     * It supports exact matches, prefix matches, and wildcard patterns.
     * 
     * @param string $pattern The pattern to match against the route
     * @return bool True if the route matches the pattern, false otherwise
     */
    public function matches(string $pattern): bool
    {
        $routePath = trim($this->getPath(), '/');
        $normalizedPattern = trim($pattern, '/');

        // Handle wildcard patterns
        if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
            return $this->matchesWildcard($routePath, $normalizedPattern);
        }

        // Exact match
        if (strcasecmp($routePath, $normalizedPattern) === 0) {
            return true;
        }

        // Prefix matching
        if ($normalizedPattern !== '' && str_starts_with("$routePath/", "$normalizedPattern/")) {
            return true;
        }

        return false;
    }

    /**
     * Check if route matches any of the provided patterns
     * 
     * This method checks if the route matches any pattern in the provided array.
     * It returns true if at least one pattern matches the route.
     * 
     * @param array $patterns An array of patterns to match against the route
     * @return bool True if the route matches any pattern, false otherwise
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
     * This method checks if the route matches all patterns in the provided array.
     * It returns true only if every pattern matches the route.
     * 
     * @param array $patterns An array of patterns to match against the route
     * @return bool True if the route matches all patterns, false otherwise
     */
    public function matchesAll(array $patterns): bool
    {
        if (empty($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (!$this->matches($pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a route path matches a wildcard pattern
     * 
     * This method checks if the given route path matches a wildcard pattern,
     * where wildcards can be represented by * (matches any sequence) and ? (matches a single character).
     * 
     * @param string $routePath The route path to check
     * @param string $pattern The wildcard pattern to match against
     * @return bool True if the route path matches the pattern, false otherwise
     */
    private function matchesWildcard(string $routePath, string $pattern): bool
    {
        if ($pattern === '' && $routePath === '') {
            return true;
        }

        if ($pattern === '') {
            return false;
        }

        $regex = $this->wildcardToRegex($pattern);
        return (bool) preg_match($regex, $routePath);
    }

    /**
     * Convert wildcard pattern to regular expression
     * 
     * This method converts a wildcard pattern (with * and ?) to a regex pattern.
     * 
     * @param string $pattern The wildcard pattern to convert
     * @return string The regex pattern equivalent to the wildcard
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

        return "/^$pattern\$/i";
    }

    /**
     * Check if URL is secure (HTTPS)
     * 
     * This method checks if the URL uses the HTTPS scheme.
     * 
     * @return bool True if the URL is secure, false otherwise
     */
    public function isSecure(): bool
    {
        return strtolower($this->getScheme()) === 'https';
    }

    /**
     * Check if URL is on default port
     * 
     * This method checks if the URL's port is the default for its scheme.
     * For HTTP, the default port is 80; for HTTPS, it is 443
     * 
     * @return bool True if the port is default, false otherwise
     */
    public function isDefaultPort(): bool
    {
        $port = $this->getPort();
        $scheme = $this->getScheme();

        return $port === null ||
            $scheme === 'http' && $port === 80 ||
            $scheme === 'https' && $port === 443;
    }

    /**
     * Get the authority part (host:port)
     * 
     * This method constructs the authority part of the URL,
     * which includes the host and port if it's not the default.
     * 
     * @return string The authority part of the URL
     */
    public function getAuthority(): string
    {
        $authority = $this->getHost();

        if (!$this->isDefaultPort()) {
            $authority .= ':' . $this->getPort();
        }

        return $authority;
    }

    /**
     * Create a new instance from components
     * 
     * This method allows constructing a URL from its components.
     * It supports all standard URL components like scheme, host, port, path, query,
     * and fragment, and constructs a valid URL string.
     * 
     * @param array $components Associative array of URL components
     * @return self A new Url instance with the constructed URL
     * @throws \InvalidArgumentException If required components are missing
     */
    public static function fromComponents(array $components): self
    {
        $url = '';

        if (isset($components['scheme'])) {
            $url .= $components['scheme'] . '://';
        } else {
            // Default to https if no scheme provided but host exists
            if (isset($components['host'])) {
                $url .= 'https://';
            }
        }

        if (isset($components['host'])) {
            $url .= $components['host'];
        }

        if (isset($components['port']) && !in_array($components['port'], [80, 443])) {
            $url .= ':' . $components['port'];
        }

        $path = $components['path'] ?? '/';
        $url .= $path;

        if (isset($components['query'])) {
            $url .= '?' . ltrim($components['query'], '?');
        }

        if (isset($components['fragment'])) {
            $url .= '#' . ltrim($components['fragment'], '#');
        }

        // Ensure we have at least a minimal valid URL
        if (empty($url)) {
            throw new \InvalidArgumentException('Cannot create URL from empty components.');
        }

        return new self($url);
    }

    /**
     * Create URL from string with validation
     * 
     * @param string $url The URL string
     * @return self A new Url instance
     * @throws \InvalidArgumentException If the URL is invalid
     */
    public static function parse(string $url): self
    {
        return new self($url);
    }

    /**
     * Get the base URL (scheme + host + port)
     * 
     * @return string The base URL
     */
    public function getBaseUrl(): string
    {
        $base = $this->getScheme() . '://' . $this->getHost();

        $port = $this->getPort();
        if ($port && !in_array($port, [80, 443])) {
            $base .= ":$port";
        }

        return $base;
    }

    /**
     * Get the full URL without query and fragment
     * 
     * @return string The URL without query and fragment
     */
    public function getPathUrl(): string
    {
        return $this->getBaseUrl() . $this->getPath();
    }

    /**
     * Check if URL is relative
     * 
     * @return bool True if URL is relative, false otherwise
     */
    public function isRelative(): bool
    {
        return empty($this->components['scheme']) && empty($this->components['host']);
    }

    /**
     * Check if URL is absolute
     * 
     * @return bool True if URL is absolute, false otherwise
     */
    public function isAbsolute(): bool
    {
        return !$this->isRelative();
    }

    /**
     * Get a specific query parameter
     * 
     * @param string $key The parameter key
     * @param mixed $default The default value if parameter doesn't exist
     * @return mixed The parameter value or default
     */
    public function getQueryParam(string $key, mixed $default = null): mixed
    {
        $params = $this->getQueryParams();
        return $params[$key] ?? $default;
    }

    /**
     * Check if a query parameter exists
     * 
     * @param string $key The parameter key
     * @return bool True if parameter exists, false otherwise
     */
    public function hasQueryParam(string $key): bool
    {
        $params = $this->getQueryParams();
        return array_key_exists($key, $params);
    }

    /**
     * Get the domain (host without subdomain)
     * 
     * @return string|null The domain or null if cannot be determined
     */
    public function getDomain(): ?string
    {
        $host = $this->getHost();

        if (empty($host)) {
            return null;
        }

        // Simple domain extraction (last two parts)
        $parts = explode('.', $host);
        $count = count($parts);

        if ($count < 2) {
            return $host;
        }

        return $parts[$count - 2] . '.' . $parts[$count - 1];
    }

    /**
     * Get the subdomain
     * 
     * @return string|null The subdomain or null if none exists
     */
    public function getSubdomain(): ?string
    {
        $host = $this->getHost();

        if (empty($host)) {
            return null;
        }

        $parts = explode('.', $host);
        $count = count($parts);

        if ($count <= 2) {
            return null;
        }

        // Return everything except the last two parts
        return implode('.', array_slice($parts, 0, $count - 2));
    }
}
