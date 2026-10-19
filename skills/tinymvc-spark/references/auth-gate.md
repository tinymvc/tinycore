# Auth and Gate

Read this reference for authentication, guards, tokens, and authorization gates. Source paths and command working directories refer to the application root unless explicitly stated otherwise. Check the installed package when versions differ.

---

## Auth: Native Authentication Standard

The **`Spark\Facades\Auth`** facade provides full access to the active authentication guard and user session.

```php
use Spark\Facades\Auth;

// 1. Attempt login with credentials (supports Arrayable directly!)
$credentials = $request->validated()->only('email', 'password');
$remember = $request->boolean('remember');

if (Auth::attempt($credentials, remember: $remember)) {
    $user = Auth::user(); // Returns authenticated Model instance
}

// 2. Authentication State Checks
Auth::check();    // True if signed in
Auth::isGuest();  // True if unauthenticated
Auth::id();       // Primary key ID of authenticated user (int|string|null)
Auth::user();     // Authenticated User model instance (or null)

// 3. Manual Login, Login By ID & Logout
Auth::login($user, remember: true);
Auth::loginUsingId($user->id, remember: true);
Auth::setUser($user);
Auth::logout();
```

> [!TIP]
> **Arrayable Support in `Auth::attempt`**:
> Because `Spark\Http\Input` implements `Arrayable`, you can pass `$request->validated()` or `$request->validated()->only('email', 'password')` directly to `Auth::attempt()` without calling `->all()` or `->toArray()`.

> [!IMPORTANT]
> **Authentication & Gates in Blade Views**:
> In Blade templates, **never call `\Spark\Facades\Auth` or `\Spark\Facades\Gate`**. Use native Blade helpers and directives: `@auth`, `@guest`, `auth()->user()`, `user('email')`, `@can('ability', $model)`, and `@cannot`.

### Custom & Named Guards

In Spark 4.0, `config/auth.php` defines the default `guard`, shared `model`, and `guards` map. Named guards resolve lazily:

```php
// Switch to a named guard
$admin = Auth::guard('admin')->user();
$isAdminLoggedIn = Auth::guard('admin')->check();

// Register a custom guard programmatically in a ServiceProvider
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

### JWT Token Management

The API guard provides stateless or table-backed JWT token generation:

```php
// Issue a token for the current user
$token = Auth::createToken();

// Issue a token with custom claims
$token = Auth::createToken(payload: ['role' => 'editor']);

// Issue a token for a specific user
$token = Auth::createToken($user);

// Revoke current user's token (requires jwt_token_table configured)
Auth::revokeToken();
```

---

## Gate: Authorization Policies and Ability Checks

The **`Spark\Facades\Gate`** facade allows you to define abilities and verify permissions across the application.

### Defining Abilities

Define gates in a service provider or bootstrap file (e.g. `app/Providers/AppServiceProvider.php`):

```php
use Spark\Facades\Gate;
use Spark\Facades\Auth;
use App\Models\Post;

// Ability with argument
Gate::define('update-post', function (Post $post) {
    return Auth::check() && (int) $post->user_id === (int) Auth::id();
});

// Admin-only ability
Gate::define('manage-users', function () {
    return Auth::check() && Auth::user()?->role === 'admin';
});
```

### Checking Abilities

```php
use Spark\Facades\Gate;

// 1. Gate::check() / Gate::allows() - Returns boolean
if (Gate::check('update-post', $post)) {
    // User is authorized
}

if (Gate::allows('update-post', $post)) {
    // Equivalent to check()
}

// 2. Gate::denies() - Returns boolean
if (Gate::denies('update-post', $post)) {
    abort(403, 'Unauthorized.');
}

// 3. Gate::authorize() - Automatically throws Spark\Exceptions\Http\AuthorizationException (HTTP 403)
Gate::authorize('update-post', $post);
```

> [!NOTE]
> Gate callbacks receive only the arguments explicitly passed to `Gate::allows('ability', ...$args)`. Read `Auth::user()` inside the callback, or pass the user explicitly.
