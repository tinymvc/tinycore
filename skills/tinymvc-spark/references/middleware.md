# Middleware

Contents:

- [Middleware](#middleware)
- [Built-In Middleware Base Classes](#built-in-middleware-base-classes)

Read this reference for middleware, built-in middleware base classes. Source paths and command working directories refer to the application root unless explicitly stated otherwise. Check the installed package when versions differ.

## Middleware

Middleware implements `Spark\Contracts\Http\MiddlewareInterface`.

```php
<?php

namespace App\Http\Middlewares;

use Spark\Contracts\Http\MiddlewareInterface;
use Spark\Http\Request;

class EnsureAdmin implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): mixed
    {
        if (!auth()->check() || !auth()->user('is_admin')) {
            abort(\Spark\Http\Response::STATUS_FORBIDDEN, 'Forbidden');
        }

        return $next($request);
    }
}
```

Register aliases in `bootstrap/middlewares.php`:

```php
<?php

return [
    'auth' => App\Http\Middlewares\Authenticate::class,
    'admin' => App\Http\Middlewares\EnsureAdmin::class,
    'csrf' => App\Http\Middlewares\VerifyCsrfToken::class,
    'cors' => App\Http\Middlewares\Cors::class,
    'throttle' => App\Http\Middlewares\ThrottleRequests::class,
];
```

Attach middleware:

```php
Route::get('/admin', [AdminController::class, 'index'])->middleware(['auth', 'admin']);
Route::post('/webhook', [WebhookController::class, 'store'])->withoutMiddleware('csrf');
Route::get('/limited', fn () => 'ok')->middleware('throttle:60,1,api');
```

Middleware parameters are parsed after `:`, comma-separated.

Middleware can wrap responses:

```php
public function handle(Request $request, \Closure $next): mixed
{
    $response = $next($request);

    if ($response instanceof \Spark\Http\Response) {
        $response->setHeader('X-App', 'TinyMVC');
    }

    return $response;
}
```

## PSR-15 middleware

Register a class name or instance implementing `Psr\Http\Server\MiddlewareInterface` with the same alias/queue APIs as native middleware:

```php
app()->withMiddleware(register: [
    'external' => Vendor\Package\Middleware::class,
]);
Route::get('/documents/{id}', $controller)->middleware('external');
```

Native and PSR middleware share ordering, exclusions, and short-circuit behavior. PSR request changes reach downstream native helpers, dependency injection, and FormRequests. The outer native request is restored when the continuation returns or throws. Routing has already matched: changing a URI inside route middleware does not reroute, and matched route parameters remain separate from general attributes.

Global middleware also wraps fallbacks, 404s, and 405s. Matched routes retain their route parameters before middleware runs. Return the response from `process()`; native early responses can also be decorated by surrounding PSR middleware.

## Built-In Middleware Base Classes

### CORS

Extend `Spark\Foundation\Http\Middlewares\CorsAccessControl`.

```php
<?php

namespace App\Http\Middlewares;

use Spark\Foundation\Http\Middlewares\CorsAccessControl;
use Spark\Http\Header;

class Cors extends CorsAccessControl
{
    public function __construct()
    {
        parent::__construct([
            'origin' => ['https://example.com', 'https://*.example.com'],
            'credentials' => true,
            'age' => 600,
            'methods' => [
                \Spark\Http\Request::METHOD_GET,
                \Spark\Http\Request::METHOD_HEAD,
                \Spark\Http\Request::METHOD_POST,
                \Spark\Http\Request::METHOD_PUT,
                \Spark\Http\Request::METHOD_PATCH,
                \Spark\Http\Request::METHOD_DELETE,
                \Spark\Http\Request::METHOD_OPTIONS,
            ],
            'headers' => [Header::CONTENT_TYPE, Header::AUTHORIZATION, Header::X_XSRF_TOKEN],
        ]);
    }
}
```

Behavior:

- Allowed origins receive CORS headers on normal responses, rendered validation/errors, early `send()` / `abort()` responses, and redirects once CORS middleware has run. Register it before middleware that can fail early.
- Preflight requires OPTIONS plus both Origin and Access-Control-Request-Method headers. Ordinary OPTIONS requests continue normally. Valid preflight returns `204` without invoking the controller; requested HEAD matches GET routes.
- Rejected preflight origins, methods, headers, and malformed method/header tokens return `403` when CORS middleware is reached. Normal disallowed-origin requests continue without access headers.
- Wildcard origins such as `https://*.example.com` are supported.
- `credentials => true` reflects concrete origins instead of using `*`.
- Existing `Vary` values are preserved; CORS adds Origin, plus requested method/headers for preflight. Response preparation is cleared per request, and excluded paths/routes do not inherit prior CORS headers.

### CSRF

Extend `Spark\Foundation\Http\Middlewares\CsrfProtection`.

```php
<?php

namespace App\Http\Middlewares;

use Spark\Foundation\Http\Middlewares\CsrfProtection;

class VerifyCsrfToken extends CsrfProtection
{
    protected array $except = [
        'webhook/*',
    ];
}
```

CSRF validates `POST`, `PUT`, `PATCH`, and `DELETE`. It accepts `_token`, then `X-CSRF-TOKEN`, then `X-XSRF-TOKEN`. Headers accept plain session tokens or encrypted cookie tokens, including percent-encoded cookie values. An explicit CSRF header takes precedence over a stale XSRF header. Invalid tokens throw an exception mapped to HTTP 419. The XSRF cookie uses the request's HTTPS scheme, including configured trusted proxies.

### Throttle

Extend `Spark\Foundation\Http\Middlewares\ThrottleIncomingRequests`.

```php
<?php

namespace App\Http\Middlewares;

use Spark\Foundation\Http\Middlewares\ThrottleIncomingRequests;

class ThrottleRequests extends ThrottleIncomingRequests
{
}
```

Use as:

```php
Route::get('/api/search', [SearchController::class, 'index'])
    ->middleware('throttle:100,1,search');
```

Parameter order is `attempts, minutes, suffix`.

The sliding window is scoped by HTTP method, path, trusted client IP, suffix, and configured limit. It counts admitted requests even if the controller later fails. Cache reads and updates run under a short named lock, released before downstream middleware or controllers run. All application nodes must share the configured cache/lock store for a shared limit. A storage/lock failure propagates instead of admitting an uncounted request.
