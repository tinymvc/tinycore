# Auth and Gate

Read when: signing users in or out, using named guards, issuing JWTs, or authorizing actions with gates. Paths are relative to the application root; the installed package is authoritative when versions differ.

## Authentication

`Spark\Facades\Auth` fronts the active guard and user session.

```php
use Spark\Facades\Auth;

$credentials = $request->validated()->only('email', 'password');

if (Auth::attempt($credentials, remember: $request->boolean('remember'))) {
    $user = Auth::user();
}

Auth::check();    // true when signed in
Auth::isGuest();  // true when unauthenticated
Auth::id();       // int|string|null
Auth::user();     // User model or null

Auth::login($user, remember: true);
Auth::loginUsingId($user->id, remember: true);
Auth::setUser($user);
Auth::logout();
```

- `Spark\Http\Input` is `Arrayable`, so pass `$request->validated()` or `->only(...)` straight to `Auth::attempt()`; no `->all()` or `->toArray()` needed.
- In Blade, never call the `Auth` or `Gate` facades. Use `@auth`, `@guest`, `auth()->user()`, `user('email')`, `@can('ability', $model)`, and `@cannot`.
- Being signed in does not authorize an action. Check abilities and ownership separately (see [Gate](#gate)). Protect routes through [middleware](middleware.md).

## Named and custom guards

`config/auth.php` defines the default `guard`, the shared `model`, and a `guards` map. Named guards resolve lazily.

```php
$admin = Auth::guard('admin')->user();
$isAdminLoggedIn = Auth::guard('admin')->check();
```

Register a custom guard in a service provider (see [extensions](extensions-errors.md)):

```php
\Spark\Http\Auth::register(
    model: \App\Models\Admin::class,
    config: [
        'channels' => ['session'],
        'session_key' => 'admin_id',
        'cookie_name' => 'admin_auth',
        'cache_name' => 'admin_auth_cache',
        'login_route' => 'admin.login',
        'redirect_route' => 'admin.dashboard',
    ],
    guard: 'admin',
);
```

## JWT tokens

The API guard issues stateless or table-backed JWTs. Prefer these helpers for API authentication; they integrate with guards and token revocation.

```php
$token = Auth::createToken();                               // current user
$token = Auth::createToken(payload: ['role' => 'editor']);  // custom claims
$token = Auth::createToken($user);                          // specific user

Auth::revokeToken(); // current user's token; requires jwt_token_table
```

### Low-level utility (`Spark\Utils\JWT`)

Use only when the guard helpers do not fit, such as signing a one-off token for a non-user purpose.

```php
use Spark\Utils\JWT;

$jwt = JWT::encode([
    'sub' => $user->id,
    'role' => $user->role,
    'exp' => now()->addHours(2)->timestamp,
], key: config('app.key'), algo: 'HS256');

try {
    $decoded = JWT::decode($jwt, key: config('app.key'), verify: true);
    $userId = $decoded['sub'];
} catch (\Throwable $e) { // narrow to the decoder's exception type
    abort(\Spark\Http\Response::STATUS_UNAUTHORIZED, 'Invalid or expired token.');
}
```

Inspect `Utils/JWT.php` (see [API discovery](api-discovery.md)) to confirm that `decode()` enforces `exp` and pins the algorithm before relying on either. Keep secrets and sensitive data out of payloads.

## Gate

`Spark\Facades\Gate` defines abilities and checks them. Gate callbacks receive the *Current Authenticated User* automatically as the first parameter.

### Defining abilities

Define abilities in a service provider or bootstrap file such as `app/Providers/AppServiceProvider.php`.

```php
use App\Models\Post;
use Spark\Facades\Auth;
use Spark\Facades\Gate;

Gate::define('update-post', function (?User $user, Post $post): bool {
    return (string) $post->user_id === (string) $user?->id;
});

Gate::define('manage-users', fn (?User $user): bool => $user?->role === 'admin');
```

Guard against a guest before comparing ids; a null id must never match a null owner.

### Checking abilities

| Call | Result |
| --- | --- |
| `Gate::check($ability, ...$args)` / `Gate::allows(...)` | `bool` |
| `Gate::denies(...)` | `bool` |
| `Gate::authorize(...)` | Throws `Spark\Exceptions\Http\AuthorizationException` (HTTP 403) |

```php
use Spark\Facades\Gate;
use Spark\Http\Response;

Gate::authorize('update-post', $post);

if (Gate::denies('update-post', $post)) {
    abort(Response::STATUS_FORBIDDEN, 'Unauthorized.');
}
```

Use a FormRequest for request-level authorization ([requests and validation](requests-validation.md)). Route model binding never authorizes access; keep ownership/tenant conditions in queries too ([advanced workflows](advanced-workflows.md#authorization-scopes-and-query-composition)).