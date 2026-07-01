# TinyMVC Framework Guide for AI Agents

This file is meant to be copied into or referenced from an application that uses TinyMVC/TinyCore. It tells an AI agent how to write backend code for a TinyMVC project without assuming Laravel, Symfony, or another framework.

If you are an AI agent working inside a TinyMVC application, read this file before editing code.

## What TinyMVC Is

TinyMVC is a small PHP framework powered by the TinyCore package.

- Core package: `tinymvc/tinycore`
- Core namespace: `Spark\\`
- Minimum PHP: `8.2`
- Style: Laravel-like ergonomics, custom implementation
- Dependency injection: `Spark\Foundation\Application` extends `Spark\Container`
- Routing: `Spark\Routing\Router`
- HTTP: `Spark\Http\Request`, `Spark\Http\Response`, `Spark\Http\Middleware`
- Database: PDO wrapper, query builder, active-record-like models, schema/migrations
- Storage utilities: sqlite or redis cache, lock, and queue

Do not assume Laravel classes such as `Illuminate\Http\Request`, `Illuminate\Support\Facades\Route`, `artisan`, Eloquent, or Laravel middleware internals exist. Use the `Spark\\` classes and global helpers documented here.

## Framework Source Lookup Paths

In an application project, TinyCore source is normally installed under:

```text
./vendor/tinymvc/tinycore/
```

If an AI needs exact behavior, it should inspect these files as read-only reference. Do not edit vendor/framework files inside an application unless the user explicitly asks to patch the framework itself.

Core bootstrap and container:

- Application lifecycle: `./vendor/tinymvc/tinycore/src/Foundation/Application.php`
- Service container and dependency injection: `./vendor/tinymvc/tinycore/src/Container.php`
- Service provider base class: `./vendor/tinymvc/tinycore/src/Foundation/Providers/ServiceProvider.php`
- Core console provider: `./vendor/tinymvc/tinycore/src/Foundation/Providers/ConsoleServiceProvider.php`
- Environment and config cache: `./vendor/tinymvc/tinycore/src/DotEnv.php`
- Global helpers: `./vendor/tinymvc/tinycore/src/Foundation/helpers.php`

Routing and request lifecycle:

- Router: `./vendor/tinymvc/tinycore/src/Routing/Router.php`
- Route builder: `./vendor/tinymvc/tinycore/src/Routing/Route.php`
- Route groups: `./vendor/tinymvc/tinycore/src/Routing/RouteGroup.php`
- Resource routes: `./vendor/tinymvc/tinycore/src/Routing/RouteResource.php`
- Route facade: `./vendor/tinymvc/tinycore/src/Facades/Route.php`
- Request: `./vendor/tinymvc/tinycore/src/Http/Request.php`
- Response: `./vendor/tinymvc/tinycore/src/Http/Response.php`
- Middleware pipeline: `./vendor/tinymvc/tinycore/src/Http/Middleware.php`

Built-in middleware:

- CORS base middleware: `./vendor/tinymvc/tinycore/src/Foundation/Http/Middlewares/CorsAccessControl.php`
- CSRF base middleware: `./vendor/tinymvc/tinycore/src/Foundation/Http/Middlewares/CsrfProtection.php`
- Throttle base middleware: `./vendor/tinymvc/tinycore/src/Foundation/Http/Middlewares/ThrottleIncomingRequests.php`
- Middleware contract: `./vendor/tinymvc/tinycore/src/Contracts/Http/MiddlewareInterface.php`

Validation, auth, and session:

- Form request base class: `./vendor/tinymvc/tinycore/src/Foundation/Http/FormRequest.php`
- Validator: `./vendor/tinymvc/tinycore/src/Http/Validator.php`
- Validated input wrapper: `./vendor/tinymvc/tinycore/src/Http/Input.php`
- Input errors: `./vendor/tinymvc/tinycore/src/Http/InputErrors.php`
- Auth manager: `./vendor/tinymvc/tinycore/src/Http/Auth.php`
- Gate/authorization: `./vendor/tinymvc/tinycore/src/Http/Gate.php`
- Session: `./vendor/tinymvc/tinycore/src/Http/Session.php`

Database, ORM, and migrations:

