<?php

namespace Spark\Foundation\Http\Middlewares;

use Spark\Contracts\Http\MiddlewareInterface;
use Spark\Foundation\Application;
use Spark\Http\Response;
use Spark\Http\Request;
use function in_array;
use function is_array;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function implode;
use function is_bool;
use function is_int;
use function is_string;
use function preg_match;
use function strcasecmp;
use function str_contains;
use function strtolower;
use function trim;

/**
 * CORS (Cross-Origin Resource Sharing) middleware class.
 *
 * This class is responsible for validating the CORS request headers and
 * setting the appropriate response headers.
 *
 * @package Middlewares
 */
abstract class CorsAccessControl implements MiddlewareInterface
{
    /**
     * Constructor for the CORS middleware.
     *
     * @param null|array $config Optional configuration array for CORS settings.
     */
    public function __construct(protected null|array $config = null)
    {
        $this->config ??= config('cors', []);
    }

    /**
     * Handle CORS requests by setting appropriate headers.
     *
     * This method sets the Access-Control-Allow-Origin, Access-Control-Allow-Credentials,
     * Access-Control-Max-Age, Access-Control-Allow-Methods, and Access-Control-Allow-Headers
     * headers based on the request and allowed settings.
     *
     * @param Request $request The current HTTP request.
     * @return mixed
     *   The response when the request is a preflight request, or the current request otherwise
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        if (!$this->shouldHandlePath($request)) {
            return $next($request);
        }

        $origin = $request->header('Origin');
        $allowedOrigin = $origin === null ? null : $this->determineAllowedOrigin($origin);
        $config = $this->normalizeConfig();

        // Detect by header presence, then validate their values. Malformed preflight
        // attempts must not fall through to an explicit OPTIONS route callback.
        if ($this->isPreflightRequest($request)) {
            $requestedMethod = strtoupper(trim($request->header('Access-Control-Request-Method', ''), " \t"));
            $requestedHeaders = $this->requestedHeaders($request);
            $vary = ['Origin', 'Access-Control-Request-Method', 'Access-Control-Request-Headers'];

            if (
                $allowedOrigin === null
                || !$this->allowsMethod($requestedMethod, $config['methods'])
                || !$this->allowsHeaders($requestedHeaders, $config['headers'])
            ) {
                return $this->withVary(response('', 403), $vary);
            }

            return $this->withVary($this->withCorsHeaders(
                response('', 204),
                $allowedOrigin,
                $this->allowedMethods($requestedMethod, $config['methods']),
                $this->allowedHeaders($requestedHeaders, $config['headers']),
                $config['credentials'],
                $config['age'],
                true
            ), $vary);
        }

        // Validation and other exceptions may be rendered outside this middleware.
        // Early send()/abort() calls also need headers before anything is emitted.
        $decorate = function (Response $response) use ($allowedOrigin, $config): Response {
            $this->withVary($response, ['Origin']);

            return $allowedOrigin === null ? $response : $this->withCorsHeaders(
                $response,
                $allowedOrigin,
                $config['methods'],
                $config['headers'],
                $config['credentials'],
                $config['age']
            );
        };
        Application::$app?->prepareResponseUsing($decorate);

        $response = $next($request);

        if ($response === null) {
            return null;
        }

        if (!$response instanceof Response) {
            $response = is_int($response)
                ? new Response('', $response)
                : response($response);
        }

        return $decorate($response);
    }

    /**
     * Normalize middleware configuration.
     *
     * @return array<string, mixed>
     */
    private function normalizeConfig(): array
    {
        $origin = $this->config['origin'] ?? '*';
        $credentials = $this->config['credentials'] ?? false;
        $maxAge = (int) ($this->config['age'] ?? 0);
        $methods = $this->normalizeList($this->config['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $headers = $this->normalizeList($this->config['headers'] ?? ['Content-Type', 'X-Requested-With', 'Authorization', 'X-CSRF-TOKEN', 'X-XSRF-TOKEN']);

        return [
            'origin' => $origin,
            'credentials' => is_bool($credentials) ? $credentials : filter_var((string) $credentials, FILTER_VALIDATE_BOOLEAN),
            'age' => $maxAge,
            'methods' => array_values(array_filter(array_map('strtoupper', $methods))),
            'headers' => $this->normalizeHeaderList($headers),
        ];
    }

    /**
     * Determine if CORS should be applied to the current request path.
     *
     * When no paths are configured, CORS applies to every path.
     * Otherwise the path must match one of the configured patterns
     * (wildcards supported, e.g. "api/*"), using CsrfProtection::skip().
     */
    private function shouldHandlePath(Request $request): bool
    {
        $paths = (array) ($this->config['paths'] ?? []);

        if ($paths === []) {
            return true;
        }

        return CsrfProtection::skip($request, $paths);
    }

    /**
     * Apply CORS headers onto the response.
     */
    private function withCorsHeaders(
        Response $response,
        string $allowedOrigin,
        array $methods,
        array $headers,
        bool $allowCredentials,
        int $maxAge,
        bool $isPreflight = false
    ): Response {
        $response
            ->setHeader('Access-Control-Allow-Origin', $allowedOrigin);

        if ($allowCredentials) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($isPreflight) {
            $response->setHeader('Access-Control-Max-Age', (string) max(0, $maxAge));
        }

        if ($isPreflight && $methods !== []) {
            $response->setHeader('Access-Control-Allow-Methods', implode(', ', $methods));
        }

        if ($isPreflight && $headers !== []) {
            $response->setHeader('Access-Control-Allow-Headers', implode(', ', $headers));
        }

        return $response;
    }

    /**
     * Merge cache variation without losing existing response header values.
     */
    private function withVary(Response $response, array $headers): Response
    {
        $keys = [];
        $values = [];
        foreach ($response->getHeaders() as $key => $value) {
            if (strcasecmp($key, 'Vary') === 0) {
                $keys[] = $key;
                $values = [...$values, ...$this->normalizeList($value)];
            }
        }

        $vary = [];
        foreach ([...$values, ...$headers] as $value) {
            $vary[strtolower($value)] = $value;
        }
        $value = isset($vary['*']) ? '*' : implode(', ', array_values($vary));
        foreach ($keys ?: ['Vary'] as $key) {
            $response->setHeader($key, $value);
        }

        return $response;
    }

    /**
     * Validate an HTTP method or field name before reflecting it in headers.
     */
    private function isHttpToken(string $value): bool
    {
        return preg_match('/\A[!#$%&\'*+.^_`|~0-9a-zA-Z-]+\z/', $value) === 1;
    }

    /**
     * Determine if the request is a CORS preflight request.
     */
    private function isPreflightRequest(Request $request): bool
    {
        return strtoupper($request->getMethod()) === 'OPTIONS'
            && $request->header('Origin') !== null
            && $request->header('Access-Control-Request-Method') !== null;
    }

    /**
     * Check if the requested method is allowed by the CORS policy.
     */
    private function allowsMethod(string $requestedMethod, array $methods): bool
    {
        return $this->isHttpToken($requestedMethod)
            && (in_array('*', $methods, true) || in_array($requestedMethod, $methods, true));
    }

    /**
     * Extract requested preflight headers.
     *
     * @return array<int, string>
     */
    private function requestedHeaders(Request $request): array
    {
        $headers = $request->header('Access-Control-Request-Headers', '');

        // HTTP list syntax permits empty list members, but not invalid tokens.
        return array_values(array_filter(
            array_map(fn($header) => trim($header, " \t"), explode(',', $headers)),
            fn($header) => $header !== ''
        ));
    }

    /**
     * Check if requested preflight headers are allowed by the CORS policy.
     */
    private function allowsHeaders(array $requestedHeaders, array $headers): bool
    {
        $allowed = array_map('strtolower', $headers);

        foreach ($requestedHeaders as $header) {
            if (
                !$this->isHttpToken($header)
                || (!in_array('*', $headers, true) && !in_array(strtolower($header), $allowed, true))
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve methods to send in the preflight response.
     */
    private function allowedMethods(string $requestedMethod, array $methods): array
    {
        return in_array('*', $methods, true) ? [$requestedMethod] : $methods;
    }

    /**
     * Resolve headers to send in the preflight response.
     */
    private function allowedHeaders(array $requestedHeaders, array $headers): array
    {
        return in_array('*', $headers, true) ? $requestedHeaders : $headers;
    }

    /**
     * Resolve requested origin into the concrete Allow-Origin value.
     */
    private function determineAllowedOrigin(string $origin): ?string
    {
        // A serialized origin is a single value; do not reflect empty values,
        // whitespace, control characters, or comma-separated origin lists.
        $origin = trim($origin, " \t");
        if ($origin === '' || preg_match('/[\x00-\x20\x7f,]/', $origin)) {
            return null;
        }

        $config = $this->normalizeConfig();
        $origins = $this->normalizeList($config['origin']);

        if (in_array('*', $origins, true)) {
            return $config['credentials'] ? $origin : '*';
        }

        foreach ($origins as $candidate) {
            if (
                strcasecmp($candidate, $origin) === 0
                || (str_contains($candidate, '*') && $this->matchOriginPattern($candidate, $origin))
            ) {
                return $origin;
            }
        }

        return null;
    }

    /**
     * Check wildcard origin pattern support.
     */
    private function matchOriginPattern(string $pattern, string $origin): bool
    {
        if (strtolower((string) $pattern) === '*') {
            return true;
        }

        $pattern = str_replace('\*', '.*', preg_quote(trim((string) $pattern), '/'));
        return (bool) preg_match("/\\A$pattern\\z/i", $origin);
    }

    /**
     * Normalize list-like config values.
     */
    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $value = array_filter($value, fn($item) => is_string($item) || is_int($item));

        return array_values(array_unique(array_filter(
            array_map(fn($item) => trim((string) $item), $value),
            fn($item) => $item !== ''
        )));
    }

    /**
     * Normalize header names as expected by Access-Control-Allow-Headers.
     */
    private function normalizeHeaderList(array $headers): array
    {
        $parsed = array_map(fn($header) => preg_replace('/\s+/', ' ', trim((string) $header)), array_map('strval', $headers));

        return array_values(array_unique(array_filter($parsed)));
    }
}
