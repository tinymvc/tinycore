# Foundation

Contents:

- [What TinyMVC Is](#what-tinymvc-is)
- [Framework Source Lookup Paths](#framework-source-lookup-paths)
- [Typical Application Layout](#typical-application-layout)
- [Core Bootstrap](#core-bootstrap)
- [Configuration](#configuration)
- [Request Lifecycle Summary](#request-lifecycle-summary)

Read this reference for what tinymvc is, typical application layout, core bootstrap, configuration, request lifecycle summary. Source paths and command working directories refer to the application root unless explicitly stated otherwise. Check the installed package when versions differ.

## What TinyMVC / Spark Is

TinyMVC is the vendor; the root framework is known as **TinyMVC / Spark** (or simply **Spark**).

- **Vendor**: `tinymvc` (the vendor namespace for packages like `tinymvc/*` on Packagist and GitHub)
- **Root Framework**: `tinymvc/spark` (Spark) — provides the `Spark\` PHP namespace alias, all Spark facades (`Spark\Facades\*`), Spark contracts, and the `php spark` CLI runner
- **Core Framework / Engine**: `tinymvc/tinycore` (TinyCore) — the underlying core framework package installed at `vendor/tinymvc/tinycore`
- **Application Boilerplate**: `tinymvc/skeleton` (Skeleton) — the starter project and repository where applications are built
- **Core Namespace**: `Spark\`
- **CLI Runner**: `php spark`
- **Minimum PHP**: `8.2`
- **Architecture & Style**: Laravel-like ergonomics with high performance, clean Spark-native APIs
- **Dependency injection**: `Spark\Foundation\Application` extends `Spark\Container`
- **Routing**: `Spark\Http\Routing\Router`
- **HTTP**: `Spark\Http\Request`, `Spark\Http\Response`, `Spark\Http\Middleware`
- **Database**: PDO wrapper, query builder, active-record-like models, schema/migrations
- **Storage utilities**: database, file, or Redis cache, locks, sessions, and queues

Do not assume Laravel classes such as `Illuminate\Http\Request`, `Illuminate\Support\Facades\Route`, `artisan`, Eloquent, or Laravel middleware internals exist. Use the `Spark\` classes and global helpers documented here.

## Framework Source Lookup Paths

In an application project, TinyCore source is normally installed under:

```text
./vendor/tinymvc/tinycore/
```

Resolve the application's installed version from `composer.lock` / Composer installed metadata and read the matching implementation. Source code takes precedence over old examples and docblocks. A separate TinyCore source checkout may differ from the installed package. Locate it explicitly for a core task; do not assume a sibling directory exists or proves the installed version.

Treat installed vendor files as reference during application tasks. If the user requests a core change, edit the actual TinyCore checkout and verify the application's dependency separately. Do not silently change dependency versions or patch vendor code to make an example work.

Core bootstrap and container:

- Application lifecycle: `./vendor/tinymvc/tinycore/src/Foundation/Application.php`
- Application contract: `./vendor/tinymvc/tinycore/src/Contracts/ApplicationContract.php`
- Service container and dependency injection: `./vendor/tinymvc/tinycore/src/Container.php`
- Service provider base class: `./vendor/tinymvc/tinycore/src/Foundation/Providers/ServiceProvider.php`
- Core console provider: `./vendor/tinymvc/tinycore/src/Foundation/Providers/ConsoleServiceProvider.php`
- Environment and config cache: `./vendor/tinymvc/tinycore/src/DotEnv.php`
- Global helpers: `./vendor/tinymvc/tinycore/src/Foundation/helpers.php`

Routing and request lifecycle:

- Router: `./vendor/tinymvc/tinycore/src/Http/Routing/Router.php`
- Route builder: `./vendor/tinymvc/tinycore/src/Http/Routing/Route.php`
- Route groups: `./vendor/tinymvc/tinycore/src/Http/Routing/RouteGroup.php`
- Resource routes: `./vendor/tinymvc/tinycore/src/Http/Routing/RouteResource.php`
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
- Read/write/condition methods: `./vendor/tinymvc/tinycore/src/Database/Query/`
- Soft deletes: `./vendor/tinymvc/tinycore/src/Database/Concerns/InteractsWithSoftDeletes.php`
- ORM, relation subqueries, and pivots: `./vendor/tinymvc/tinycore/src/Database/Concerns/`
- Model callbacks: `./vendor/tinymvc/tinycore/src/Database/Events.php`
- DB facade transactions: `./vendor/tinymvc/tinycore/src/Facades/DB.php`
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

- Cache: `./vendor/tinymvc/tinycore/src/Cache/Cache.php`
- Lock: `./vendor/tinymvc/tinycore/src/Cache/Lock.php`
- Cache and lock contracts: `./vendor/tinymvc/tinycore/src/Cache/Contracts/`
- Cache storage drivers: `./vendor/tinymvc/tinycore/src/Cache/Storage/`
- Queue: `./vendor/tinymvc/tinycore/src/Queue/Queue.php`
- Queue storage drivers: `./vendor/tinymvc/tinycore/src/Queue/Storage/`
- Job wrapper: `./vendor/tinymvc/tinycore/src/Queue/Job.php`
- Class job dispatch trait: `./vendor/tinymvc/tinycore/src/Queue/Dispatchable.php`
- Fluent pending dispatch: `./vendor/tinymvc/tinycore/src/Queue/PendingDispatch.php`
- Job contracts: `./vendor/tinymvc/tinycore/src/Queue/Contracts/`
- Redis connector: `./vendor/tinymvc/tinycore/src/Utils/RedisConnector.php`

Views, console, events, facades, utilities, testing:

- Blade renderer: `./vendor/tinymvc/tinycore/src/View/Blade.php`
- Blade compiler: `./vendor/tinymvc/tinycore/src/View/BladeCompiler.php`
- View attributes: `./vendor/tinymvc/tinycore/src/View/Attributes.php`
- Console runner: `./vendor/tinymvc/tinycore/src/Console/Console.php`
- Command registry: `./vendor/tinymvc/tinycore/src/Console/Commands.php`
- Console stubs: `./vendor/tinymvc/tinycore/src/Foundation/Console/stubs/`
- Migration/pivot generators: `./vendor/tinymvc/tinycore/src/Foundation/Console/MakeStubCommandsHandler.php`
- Event dispatcher: `./vendor/tinymvc/tinycore/src/Events.php`
- Facade base class: `./vendor/tinymvc/tinycore/src/Facades/Facade.php`
- All facades: `./vendor/tinymvc/tinycore/src/Facades/`
- Carbon-like date utility: `./vendor/tinymvc/tinycore/src/Carbon.php`
- Mail utility: `./vendor/tinymvc/tinycore/src/Utils/Mail.php`
- HTTP client: `./vendor/tinymvc/tinycore/src/Http/Client/`
- Upload/file/image utilities: `./vendor/tinymvc/tinycore/src/Storage/Uploader.php`, `./vendor/tinymvc/tinycore/src/Utils/File.php`, `./vendor/tinymvc/tinycore/src/Utils/Image.php`
- Tracer/debugging: `./vendor/tinymvc/tinycore/src/Tracer.php`
- Vite integration: `./vendor/tinymvc/tinycore/src/Utils/Vite.php`
- Unit/Feature Testing: `vendor/tinymvc/tinycore/src/Testing/ApplicationTestCase.php`, `vendor/tinymvc/tinycore/src/Testing/Assert.php`, `vendor/tinymvc/tinycore/src/Testing/TestCase.php`

## Typical Application Layout

Actual apps may vary, but common TinyMVC app layout is:

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
  app.php
  auth.php
  cache.php
  cors.php
  database.php
  mail.php
  queue.php
  session.php
  storage.php
database/
  migrations/
public/
  index.php
resources/
  views/
  languages/
routes/
  web.php
  api.php
  webhook.php
  console.php
storage/
  app/public
  app/private
  framework/
  logs/
  temp/
tests/
```

Always verify the actual project before creating files.

## Core Bootstrap

TinyMVC apps usually bootstrap the framework through `Spark\Foundation\Application`.

Example shape:

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
11. `Application::terminate()` finishes the client response and runs callbacks registered with `defer()`.

Post-response work:

```php
defer(function () use ($userId) {
    app(App\Services\Analytics::class)->trackSignup($userId);
});

app()->defer(function (App\Services\AuditLog $audit) use ($order) {
    $audit->recordOrderViewed($order->id);
});
```

Use `defer()` for small post-response tasks such as audit logging, analytics, cleanup, or lightweight notifications. Deferred callbacks are invoked through the container, so type-hinted dependencies can be injected. They run after the response is sent, in registration order. A deferred callback may register another deferred callback; it will run in the same termination cycle after the callbacks that were already in the queue.

Important defer notes:

- Deferred callbacks are not a replacement for durable queues; use Queue jobs for work that must survive process crashes, timeouts, or worker restarts.
- `Application::terminate()` calls `fastcgi_finish_request()` or `litespeed_finish_request()` when available, otherwise it flushes output buffers.
- `defer()` registers a shutdown fallback so callbacks can still run when code sends a response and exits early.
- Exceptions thrown by deferred callbacks are reported/logged and do not stop later deferred callbacks.
- In debug mode, `app:terminated` is dispatched during application termination.

## Configuration

Config files return PHP arrays. Use `config('key.path')` and `env('KEY', $default)`.

### Database Config

Expected shape:

```php
<?php

return [
    // Default database connection name
    'default' => env('DB_CONNECTION', 'sqlite'),

    // Database connections for different drivers
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'file' => dirname(__DIR__) . '/database/sqlite.db',
        ],
        'default' => [
            // 'driver' => 'mysql', // auto detected from env('DB_CONNECTION')
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

- If `connections[<default>]` exists, that entry is used. Its `driver` key wins; when omitted, the connection name is used as the driver if it is a PDO driver name (`mysql`, `pgsql`, `sqlite`, ...), otherwise `mysql`.
- If no entry has that name but the name is a PDO driver (for example `DB_CONNECTION=mysql` or `pgsql`), `connections.default` is used with that driver. This is how the skeleton's driver-less `default` entry works.
- Any other undefined name throws `InvalidDatabaseConfigException` ("Undefined database connection").
- `DB::connection('name')` reads `database.connections.name` directly. A driver-less entry uses its own name when that is a PDO driver, otherwise the driver of `database.default`.

Older 4.0 config files that still use the top-level `driver` key (or `default_connection`) keep working as a fallback; rename it to `default`.

### Cache, lock, queue, and session config

Spark 4.0 uses `database`, `file`, or `redis` for cache/locks and queue storage. The default is `database`; `sqlite` remains a database connection type, not a storage-driver name. Session storage has its own `config/session.php`, defaulting to `database`. Unknown drivers raise an exception.

The default store/connection is selected by name:

| Config | Key | Env | Skeleton default |
| --- | --- | --- | --- |
| `config/database.php` | `default` | `DB_CONNECTION` | `sqlite` |
| `config/cache.php` (cache and locks) | `default` | `CACHE_STORE` | `database` |
| `config/queue.php` | `default` | `QUEUE_CONNECTION` | `database` |

`cache.default` and `queue.default` name an entry in their `connections` array. That entry's `driver` (`database`, `file`, or `redis`) selects the backend; when `driver` is omitted, the entry name is used. A built-in name without an entry uses that driver's defaults; any other undefined name throws `InvalidArgumentException`. Older 4.0 configs with a top-level `driver` key are still read as a fallback; rename it to `default`.

Database tables are created by migrations, not by storage constructors. Run the framework migration before using the database drivers. Connections default to the application database; use `cache.connections.database.connection`, `lock_connection`, `queue.connections.database.connection`, and `session.connections.database.connection` for named connections. Configurable table names default to `caches`, `locks`, `jobs`, `failed_jobs`, and `sessions`.

File paths in the skeleton:

- Cache: `storage/framework/temp/cache`; locks: `storage/framework/temp/locks`.
- Queue: `storage/framework/queue.d`; sessions: `storage/framework/sessions`.
- Private files: `storage/app/private`; public uploads: `storage/app/public`.
- Temporary files: `storage/framework/temp`.

Redis connections use the shared connector options: host, port, password, database, prefix, socket, timeout, read_timeout, and persistent. Require `ext-redis`, distinct environment prefixes, and a deliberate durability policy. File drivers require a local filesystem with working locks and atomic rename; do not use NFS/SMB. Use database/Redis for shared deployments.

### Session configuration

`session.handler` selects `database`, `file`, or `redis`. `session.lifetime` is idle lifetime in minutes, default 120; `expire_on_close` controls cookie lifetime. `cookie_name` defaults to `spark_session`; `cookie_settings` uses `path`, `domain`, `secure`, `http_only`, and `same_site`. Set `secure=true` behind a TLS-terminating proxy. Unknown IDs are rejected by strict session validation.

The file handler serializes requests for the same session until close. Database and Redis session writes are atomic but do not lock a whole request; avoid concurrent read-modify-write updates to session data or coordinate them explicitly. The testing harness uses in-memory session state; use real HTTP tests when changing native session behavior.

## Request Lifecycle Summary

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
