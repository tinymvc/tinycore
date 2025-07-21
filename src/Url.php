<?php

namespace Spark;

use ArrayAccess;
use JsonSerializable;
use Stringable;

/**
 * Enhanced URL object implementation with optimized ArrayAccess and magic methods
 * 
 * This class provides a flexible interface for accessing and manipulating URL data
 * through multiple access patterns while maintaining performance and modern PHP standards.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Url implements ArrayAccess, JsonSerializable, Stringable
{
    private array $components = [];
    private array $parameters = [];
    private string $absoluteUrl;

    public function __construct(string $absoluteUrl, array $parameters = [])
    {
        $this->absoluteUrl = $absoluteUrl;
        $this->components = parse_url($absoluteUrl) ?: [];
        $this->parameters = $parameters;
    }

    /**
     * Build the parsed path including query and fragment
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
            default => $this->parameters[$name] ?? $name
        };
    }

    /**
     * Check if property exists
     */
    public function __isset(string $name): bool
    {
        $urlProperties = ['absoluteUrl', 'scheme', 'host', 'port', 'path', 'query', 'fragment', 'parsedPath', 'parameters'];
        return in_array($name, $urlProperties) || isset($this->parameters[$name]);
    }

    /**
     * Set property value
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
     */
    public function __unset(string $name): void
    {
        unset($this->parameters[$name]);
    }

    /**
     * ArrayAccess: Check if offset exists
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset($offset);
    }

    /**
     * ArrayAccess: Get value by offset
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get($offset);
    }

    /**
     * ArrayAccess: Set value at offset
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
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->__unset($offset);
    }

    /**
     * Merge additional parameters into the existing ones
     */
    public function mergeParameters(array $params): self
    {
        $new = clone $this;
        $new->parameters = array_merge($this->parameters, $params);
        return $new;
    }

    /**
     * Convert URL object to string representation
     */
    public function __toString(): string
    {
        return $this->getUrl();
    }

    /**
     * Get the scheme (protocol)
     */
    public function getScheme(): string
    {
        return $this->components['scheme'] ?? 'https';
    }

    /**
     * Get the host/domain
     */
    public function getHost(): string
    {
        return $this->components['host'] ?? '';
    }

    /**
     * Get the port number
     */
    public function getPort(): ?int
    {
        return $this->components['port'] ?? null;
    }

    /**
     * Get the path component (without query or fragment)
     */
    public function getPath(): string
    {
        return $this->components['path'] ?? '/';
    }

    /**
     * Get the query string (without the ?)
     */
    public function getQuery(): string
    {
        return $this->components['query'] ?? '';
    }

    /**
     * Get query parameters as associative array
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
     */
    public function getFragment(): string
    {
        return $this->components['fragment'] ?? '';
    }

    /**
     * Get the absolute URL
     */
    public function getUrl(): string
    {
        return $this->absoluteUrl;
    }

    /**
     * Build absolute URL from components
     */
    private function buildAbsoluteUrl(): string
    {
        $url = $this->getScheme() . '://' . $this->getHost();

        $port = $this->getPort();
        if ($port && !in_array($port, [80, 443])) {
            $url .= ':' . $port;
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
     */
    private function updateUrl(): void
    {
        $this->absoluteUrl = $this->buildAbsoluteUrl();
    }

    /**
     * Add or modify query parameters
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
     */
    public function withQueryParam(string $key, mixed $value): self
    {
        return $this->withQuery([$key => $value], true);
    }

    /**
     * Remove query parameters
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
     */
    public function withoutFragment(): self
    {
        return $this->withFragment('');
    }

    /**
     * Change the path component
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
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get a specific route parameter
     */
    public function getParameter(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }

    /**
     * Check if route has specific parameter
     */
    public function hasParameter(string $key): bool
    {
        return isset($this->parameters[$key]);
    }

    /**
     * Add route parameters
     */
    public function withParameters(array $parameters): self
    {
        $new = clone $this;
        $new->parameters = array_merge($this->parameters, $parameters);

        return $new;
    }

    /**
     * Convert route data to array
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
     * JsonSerializable implementation
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Generate cache key for this route
     */
    public function getCacheKey(string $suffix = ''): string
    {
        $key = 'route:' . md5($this->getUrl());
        return $suffix ? "$key:$suffix" : $key;
    }

    /**
     * Check if the given path matches this route exactly
     */
    public function is(string $path): bool
    {
        return trim($this->getPath(), '/') === trim($path, '/');
    }

    /**
     * Check if the given path matches this route (supports wildcards)
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
        if ($normalizedPattern !== '' && str_starts_with($routePath . '/', $normalizedPattern . '/')) {
            return true;
        }

        return false;
    }

    /**
     * Check if route matches any of the provided patterns
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

        return '/^' . $pattern . '$/i';
    }

    /**
     * Check if URL is secure (HTTPS)
     */
    public function isSecure(): bool
    {
        return strtolower($this->getScheme()) === 'https';
    }

    /**
     * Check if URL is on default port
     */
    public function isDefaultPort(): bool
    {
        $port = $this->getPort();
        $scheme = $this->getScheme();

        return $port === null ||
            ($scheme === 'http' && $port === 80) ||
            ($scheme === 'https' && $port === 443);
    }

    /**
     * Get the authority part (host:port)
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
     */
    public static function fromComponents(array $components): self
    {
        $url = '';

        if (isset($components['scheme'])) {
            $url .= $components['scheme'] . '://';
        }

        if (isset($components['host'])) {
            $url .= $components['host'];
        }

        if (isset($components['port']) && !in_array($components['port'], [80, 443])) {
            $url .= ':' . $components['port'];
        }

        if (isset($components['path'])) {
            $url .= $components['path'];
        }

        if (isset($components['query'])) {
            $url .= '?' . $components['query'];
        }

        if (isset($components['fragment'])) {
            $url .= '#' . $components['fragment'];
        }

        return new self($url ?: '/');
    }
}