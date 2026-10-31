# Concurrency and execution boundaries

> **Read when:** choosing inline work, HTTP fan-out, concurrent tasks, subprocesses, defer, or jobs.
> **Related:** [Mail and HTTP](mail-http.md) · [Queues](queues.md) · [Advanced workflows](advanced-workflows.md)
> Source: installed `Concurrency.php`, `Console/Process.php`, `Http/Client/HttpPool.php`, and `Queue/`.

Contents:

- [Choose an execution model](#choose-an-execution-model)
- [Callable tasks](#callable-tasks)
- [Subprocesses](#subprocesses)
- [Verification](#verification)

## Choose an execution model

| Requirement | Framework path | Boundary to verify |
| --- | --- | --- |
| Several independent network requests | HTTP pool in [Mail and HTTP](mail-http.md) | Per-response failures, timeouts, connection limits |
| Independent callable tasks | `Spark\Concurrency` | Runtime support, isolated inputs, per-task errors |
| External executable | `Spark\Console\Process` | Arguments, working directory, timeout, exit status |
| Work that must survive request/process loss | [Queues](queues.md) | Durable backend, retry/idempotency, transaction visibility |
| Small process-local work after a response | `defer()` | No durability or independent worker guarantee; look up the installed signature first ([API discovery](api-discovery.md)) |
| Sequential transformations | [Pipelines](container-pipelines.md#staged-transformations) | Early termination and partial effects |

A concurrency helper does not coordinate database locks or business idempotency. For shared-state writes, define invariants and commit boundaries first ([advanced workflows](advanced-workflows.md)).

## Callable tasks

```php
use Spark\Concurrency;

$results = Concurrency::runWithLimit([
    'summary' => fn () => 'ready',
    'total' => fn () => 2 + 2,
], 2);
```

- The positive limit bounds tasks only when parallel execution is available. Real parallelism needs a ZTS PHP build and the `parallel` extension; otherwise tasks run sequentially.
- Keep callables self-contained. Do not capture an open PDO connection, container, request, resource handle, or mutable model and expect it to work in an isolated runtime. Inspect runtime bootstrapping before using application classes inside parallel workers.
- Caught task failures come back at the task's key as `['error' => true, 'message' => ...]`. Inspect every result before combining successes, and define a result envelope if a business result could have the same shape.
- Pending tasks continue after runtime startup or task failures. A partially successful batch is not an all-or-nothing transaction.
- An instance offers `add()`, `limit()`, `execute()`, and `getResults()`. `wait()` executes the batch **again**; use `getResults()` to inspect completed work without repeating effects. Do not rerun a batch with external writes unless every task has a deliberate idempotency policy.

## Subprocesses

```php
use Spark\Console\Process;

$process = Process::command([PHP_BINARY, '-v'])
    ->path(root_dir())
    ->timeout(10)
    ->execute();
```

- Prefer an argument array over concatenated shell text.
- After execution, inspect `exitCode()`, `successful()`, and `errorOutput()`; confirm the installed result APIs in `Console/Process.php` before deciding success.
- `input()` supplies stdin while piped execution drains stdout/stderr, so large bidirectional transfers remain subject to `timeout()`. Custom `env()` values override inherited environment values.
- Bound runtime and output, keep a useful failure message, and never pass secrets in command arguments or logs.
- Spawning a process does not detach durable work from the request.

## Verification

Test one task failing alongside successful tasks, stable key/result association, timeout handling, and bounded retry behavior. When speedup is a requirement, check both the sequential fallback and the actual deployed parallel runtime.