- DB/PDO wrapper: `./vendor/tinymvc/tinycore/src/Database/DB.php`
- Query builder: `./vendor/tinymvc/tinycore/src/Database/QueryBuilder.php`
- Model base class: `./vendor/tinymvc/tinycore/src/Database/Model.php`
- Model casts trait: `./vendor/tinymvc/tinycore/src/Database/Casts/Castable.php`
- Attribute cast helper: `./vendor/tinymvc/tinycore/src/Database/Casts/Attribute.php`
- Migration runner: `./vendor/tinymvc/tinycore/src/Database/Migration.php`
- Schema facade/class: `./vendor/tinymvc/tinycore/src/Database/Schema/Schema.php`
- Blueprint: `./vendor/tinymvc/tinycore/src/Database/Schema/Blueprint.php`
- Column definitions: `./vendor/tinymvc/tinycore/src/Database/Schema/Column.php`
- Schema grammar: `./vendor/tinymvc/tinycore/src/Database/Schema/Grammar.php`
- Relations: `./vendor/tinymvc/tinycore/src/Database/Relation/`

Cache, lock, queue, and redis:

- Cache: `./vendor/tinymvc/tinycore/src/Utils/Cache.php`
- Lock: `./vendor/tinymvc/tinycore/src/Utils/Lock.php`
- Queue: `./vendor/tinymvc/tinycore/src/Queue/Queue.php`
- Job wrapper: `./vendor/tinymvc/tinycore/src/Queue/Job.php`
- Job contracts: `./vendor/tinymvc/tinycore/src/Queue/Contracts/`
- Redis connector: `./vendor/tinymvc/tinycore/src/Utils/RedisConnector.php`

Views, console, events, facades, utilities:

- Blade renderer: `./vendor/tinymvc/tinycore/src/View/Blade.php`
- Blade compiler: `./vendor/tinymvc/tinycore/src/View/BladeCompiler.php`
- View attributes: `./vendor/tinymvc/tinycore/src/View/Attributes.php`
- Console runner: `./vendor/tinymvc/tinycore/src/Console/Console.php`
- Command registry: `./vendor/tinymvc/tinycore/src/Console/Commands.php`
- Console stubs: `./vendor/tinymvc/tinycore/src/Foundation/Console/stubs/`
- Event dispatcher: `./vendor/tinymvc/tinycore/src/Events.php`
- Facade base class: `./vendor/tinymvc/tinycore/src/Facades/Facade.php`
- All facades: `./vendor/tinymvc/tinycore/src/Facades/`
- Carbon-like date utility: `./vendor/tinymvc/tinycore/src/Utils/Carbon.php`
- Mail utility: `./vendor/tinymvc/tinycore/src/Utils/Mail.php`
- HTTP client: `./vendor/tinymvc/tinycore/src/Http/Client/`
- Upload/file/image utilities: `./vendor/tinymvc/tinycore/src/Utils/Uploader.php`, `./vendor/tinymvc/tinycore/src/Utils/FileManager.php`, `./vendor/tinymvc/tinycore/src/Utils/Image.php`
- Tracer/debugging: `./vendor/tinymvc/tinycore/src/Utils/Tracer.php`
- Vite integration: `./vendor/tinymvc/tinycore/src/Utils/Vite.php`

## How To Use This File

When implementing a feature in a TinyMVC app:

1. Inspect the current app structure first.
2. Look for `bootstrap/app.php`, `routes/*.php`, `config/*.php`, `app/`, `database/`, `resources/views/`, and `storage/`.
3. Follow existing namespaces and directory conventions in that app.
4. Use `Spark\\` classes, TinyMVC helpers, and the app's own base classes.
5. Do not edit `vendor/tinymvc/tinycore` or framework source unless the user explicitly asks to modify the framework itself.
6. Do not create Laravel-specific files or syntax unless this app already provides compatibility.

## Typical Application Layout

Actual apps may vary, but common TinyMVC app layout is:

```text
app/
  Http/
    Controllers/
    Middlewares/
    Requests/
  Models/
  Providers/
  Jobs/
  Services/
bootstrap/
  app.php
  middlewares.php
  providers.php
config/
  app.php
  cache.php
  database.php
  mail.php
  queue.php
database/
  migrations/
public/
  index.php
resources/
  views/
routes/
  web.php
  api.php
  webhook.php
  console.php
storage/
  cache/
  logs/
  queue/
  temp/
  uploads/
```

Always verify the actual project before creating files.

## Core Bootstrap

