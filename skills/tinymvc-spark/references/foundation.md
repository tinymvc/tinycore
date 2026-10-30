# Foundation

> **Read when:** you need to know what TinyMVC / Spark is, where framework source lives, the typical app layout, bootstrap and request lifecycle, `defer()`, or configuration (env, database, cache, queue, session).
> **Related:** [API discovery](api-discovery.md) · [Feature map](native-feature-map.md) · [Helpers](helpers.md)
> Paths are relative to the application root unless stated. The installed package is authoritative when versions differ.

Contents:

- [What TinyMVC / Spark is](#what-tinymvc--spark-is)
- [Framework source lookup](#framework-source-lookup)
- [Typical application layout](#typical-application-layout)
- [Bootstrap and request lifecycle](#bootstrap-and-request-lifecycle)
- [Post-response work with defer()](#post-response-work-with-defer)
- [Configuration](#configuration)
- [PSR application entry point](#psr-application-entry-point)

## What TinyMVC / Spark is

TinyMVC is the vendor; the root framework is **TinyMVC / Spark** (or simply **Spark**). Laravel-like ergonomics, Spark-native APIs.

| Piece | Package / name | Role |
| --- | --- | --- |
| Vendor | `tinymvc` | Vendor namespace for `tinymvc/*` on Packagist and GitHub |
| Root framework | `tinymvc/spark` | `Spark\` namespace, facades (`Spark\Facades\*`), contracts, `php spark` CLI |
| Core engine | `tinymvc/tinycore` | Core package installed at `vendor/tinymvc/tinycore` |
| App boilerplate | `tinymvc/skeleton` | Starter project applications are built from |

Key facts:

- Minimum PHP **8.2**; CLI runner is `php spark`.
- Container: `Spark\Foundation\Application` extends `Spark\Container`.
- Routing: `Spark\Http\Routing\Router`. HTTP: `Spark\Http\Request`, `Response`, `Middleware`.
- Database: PDO wrapper, query builder, active-record-style models, schema/migrations.
- Cache, locks, queues and sessions each support `database`, `file`, or `redis` backends.

> **Do not assume Laravel classes exist** (`Illuminate\Http\Request`, `Illuminate\Support\Facades\Route`, `artisan`, Eloquent, Laravel middleware internals). Use `Spark\` classes and the global helpers in [helpers](helpers.md).

## Framework source lookup

In an application, TinyCore is normally at `vendor/tinymvc/tinycore/`. In the lists below, `src/` means `vendor/tinymvc/tinycore/src/`.

Resolve the installed version from `composer.lock` / Composer installed metadata and read the matching implementation. Source beats old examples and docblocks. A separate TinyCore checkout may differ from the installed package: locate it explicitly for core tasks, never assume a sibling directory exists or proves the installed version, and never patch vendor code. For bounded declaration lookup use [API discovery](api-discovery.md); for subsystem locations use the [feature map](native-feature-map.md).

**Bootstrap and container**

- `src/Foundation/Application.php`: application lifecycle
- `src/Contracts/ApplicationContract.php`: application contract
- `src/Container.php`: service container / DI
- `src/Foundation/Providers/ServiceProvider.php`: provider base class
- `src/Foundation/Providers/ConsoleServiceProvider.php`: core console provider
- `src/Utils/DotEnv.php`: environment loading
- `src/Utils/Configuration.php`: config discovery and cache
- `src/Foundation/helpers.php`: global helpers

**Routing, request, response**

- `src/Http/Routing/Router.php`, `Route.php` (builder), `RouteGroup.php`, `RouteResource.php`
- `src/Facades/Route.php`
- `src/Http/Request.php`, `Response.php`, `Middleware.php` (pipeline)

**Built-in middleware**

- `src/Foundation/Http/Middlewares/CorsAccessControl.php`, `CsrfProtection.php`, `ThrottleIncomingRequests.php`
- `src/Contracts/Http/MiddlewareInterface.php`

**Validation, auth, session**

- `src/Foundation/Http/FormRequest.php`
- `src/Http/Validator.php`, `Input.php` (validated input wrapper), `InputErrors.php`
- `src/Http/Auth.php`, `Gate.php`, `Session.php`

**Database, ORM, migrations**

- `src/Database/DB.php` (PDO wrapper), `QueryBuilder.php`, `Model.php`, `Events.php` (model callbacks)
- `src/Database/Query/`: read/write/condition methods
- `src/Database/Concerns/`: ORM, relation subqueries, pivots; `InteractsWithSoftDeletes.php`; `InteractsWithTracking.php` (change detection)
- `src/Database/Casts/Castable.php` (casts trait), `Attribute.php` (attribute cast helper)
- `src/Database/Relation/`: relations
- `src/Database/Migration.php`; `src/Database/Schema/Schema.php`, `Blueprint.php`, `Column.php`, `Grammar.php`
- `src/Facades/DB.php`: facade and transactions

**Cache, lock, queue, Redis**

- `src/Cache/Cache.php`, `LockHandle.php`, `Contracts/`, `Storage/` (drivers)
- `src/Queue/Queue.php`, `Job.php`, `Dispatchable.php` (class-job dispatch trait), `PendingDispatch.php` (fluent dispatch), `Contracts/`, `Storage/` (drivers)
- `src/Utils/RedisConnector.php`

**Views, console, events, utilities, testing**

- `src/View/Blade.php`, `BladeCompiler.php`, `Attributes.php`
- `src/Console/Console.php` (runner), `Commands.php` (registry)
- `src/Foundation/Console/stubs/`; `src/Foundation/Console/MakeStubCommandsHandler.php` (migration/pivot generators)
- `src/Events.php` (dispatcher); `src/Facades/Facade.php` (base) and `src/Facades/` (all facades)
- `src/Carbon.php`, `src/Utils/Mail.php`, `src/Http/Client/`
- `src/Storage/Uploader.php`, `src/Utils/File.php`, `src/Utils/Image.php`
- `src/Utils/Tracer.php` (debugging), `src/Utils/Vite.php`
- `src/Testing/ApplicationTestCase.php`, `Assert.php`, `TestCase.php`

## Typical application layout

Apps vary; always verify the actual project before creating files.

```text
app/
  Http/
    Controllers/
    Middlewares/
    Requests/
    Resources/
  Models/
  Providers/
  Jobs/
  Services/
bootstrap/
  app.php
  middlewares.php
  providers.php
  helpers.php
config/
  app.php  auth.php  cache.php  cors.php  database.php
  mail.php  queue.php  session.php  storage.php
database/
  migrations/
public/
  index.php
resources/
  views/
  languages/
routes/
  web.php  api.php  webhook.php  console.php
storage/
  app/public
  app/private
  framework/
  logs/
  temp/
tests/
```

## Bootstrap and request lifecycle

Apps bootstrap through `Spark\Foundation\Application`:

```php
<?php

use Spark\Foundation\Application;

return Application::create(path: dirname(__DIR__))
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

Request flow:

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

Detailed order:

1. `Application` sets `Application::$app`.
2. `.env` is loaded and cached.
3. Core services are registered.
4. Config is discovered and cached.
5. Providers register and boot.
6. Router dispatches the current `Request`.
7. Middleware wraps the matched route.
8. The route callback/controller returns a value.
9. Router converts it to a `Response`.
10. `Response::send()` sends headers and body.
11. `Application::terminate()` finishes the client response and runs callbacks registered with `defer()`.

When unsure, inspect nearby app files and follow the existing pattern.

## Post-response work with defer()

```php
defer(function () use ($userId) {
    app(App\Services\Analytics::class)->trackSignup($userId);
});

app()->defer(function (App\Services\AuditLog $audit) use ($order) {
    $audit->recordOrderViewed($order->id);
});
```

Use `defer()` for small post-response tasks: audit logging, analytics, cleanup, lightweight notifications. Callbacks are invoked through the container (type-hinted dependencies are injected) and run after the response is sent, in registration order. A deferred callback may register another; it runs in the same termination cycle after callbacks already queued.

- **Not durable.** Use Queue jobs for work that must survive crashes, timeouts, or worker restarts.
- `Application::terminate()` calls `fastcgi_finish_request()` or `litespeed_finish_request()` when available; otherwise it flushes output buffers. A host-owned non-removable output buffer does not block deferred callbacks.
- One shutdown fallback is registered, so termination and deferred callbacks also run after an early exit.
- Exceptions in deferred callbacks are reported/logged and do not stop later callbacks.
- In debug mode, `app:terminated` is dispatched during termination.

## Configuration

Config files return PHP arrays. Read with `config('key.path')` and `env('KEY', $default)`.

- `Configuration::discover($folder, $cache, $env, $useCache)` loads config files; `DotEnv::discoverConfig()` forwards to it for existing callers.
- Quoted env values stay strings, including when followed by a whitespace-separated comment (`CODE="123" # text`). Hashes inside quotes are preserved. An empty or comment-only `.env` produces a valid empty environment cache.
- Environment and config caches use atomic file replacement: concurrent readers see a complete old or new cache.
- URL helpers build values from the current context rather than a process-global root URL.

### Database config

```php
<?php

use Spark\Database\Contracts\DatabaseDriver;

return [
    // Default database connection name
    'default' => env('DB_CONNECTION', 'sqlite'),

    // Database connections for different drivers
    'connections' => [
        'sqlite' => [
            'driver' => DatabaseDriver::DRIVER_SQLITE,
            'file' => dirname(__DIR__) . '/database/sqlite.db',
        ],
        'default' => [
            // 'driver' => DatabaseDriver::DRIVER_MYSQL, // auto detected from env('DB_CONNECTION')
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'name' => env('DB_DATABASE', 'spark'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
        ],
    ],
];
```

`database.default` is a **connection name**, not a driver. Resolution:

- If `connections[<default>]` exists, that entry is used. Its `driver` key wins; if omitted, the connection name is used as the driver when it is a PDO driver name (`mysql`, `pgsql`, `sqlite`, ...), otherwise `mysql`.
- If no entry has that name but the name is a PDO driver (e.g. `DB_CONNECTION=mysql` or `pgsql`), `connections.default` is used with that driver. This is how the skeleton's driver-less `default` entry works.
- Any other undefined name throws `InvalidDatabaseConfigException` ("Undefined database connection").
- `DB::connection('name')` reads `database.connections.name` directly. A driver-less entry uses its own name when that is a PDO driver, otherwise the driver of `database.default`.
- Older 4.0 configs with a top-level `driver` key (or `default_connection`) still work as a fallback; rename to `default`.

### Cache, lock, queue, and session config

Spark 4.0 uses `database`, `file`, or `redis` for cache/locks and queue storage. The default is `database`; `sqlite` is a database connection type, not a storage-driver name. Unknown drivers raise an exception.

| Config | Key | Env | Skeleton default |
| --- | --- | --- | --- |
| `config/database.php` | `default` | `DB_CONNECTION` | `sqlite` |
| `config/cache.php` (cache and locks) | `default` | `CACHE_STORE` | `database` |
| `config/queue.php` | `default` | `QUEUE_CONNECTION` | `database` |

- `cache.default` and `queue.default` name an entry in their `connections` array. That entry's `driver` (`database`, `file`, `redis`) selects the backend; when omitted, the entry name is used. A built-in name without an entry uses that driver's defaults; any other undefined name throws `InvalidArgumentException`. Older configs with a top-level `driver` key are still read as a fallback; rename to `default`.
- Database tables come from migrations, not storage constructors: run the framework migration before using database drivers. Default tables: `caches`, `locks`, `jobs`, `failed_jobs`, `sessions` (configurable).
- Drivers use the application database unless you set `cache.connections.database.connection`, `lock_connection`, `queue.connections.database.connection`, or `session.connections.database.connection`.

**Skeleton file paths**

| Purpose | Path |
| --- | --- |
| Cache | `storage/framework/temp/cache` |
| Locks | `storage/framework/temp/locks` |
| Queue | `storage/framework/queue.d` |
| Sessions | `storage/framework/sessions` |
| Private files | `storage/app/private` |
| Public uploads | `storage/app/public` |
| Temporary files | `storage/framework/temp` |

**Redis** connections use the shared connector options: host, port, password, database, prefix, socket, timeout, read_timeout, persistent. Require `ext-redis`, distinct environment prefixes, and a deliberate durability policy.

**File drivers** need a local filesystem with working locks and atomic rename. Do not use NFS/SMB; use database/Redis for shared deployments.

### Session config (`config/session.php`)

- `handler`: `database` (default), `file`, or `redis`.
- `lifetime`: idle lifetime in minutes (default 120). `expire_on_close` controls cookie lifetime.
- `cookie_name` defaults to `spark_session`. `cookie_settings`: `path`, `domain`, `secure`, `http_only`, `same_site`. Set `secure=true` behind a TLS-terminating proxy.
- Unknown session IDs are rejected by strict validation.
- The file handler serializes requests for the same session until close. Database and Redis writes are atomic but do not lock the whole request: avoid concurrent read-modify-write on session data or coordinate explicitly.
- The testing harness uses in-memory session state; use real HTTP tests when changing native session behavior.

## PSR application entry point

`Application::handle()` accepts a native `Request` or a PSR `ServerRequestInterface` and returns a native response without emitting it. `handlePsr()` returns a PSR response. `app(Psr\Http\Server\RequestHandlerInterface::class)` adapts it to PSR-15.

```php
$response = app()->handlePsr($serverRequest);
// The embedding host emits $response using its own emitter.
app()->terminate(); // After emission; safe to call once more during shutdown.
```

- Incoming PSR requests do not replace PHP globals; the host owns transport and session integration.
- Native `run()` still captures, handles, emits, and terminates.
- Request-dependent `input` and `ServerRequestInterface` bindings resolve the current request lazily.