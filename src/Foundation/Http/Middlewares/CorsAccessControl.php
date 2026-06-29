<?php

namespace Spark\Foundation\Http\Middlewares;

use Spark\Contracts\Http\MiddlewareInterface;
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
     * CORS settings.
     *
     * @var array
     */
    protected array $config = [];

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
        // Retrieve the origin from the request headers
        $origin = $request->header('origin', null);

        // If an origin is present, proceed with CORS header setup
        if ($origin !== null) {
            $allowedOrigin = $this->determineAllowedOrigin($origin);

            // If origin is not allowed, do nothing and continue.
            if ($allowedOrigin === null) {
                return $next($request);
            }

            $config = $this->normalizeConfig();

            $methods = $config['methods'];
            $headers = $config['headers'];
            $allowCredentials = $config['credentials'];

            // Handle preflight requests with OPTIONS method.
            if ($request->isMethod('options')) {
                $preflightResponse = response('', 204);
                return $this->withCorsHeaders($preflightResponse, $allowedOrigin, $methods, $headers, $allowCredentials, $config['age'], true);
            }

            $response = $next($request);

            if (!$response instanceof Response) {
                $response = is_int($response)
                    ? new Response('', $response)
                    : response($response);
            }

            return $this->withCorsHeaders($response, $allowedOrigin, $methods, $headers, $allowCredentials, $config['age']);
        }

        return $next($request); // Proceed to the next middleware or request handler
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
            ->setHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->setHeader('Access-Control-Allow-Credentials', $allowCredentials ? 'true' : 'false')
            ->setHeader('Access-Control-Max-Age', (string) max(0, $maxAge));

        if ($allowedOrigin !== '*') {
            $response->setHeader('Vary', 'Origin');
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
     * Resolve requested origin into the concrete Allow-Origin value.
     */
    private function determineAllowedOrigin(string $origin): ?string
    {
        $config = $this->normalizeConfig();
        $allowedOrigin = $config['origin'];
        $credentials = $config['credentials'];

        if ($allowedOrigin === '*') {
            return $credentials ? $origin : '*';
        }

        if (is_array($allowedOrigin)) {
            if (in_array('*', $allowedOrigin, true) || in_array('*', array_map('strtolower', $allowedOrigin), true)) {
                return $credentials ? $origin : '*';
            }

            foreach ($allowedOrigin as $allowed) {
                if (!is_string($allowed)) {
                    continue;
                }

                $candidate = trim($allowed);
                if ($candidate === '') {
                    continue;
                }

                if (strcasecmp($candidate, $origin) === 0 || (str_contains($candidate, '*') && $this->matchOriginPattern($candidate, $origin))) {
                    return $origin;
                }
            }

            return null;
        }

        if (is_string($allowedOrigin)) {
            if (str_contains($allowedOrigin, ',')) {
                foreach ($this->normalizeList(explode(',', $allowedOrigin)) as $candidate) {
                    if (str_contains((string) $candidate, '*') && $this->matchOriginPattern((string) $candidate, $origin)) {
                        return $origin;
                    }

                    if (strcasecmp((string) $candidate, $origin) === 0) {
                        return $origin;
                    }
                }
            } elseif (strcasecmp($allowedOrigin, '*') === 0) {
                return $credentials ? $origin : '*';
            } elseif (strcasecmp($allowedOrigin, $origin) === 0) {
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

        $pattern = str_replace('*', '.*', preg_quote(trim((string) $pattern), '/'));
        return (bool) preg_match("/^$pattern\$/i", $origin);
    }

    /**
     * Normalize list-like config values.
     */
    private function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_unique(array_filter(array_map('trim', array_map('strval', $value)))));
        }

        if (!is_string($value)) {
            return [];
        }

        return array_values(
            array_unique(array_filter(array_map('trim', explode(',', $value))))
        );
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
