# Middleware

> **Read when:** adding request middleware, PSR-15 integrations, CORS, CSRF, or throttling.
> **Related:** [Routing and controllers](routing-controllers.md) · [Auth and gate](auth-gate.md) · [Sessions](sessions.md) · [Cache and locks](cache-locks.md)
> Preserve middleware order and exclusions; aliases are application configuration.

Contents:

- [Native middleware and registration](#native-middleware-and-registration)
- [PSR-15 middleware](#psr-15-middleware)
- [CORS](#cors)
- [CSRF](#csrf)
- [Throttling and verification](#throttling-and-verification)

## Native middleware and registration

Implement `Spark\Contracts\Http\MiddlewareInterface`:

```php
namespace App\Http\Middlewares;

use Spark\Contracts\Http\MiddlewareInterface;
use Spark\Http\Request;
use Spark\Http\Response;

class EnsureAdmin implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): mixed
    {
        if (!auth()->check() || !auth()->user('is_admin')) {
            abort(Response::STATUS_FORBIDDEN, 'Forbidden');
        }

        return $next($request);
    }
}
```

Register aliases in the existing `bootstrap/middlewares.php` array (or `withMiddleware(register: [...])`), then attach through `Route`:

```php
Route::get('/admin', [AdminController::class, 'index'])->middleware(['auth', 'admin']);
Route::get('/search', [SearchController::class, 'index'])->middleware('throttle:100,1,search');
```

Parameters follow `:` and are comma-separated. `withoutMiddleware('csrf')` excludes an alias; use it only where another verified request-authentication mechanism is intended. Middleware may call `$next($request)`, decorate its response, and return it, or return early.

## PSR-15 middleware

Class names/instances implementing `Psr\Http\Server\MiddlewareInterface` use the same alias/queue APIs. Native and PSR middleware share ordering, exclusions, and short-circuit behavior.

PSR request changes reach downstream helpers, DI, and FormRequests. The outer native request is restored on return/throw. Routing has already matched: changing a URI does not reroute; route parameters remain separate from general attributes. Global middleware wraps fallbacks, 404s, and 405s. Return the response from `process()`; surrounding PSR middleware can decorate native early responses.

## CORS

Extend `Spark\Foundation\Http\Middlewares\CorsAccessControl` and pass configuration to its constructor:

```php
parent::__construct([
    'origin' => ['https://example.com', 'https://*.example.com'],
    'credentials' => true,
    'age' => 600,
    'methods' => [Request::METHOD_GET, Request::METHOD_POST, Request::METHOD_OPTIONS],
    'headers' => [Header::CONTENT_TYPE, Header::AUTHORIZATION, Header::X_XSRF_TOKEN],
]);
```

Import Request and Header from `Spark\Http`; allow only the methods/headers the client needs.

- Place CORS before middleware that can fail early. Once reached, allowed-origin headers cover normal responses, validation/errors, early send/abort, and redirects.
- Preflight requires OPTIONS, Origin, and Access-Control-Request-Method. Ordinary OPTIONS continues normally. Valid preflight returns 204 without the controller; requested HEAD can match GET.
- Invalid origin/method/header policies or malformed tokens return 403 for preflight. Normal disallowed-origin requests continue without CORS access headers.
- Wildcard origin patterns work; credentials reflect a concrete origin instead of `*`.
- Preserve existing Vary values; CORS adds Origin and preflight request method/headers. Preparation clears each request; excluded routes do not inherit previous CORS headers.

## CSRF

Extend `Spark\Foundation\Http\Middlewares\CsrfProtection`. A protected `$except` array defines explicit path patterns, such as `['webhook/*']`; authenticate those endpoints separately.

POST/PUT/PATCH/DELETE validate `_token`, then X-CSRF-TOKEN, then X-XSRF-TOKEN. Headers accept plain session tokens or encrypted cookie tokens, including percent-encoded values. An explicit CSRF header wins over stale XSRF. Invalid tokens map to 419. XSRF cookies use the request's trusted HTTPS scheme.

## Throttling and verification

Extend `Spark\Foundation\Http\Middlewares\ThrottleIncomingRequests`. Alias parameters are `attempts, minutes, suffix`.

The sliding window includes method, path, trusted client IP, suffix, and configured limit. It counts admitted requests even if downstream work fails. Cache read/update uses a short named lock released before downstream execution. Nodes need a shared cache/lock store for a shared limit; storage/lock failures propagate instead of admitting uncounted requests.

Verify middleware order, short-circuits, exclusions, CORS errors/preflight, CSRF cookie/header variants, trusted proxies, throttled responses, and state isolation across consecutive requests.