TinyMVC apps usually bootstrap the framework through `Spark\Foundation\Application`.

Example shape:

```php
<?php

use Spark\Foundation\Application;

return Application::create(
    path: dirname(__DIR__),
    config: 'config',
    providers: require __DIR__ . '/providers.php',
)
    ->withMiddleware(
        load: __DIR__ . '/middlewares.php',
        queue: ['csrf']
    )
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        webhook: __DIR__ . '/../routes/webhook.php'
    );
```

Important lifecycle:

1. `Application` sets `Application::$app`.
2. `.env` is loaded and cached.
3. Core services are registered.
4. Config is discovered and cached.
5. Providers register and boot.
6. Router dispatches the current `Request`.
7. Middleware wraps the matched route.
8. Route callback/controller returns a value.
9. Router converts it to `Response`.
10. `Response::send()` sends headers and body.

## Configuration

Config files return PHP arrays. Use `config('key.path')` and `env('KEY', $default)`.

### Database Config

Expected shape:

```php
<?php

return [
    'driver' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'file' => dirname(__DIR__) . '/database/sqlite.db',
        ],
        'default' => [
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'name' => env('DB_DATABASE', 'spark'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
        ],
    ],
];
```

### Cache and Lock Config

Cache and lock both use `config('cache')`.

```php
<?php

return [
    'driver' => env('CACHE_DRIVER', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'path' => dirname(__DIR__) . '/storage/cache',
        ],
        'redis' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_DATABASE', 0),
            'prefix' => env('REDIS_PREFIX', 'spark'),
            'timeout' => env('REDIS_TIMEOUT', 0.0),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 0.0),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],
    ],
];
```

### Queue Config

Queue uses `config('queue')`.

```php
<?php

return [
    'driver' => env('QUEUE_DRIVER', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'path' => dirname(__DIR__) . '/storage/queue/jobs.db',
        ],
        'redis' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_DATABASE', 0),
            'prefix' => env('REDIS_PREFIX', 'spark'),
            'timeout' => env('REDIS_TIMEOUT', 0.0),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 0.0),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],
    ],
];
```

## Global Helpers

Common helpers:

```php
app();                 // application container
app(Foo::class);       // resolve from container
get(Foo::class);       // resolve from container
config('app.debug');
config(['app.debug' => true]);
env('APP_KEY');

request();
request('email');
response('OK', 200);
json(['ok' => true]);
redirect('/login');
back();

router();
route_url('users.show', ['id' => 5]);
route('users.show', ['id' => 5]); // returns Spark\Url

view('users.index', ['users' => $users]);
fireline('emails.welcome', ['user' => $user]);

auth();
user();
gate();
authorize('update-post', $post);

db();
query('users');
cache('default');
lock('key');

storage_dir('cache');
root_dir('routes/web.php');
dir_path($path);

now();
carbon('2026-01-01');
abort(404, 'Not found');
```

Use helpers only when they already match the app style. In service classes, dependency injection is often cleaner.

## Routing

Routes are usually written in `routes/web.php`, `routes/api.php`, or `routes/webhook.php`.

The route helper returns the router:

```php
use Spark\Facades\Route;
use App\Http\Controllers\UserController;

Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show');
Route::post('/users', [UserController::class, 'store'])->middleware('auth');
Route::put('/users/{id}', [UserController::class, 'update']);
Route::patch('/users/{id}', [UserController::class, 'update']);
Route::delete('/users/{id}', [UserController::class, 'destroy']);
```

Supported route methods:

```php
Route::get($path, $callback);
Route::post($path, $callback);
Route::put($path, $callback);
Route::patch($path, $callback);
Route::delete($path, $callback);
Route::options($path, $callback);
Route::any($path, $callback);
Route::match(['GET', 'POST'], $path, $callback);
Route::view('/about', 'pages.about');
Route::fireline('/email-preview', 'emails.welcome');
Route::inertia('/contact', 'Contact', ['key' => 'value']);
Route::redirect('/old', '/new', 301);
Route::fallback(fn() => response('Not found', 404));
```

Route parameters:

```php
Route::get('/posts/{id}', fn(int $id) => "Post $id");
Route::get('/posts/{id?}', fn(?string $id = null) => $id);
Route::get('/files/*', fn() => 'wildcard');
```

Route groups:

