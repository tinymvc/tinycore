# Extensions and Errors

> **Read when:** adding service providers, events, console commands, or custom error handling.
> **Related:** [Foundation](foundation.md) · [Helpers](helpers.md) · [Advanced workflows](advanced-workflows.md#request-lifecycle-and-extension-boundaries)
> Paths and command working directories are relative to the application root. Check the installed package when versions differ.

## Service providers

Providers extend `Spark\Foundation\Providers\ServiceProvider`.

```php
<?php

namespace App\Providers;

use Spark\Foundation\Providers\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app()->singleton(\App\Services\BillingService::class);
    }

    public function boot(): void
    {
        // boot code
    }
}
```

Register providers in `bootstrap/app.php` via `Application::create(... providers: [...])` or `withApp(providers: [...])`. Adapter packages (FireLine, Inertia) register theirs in `bootstrap/providers.php`; preserve the existing array when adding one.

Do not store a request/user/tenant in a singleton consumed by repeated requests or workers (see [request lifecycle](advanced-workflows.md#request-lifecycle-and-extension-boundaries)).

## Events

```php
event('order.created', $order);

app()->on('order.created', function ($order) {
    // handle event
});
```

The dispatcher supports priorities, one-time listeners, dispatch with responses, `until`, and subscriptions. The `Event` facade exposes `listen()`, `dispatch()`, `dispatchIf()`, `until()`, and `once()` (backend code only, never Blade).

## Console commands

Register commands with the command registry; follow the existing app command style.

```php
use App\Console\ReportCommand; // your handler class

command('reports:sync', [ReportCommand::class, 'handle'])
    ->description('Sync reports');
```

Load command routes through `withRouting(commands: __DIR__ . '/../routes/console.php')` in `bootstrap/app.php`.

**Output conventions**

- Built-in commands use consistent `INFO`, `DONE`, `WARN`, `ERROR` notices. Migrations/rollbacks print `RUNNING` then timed `DONE` / `FAIL` lines. Route and queue listings use tables.
- ANSI colors are disabled when output is redirected, `NO_COLOR` is nonempty, or `TERM=dumb`. Progress uses complete lines for readable logs.
- Custom commands can use `Spark\Console\Prompt`: `info()`, `success()`, `warning()`, `error()`, `line()`, `table()`, `status($message, $status, $duration)`.
- `status()` takes an optional elapsed duration (seconds) and only renders output; use `line()` for intentional multiline text.

## Error handling

```php
abort(\Spark\Http\Response::STATUS_NOT_FOUND, 'Post not found');
abort(\Spark\Http\Response::STATUS_FORBIDDEN, 'Forbidden');
```

Conditional forms: `abort_if()`, `abort_unless()`, `throw_if()`, `throw_unless()` (see [helpers](helpers.md#flow-control-and-diagnostics)).

| Condition | Status |
| --- | --- |
| Route not found | 404 |
| Not found / item not found | 404 |
| Authorization failure | 403 |
| Invalid CSRF | 419 |
| Too many requests | 429 |

Register custom exception handlers with `withExceptions()` if the app uses that style. Preserve useful HTTP status and the app's JSON/browser contract; keep unexpected errors observable and never catch every throwable and report success.