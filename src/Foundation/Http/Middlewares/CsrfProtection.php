<?php

namespace Spark\Foundation\Http\Middlewares;

use Spark\Contracts\Http\MiddlewareInterface;
use Spark\Foundation\Exceptions\InvalidCsrfTokenException;
use Spark\Hash;
use Spark\Http\Request;
use function is_string;

/**
 * Class CsrfProtection
 * 
 * CSRF protection middleware class. This class is responsible for
 * validating the CSRF token sent in the request. If the token is
 * invalid or missing, it returns a 403 Forbidden response.
 */
abstract class CsrfProtection implements MiddlewareInterface
{
    /**
     * An array of URI paths that should be excluded from CSRF verification.
     *
     * These paths will be matched against the request URI, and if a match is found,
     * the CSRF token validation will be skipped for that request.
     *
     * @var array
     */
    protected array $except = [];

    /**
     * CSRF protection middleware.
     *
     * This middleware validates the CSRF token sent in the request. If the token is
     * invalid or missing, it returns a 403 Forbidden response.
     *
     * @param Request $request The current request.
     *
     * @return mixed The response when the token is invalid, current request otherwise.
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        if ($this->skip($request)) {
            return $next($request);
        }

        // Ensure token exists on safe requests where it can be refreshed.
        $this->checkCsrfToken();

        // Validate CSRF token on unsafe requests.
        if ($request->isPostBack()) {
            // Retrieve the CSRF token from the POST data
            $token = $request->post('_token');
            $token ??= $this->getXsrfToken($request);

            // Validate the CSRF token against the cookie token
            if (empty($token) || !hash_equals(session('csrf_token', ''), $token)) {
                // Return a 403 Forbidden response if the token is invalid
                throw new InvalidCsrfTokenException('Invalid CSRF token');
            }
        }

        return $next($request); // Proceed to the next middleware or request handler
    }

    /**
     * Retrieves the CSRF token from the request headers.
     * 
     * If the CSRF token is present in the request headers,
     * it decrypts the token using the Hash facade and returns
     * the decrypted token.
     * 
     * @param Request $request The current request.
     * 
     * @return string|null The CSRF token if it exists, null otherwise.
     */
    protected function getXsrfToken(Request $request): ?string
    {
        $rawToken = null;

        // Legacy header key used by frameworks and JS clients.
        if (($candidate = $request->header('X-XSRF-TOKEN')) !== null) {
            $rawToken = $candidate;
        } elseif (($candidate = $request->header('X-CSRF-TOKEN')) !== null) {
            // Alternative header name commonly used by Axios/Fetch.
            $rawToken = $candidate;
        }

        if ($rawToken === null) {
            return null;
        }

        // Retrieve the CSRF token from the request headers

        $token = $rawToken;

        // If client sends plain token, use as-is.
        if (!is_string($token) || $token === '') {
            return null;
        }

        $sessionToken = session('csrf_token', '');
        if (hash_equals($sessionToken, $token)) {
            return $token;
        }

        // Decrypt the token using the Hash facade
        try {
            $decryptedToken = get(Hash::class)->decrypt($token);
        } catch (\Throwable $e) {
            return null;
        }

        // Return the decrypted token if it exists
        return $decryptedToken;
    }

    /**
     * Determine if the request has a URI that should pass through CSRF verification.
     *
     * @param Request $request
     *
     * @return bool
     */
    protected function skip(Request $request): bool
    {
        // If the except property is empty, return false
        if (empty($this->except)) {
            return false;
        }

        $path = trim($request->getPath(), '/');

        // Iterate over the except array
        foreach ($this->except as $url) {
            if (!is_string($url)) {
                continue;
            }

            $url = trim($url, '/');

            if ($url === '') {
                if ($path === '') {
                    return true;
                }

                continue;
            }

            // If the URL ends with a wildcard, check if the request path starts with the URL
            if (str_contains($url, '*')) {
                $pattern = '#^' . str_replace('\*', '.*', preg_quote($url, '#')) . '$#';
                $skip = (bool) preg_match($pattern, $path);
            } else {
                $skip = $url === $path || $url === '/' && $path === '';
            }

            // If the request path matches the URL, return true
            if ($skip) {
                return true;
            }
        }

        // If no matching URL is found, return false
        return false;
    }

    /**
     * Ensure a CSRF token is present in cookies.
     *
     * This method checks if a CSRF token is already set in the browser cookies.
     * If not, it generates a new token and sets it as a cookie. The token is
     * a 64-character hexadecimal string generated from random bytes. The cookie
     * is configured with session-scope options, HTTPS-only transmission when
     * detected, a session-based lifetime, and Lax same-site policy.
     * 
     * @return void
     */
    protected function checkCsrfToken(): void
    {
        if (empty(session('csrf_token'))) {
            /** @var \Spark\Hash $hash The Hash instance */
            $hash = app(Hash::class);
            $token = $hash->random(32);
            $encrypted = $hash->encrypt($token);

            // Set the CSRF token as a cookie
            cookie(
                'XSRF-TOKEN',
                $encrypted,
                ['path' => '/', 'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => false, 'samesite' => 'Lax', 'expires' => 0]
            );

            // Store the token in the session
            session(['csrf_token' => $token]);
        }
    }
}
