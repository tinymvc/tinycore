# Global Helpers, Facades, and Modern Support Primitives

Read this reference for global helpers, static facades, and utility functions in TinyMVC / TinyCore (Spark). Source paths and command working directories refer to the application root.

---

## Static Facades (`Spark\Facades\*`)

TinyCore provides first-class static facades under `Spark\Facades\*`. They provide clean, testable, static access to underlying services in the application container:

| Facade | Service / Class | Primary Use Case |
| --- | --- | --- |
| `Spark\Facades\App` | `Spark\Foundation\Application` | Service container binding, resolving, environment checks (`App::isProduction()`, `App::isTesting()`, `App::isDebugMode()`). |
| `Spark\Facades\Auth` | `Spark\Http\Auth` | User authentication, guards, credentials verification, tokens (`Auth::attempt()`, `Auth::user()`, `Auth::id()`, `Auth::loginUsingId()`, `Auth::createToken()`, `Auth::revokeToken()`). |
| `Spark\Facades\Blade` | `Spark\View\Blade` | Template compilation, directive registration (`Blade::directive()`), component rendering, variable sharing (`Blade::share()`). |
| `Spark\Facades\Cache` | `Spark\Cache\Cache` | Storing, retrieving, atomic operations, and cache invalidation (`Cache::remember()`, `Cache::get()`, `Cache::put()`, `Cache::forget()`, `Cache::erase()`, `Cache::flush()`, `Cache::make('store')`). |
| `Spark\Facades\DB` | `Spark\Database\DB` | Database transactions, connection switching, raw queries, table queries (`DB::transaction()`, `DB::table()`, `DB::raw()`, row locking `lockForUpdate()`). |
| `Spark\Facades\Event` | `Spark\Events` | Event dispatching and listener registration (`Event::listen()`, `Event::dispatch()`, `Event::dispatchIf()`, `Event::until()`, `Event::once()`). |
| `Spark\Facades\Gate` | `Spark\Http\Gate` | User authorization policies and ability checks (`Gate::define()`, `Gate::allows()`, `Gate::denies()`, `Gate::check()`, `Gate::authorize()`). |
| `Spark\Facades\Hash` | `Spark\Hash` | Password hashing (Bcrypt/Argon2id), encryption, and verification (`Hash::make()`, `Hash::check()`, `Hash::bcrypt()`, `Hash::argon2id()`, `Hash::encrypt()`, `Hash::decrypt()`, `Hash::uuid()`). |
| `Spark\Facades\Http` | `Spark\Http\Client\Http` | Fluent outbound HTTP client with JSON handling and authentication (`Http::withToken()`, `Http::timeout()`, `Http::post()`, `Http::get()`, `Http::pool()`). |
| `Spark\Facades\Lock` | `Spark\Cache\Lock` | Atomic distributed locking (`Lock::withLock()`, `Lock::lock()`, `Lock::unlock()`, `Lock::isLocked()`, `Lock::make('store')`). |
| `Spark\Facades\Log` | `Spark\Log` | Application logging (`Log::info()`, `Log::error()`, `Log::warning()`, `Log::debug()`). |
| `Spark\Facades\Mail` | `Spark\Utils\Mail` | Fluent email sending with Blade view rendering (`Mail::to()`, `Mail::subject()`, `Mail::view()`, `Mail::send()`). |
| `Spark\Facades\Queue` | `Spark\Queue\Queue` | Background queue operations, queue size inspection, clearing. |
| `Spark\Facades\Route` | `Spark\Http\Routing\Router` | Defining routes, route groups, resource routes, model binding, middleware. |
| `Spark\Facades\Schema` | `Spark\Database\Schema\Schema` | Database migrations and schema building (`Schema::create()`, `Schema::table()`, `Schema::dropIfExists()`). |
| `Spark\Facades\Session` | `Spark\Http\Session` | Web session manipulation (`Session::get()`, `Session::put()`, `Session::flash()`, `Session::regenerate()`, `Session::invalidate()`). |
| `Spark\Facades\Storage` | `Spark\Storage\Storage` | Filesystem storage (`Storage::disk('public')->put()`, `Storage::url()`, `Storage::temporaryUrl()`, disk uploaders). |
| `Spark\Facades\Validator` | `Spark\Http\Validator` | Explicit validator construction (`Validator::make($rules, $data)`). |

