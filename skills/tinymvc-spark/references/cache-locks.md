# Cache and locks

Use `Spark\Facades\Cache` for caching and lock handles, or the `cache()` / `lock()` helpers. Cache supports `database`, `file`, and `redis`; migrate the framework cache/lock tables before using the database driver. Keep configured table names logical/unprefixed; each selected database connection applies its prefix.

## Stores and namespaces

`Cache::store('redis')` selects a configured entry in `cache.stores` (`cache.connections` remains supported). `cache.default` selects the default store. Repositories are shared within one application and store/namespace pair.

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

`Cache::store()` selects a configuration; use `put()`/`set()` to write. Repository `store($key, $value, $expire)` remains a write alias. Namespaces do not select configurations. Different store names sharing the same backend/path/prefix/tables can share data.

## Values and lifecycle

```php
cache(['var' => 'value'], '10 minutes'); // Write values in the default store/namespace.
$value = Cache::get('settings', default: []);
$posts = Cache::remember('featured', fn() => Post::where('featured', true)->get(), '+1 hour');
Cache::forget('featured');
Cache::store('redis')->flush(); // Selected namespace's values; preserves locks.
Cache::purge('redis');         // Forget resolved repositories; preserves stored data.
```

Expiration accepts TTL seconds, date strings, `DateTimeInterface`, `Spark\Carbon`, `DateInterval`, or null (no expiry). The array form of `cache($values, $expire)` uses these same rules and returns the default repository; `cache('name')` still selects a namespace. Zero/negative TTLs and elapsed deadlines expire immediately; invalid dates throw. `remember()`/`load()` take the callback before expiration and do not serialize concurrent misses. `storeManyWithExpiry()` takes `['key' => ['value' => ..., 'expire' => ...]]`.

Managers reuse repositories and resolved store configuration until `purge($store)` / `forgetDriver($stores)`. Existing references, including subsequent `withNamespace()` calls, retain their original store configuration. Managers are scoped to one application. `flush()`/`clear()` delete values only in the selected namespace.

Database key/group equality follows the column collation; case-insensitive collations can treat `Item` and `item` as one entry. Batch reads return stored key spellings. Lock owner tokens always match exactly; file and Redis keys are case-sensitive.

For PSR-16, inject `Psr\SimpleCache\CacheInterface` or wrap a repository in `Spark\Cache\SimpleCache`. The adapter returns booleans for writes, accepts integer/interval/null TTLs, rejects reserved PSR key characters, and deletes immediately for nonpositive TTLs. The native cache retains fluent writes and its existing key formats.

## Independent lock handles

```php
$result = Cache::store('redis')->lock('report:daily', 30)
    ->block(5, fn() => $service->generateDaily());

$lock = lock('invoice:' . $invoiceId, 30, name: 'custom');
if ($lock->get()) {
    try {
        // Protected operation.
    } finally {
        $lock->release();
    }
}
```

`Cache::lock()` and `lock($key)` create fresh owner tokens; acquisition is explicit. `get()` returns false immediately on contention, including file shard-guard contention; backend I/O failures throw. `block($seconds)` throws `Spark\Cache\Exceptions\LockTimeoutException` on timeout. Both accept callbacks, return their result, and release in `finally`. Leases are positive integer seconds (default 10); waits are nonnegative seconds. The key `'0'` is valid.

`owner()` and the same repository's `restoreLock($key, $owner)` transfer ownership across processes. Handles do not release on destruction; use a callback or explicit `release()`. `isOwnedByCurrentProcess()` checks ownership; `forceRelease()` bypasses it for deliberate recovery. Purging/flushing must not release live locks. Processes must share the backend and namespace; expired leases can permit overlap, so retain database constraints and idempotency.

`extend($seconds)` adds positive integer seconds to an active lease owned by the handle; it returns false for missing, expired, or foreign-owned locks. `isLocked()` checks for any active owner. `info()` returns `['owner' => ..., 'expire_at' => ...]` (Unix timestamp), or null. Inspection does not acquire a lock.

Database locks resolve `lock_connection` → store `connection` → default database, using a dedicated PDO connection so ownership commits independently of application transactions.
