# Cache and locks

Read when: caching values, choosing a store or namespace, or taking locks. Use `Spark\Facades\Cache` or the `cache()` / `lock()` helpers. Drivers: `database`, `file`, `redis`. Migrate the framework cache/lock tables before using the database driver, and keep configured table names logical and unprefixed; each selected database connection applies its prefix.

## Stores and namespaces

- `Cache::store('redis')` selects an entry in `cache.stores` (`cache.connections` remains supported). `cache.default` selects the default store.
- A namespace partitions keys inside a store. Namespaces never select configurations.
- Repositories are shared within one application per store/namespace pair.
- Different store names that share the same backend, path, prefix, or tables can share data.

```php
use Spark\Facades\Cache;

Cache::store('redis')->put('rate', $rate, 3600);
$reports = Cache::store('redis')->withNamespace('reports');
$reports->put('daily', $report, '+1 hour');

// These resolve the same namespace in the default store:
$custom = cache('custom');
$custom = Cache::make('custom');
$custom = lock(name: 'custom');
```

`Cache::store()` selects a configuration; write with `put()` / `set()`. On a repository, `store($key, $value, $expire)` is only a write alias, not a selector.

## Values and expiry

```php
cache(['var' => 'value'], '10 minutes'); // write in the default store/namespace
$value = Cache::get('settings', default: []);
$posts = Cache::remember('featured', fn () => Post::where('featured', true)->get(), '+1 hour');
Cache::forget('featured');
Cache::store('redis')->flush(); // selected namespace's values; preserves locks
Cache::purge('redis');          // forget resolved repositories; preserves stored data
```

- **Expiration** accepts TTL seconds, date strings, `DateTimeInterface`, `Spark\Carbon`, `DateInterval`, or `null` (no expiry). Zero/negative TTLs and elapsed deadlines expire immediately; invalid dates throw.
- `cache($values, $expire)` follows the same rules and returns the default repository; `cache('name')` still selects a namespace.
- `remember()` / `load()` take the callback **before** expiration and do not serialize concurrent misses (see [advanced workflows](advanced-workflows.md#lock-ownership-and-cache-consistency) when duplicate computation matters).
- `storeManyWithExpiry()` takes `['key' => ['value' => ..., 'expire' => ...]]`.

## Lifecycle and key semantics

- Managers reuse repositories and resolved store configuration until `purge($store)` / `forgetDriver($stores)`. Existing references, including later `withNamespace()` calls on them, keep their original configuration.
- Managers are scoped to one application. `flush()` delete values only in the selected namespace.
- Database key/group equality follows the column collation; case-insensitive collations can treat `Item` and `item` as one entry, and batch reads return the stored spelling. File and Redis keys are case-sensitive. Lock owner tokens always match exactly.

## PSR-16

Inject `Psr\SimpleCache\CacheInterface` or wrap a repository in `Spark\Cache\SimpleCache`. The adapter returns booleans for writes, accepts integer/interval/null TTLs, rejects reserved PSR key characters, and deletes immediately for nonpositive TTLs. The native cache keeps its fluent writes and key formats.

## Locks

```php
$result = Cache::store('redis')->lock('report:daily', 30)
    ->block(5, fn () => $service->generateDaily());

$lock = lock('invoice:' . $invoiceId, 30, name: 'custom');
if ($lock->get()) {
    try {
        // Protected operation.
    } finally {
        $lock->release();
    }
}
```

`Cache::lock()` and `lock($key)` create a fresh owner token; acquisition is explicit.

| Call | Behavior |
| --- | --- |
| `get()` | Acquire or return `false` immediately on contention (including file shard-guard contention). Backend I/O failures throw. |
| `block($seconds)` | Wait, then throw `Spark\Cache\Exceptions\LockTimeoutException` on timeout. |
| `get()` / `block()` with a callback | Return the callback's result and release in `finally`. |
| `release()` | Release a lock this handle owns. |
| `extend($seconds)` | Add positive integer seconds to an active lease owned by this handle; `false` for missing, expired, or foreign-owned locks. |
| `isLocked()` | Any active owner exists. |
| `info()` | `['owner' => ..., 'expire_at' => ...]` (Unix timestamp) or `null`. |
| `isOwnedByCurrentProcess()` | Ownership check. |
| `owner()` + `restoreLock($key, $owner)` | Transfer ownership across processes (same repository). |
| `forceRelease()` | Bypasses ownership; deliberate recovery only. |

Rules:
- Leases are positive integer seconds (default 10); waits are nonnegative seconds. The key `'0'` is valid.
- Handles do not release on destruction. Use a callback or explicit `release()` in `finally`.
- Inspection (`isLocked()`, `info()`) does not acquire a lock.
- Purging or flushing must not release live locks.
- Processes must share the backend and namespace. Expired leases can allow overlap, so keep database constraints and idempotency as the final protection.
- Database locks resolve `lock_connection` → store `connection` → default database, on a dedicated PDO connection so ownership commits independently of application transactions.