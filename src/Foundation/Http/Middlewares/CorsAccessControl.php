<?php

namespace Spark\Foundation\Http\Middlewares;

use Closure;
use Spark\Contracts\Http\MiddlewareInterface;
use Spark\Http\Request;

/**
 * CORS (Cross-Origin Resource Sharing) middleware class.
 *
 * This class is responsible for validating the CORS request headers and
 * setting the appropriate response headers.
 *
 * @package Middlewares
 */
class CorsAccessControl implements MiddlewareInterface
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
    public function handle(Request $request, Closure $next): mixed
    {
        // Retrieve the origin from the request headers
        $origin = $request->header('origin', null);

        // If an origin is present, proceed with CORS header setup
        if ($origin !== null) {
            $allowedOrigins = $this->config['origin'];

            // Allow any origin if wildcard '*' is specified
            if ($allowedOrigins === '*') {
                header("Access-Control-Allow-Origin: *");
            }
            // Allow specific origins if they are listed
            elseif (is_array($allowedOrigins) && in_array($origin, $allowedOrigins)) {
                header("Access-Control-Allow-Origin: $origin");
            }

            // Set Access-Control-Allow-Credentials header
            header('Access-Control-Allow-Credentials: ' . ($this->config['credentials'] ?? 'false'));
            // Set Access-Control-Max-Age header
            header('Access-Control-Max-Age: ' . ($this->config['age'] ?? '0'));

            // Handle preflight requests with OPTIONS method
            if ($request->isMethod('options')) {
                header('Access-Control-Allow-Methods: ' . implode(', ', $this->config['methods']));
                header('Access-Control-Allow-Headers: ' . implode(', ', $this->config['headers']));

                return 204; // Return 204 No Content for preflight requests
            }
        }

        return $next($request); // Proceed to the next middleware or request handler
    }
}