```php
use App\Http\Controllers\Api\UserController;

Route::group(['prefix' => 'admin', 'middleware' => ['auth']], function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

Route::group(['prefix' => 'api', 'middleware' => ['cors'], 'withoutMiddleware' => ['csrf']], function () {
    Route::get('/users', [UserController::class, 'index']);
});
```

Controller grouping:

```php
Route::group(['prefix' => 'users', 'callback' => UserController::class], function () {
    Route::get('/', 'index')->name('users.index');
    Route::post('/', 'store')->name('users.store');
    Route::get('/{id}', 'show')->name('users.show');
});
```

Resource routes:

```php
Route::resource('/posts', PostController::class, name: 'posts');
```

Resource route method map:

- `GET /posts` -> `index`
- `GET /posts/create` -> `create`
- `POST /posts` -> `store`
- `GET /posts/{id}` -> `show`
- `GET /posts/{id}/edit` -> `edit`
- `PUT/PATCH /posts/{id}` -> `update`
- `DELETE /posts/{id}` -> `destroy`

## Controllers

Generated controller stubs usually extend an app-level `Controller` class. Follow existing app convention.

Example API controller:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controller;
use App\Models\Post;
use Spark\Http\Request;

class PostController extends Controller
{
    public function index(): array
    {
        return [
            'data' => Post::latest()->take(20)->all(),
        ];
    }

    public function show(int $id): array
    {
        $post = Post::findOrFail($id);

        return ['data' => $post];
    }

    public function store(Request $request): \Spark\Http\Response
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $post = Post::create($data);

        return json(['data' => $post], 201);
    }

    public function update(int $id, Request $request): array
    {
        $post = Post::findOrFail($id);
        $post->fill($request->only(['title', 'body']));
        $post->save();

        return ['data' => $post];
    }

    public function destroy(int $id): \Spark\Http\Response
    {
        Post::findOrFail($id)->remove();

        return response('', 204);
    }
}
```

Route callbacks and controller methods can return:

- `Spark\Http\Response`
- string
- integer HTTP status code
- array
- object castable to string
- `Arrayable`

Arrays are JSON encoded by `Response::send()`.

## Requests and Validation

Base request: `Spark\Http\Request`

Form request: `Spark\Foundation\Http\FormRequest`

Form requests validate immediately in the constructor:

```php
<?php

namespace App\Http\Requests;

use Spark\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'published' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'A title is required.',
        ];
    }
}
```

Use in a controller:

```php
public function store(StorePostRequest $request)
{
    $data = $request->validated()->toArray();
}
```

Common validation rules:

- `required`, `required_if`, `required_unless`
- `present`, `filled`, `nullable`
- `email`, `url`
- `string`, `text`, `char`
- `numeric`, `number`, `int`, `integer`
- `array`, `list`
- `min`, `max`, `size`, `between`
- `same`, `confirmed`
- `in`, `not_in`
- `regex`
- `unique`, `exists`, `not_exists`
- `boolean`, `float`, `decimal`
- `alpha`, `alpha_num`, `alpha_dash`
- `digits`, `digits_between`, `min_digits`, `max_digits`
- `date`, `date_format`, `before`, `after`
- `json`, `ip`, `ipv4`, `ipv6`, `mac_address`, `uuid`
- `lowercase`, `uppercase`
- `starts_with`, `ends_with`, `contains`, `not_contains`
- `accepted`, `declined`, `prohibited`
- `file`, `image`, `mimes`
- `password`

Request input helpers:

```php
$request->query('page', 1);
$request->post('email');
$request->input('email');
$request->only(['name', 'email']);
$request->except(['password']);
$request->safe('body', ['p', 'strong']);
$request->input()->boolean('published'); // through validated Input object
```

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
            abort(403, 'Forbidden');
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
Route::get('/limited', fn() => 'ok')->middleware('throttle:60,1,api');
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

## Built-In Middleware Base Classes

### CORS

Extend `Spark\Foundation\Http\Middlewares\CorsAccessControl`.

```php
<?php

namespace App\Http\Middlewares;

use Spark\Foundation\Http\Middlewares\CorsAccessControl;