> [!IMPORTANT]
> **Facades in Backend PHP vs. Global Helpers in Blade Views**:
> Static facades (`Spark\Facades\*`) are designed for backend PHP classes (controllers, services, jobs, models, commands).
> **Never use static facades inside Blade templates (`.blade.php`)**. In Blade views, always use Spark's expressive global helpers (`session()`, `auth()`, `user()`, `app()`, `request()`, `route()`, `old()`, `csrf_field()`, `now()`).

---

## Global Application Helpers

Spark includes a rich suite of built-in global helper functions defined in `Spark\Foundation\helpers.php` and `Spark\Support\helpers.php`:

### 1. Application, Container & Config
- `app(?string $abstract = null, array $parameters = [])`: Returns the application container or resolves the given service abstract.
- `get(string $abstract)`: Resolves an instance from the container.
- `has(string $abstract)`: Checks if a container binding exists.
- `bind(string $abstract, $concrete = null)`: Registers a transient container binding.
- `singleton(string $abstract, $concrete = null)`: Registers a singleton container binding.
- `call(callable|array $abstract, array $parameters = [])`: Invokes a callable with container dependency injection.
- `defer(callable $callback)`: Defers a callback to run after the HTTP response has been sent to the client.
- `config(string $key, mixed $default = null)`: Reads an application configuration key using dot notation.
- `env(string $key, mixed $default = null)`: Reads an environment variable with optional fallback.

### 2. HTTP Requests & Responses
- `request(?string $key = null, mixed $default = null)`: Returns the current `Spark\Http\Request` instance or reads an input key.
- `response(mixed $content = '', int $statusCode = 200, array $headers = [])`: Returns the `Spark\Http\Response` factory or creates a new response instance.
- `json(array $data, int $statusCode = 200)`: Creates a JSON HTTP response (ideal for non-resource contracts, messages, or webhooks).
- `redirect(string $url, int $httpCode = 302)`: Creates a redirect response.
- `to_route(string $name, mixed $context = null, int $httpCode = 302)`: Redirects to a named route.
- `back()`: Redirects back to the previous request URL.

### 3. URLs and Asset Paths
- `url(?string $path = null, array $parameters = [])`: Generates a `Spark\Url` object with fluent query parameter support.
- `route(string $name, mixed $context = null)`: Generates a `Spark\Url` object for a named route.
- `route_url(string $name, mixed $context = null)`: Generates an absolute URL string for a named route.
- `home_url(string $path = '')`: Generates a URL relative to the application's base URL.
- `asset(string $path = '')`: Generates a `Spark\Url` object for a public asset.
- `asset_url(string $path = '')`: Generates an absolute URL string for a public asset.
- `media(string $path = '')` / `media_url(string $path = '')`: Generates a URL for uploaded media files.
- `request_url()`: Returns the absolute URL of the current HTTP request.
- `root_dir(string $path = '/')`: Returns an absolute path within the project root.
- `storage_dir(string $path = '/')`: Returns an absolute path within the storage directory.
- `resource_dir(string $path = '/')`: Returns an absolute path within the resources directory.

### 4. Authentication, Security & Gates
- `auth()`: Returns the `Spark\Http\Auth` manager.
- `user(?string $key = null)`: Returns the currently authenticated user model instance, or a specific attribute on that model.
- `is_logged()`: Returns true if a user is currently authenticated.
- `is_guest()`: Returns true if the visitor is unauthenticated.
- `can(string $ability, ...$args)`: Checks if the user is authorized for an ability via Gate.
- `cannot(string $ability, ...$args)`: Inverted authorization check.
- `authorize(string $ability, ...$args)`: Authorizes an ability or throws HTTP 403 `AuthorizationException`.
- `csrf()` / `csrf_token()`: Returns the active CSRF token string.
- `csrf_field()`: Returns an HTML hidden input containing the CSRF token.
- `method(string $method)` / `method_field(string $method)`: Returns an HTML hidden input for HTTP method spoofing (`PUT`, `PATCH`, `DELETE`).

