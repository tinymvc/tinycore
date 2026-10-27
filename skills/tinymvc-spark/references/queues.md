# Queues

Contents:

- [Queue and Jobs](#queue-and-jobs)

Read this reference for queue and jobs. Source paths and command working directories refer to the application root unless explicitly stated otherwise. Check the installed package when versions differ.

## Queue and Jobs

`php spark queue:work --queue=emails --once --sleep=0` processes at most one due job and exits. Without `--once`, the worker polls until its lifetime expires. `--timeout` is the worker lifetime checked between jobs, not a hard execution deadline for a job; `--timeout=0` disables that lifetime limit. Timeout, sleep, and retry delay must be non-negative; tries must be at least one. The first poll is immediate. Use a process supervisor to restart exited workers. After deployments, `php spark queue:restart` signals workers to finish their current job and exit; idle workers stop on their next poll. The command and workers must share the configured cache store and namespace (shared storage for file cache). New workers ignore earlier restart signals.

Job-construction and retry-policy failures follow the same retry/failure path as handler errors, using worker defaults when job settings cannot be resolved. Stale reservations are recovered at worker startup after 3600 seconds; an indefinitely running worker needs restarting to repeat recovery. Keep job execution below that reservation window. The worker does not forcibly interrupt a running job.

Worker logs use unbracketed timestamps and compact job labels (`App\Jobs\Example  #2 · 1/3`). Job lines use dotted leaders before `RUNNING`, `DONE`, or `FAIL`, with elapsed time on completion. Workers listening to multiple queues also show the job's queue name. Informational and error lines put their status first. Recurring schedules and retries appear underneath as indented `↳ Next run: ...` and `↳ Retry at: ...` notes; blank lines separate job groups. The final summary counts completed jobs and failed attempts. Colors auto-detect the terminal and respect `NO_COLOR` by default; `--ansi` forces colors and `--no-ansi` disables them, taking precedence if both flags are present.

A pending dispatch attempts to enqueue only once, including when explicit `send()` throws. Its destructor does not retry an ambiguous transport failure. Any deliberate producer retry needs application-level deduplication or idempotency.

Database queue operations use the configured connection, including their transactions. Default-connection dispatch participates in the business transaction; named connection/file/Redis dispatch does not. MySQL `pushOnce()`/`dispatchOnce()` rejects active transactions; call it after commit so deduplication does not outlive its advisory lock. Queue delivery is at least once: handlers must be idempotent, and stale recovery must exceed the longest execution time. File storage is for local, modest backlogs. Redis queue transitions are atomic and target standalone Redis, not Redis Cluster. Stop workers before clearing or changing storage. `getConnection()` throws for file queues.

Prefer class jobs for application work. A class job implements `Spark\Queue\Contracts\JobInterface` and usually uses `Spark\Queue\Dispatchable`.

```php
<?php

namespace App\Jobs;

use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\JobInterface;
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
        // send email
    }

    public function failed(JobContract $job, Throwable $exception): void
    {
        // called only after the queue exhausts all tries
    }
}
```

### Smart Class Job Dispatching

Application jobs implement `Spark\Queue\Contracts\JobInterface` and use `Spark\Queue\Dispatchable`. In application controllers, models, event listeners, and services, **always use static dispatch methods**:

```php
use App\Jobs\SendAccountMail;
use App\Jobs\SyncReports;

// Standard queue dispatch
SendAccountMail::dispatch($user->id)->onQueue('emails');

// Dispatch with delay (in seconds)
SendAccountMail::dispatch($user->id)->onQueue('emails')->delay(60);

// Dispatch with duplicate protection (runs once)
SendAccountMail::dispatchOnce($user->id)->onQueue('emails');

// Conditional dispatch
SendAccountMail::dispatchIf($user->is_active, $user->id)->onQueue('emails');
SendAccountMail::dispatchUnless($user->is_banned, $user->id)->onQueue('emails');

// Immediate synchronous execution in current process
SendAccountMail::dispatchSync($user->id);

// Recurring scheduled jobs
SyncReports::dispatchOnce()
    ->onQueue('reports')
    ->repeatEveryMinutes(5);
```

> [!IMPORTANT]
> **Never write `job(SendAccountMail::class, [$user->id])->dispatch('emails')` in application code**.
> Because the job uses `Dispatchable`, always write `SendAccountMail::dispatch($user->id)->onQueue('emails')`.
> The procedural `job()` helper is legacy and should only be used when defining raw closure jobs or in `bootstrap/app.php` scheduler registration.

### Real-World Smart Job Example: Queued Mail

```php
<?php

namespace App\Jobs;

use App\Models\User;
use Spark\Facades\Mail;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;
use Throwable;

class SendAccountMail implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(private int $userId)
    {
    }

    public function handle(): void
    {
        $user = User::findOrFail($this->userId);

        Mail::to($user->email, $user->name)
            ->subject('Welcome to TinyMVC!')
            ->view('emails.welcome', ['user' => $user])
            ->send();
    }

    public function failed(JobContract $job, Throwable $exception): void
    {
        // Handle final failure after 3 attempts
    }
}
```

Dispatched cleanly from a controller:

```php
public function store(StoreUserRequest $request): Response
{
    $user = User::create($request->validated());

    SendAccountMail::dispatch($user->id)->onQueue('emails');

    return UserResource::make($user)->response(Response::STATUS_CREATED);
}
```

---

### Recurring Jobs in Bootstrap

In `bootstrap/app.php`, recurring scheduler jobs should be registered with `withQueue()` so they use queue `pushOnce()` behavior and do not duplicate every bootstrap:

```php
$app->withQueue(
    jobs: [
        job(App\Jobs\SyncReports::class)->repeatEveryMinutes(5),
    ],
);
```

`withQueue()` accepts `jobs` and an optional `then` callback.

Supported repeat intervals and aliases on `Job`:
- `repeatEveryMinutes(int $minutes)`
- `repeatHourly()` / `repeat('hourly')`
- `repeatDaily()` / `repeat('daily')`
- `repeatWeekly()` / `repeat('weekly')`
- `repeatMonthly()` / `repeat('monthly')`
- `repeat('biweekly')`, `repeat('quarterly')`, `repeat('yearly')`

The queue connection is selected by `config('queue.default')` (`QUEUE_CONNECTION`), which names an entry in `queue.connections`; that entry's `driver` selects `database`, `file`, or `redis`.

```php
return [
    // The default queue connection name
    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
        ],
        'file' => [
            'driver' => 'file',
            'path' => dirname(__DIR__) . '/storage/framework/queue.d',
        ],
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => env('REDIS_DATABASE', 0),
            'prefix' => env('REDIS_PREFIX', 'spark'),
        ],
    ],
];
```

Important:

- Use `dispatchOnce()` or `withQueue(jobs: [...])` for scheduler/cron-style repeated jobs.
- Public job properties `$tries` and `$backoff` override worker retry defaults when present.
- Job `failed()` hooks are called by `Queue` only after all tries are exhausted, not on every retryable exception.
- A `failed()` method may accept either `Throwable $exception` or `JobContract $job, Throwable $exception`.
- Use `Queue::connection('redis')` or `SendEmail::dispatch($id)->onConnection('redis')->onQueue('emails')`; `--connection=redis` selects that connection for worker/management commands. Managers reuse connections until `Queue::purge($name)`; purging preserves queued jobs.
- Queue has separate config from cache.
- Database, file, and Redis drivers should behave consistently for push/pushOnce/work. Database jobs have no created_at column. Treat job metadata fields as driver-dependent unless explicitly guaranteed.