class Cors extends CorsAccessControl
{
    protected array $config = [
        'origin' => ['https://example.com', 'https://*.example.com'],
        'credentials' => true,
        'age' => 600,
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'headers' => ['Content-Type', 'Authorization', 'X-XSRF-TOKEN'],
    ];
}
```

Behavior:

- Normal requests get CORS headers after the route response.
- Valid preflight returns `204`.
- Invalid preflight returns `403`.
- Wildcard origins such as `https://*.example.com` are supported.
- `credentials => true` reflects concrete origins instead of using `*`.

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

CSRF validates `POST`, `PUT`, `PATCH`, and `DELETE`. It accepts `_token`, `X-CSRF-TOKEN`, or `X-XSRF-TOKEN`. Invalid tokens throw an exception mapped to HTTP 419.

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

## Models

Models extend `Spark\Database\Model`.

```php
<?php

namespace App\Models;

use Spark\Database\Model;

class Post extends Model
{
    protected string $table = 'posts';

    protected array $guarded = [];

    protected array $casts = [
        'published' => 'boolean',
        'meta' => 'array',
        'published_at' => 'datetime',
    ];
}
```

Defaults:

- Table defaults to snake plural class name if not set.
- Primary key defaults to `id`.
- Timestamps are enabled by default with `created_at` and `updated_at`.
- Timestamp columns are date/datetime parsed when timestamps are enabled.

Mass assignment:

- Use `$guarded = []` to allow all fields.
- Use `$fillable = [...]` to allow only specific fields.
- Avoid passing unvalidated request data directly to `create()` or `fill()`.

Create/update:

```php
$post = Post::create([
    'title' => $request->post('title'),
    'body' => $request->post('body'),
]);

$post = Post::findOrFail($id);
$post->fill($request->only(['title', 'body']));
$post->save();

$post->remove();
```

Querying:

```php
$posts = Post::where('status', 'published')
    ->latest()
    ->take(10)
    ->all();

$post = Post::where('slug', $slug)->first();
$post = Post::findOrFail($id);
$exists = Post::where('email', $email)->exists();
```

Casts:

- `int`, `integer`
- `float`, `double`, `real`
- `decimal:2`
- `string`
- `bool`, `boolean`
- `array`, `json`, `object`
- `collection`
- `date`, `datetime`, `timestamp`
- `encrypted`
- `hashed`
- custom cast class implementing `Spark\Database\Contracts\CastsAttributes`

Accessors/mutators:

```php
use Spark\Database\Casts\Attribute;

class User extends Model
{
    public function nameAttribute(): Attribute
    {
        return Attribute::make(
            get: fn($value) => trim((string) $value),
            set: fn($value) => trim((string) $value),
        );
    }
}
```

Relations exist in `Spark\Database\Relation` and through model relation traits. Prefer following existing app examples before inventing relation syntax.

## Query Builder

Use `query($table)` or model static calls.

```php
$users = query('users')
    ->where('active', true)
    ->orderDesc('id')
    ->take(20)
    ->all();

$id = query('users')->insert([
    'name' => 'Jane',
    'email' => 'jane@example.com',
]);

query('users')->where('id', $id)->update(['active' => false]);
query('users')->where('id', $id)->delete();
```

Common methods:

- `table`, `from`, `select`, `selectRaw`, `column`
- `where`, `orWhere`, `whereRaw`, `grouped`
- `whereNull`, `whereIn`, `between`, `like`
- `whereDate`, `whereYear`, `whereMonth`
- JSON helpers
- joins
- `orderBy`, `orderAsc`, `orderDesc`
- `limit`, `offset`, `take`, `skip`
- `first`, `firstOrFail`, `last`, `all`, `get`, `paginate`
- `value`, `pluck`, `count`, `exists`, `notExists`
- `insert`, `bulkUpdate`, `update`, `delete`, `truncate`
- `updateOrInsert`, `increment`, `decrement`
- `toSql`

Prefer builder methods over string SQL. Use `whereRaw` or `raw` only when needed.

## Migrations and Schema

Migration files return an anonymous class with `up()` and `down()`.

