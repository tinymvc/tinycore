<?php

namespace Spark\Foundation\Http\Middlewares;

use Spark\Http\Request;

/**
 * CORS (Cross-Origin Resource Sharing) middleware class.
 *
 * This class is responsible for validating the CORS request headers and
 * setting the appropriate response headers.
 *
 * @package Middlewares
 */
class CorsAccessControl
{
    /**
     * CORS settings.
     *
     * @var array
     */
    protected array $allowed = [];

    /**
     * Handle CORS requests by setting appropriate headers.
     *
     * This method sets the Access-Control-Allow-Origin, Access-Control-Allow-Credentials,
     * Access-Control-Max-Age, Access-Control-Allow-Methods, and Access-Control-Allow-Headers
     * headers based on the request and allowed settings.
     *
     * @param Request $request The current HTTP request.
     * @return void
     */
    public function handle(Request $request): void
    {
        // Retrieve the origin from the request headers
        $origin = $request->header('origin', null);

        // If an origin is present, proceed with CORS header setup
        if ($origin !== null) {
            $allowedOrigins = $this->allowed['origin'];

            // Allow any origin if wildcard '*' is specified
            if ($allowedOrigins === '*') {
                header("Access-Control-Allow-Origin: *");
            }
            // Allow specific origins if they are listed
            elseif (is_array($allowedOrigins) && in_array($origin, $allowedOrigins)) {
                header("Access-Control-Allow-Origin: $origin");
            }

            // Set Access-Control-Allow-Credentials header
            header('Access-Control-Allow-Credentials: ' . ($this->allowed['credentials'] ?? 'false'));
            // Set Access-Control-Max-Age header
            header('Access-Control-Max-Age: ' . ($this->allowed['age'] ?? '0'));

            // Handle preflight requests with OPTIONS method
            if ($request->isMethod('options')) {
                header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowed['methods']));
                header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowed['headers']));
                exit(0); // Exit to prevent further processing of the request
            }
        }
    }
}
