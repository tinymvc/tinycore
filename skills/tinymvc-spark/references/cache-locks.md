# Cache and Distributed Locks

Read this reference for caching, cache drivers, and atomic distributed locks. Source paths and command working directories refer to the application root unless explicitly stated otherwise. Check the installed package when versions differ.

---

## Cache: Fluent Storage & Invalidation

TinyCore provides the **`Spark\Facades\Cache`** facade for all caching operations.

### Configuration

Supported storage drivers in `config/cache.php` include `database`, `file`, and `redis`. The default is `database` (`CACHE_STORE=database`).

### Storing and Retrieving Values

```php
use Spark\Facades\Cache;

// 1. Get or Default
$value = Cache::get('site_settings', default: []);

// 2. Put / Set with seconds, date expressions, or date objects
Cache::put('featured_products', $products, '+10 minutes');
Cache::set('exchange_rates', $rates, 3600);
Cache::put('report', $report, now()->addHours(1));

// 3. Check existence
if (Cache::has('featured_products')) {
    // Key exists and has not expired
}

// 4. Remember: Cache or Compute (Most Popular)
$posts = Cache::remember(
    'posts:featured',
    fn () => Post::where('featured', true)->latest()->take(10)->get(),
    '+1 hour',
);

// 5. Invalidation & Removal
Cache::forget('featured_products'); // Removes specific key
Cache::erase(['key1', 'key2']);     // Removes array of keys
Cache::flush();                     // Flushes the entire cache store

// 6. Named Stores
$redisCache = Cache::make('redis');
$redisCache->put('user:1:feed', $feed, '+15 minutes');
```

---

Cache expiration parameters accept `int|string|DateTimeInterface|Spark\Carbon|DateInterval|null`. Integers are TTL seconds, strings use `Carbon::parse()` date semantics, date objects are absolute deadlines, and null means no expiration. This applies to `store`, `set`, `put`, `add`, `load`, `remember`, and both bulk-write methods. Zero/negative TTLs and past deadlines are immediately expired; invalid date strings throw. Date objects are copied, never mutated.

## PSR-16 interoperability

The native cache keeps fluent `set()`/`delete()`, flexible expirations, and existing key formats. For libraries accepting `Psr\SimpleCache\CacheInterface`, resolve that interface from the application or wrap a named cache:

```php
$cache = new Spark\Cache\SimpleCache(Spark\Cache\Cache::make('reports'));
$cache->set('summary', $report, new DateInterval('PT1H')); // bool
$values = $cache->getMultiple(['summary', 'missing'], default: []);
```

The adapter accepts integer seconds, `DateInterval`, or null TTLs. Zero/negative TTLs delete immediately. Empty keys and keys containing `{}()/\@:` throw `Psr\SimpleCache\InvalidArgumentException`. `clear()` clears only the wrapped cache namespace. Null values are indistinguishable from misses. Storage failures propagate rather than reporting success.

## Distributed Locks

Distributed atomic locking is handled via **`Spark\Facades\Lock`**. Locks are critical for avoiding race conditions in background jobs, inventory management, order payments, and scheduled tasks.

### Executing Code Under Lock (`Lock::withLock`)

The safest and most idiomatic way to use locks is via `withLock()`, which automatically releases the lock upon completion or if an exception is thrown:

```php
use Spark\Facades\Lock;

$result = Lock::withLock(
    key: "order:{$orderId}:pay",
    callback: function () use ($order) {
        // Critical section: atomic payment processing
        return $order->processPayment();
    },
    timeout: 10,     // Lock lease duration in seconds
    waitTimeout: 5,  // Maximum seconds to wait to acquire the lock
);
```

Lock `timeout` accepts the same non-null types through `lock()`, `withLock()`, `Cache::lock()`, and the `lock()` helper. Non-integer leases are resolved to deadlines; waiting never moves that deadline forward. An elapsed deadline returns false. Integer leases keep the existing minimum of one second. `waitTimeout` and `extendLock($key, $additionalSeconds)` remain durations in integer seconds.

### Manual Lock Acquisition and Release

```php
use Spark\Facades\Lock;

$key = "invoice:{$invoiceId}";

if (Lock::lock($key, timeout: 30, waitTimeout: 5)) {
    try {
        // Perform processing
    } finally {
        Lock::unlock($key);
    }
} else {
    abort(409, 'Invoice is currently being processed by another worker.');
}
```

### Lock State Inspection

```php
use Spark\Facades\Lock;

if (Lock::isLocked('report:monthly')) {
    // Report is currently generating
}

// Named lock store
$customLock = Lock::make('redis');
```
