# Extensions Errors

Read this reference for service providers, events, console commands, error handling. Source paths and command working directories refer to the application root unless explicitly stated otherwise. Check the installed package when versions differ.

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
        app()->singleton(\App\Services\BillingService::class);
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

Built-in commands use consistent `INFO`, `DONE`, `WARN`, and `ERROR` notices. Migrations and rollbacks print `RUNNING` followed by timed `DONE` / `FAIL` lines. Route and queue listings use tables. ANSI colors are disabled when output is redirected, `NO_COLOR` is nonempty, or `TERM=dumb`; progress uses complete lines for readable logs. Custom commands can use `Spark\Console\Prompt::info()`, `success()`, `warning()`, `error()`, `line()`, `table()`, and `status($message, $status, $duration)`. `status()` accepts an optional elapsed duration in seconds and only renders output; use `line()` when intentional multiline text is needed.

Command routes may be loaded through `withRouting(commands: __DIR__ . '/../routes/console.php')` from `bootstrap/app.php`.

Use the command registry:

```php
command('reports:sync', [ReportCommand::class, 'handle'])
    ->description('Sync reports');
```

Follow existing app command style.

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
