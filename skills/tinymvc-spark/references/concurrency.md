# Concurrency and execution boundaries

Read when choosing between inline work, HTTP fan-out, concurrent tasks, subprocesses, deferred callbacks, and jobs. Source: `Concurrency.php`, `Console/Process.php`, `Http/Client/HttpPool.php`, and `Queue/` under installed TinyCore `src`.

## Choose an execution model

| Requirement | Framework path | Boundary to verify |
| --- | --- | --- |
| Several independent network requests | HTTP pool in [Mail and HTTP](mail-http.md) | Per-response failures, timeouts, connection limits |
| Independent callable tasks | `Spark\Concurrency` | Runtime support, isolated inputs, per-task errors |
| External executable | `Spark\Console\Process` | Arguments, working directory, timeout, exit status |
| Work that must survive request/process loss | [Queues](queues.md) | Durable backend, retry/idempotency, transaction visibility |
| Small process-local work after a response | `defer()` | No durability or independent worker guarantee |
| Sequential transformations | [Pipelines](container-pipelines.md#staged-transformations) | Early termination and partial effects |

## Callable tasks

```php
use Spark\Concurrency;

$results = Concurrency::runWithLimit([
    'summary' => fn () => 'ready',
    'total' => fn () => 2 + 2,
], 2);
```

The positive limit bounds tasks only when parallel execution is available. Real parallelism requires a ZTS PHP build and the `parallel` extension; otherwise tasks run sequentially. Keep callables self-contained. Do not capture an open PDO connection, container, request, resource handle, or mutable model and expect it to work in an isolated runtime. Inspect runtime bootstrapping before using application classes inside parallel workers.

Caught task failures are returned at their task key as `['error' => true, 'message' => ...]`; inspect every result before combining successes. Define a result envelope if business results could have the same shape. Do not treat a partially successful batch as an all-or-nothing transaction.

An instance offers `add()`, `limit()`, `execute()`, and `getResults()`. `wait()` executes the batch again; use `getResults()` to inspect completed work without repeating effects. Do not rerun a batch with external writes unless every task has a deliberate idempotency policy.

## Subprocesses

```php
use Spark\Console\Process;

$process = Process::command([PHP_BINARY, '-v'])
    ->path(root_dir())
    ->timeout(10)
    ->execute();
```

Prefer an argument array over concatenated shell text. Inspect `Console/Process.php` for the installed result/exit APIs before deciding success. Bound runtime and output, preserve a useful failure message, and avoid passing secrets in command arguments or logs. Spawning a process does not detach durable work from the request automatically.

## Verification

Test one task failing alongside successful tasks, stable key/result association, timeout handling, and bounded retry behavior. Check both fallback behavior and the actual deployed parallel runtime when speedup is a requirement. For writes, use [advanced workflows](advanced-workflows.md) to define shared-state invariants and commit boundaries; a concurrency helper does not coordinate database locks or business idempotency.
