# Dependency injection, events, and pipelines

> **Read when:** binding services, observing events/telemetry, isolating request state, or staging transformations.
> **Related:** [Application patterns](application-patterns.md) · [Extensions and errors](extensions-errors.md) · [Advanced workflows](advanced-workflows.md)
> Source paths are relative to the installed TinyCore `src/`.

Contents:

- [Spark vs Laravel habits](#spark-vs-laravel-habits)
- [Resolve and bind dependencies](#resolve-and-bind-dependencies)
- [Standard service interfaces](#standard-service-interfaces)
- [Events and payload shape](#events-and-payload-shape)
- [Runtime telemetry](#runtime-telemetry)
- [Staged transformations](#staged-transformations)

## Spark vs Laravel habits

| Intent | Spark | Avoid |
| --- | --- | --- |
| Contextual binding | `app()->when(concrete:, needs:, give:)` (three arguments) | `when()->needs()->give()` |
| Binding | `bind()` / `singleton()` / `instance()`; not a fluent builder | Laravel fluent binding methods |
| Alias | `alias($alias, $abstract)` | Reversed arguments |
| Event payload | `event('order.created', [$order])`; an array payload is an argument list | Passing an array record and expecting it as one argument |
| Object events | PSR-14: `app()->on(OrderPlaced::class, $listener)`; `event(new OrderPlaced($order))` | String-event APIs (`until()`, response aggregation) |
| Pipeline concurrency | `async()` returns a Generator | Assuming parallel execution |

## Resolve and bind dependencies

The application extends `Spark\Container`. `app(Service::class)` resolves concrete classes with resolvable constructor dependencies. Bind interfaces and scalar configuration in a provider's `register()`; use `boot()` for setup that needs registered services.

```php
app()->bind(
    App\Contracts\Catalog::class,
    App\Services\RemoteCatalog::class,
);

app()->singleton(App\Services\RemoteCatalog::class, function () {
    return new App\Services\RemoteCatalog(config('services.catalog_url'));
});
```

These are application-defined types; the factory must match the actual constructor.

- `bind()` creates a transient binding and clears an existing shared instance. `singleton()` shares the resolved instance. `instance($abstract, $object)` installs an existing value.
- Contextual binding:

  ```php
  app()->when(
      concrete: App\Services\ExportService::class,
      needs: App\Contracts\Catalog::class,
      give: App\Services\ArchiveCatalog::class,
  );
  ```

- `make($class, $parameters)` and `call($callback, $parameters, $bindingFields)` provide explicit construction/action parameters; inspect `Container.php` before mixing route scalars with injected types.
- Callable factories and `call()` accept invokable objects and static callables. Named arguments take precedence; remaining integer-keyed values fill positional parameters, including when names and positions are mixed. Supplied positional objects matching a declared class type keep their identity; omitted class dependencies resolve through the container. Variadic parameters accept remaining positional values, or an array under their parameter name (including an empty list).
- Diagnose resolution failures by tracing imports/autoloading, constructor arguments, interface bindings, provider registration, and circular dependencies. Use `bound()` / `resolved()` for diagnostics. `forgetInstance()` drops a shared instance but does not replace references another object already captured. Avoid broad `flush()` during a request.
- Do not capture current users, tenants, requests, or open transactions in long-lived services; pass operation-specific values to methods. Bind a test fake before the consumer is resolved. When changing shared state, verify two distinct users/jobs in the same process.

## Standard service interfaces

- Native contracts for Queue, Translator, DB, Blade, Hash, Events, Router, Middleware, and the registered HTTP services resolve their existing shared instances. `ApplicationContract` and `ContainerContract` resolve the application.
- Contract bindings are lazy and follow replacement request/service instances; they do not create a second singleton. Rebind a concrete service to replace its helper/facade access too, or bind only its contract to replace that interface's consumers.
- HTTP service registrations and short aliases (`request`, `response`, `session`, `auth`, `gate`, `inputErrors`, `vite`) exist in CLI as well as web. Registration neither captures a request nor starts a session; services are built only when resolved. Native session startup remains SAPI-dependent, and PSR hosts own session/transport integration.
- `ContainerContract` extends PSR-11. `has()` follows aliases; resolving a missing entry throws `Psr\Container\NotFoundExceptionInterface`, and container resolution exceptions implement the matching PSR-11 interfaces. `app(Psr\Container\ContainerInterface::class)` returns the current application.
- **Logging:** inject `Psr\Log\LoggerInterface`. The default `Spark\Logger` writes through Tracer, supports `{name}` placeholders, and rejects unknown levels. `Spark\Facades\Log` forwards to that binding, so replacing the PSR logger also replaces facade logging. `Spark\Contracts\LoggerInterface` is an instance interface extending PSR-3; call the facade for static logging.
- **Clock:** inject `Psr\Clock\ClockInterface` or resolve `app('clock')` for a `DateTimeImmutable` in the configured application timezone. Use `new Spark\Support\Clock('UTC')` for an explicit timezone. Carbon exports independent dates through `toDateTime()` and `toDateTimeImmutable()`.

## Events and payload shape

`event($name, $payload = [], $halt = false)` dispatches synchronously. Register listeners through `app()->on()` or `Spark\Events` as the application does.

- An array payload is an argument list. Wrap a single array or object argument (`event('order.created', [$order])`) rather than accidentally spreading its fields.
- Higher numeric priorities run first. For string events, `dispatch()` stops when a listener returns `false` or calls `halt()`. Nested dispatches keep separate halt state: an inner event cannot stop or resume its outer event. `until()` returns the first non-null response, including `false`. Inspect `Events.php` and `Foundation/helpers.php` before choosing response aggregation or one-time listeners.
- String-event patterns support `*` anywhere (`order.*`, `app:http.*`, `*`); it matches zero or more characters, including dots/colons. Other characters are literal. Matching exact/pattern listeners share descending priority order and work with `dispatch()`, `dispatchWithResponse()`, `until()`, and `once()`. Wildcards receive the event name followed by the original arguments: `app()->on('order.*', fn ($event, ...$payload) => ...);`. Nested events still reach matching listeners. `hasListeners()` matches patterns; inspection/removal methods address the registered name/pattern itself.
- Object events use PSR-14. Listeners register by class, parent class, or interface. Dispatch returns the same event, ignores listener return values, and propagates exceptions unchanged in every environment. Only `StoppableEventInterface::isPropagationStopped()` stops object dispatch. Higher priorities run first across all matching types.
- `Psr\EventDispatcher\EventDispatcherInterface` and `ListenerProviderInterface` both resolve to the application's `Spark\Events` instance.
- Ordinary string-listener failures are logged; debug mode rethrows them. Object-event exceptions always propagate.

An event is not a durable job or an after-commit boundary, and a listener that writes to another service cannot roll back with SQL. Put external effects behind the application's explicit commit/dispatch policy ([advanced workflows](advanced-workflows.md#commit-dispatch-and-external-effects)), and test listener failure and repeated delivery where relevant.

## Runtime telemetry

`Spark\Telemetry` and its `Spark\Facades\Telemetry` facade share `emit()`, `emitIf()`, `listen()`, `isListening()`, `enable()`, and `disable()`. Names receive the `app:` prefix once. Register observers before the activity you need; there is no replay. Observe booting in registration/bootstrap, since provider boot methods run after it.

```php
use Spark\Telemetry;

Telemetry::listen('http.*', function (string $event, string $url, array $payload, array $response, float $durationMs) {
    // Record permitted fields in your application's observer.
});
Telemetry::emit('orders.checked', fn() => ['count' => $count]);
```

- Closure arguments resolve only with a matching listener or debug mode. `emit()` keeps each argument intact: a closure returning an array supplies **one** array argument. `emitIf()` also skips its lazy condition when inactive. Wrap a Closure-valued payload as `fn() => $callback` so the callback itself is not executed. No application means no emission.
- `listen('*', ...)` observes `app:*`; use Events `'*'` to include ordinary application string events. Wildcards prepend the full event name and spread the original arguments; they do not wrap all arguments into a second array.
- Observer/lazy-payload failures are logged without changing application success, retry, or error handling. Telemetry emitted while an observer is active is suppressed to avoid recursive exports; normal application Events remain synchronous and can recurse.
- Payloads may contain credentials, user data, query bindings, model attributes, and bodies. Select/redact fields before export; bound retained data in long-running workers. This is observation, not a durable audit log or a transaction boundary.

| Activity | Events / payload |
| --- | --- |
| Application | `booting`, `booted`, `terminated` (no arguments); `request.handled` metadata with request, response, duration_ms, memory_peak, start_time |
| Routing | `routeMatched` (route array values as positional arguments), `routeFallback`, `routeDispatched`; `middlewaresHandled` (stack values) |
| Queries | `db.queryExecuted`: builder `(query, time, bindings, memory_before, connection)`; direct DB `(query, time, memory_before, connection)` |
| Views/languages | `bladeTemplateRendered(path, time, memory_before, data_keys)`; `translator.loadedLanguageFile(file, load_time, memory_before)` |
| HTTP | `http.request(url, payload, response, duration_ms, error)`; one final single-request event after retries, one per completed pooled transfer |
| Cache | `cache.hit`, `cache.miss` (one per requested key, including null/false hits), `cache.written`, `cache.forgotten` |
| Models/gates | `model.action` (action/model/key/table/attributes/changes); `gate.checked` (ability/result/arguments/user) |
| Jobs | `job.pushed` (enqueue call, including deduplicated pushOnce), `job.processing`, `job.processed`, `job.failed` (attempt failure) |
| Mail/logs/errors | `mail.sent`, `mail.failed`, `log.logged`, `exception.occurred` (unexpected/custom-handled request exceptions or Tracer exceptions) |
| Commands | `command.starting`, `command.finished` (name/args/exit_code/duration_ms) |

Except for the positional legacy hooks above, data-bearing events supply one metadata array. Timings are milliseconds; peak memory is process-wide. Hooks cover framework operations: raw PDO/cURL/filesystem calls, direct storage-driver use, and synchronous jobs do not automatically gain every service/worker event.

## Staged transformations

Use `Spark\Pipeline` for a meaningful sequence of independently testable transformations; a direct method is enough for a short operation.

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

- A pipe implements `Spark\Contracts\PipeInterface::handle($payload, Closure $next): mixed` or another supported callable form. Return `$next($payload)` to continue; returning without calling it deliberately short-circuits.
- `through()` takes variadic pipes. `withContext()` supplies shared context. `middleware()` wraps execution.
- `onError($handler, stopOnError: true)` receives the exception, payload, and context. Define the error response explicitly; never convert partially completed persistence into success.
- A pipeline provides neither transactions nor rollback. `async()` returns a Generator, not parallel execution. Inspect `Pipeline.php` before reusing or resetting a configured instance.

Verify pipe order, normalization, early termination, exceptions, and whether a failed stage leaves persisted effects. Use [concurrency](concurrency.md) for independent fan-out and [queues](queues.md) for durable work.
