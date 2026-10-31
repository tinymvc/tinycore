# Global Helpers, Facades, and Support Primitives

> **Read when:** you need a facade, a global helper function, or a support utility (Collection, Str, Arr, Number, Js, Url, InputErrors).
> **Related:** [Foundation](foundation.md) · [API discovery](api-discovery.md) · [date boundaries](localization-dates.md)
> Paths are relative to the application root. Verify exact signatures against installed source ([API discovery](api-discovery.md)).

Contents:

- [Facades (`Spark\Facades\*`)](#facades-sparkfacades)
- [Global helpers](#global-helpers)
- [Helpers documented in other references](#helpers-documented-in-other-references)
- [Support primitives](#support-primitives)

## Facades (`Spark\Facades\*`)

Static access to services in the application container.

| Facade | Backing class | Typical use |
| --- | --- | --- |
| `App` | `Spark\Foundation\Application` | Container binding/resolving; environment checks (`isProduction()`, `isTesting()`, `isDebugMode()`) |
| `Auth` | `Spark\Http\Auth` | `attempt()`, `user()`, `id()`, `loginUsingId()`, `createToken()`, `revokeToken()` |
| `Blade` | `Spark\View\Blade` | `directive()`, component rendering, `share()` |
| `Cache` | `Spark\Cache\CacheManager`, `Spark\Cache\Cache` | `remember()`, `get()`, `put()`, `forget()`, `erase()`, `flush()`, `store('redis')` |
| `DB` | `Spark\Database\DB` | `transaction()`, `table()`, `raw()`, `lockForUpdate()` |
| `Event` | `Spark\Events` | `listen()`, `dispatch()`, `dispatchIf()`, `until()`, `once()` |
| `Gate` | `Spark\Http\Gate` | `define()`, `allows()`, `denies()`, `check()`, `authorize()` |
| `Hash` | `Spark\Hash` | Passwords: `password()`, `bcrypt()`, `argon2id()`, `verify()`. Also `encrypt()`, `decrypt()`, `uuid()`. `make()` / `check()` are **keyed HMAC helpers, not password hashing** |
| `Http` | `Spark\Http\Client\Http` | Outbound client: `withToken()`, `timeout()`, `get()`, `post()`, `pool()` |
| `Log` | `Psr\Log\LoggerInterface` (native `Spark\Logger`) | `info()`, `error()`, `warning()`, `debug()` |
| `Mail` | `Spark\Utils\Mail` | `to()`, `subject()`, `view()`, `send()` |
| `Queue` | `Spark\Queue\QueueManager`, `Spark\Queue\Queue` | Queue operations, size inspection, clearing |
| `Route` | `Spark\Http\Routing\Router` | Routes, groups, resource routes, model binding, middleware |
| `Schema` | `Spark\Database\Schema\Schema` | `create()`, `table()`, `dropIfExists()` |
| `Session` | `Spark\Http\Session` | `get()`, `put()`, `flash()`, `regenerate()`, `invalidate()` |
| `Storage` | `Spark\Storage\Storage` | `disk('public')->put()`, `url()`, `temporaryUrl()`, uploaders; see [files and storage](files-storage.md) |
| `Telemetry` | `Spark\Telemetry` | Lazy runtime events and wildcard observers; [telemetry contracts](container-pipelines.md#runtime-telemetry) |
| `Validator` | `Spark\Http\Validator` | `Validator::make($rules, $data)` |

> [!IMPORTANT]
> **Facades are for backend PHP** (controllers, services, jobs, models, commands). **Never use facades inside Blade templates.** In `.blade.php` use global helpers: `session()`, `auth()`, `user()`, `app()`, `request()`, `route()`, `old()`, `csrf_field()`, `now()`.

## Global helpers

Defined in `Spark\Foundation\helpers.php` and `Spark\Support\helpers.php`.

### Application, container, config

| Helper | Purpose |
| --- | --- |
| `app(?string $abstract = null, array $parameters = [])` | Container, or resolve an abstract |
| `get(string $abstract)` / `has(string $abstract)` | Resolve from / check binding in the container |
| `bind(string $abstract, $concrete = null)` | Transient binding |
| `singleton(string $abstract, $concrete = null)` | Singleton binding |
| `call(callable\|array $abstract, array $parameters = [])` | Invoke a callable with DI |
| `defer(callable $callback)` | Run after the response is sent (see [Foundation](foundation.md#post-response-work-with-defer)) |
| `config(string $key, mixed $default = null)` | Config by dot notation |
| `env(string $key, mixed $default = null)` | Environment variable with fallback |

### HTTP

| Helper | Purpose |
| --- | --- |
| `request(?string $key = null, mixed $default = null)` | Current `Spark\Http\Request`, or an input key |
| `response(mixed $content = '', int $statusCode = Response::STATUS_OK, array $headers = [])` | Response factory / new response |
| `json(array $data, int $statusCode = Response::STATUS_OK)` | JSON response; for non-resource contracts, messages, webhooks |
| `redirect(string $url, int $httpCode = Response::STATUS_FOUND)` | Redirect response |
| `to_route(string $name, mixed $context = null, int $httpCode = Response::STATUS_FOUND)` | Redirect to a named route |
| `back()` | Redirect to the previous URL |

(`Response` is `\Spark\Http\Response`.)

### URLs and paths

| Helper | Returns |
| --- | --- |
| `url(?string $path = null, array $parameters = [])` | `Spark\Url` with fluent query support |
| `route(string $name, mixed $context = null)` | `Spark\Url` for a named route |
| `route_url(string $name, mixed $context = null)` | Absolute URL **string** for a named route |
| `home_url(string $path = '')` | URL relative to the app base URL |
| `asset(string $path = '')` / `asset_url(string $path = '')` | `Spark\Url` / absolute string for a public asset |
| `media(string $path = '')` / `media_url(string $path = '')` | URL for uploaded media |
| `request_url()` | Absolute URL of the current request |
| `root_dir($path = '/')`, `storage_dir($path = '/')`, `resource_dir($path = '/')` | Absolute paths under project root / storage / resources |

### Authentication, security, gates

| Helper | Purpose |
| --- | --- |
| `auth()` | `Spark\Http\Auth` manager |
| `user(?string $key = null)` | Authenticated user model, or one attribute |
| `is_logged()` / `is_guest()` | Authenticated / unauthenticated check |
| `can($ability, ...$args)` / `cannot($ability, ...$args)` | Gate checks (cannot = inverted) |
| `authorize($ability, ...$args)` | Authorize or throw HTTP 403 `AuthorizationException` |
| `csrf()` / `csrf_token()` | Active CSRF token string |
| `csrf_field()` | Hidden input with the CSRF token |
| `method($method)` / `method_field($method)` | Hidden input for method spoofing (`PUT`, `PATCH`, `DELETE`) |

### Session, cookies, validation errors

- `session(array|string|null $param = null, mixed $default = null)`: get/set session data, or the `Spark\Http\Session` instance.
- `cookie(string $name, mixed $value = null, mixed $options = null)`: read or set a cookie (use `now()->addDays(...)` for expiry).
- `errors()`: flashed `Spark\Http\InputErrors` bag.
- `old(string $key, ?string $default = null)`: flashed previous input.

### Views and Vite

- `view(?string $template = null, array $context = [])`: render Blade, returns a `Spark\Http\Response`.
- `blade(?string $template = null, array $context = [])`: render Blade, returns an HTML **string**.
- `vite(null|string|array $entrypoints = null)`: configure and render `Spark\Utils\Vite` tags.

### Dates and times (`Spark\Carbon`)

- `now()`: current time in `app.timezone` (default `UTC`).
- `today()`: today at 00:00:00.
- `carbon(mixed $date = null)`: parse a value.

Use Carbon for application dates and preserve explicit timezone/output contracts. For elapsed-duration measurement use `hrtime(true)`. See [date boundaries](localization-dates.md).

### Flow control and diagnostics

| Helper | Purpose |
| --- | --- |
| `tap($value, ?callable $callback = null)` | Run callback, return the original value |
| `abort(int $code, string $message = '', array $headers = [])` | Throw an HTTP exception |
| `abort_if(bool $boolean, int $code, string $message = '')` | Abort when true |
| `abort_unless(bool $boolean, int $code, string $message = '')` | Abort when false |
| `throw_if(bool $boolean, string\|Throwable $exception, ...$parameters)` | Throw when true |
| `throw_unless(bool $boolean, string\|Throwable $exception, ...$parameters)` | Throw when false |
| `blank($value)` / `filled($value)` | Null, empty string, or whitespace / its inverse |
| `data_get($target, string\|array $key, $default = null)` | Read by dot notation from array/object |
| `data_set(&$target, string\|array $key, $value, bool $overwrite = true)` | Write by dot notation |

### Translations (`resources/languages/`)

- `__(string $key, array|int $replace = [])`: translate.
- `_e(string $key, array $replace = [])`: translate and HTML-escape.
- `trans(?string $key = null, array $replace = [], ?string $locale = null)`: named translation, or the translator when no key is given.
- `trans_choice(string $key, int|float|array|Countable $number, array $replace = [], ?string $locale = null)`: plural selection with `:count`.

## Helpers documented in other references

| Helper | Where |
| --- | --- |
| `event()`, `command()` | [Extensions and errors](extensions-errors.md) |
| `fm()` (`Spark\Utils\File`), `image()` (`Spark\Utils\Image`), `temp_dir()` | [Files and storage](files-storage.md) |
| `fire()`, `is_fire_js()`, `is_fire_partial()`, `is_fire_preload()` | [FireLine](fireline.md) (optional adapter package) |
| `inertia()` | [Inertia](inertia.md) (optional adapter package) |

## Support primitives

Spark-native classes with familiar fluent APIs. They live under `Spark\Support\`; the whole `Support/` directory is hidden from `api-lookup.php` unless you pass `--include-support`.

### Collections and higher-order proxies

```php
$posts = Post::published()->latest()->get(); // Spark\Support\Collection<Post>

// Higher-order proxies
$titles = $posts->map->title;
$activePosts = $posts->filter->is_active;
$orders->each->cancel();
$totalAmount = $orders->sum->total;

// Common operations
$grouped = $posts->groupBy('category_id');
$keyed = $posts->keyBy('id');
$plucked = $posts->pluck('title', 'id');
$chunked = $posts->chunk(50);
$first = $posts->first(fn ($p) => $p->views > 100);
```

Collection operations hydrate the whole result set; use SQL pagination for large data (see [advanced workflows](advanced-workflows.md#authorization-scopes-and-query-composition)).

### LazyCollection

Memory-efficient streaming of large datasets:

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

### Strings (`str()` and `Spark\Support\Str`)

```php
use Spark\Support\Str;

// Fluent
$slug = str('Building APIs with TinyMVC')->slug();   // 'building-apis-with-tinymvc'
$headline = str('user_profile_photo')->headline();   // 'User Profile Photo'
$camel = str('created_at')->camel();                 // 'createdAt'
$kebab = str('firstName')->kebab();                  // 'first-name'
$limited = str($body)->limit(150, '...');
$excerpt = str($article)->excerpt('framework');

// Static
$uuid = Str::uuid();
$random = Str::random(32);
$contains = Str::contains($haystack, 'needle');
$startsWith = Str::startsWith($haystack, 'SPARK-');
$endsWith = Str::endsWith($email, '@example.com');
$between = Str::between('<div>Hello</div>', '<div>', '</div>'); // 'Hello'
```

`Str::slug()` / ASCII helpers need `voku/portable-ascii`; `Str::uuid()` needs `ramsey/uuid` (see [optional capabilities](api-discovery.md#4-check-optional-capabilities)).

### Arrays (`Spark\Support\Arr`)

```php
use Spark\Support\Arr;

$val = Arr::get($data, 'user.profile.city', default: 'New York');
$filtered = Arr::only($data, ['id', 'title', 'slug']);
$without = Arr::except($data, ['password', 'secret_token']);
$dot = Arr::dot($nestedArray);        // 'user.profile.city' => '...'
$undot = Arr::undot($flattenedArray); // back to nested
$first = Arr::first($items, fn ($v) => $v > 10);
$joined = Arr::join(['Apples', 'Bananas', 'Oranges'], ', ', ' and '); // 'Apples, Bananas and Oranges'
```

### Numbers (`Spark\Support\Number`)

```php
use Spark\Support\Number;

Number::currency(49.99, in: 'USD');           // '$49.99'
Number::fileSize(10485760);                   // '10 MB'
Number::percentage(0.854, precision: 1);      // '85.4%'
Number::abbreviate(1250000);                  // '1.3M'
Number::clamp($value, min: 1, max: 100);
Number::spell(42);                            // 'forty-two'
Number::ordinal(3);                           // '3rd'
```

### JavaScript encoding (`Spark\Support\Js`)

Safely serialize server values for inline JS or Alpine attributes:

```blade
<div x-data="{ config: {{ \Spark\Support\Js::from($userConfig) }} }">
    {{-- Escaped JSON string, XSS-safe --}}
</div>

{{-- Or the @json directive: --}}
<script>
    window.AppConfig = @json($appConfig);
</script>
```

### URLs (`Spark\Url`)

```php
$url = route('posts.show', ['post' => 'spark-guide'])
    ->withQuery(['ref' => 'email', 'utm_source' => 'newsletter'])
    ->withFragment('comments');

$url->getUrl();         // full absolute URL
$url->getPath();        // '/posts/spark-guide'
$url->getQueryParams(); // ['ref' => 'email', ...]
```

### Validation error bag (`Spark\Http\InputErrors`)

```php
$errors = errors();

if ($errors->any()) {
    $first = $errors->first('email');
    $allMessages = $errors->all();
    $fieldMessages = $errors->get('password');
}
```

In Blade:

```blade
@error('email') <p class="text-red-500">{{ $message }}</p> @enderror
```