```php
<?php

use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;

return new class {
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

Useful blueprint methods:

- `id`, `increments`, `bigIncrements`
- integer variants
- `string`, `char`, `text`, `longText`
- `decimal`, `double`, `float`
- `boolean`, `enum`, `json`
- `date`, `dateTime`, `time`, `timestamp`
- `timestamps`, `nullableTimestamps`, `softDeletes`, `rememberToken`
- `foreignId`, `nullableForeignId`, `foreign`, `constrained`
- `primary`, `unique`, `index`, `fullText`, `spatialIndex`
- `dropColumn`, `dropIndex`, `dropForeign`, `renameColumn`

Column modifiers:

```php
$table->string('email')->unique();
$table->text('body')->nullable();
$table->boolean('active')->default(true);
$table->timestamp('published_at')->nullable();
```

## Auth and Authorization

Auth helper:

```php
auth()->attempt(['email' => $email, 'password' => $password]);
auth()->login($user, remember: true);
auth()->logout();
auth()->check();
auth()->isGuest();
auth()->isLogged();
auth()->user();
auth()->id();
```

Gate:

```php
gate()->define('update-post', function ($user, $post) {
    return $user && $post->user_id === $user->id;
});

if (can('update-post', $post)) {
    // allowed
}

authorize('update-post', $post); // throws AuthorizationException on deny
```

`AuthorizationException` is mapped to HTTP 403.

## Cache

Use:

```php
cache('default')->store('key', $value, '+10 minutes');
$value = cache('default')->retrieve('key');
$value = cache('default')->remember('key', fn() => expensive(), '+10 minutes');
cache('default')->erase('key');
cache('default')->flush();
```

Cache driver is configured by `config('cache.driver')`.

SQLite cache:

- `cache.connections.sqlite.path` can be a directory.
- The cache class creates one sqlite cache file per cache name.

Redis cache:

- Uses `cache.connections.redis`.
- Uses configured prefix.

## Locks

Use locks for critical sections.

```php
lock(name: 'default')->withLock('invoice:' . $invoiceId, function () use ($invoice) {
    // critical work
}, timeout: 10, waitTimeout: 5);
```

Or:

```php
$lock = lock(name: 'default');

if ($lock->lock('report:daily', 30, 5)) {
    try {
        // work
    } finally {
        $lock->unlock('report:daily');
    }
}
```

Lock driver follows cache config.

## Queue and Jobs

Jobs may implement `Spark\Queue\Contracts\JobInterface`.

```php
<?php

namespace App\Jobs;

use Spark\Queue\Contracts\JobInterface;

class SendWelcomeEmail implements JobInterface
{
    public function handle(): void
    {
        // send email
    }
}
```

Dispatch:

```php
job(App\Jobs\SendWelcomeEmail::class)->dispatch('emails');
job(App\Jobs\SyncReports::class)->repeatEveryMinutes(5)->dispatchOnce('reports');
```

In `bootstrap/app.php`, recurring jobs should be registered with `withQueue()` and `pushOnce()` behavior:

```php
->withQueue(
    jobs: [
        job(App\Jobs\SyncReports::class)->repeatEveryMinutes(5),
    ],
    log: true
)
```

Queue driver is configured by `config('queue.driver')`.

Important:

- Use `dispatchOnce()` or `withQueue(jobs: [...])` for scheduler/cron-style repeated jobs.
- Queue has separate config from cache.
- Redis and sqlite drivers should behave consistently for push/pushOnce/work.

## Views

Return views from routes/controllers:

```php
return view('posts.index', ['posts' => $posts]);
```

Blade-like templates live under `resources/views` in many apps.

Common view helpers:

```php
view('template.name', $context);
blade()->render('template.name', $context);
Blade::share('key', $value);
```

Use existing app template style. TinyCore has its own Blade-like compiler, not full Laravel Blade.

## Responses and Redirects

```php
return response('Saved', 200);
return json(['saved' => true], 201);
return redirect('/login');
return to_route('posts.show', ['id' => $post->id]);
return back()->withErrors(['email' => 'Invalid'])->withInput();
return response('', 204);
```

For APIs, returning arrays is acceptable because `Response::send()` JSON encodes arrays.

For explicit JSON status codes, prefer `json($data, $status)`.

## Files and Uploads

Request file helpers:

```php
if ($request->hasFile('avatar')) {
    $file = $request->file('avatar');
    $request->moveFile('avatar', storage_dir('uploads/avatar.jpg'));
}
```

Utilities:

- `uploader()`
- `filemanager()` or `fm()`
- `image()`

Inspect existing app usage before implementing uploads.

## Mail and HTTP Client

Helpers/facades:

```php
mailer();
http();
```

HTTP client classes live under `Spark\Http\Client`.

Mail utility depends on optional `phpmailer/phpmailer`.

## Service Providers

Providers extend `Spark\Foundation\Providers\ServiceProvider`.

```php
<?php

