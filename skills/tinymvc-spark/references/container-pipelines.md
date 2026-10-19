# Dependency injection, events, and pipelines

Read for multi-service features, replaceable integrations, request/job isolation, and staged imports. Source paths below are relative to `vendor/tinymvc/tinycore/src`. Pair this with [application patterns](application-patterns.md), [extensions](extensions-errors.md), and [advanced workflows](advanced-workflows.md).

## Resolve and bind dependencies

The application extends `Spark\Container`. `app(Service::class)` resolves concrete classes with resolvable constructor dependencies. Bind interfaces and scalar configuration in a provider's `register()`; use `boot()` for setup that needs registered services.

```php
app()->bind(
    App\Contracts\Catalog::class,
    App\Services\RemoteCatalog::class,
);

app()->singleton(App\Services\RemoteCatalog::class, function ($container) {
    return new App\Services\RemoteCatalog(config('services.catalog_url'));
});
```

These are application-defined types; the factory must match the actual constructor. `bind()` creates a transient binding and clears an existing shared instance; `singleton()` shares the resolved instance. `instance($abstract, $object)` installs an existing value. Methods are not a Laravel fluent binding builder.

Contextual binding uses three arguments:

```php
app()->when(
    concrete: App\Services\ExportService::class,
    needs: App\Contracts\Catalog::class,
    give: App\Services\ArchiveCatalog::class,
);
```

Do not use `when()->needs()->give()`. Aliases use `alias($alias, $abstract)` in that order. `make($class, $parameters)` and `call($callback, $parameters, $bindingFields)` provide explicit construction/action parameters; inspect `Container.php` before mixing route scalars with injected types.

For resolution failures, trace imports/autoloading, constructor arguments, interface binding, provider registration, and circular dependencies. Use `bound()` / `resolved()` for diagnostics. `forgetInstance()` drops a shared instance; it does not replace references already captured by another object. Avoid broad `flush()` during a request.

Do not capture current users, tenants, requests, or open transactions in long-lived services. Pass operation-specific values to methods. A test fake must be bound before the consumer is resolved. Verify two distinct users/jobs in the same process when changing shared state.

## Events and payload shape

`event($name, $payload = [], $halt = false)` dispatches synchronously. An array payload is an argument list, so use `event('order.created', [$order])` for a single array/object argument and wrap an array record rather than accidentally spreading its fields. Register listeners through `app()->on()` or `Spark\Events` as the application does.

Higher numeric priorities run first. Ordinary `dispatch()` stops on a listener returning `false` or calling `halt()`. `until()` returns the first non-null response, including false. Inspect `Events.php` and `Foundation/helpers.php` before choosing response aggregation or one-time listeners.

An event does not establish a durable job or an after-commit boundary. A listener that writes to another service cannot roll back with SQL. Put external effects behind the application's explicit commit/dispatch policy; test listener failure and repeated delivery where relevant.

## Staged transformations

Use `Spark\Pipeline` for a meaningful sequence of independently testable transformations. A direct method is sufficient for a short operation.

```php
use Spark\Pipeline;

$result = Pipeline::make($validated)
    ->through(
        function ($payload, $next) {
            $payload['name'] = trim($payload['name']);

            return $next($payload);
        },
        App\Pipes\NormalizeImport::class,
    )
    ->thenReturn();
```

The application pipe implements `Spark\Contracts\PipeInterface::handle($payload, Closure $next): mixed` or another supported callable form. Return `$next($payload)` to continue; returning without calling it deliberately short-circuits. `through()` takes variadic pipes. `withContext()` supplies shared context, and `middleware()` wraps execution.

`onError($handler, stopOnError: true)` receives exception, payload, and context. Define the error response explicitly; do not convert partially completed persistence into success. A pipeline provides neither transactions nor rollback. `async()` returns a Generator, not parallel execution. Inspect `Pipeline.php` before reusing/resetting a configured instance.

Verify pipe order, normalization, early termination, exceptions, and whether a failed stage leaves persisted effects. Use [concurrency](concurrency.md) for independent fan-out and [queues](queues.md) for durable work.
