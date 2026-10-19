# Sessions and Cookies

Read this reference for web session state, cookies, flash messages, and session storage handlers. All source paths refer to the application root.

---

## Session Management with `Spark\Facades\Session`

TinyCore provides the **`Spark\Facades\Session`** facade as well as the global `session()` helper:

```php
use Spark\Facades\Session;

// 1. Reading Session Values
$theme = Session::get('theme', default: 'light');
$cartCount = session('cart_count', 0);

// 2. Writing Session Values
Session::put('theme', 'dark');
Session::set('locale', 'en');
session(['cart_count' => 3]);

// 3. Checking Existence
if (Session::has('user_id')) {
    // Key exists and is not null
}

// 4. Removing Values
Session::forget('cart_count');
$token = Session::pull('csrf_token'); // Reads and immediately deletes
Session::flush(); // Clears all session data

// 5. Flash Messages (available until retrieved)
Session::flash('status', 'Your profile was updated successfully!');
$status = Session::getFlash('status'); // Retrieves and clears
```

### Session Access in Blade Views

In Blade templates, **never use `\Spark\Facades\Session`**. Always use the `session()` helper or `@session` directive:

```blade
{{-- Flash message alert --}}
@session('status')
    <x-alert :message="session('status')" type="success" />
@endsession

{{-- Reading session values --}}
<p>Selected theme: {{ session('theme', 'light') }}</p>
```

---

## Cookies with `now()`

Use the `cookie()` helper for managing HTTP cookies:

```php
// Read a cookie
$displayMode = cookie('display_mode');

// Set a simple cookie (name, value, minutes)
cookie('display_mode', 'compact', 60 * 24 * 30);

// Set a cookie with options array (using now()->addDays(...) for expiration timestamp)
cookie('display_mode', 'compact', [
    'expires' => now()->addDays(30)->timestamp,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
```

> [!IMPORTANT]
> **Use `now()` for Cookie Expiration**:
> Never use `time() + 86400`. Always write `now()->addDay()->timestamp` or `now()->addDays(30)->timestamp`.

---

## Session Configuration

Spark supports `database`, `file`, and `redis` session handlers in `config/session.php`:

| Handler | Connection settings | Storage |
| --- | --- | --- |
| `database` | `connection`, `table` (default `sessions`) | `id`, base64 `payload`, `last_activity` |
| `file` | `path` | `storage/framework/sessions` |
| `redis` | Shared Redis connector options | Application-prefixed keys with TTL |

- **`lifetime`**: Session idle lifetime in **minutes** (default 120).
- **Regeneration**: Call `Session::regenerate()` after login to prevent session fixation.
- **Invalidation**: Call `Session::invalidate()` on logout to flush data and change session ID.