namespace App\Providers;

use Spark\Foundation\Providers\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app()->singleton(App\Services\BillingService::class);
    }

    public function boot(): void
    {
        // boot code
    }
}
```

Register providers in `bootstrap/app.php` through `Application::create(... providers: [...])` or `withApp(providers: [...])`.

## Events

```php
event('order.created', $order);

app()->on('order.created', function ($order) {
    // handle event
});
```

The event dispatcher supports priorities, one-time listeners, dispatch with responses, `until`, and subscriptions.

## Console Commands

Command routes may be loaded through `withRouting(commands: __DIR__ . '/routes/console.php')`.

Use the command registry:

```php
command('reports:sync', [ReportCommand::class, 'handle'])
    ->description('Sync reports');
```

Follow existing app command style.

## Facades

Available facades include:

```php
use Spark\Facades\App;
use Spark\Facades\Auth;
use Spark\Facades\Blade;
use Spark\Facades\Cache;
use Spark\Facades\DB;
use Spark\Facades\Event;
use Spark\Facades\Gate;
use Spark\Facades\Hash;
use Spark\Facades\Http;
use Spark\Facades\Lock;
use Spark\Facades\Mail;
use Spark\Facades\Route;
```

Facades resolve services from the application container. Use them only if the app already uses facade style or it improves clarity.

## Error Handling

Use:

```php
abort(404, 'Post not found');
abort(403, 'Forbidden');
```

Framework mappings:

- route not found -> 404
- not found/item not found -> 404
- authorization failure -> 403
- invalid CSRF -> 419
- too many requests -> 429

Register custom exception handlers with `withExceptions()` if the app uses that style.

## Common Feature Recipe

For a new API resource:

1. Create migration in `database/migrations`.
2. Create model in `app/Models`.
3. Create request class in `app/Http/Requests` if validation is more than trivial.
4. Create controller in `app/Http/Controllers/Api`.
5. Add routes in `routes/api.php`.
6. Add middleware only if needed.
7. Return arrays or `json()` responses for APIs.
8. Use model/query builder APIs, not raw SQL.

Example:

```php
// routes/api.php
use App\Http\Controllers\Api\PostController;

Route::get('/posts', [PostController::class, 'index']);
Route::post('/posts', [PostController::class, 'store']);
Route::get('/posts/{id}', [PostController::class, 'show']);
Route::put('/posts/{id}', [PostController::class, 'update']);
Route::delete('/posts/{id}', [PostController::class, 'destroy']);
```

## Common Mistakes To Avoid

- Do not use Laravel `Route::get()` unless the app has explicitly aliased it. Prefer `route()->get()`.
- Do not import `Illuminate\\*` classes.
- Do not create Laravel `FormRequest`, `Middleware`, `Migration`, or `Model` classes.
- Do not use `artisan`; TinyMVC has its own console command system.
- Do not assume Eloquent relationship syntax is identical. Inspect existing models.
- Do not edit framework/vendor files in an app unless asked.
- Do not bypass config with hardcoded storage paths.
- Do not use raw `$_POST`/`$_GET` in controllers when `Request` helpers are available.
- Do not run non-OPTIONS controller logic for CORS preflight.
- Do not send `Access-Control-Allow-Credentials: false`.
- Do not mix queue config with cache config.

## Verification Checklist For AI Agents

Before finishing changes in a TinyMVC app:

1. Run `php -l` on every changed PHP file.
2. Check route/controller namespaces match the app.
3. Check middleware aliases exist in `bootstrap/middlewares.php`.
4. Check config keys match this file.
5. If changing DB code, verify migration/model/table names.
6. If changing CORS/CSRF/throttle, test normal request and preflight/invalid cases when possible.
7. If changing queue/cache/lock, test sqlite default and consider redis parity.
8. Run `git diff --check`.
9. Mention anything not tested.

## Minimal Mental Model

TinyMVC request flow:

```text
public/index.php
  -> bootstrap/app.php
  -> Application
  -> DotEnv and config cache
  -> providers
  -> Request
  -> Router
  -> Middleware pipeline
  -> controller/callback
  -> Response
```

Use `Spark\\` classes, app namespaces, and the helpers in this file. When unsure, inspect nearby app files and follow the existing TinyMVC pattern.