### 5. Session, Cookies & Validation Errors
- `session(array|string|null $param = null, mixed $default = null)`: Gets/sets session data, or returns the `Spark\Http\Session` instance.
- `cookie(string $name, mixed $value = null, mixed $options = null)`: Reads or sets an HTTP cookie (use `now()->addDays(...)` for expiration timestamps).
- `errors()`: Returns the flashed `Spark\Http\InputErrors` validation error bag.
- `old(string $key, ?string $default = null)`: Retrieves flashed previous input value.

### 6. Views, Templates & Vite
- `view(?string $template = null, array $context = [])`: Renders a Blade template and returns a `Spark\Http\Response`.
- `blade(?string $template = null, array $context = [])`: Renders a Blade template and returns the rendered HTML string.
- `vite(null|string|array $entrypoints = null)`: Configures and renders the `Spark\Utils\Vite` asset loader tags.

### 7. Dates & Times (`Spark\Carbon`)
- `now()`: Returns a `Spark\Carbon` instance set to the current time in application UTC.
- `today()`: Returns a `Spark\Carbon` instance set to today at 00:00:00.
- `carbon(mixed $date = null)`: Parses a value into a `Spark\Carbon` instance.

> [!CAUTION]
> **Strict Rule**: Procedural date functions (`gmdate`, `date`, `time`, `strtotime`, `microtime`) are **prohibited**. Always use `now()`, `carbon()`, and `Spark\Carbon`.

### 8. Flow Control, Diagnostics & Diagnostics
- `tap(mixed $value, ?callable $callback = null)`: Taps into a value, executes the callback, and returns the original value.
- `abort(int $code, string $message = '', array $headers = [])`: Throws an HTTP exception with status code.
- `abort_if(bool $boolean, int $code, string $message = '')`: Aborts if condition is true.
- `abort_unless(bool $boolean, int $code, string $message = '')`: Aborts unless condition is true.
- `throw_if(bool $boolean, string|Throwable $exception, ...$parameters)`: Throws exception conditionally.
- `throw_unless(bool $boolean, string|Throwable $exception, ...$parameters)`: Throws exception conditionally.
- `blank(mixed $value)`: Returns true if value is null, empty string, or whitespace.
- `filled(mixed $value)`: Inverted `blank()` check.
- `data_get(mixed $target, string|array $key, mixed $default = null)`: Retrieves a value from an array or object using dot notation.
- `data_set(mixed &$target, string|array $key, mixed $value, bool $overwrite = true)`: Sets a value using dot notation.

### 9. Translations & Localization
- `__(string $key, array|int $replace = [])`: Translates a text string using language files in `resources/languages/`.
- `_e(string $key, array $replace = [])`: Translates and HTML-escapes the result.
- `trans(string $key, mixed $arg = null, array $args = [])`: Alias for `__()`.

---

## Modern Support Primitives (Laravel 12 Style)

### 1. Collections (`collect`) and Higher-Order Proxies

```php
$posts = Post::published()->latest()->get(); // Spark\Support\Collection<Post>

// Fluent Higher-Order Collection Proxies (no verbose closures needed!)
$titles = $posts->map->title;             // Maps each model's 'title' attribute
$activePosts = $posts->filter->is_active; // Filters models by boolean property
$orders->each->cancel();                  // Calls ->cancel() on each model instance
$totalAmount = $orders->sum->total;       // Sums a property across collection

// Common collection operations
$grouped = $posts->groupBy('category_id');
$keyed = $posts->keyBy('id');
$plucked = $posts->pluck('title', 'id');
$chunked = $posts->chunk(50);
$first = $posts->first(fn ($p) => $p->views > 100);
```

