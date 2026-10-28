# Queues

Use class jobs implementing `Spark\Queue\Contracts\JobInterface` with `Spark\Queue\Dispatchable`. Queue configuration is independent of cache.

## Connections and storage

`queue.default` (`QUEUE_CONNECTION`) names an entry in `queue.connections`; its `driver` is `database`, `file`, or `redis`. `Queue::connection('redis')`, job `onConnection('redis')`, and CLI `--connection=redis` select that configuration. `onQueue('emails')` selects a queue within it.

Managers resolve connections lazily and reuse them within the application. `Queue::purge($name)` reloads configuration on the next resolution without deleting jobs; existing references keep their configuration. Names sharing the same paths, prefixes, or tables may share data.

- **Database:** configure `connection`, `table` (default `jobs`), and `failed_table` (default `failed_jobs`); migrate both tables first. Keep table names unprefixed: the selected database connection applies its prefix. Jobs have no `created_at`; treat metadata as driver-dependent.
- **File:** configure a private local `path`; intended for modest backlogs.
- **Redis:** requires `ext-redis`; configure host/socket, port, password, database, and an application-specific prefix. Atomic queue transitions target standalone Redis, not Redis Cluster.

## Class jobs and dispatch

```php
namespace App\Jobs;

use Spark\Queue\Contracts\{JobContract, JobInterface};
use Spark\Queue\Dispatchable;
use Throwable;

class SendWelcomeEmail implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;
    public array $backoff = [120, 300];

    public function __construct(private int $userId)
    {
    }

    public function handle(): void
    {
        // Load the user and perform the application work.
    }

    public function failed(JobContract $job, Throwable $exception): void
    {
        // Report final failure after attempts are exhausted.
    }
}
```

```php
SendWelcomeEmail::dispatch($userId)->onConnection('redis')->onQueue('emails')->delay(60);
SendWelcomeEmail::dispatchOnce($userId)->onQueue('emails');
SendWelcomeEmail::dispatchIf($condition, $userId)->onQueue('emails');
SendWelcomeEmail::dispatchUnless($condition, $userId)->onQueue('emails');
SendWelcomeEmail::dispatchSync($userId);
```

Constructor arguments should be small and serializable; handler dependencies are container-injected. Pending dispatches send when they leave scope, or explicitly through `send()` / `dispatch()`. An explicit send failure is not retried by the destructor; deliberate producer retries need deduplication or idempotency.

`delay()` uses seconds; `schedule()` accepts a date string or `Spark\Carbon`. Drivers persist schedules in UTC; strings without offsets use the application timezone. The low-level `job()` helper accepts class/function strings or callable arrays, not closures. Prefer static dispatch for application jobs; see [queued mail](mail-http.md#3-smart-standard-queued-mail-in-jobs) for a complete handler.

## Repeating jobs

```php
SyncReports::dispatchOnce()->onQueue('reports')->repeatEveryMinutes(5);

// In bootstrap/app.php:
$app->withQueue(jobs: [
    job(App\Jobs\SyncReports::class)->repeatEveryMinutes(5),
]);
```

`withQueue()` uses `pushOnce()` and accepts an optional `then` callback. Deduplication avoids duplicate pending registration, not repeated business side effects. Other repeat helpers are `repeatHourly()`, `repeatDaily()`, `repeatWeekly()`, and `repeatMonthly()`; `repeat()` also accepts `biweekly`, `quarterly`, and `yearly`. Repeats are intervals, not cron expressions, and require a running worker.

## Workers, retries, and restarts

```sh
php spark queue:work --connection=redis --queue=emails --once --sleep=0
php spark queue:restart
php spark queue:failed --connection=redis
php spark queue:retryFailed --connection=redis
```

Without `--once`, workers poll until their lifetime expires. `--timeout` is checked between jobs, not a hard job deadline; zero disables the lifetime limit. Timeout, sleep, and retry delay must be nonnegative; tries must be at least one. The first poll is immediate. Use a supervisor and configure I/O timeouts inside jobs.

Public `$tries` and `$backoff` override worker defaults. Backoff accepts seconds or an array indexed by failed attempt; later attempts reuse its last entry. `failed()` runs only after exhaustion and accepts either `Throwable $exception` or `JobContract $job, Throwable $exception`. Constructor/retry-policy failures follow the retry path using worker defaults when necessary. Logs report completed jobs and failed attempts; `--ansi` / `--no-ansi` control color.

`queue:restart` signals workers on all connections to finish and acknowledge the current job, then exit; idle workers exit on their next poll. Command and workers share the default cache store's `queue:workers` namespace. Signals survive default `cache:clear`; new workers ignore old signals. Restart old workers through the supervisor once when changing restart-key formats.

Stale reservations older than 3600 seconds are recovered at worker startup. An indefinitely running worker needs restarting to repeat recovery; keep job execution below that window.

## Transactions and delivery

Default-connection database dispatch participates in the business transaction. Named database connections, file, and Redis do not join it. MySQL `pushOnce()` / `dispatchOnce()` rejects active transactions; dispatch after commit so deduplication does not outlive its advisory lock. There is no automatic `afterCommit()` API; use an outbox or explicit retry record when enqueueing after commit must be recoverable.

Delivery is at least once: handlers must be idempotent. Stop workers/producers before clearing queues or changing drivers. `queue:clear` / `queue:clearFailed` delete work; purging a connection does not.
