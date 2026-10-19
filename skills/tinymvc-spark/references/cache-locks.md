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

// 2. Put / Set with expiration (string expression or seconds)
Cache::put('featured_products', $products, '+10 minutes');
Cache::set('exchange_rates', $rates, 3600); // 3600 seconds

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