### 2. LazyCollection (`Spark\Support\LazyCollection`)

For memory-efficient streaming of large datasets:

```php
use Spark\Support\LazyCollection;

$lines = LazyCollection::make(function () {
    $handle = fopen(storage_dir('large-log.txt'), 'r');
    while (($line = fgets($handle)) !== false) {
        yield $line;
    }
    fclose($handle);
})
    ->filter(fn ($line) => str_contains($line, 'ERROR'))
    ->take(100);
```

### 3. Strings & Stringable (`str` and `Spark\Support\Str`)

```php
use Spark\Support\Str;

// Fluent Stringable helper via str()
$slug = str('Building APIs with TinyMVC')->slug();      // 'building-apis-with-tinymvc'
$headline = str('user_profile_photo')->headline();      // 'User Profile Photo'
$camel = str('created_at')->camel();                    // 'createdAt'
$kebab = str('firstName')->kebab();                     // 'first-name'
$limited = str($body)->limit(150, '...');               // Truncates string
$excerpt = str($article)->excerpt('framework');

// Static Str utility methods
$uuid = Str::uuid();
$random = Str::random(32);
$contains = Str::contains($haystack, 'needle');
$startsWith = Str::startsWith($haystack, 'SPARK-');
$endsWith = Str::endsWith($email, '@example.com');
$between = Str::between('<div>Hello</div>', '<div>', '</div>'); // 'Hello'
```

### 4. Arrays (`Spark\Support\Arr`)

```php
use Spark\Support\Arr;

$val = Arr::get($data, 'user.profile.city', default: 'New York');
$filtered = Arr::only($data, ['id', 'title', 'slug']);
$without = Arr::except($data, ['password', 'secret_token']);
$dot = Arr::dot($nestedArray);       // Flattens into 'user.profile.city' => '...'
$undot = Arr::undot($flattenedArray); // Reconstructs multi-dimensional array
$first = Arr::first($items, fn ($v) => $v > 10);
$joined = Arr::join(['Apples', 'Bananas', 'Oranges'], ', ', ' and '); // 'Apples, Bananas and Oranges'
```

### 5. Numbers (`Spark\Support\Number`)

TinyCore includes modern number and currency formatting utilities:

```php
use Spark\Support\Number;

$formattedPrice = Number::currency(49.99, in: 'USD');   // '$49.99'
$humanSize      = Number::fileSize(10485760);           // '10 MB'
$percent        = Number::percentage(0.854, precision: 1); // '85.4%'
$abbreviated    = Number::abbreviate(1250000);          // '1.3M'
$clamped        = Number::clamp($value, min: 1, max: 100);
$spelled        = Number::spell(42);                    // 'forty-two'
$ordinal        = Number::ordinal(3);                   // '3rd'
```

### 6. JavaScript Encoding (`Spark\Support\Js`)

Safely serialize server variables for inline client JavaScript or Alpine attributes:

```blade
<div x-data="{ config: {{ \Spark\Support\Js::from($userConfig) }} }">
    {{-- Safely escaped JSON string avoiding XSS --}}
</div>

{{-- Or use the @json directive: --}}
<script>
    window.AppConfig = @json($appConfig);
</script>
```

### 7. URL Utility (`Spark\Url`)

```php
$url = route('posts.show', ['post' => 'spark-guide'])
    ->withQuery(['ref' => 'email', 'utm_source' => 'newsletter'])
    ->withFragment('comments');

echo $url->getUrl();          // Full absolute URL
echo $url->getPath();         // '/posts/spark-guide'
$queryParams = $url->getQueryParams(); // ['ref' => 'email', ...]
```

### 8. Validation Error Bag (`Spark\Http\InputErrors`)

```php
$errors = errors();

if ($errors->any()) {
    $first = $errors->first('email');
    $allMessages = $errors->all();
    $fieldMessages = $errors->get('password');
}

// In Blade templates:
// @error('email') <p class="text-red-500">{{ $message }}</p> @enderror
```
