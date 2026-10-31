# Sessions and Cookies

> **Read when:** handling session values, flash messages, cookies, or session drivers.
> **Related:** [Auth and gate](auth-gate.md) · [Middleware](middleware.md) (CSRF) · [Foundation](foundation.md) · [Views and frontend](views-frontend.md)
> Session state and cookies are request-specific; avoid capturing them in long-lived services.

Contents:

- [Values and flash](#values-and-flash)
- [Cookies](#cookies)
- [Drivers and verification](#drivers-and-verification)

## Values and flash

Use `Spark\Facades\Session` in backend code and `session()` in views:

```php
Session::put('theme', 'dark'); // set() is also supported
$theme = Session::get('theme', default: 'light');
session(['cart_count' => 3]);
$count = session('cart_count', 0);
Session::forget('cart_count');
$value = Session::pull('one_time_value'); // read and delete
Session::flash('status', 'Profile updated.');
$message = Session::getFlash('status'); // retrieve and clear
```

`has()` checks existence/non-null; `flush()` clears all data. Flash persists until retrieved, rather than assuming Laravel's exact flash lifecycle.

```blade
@session('status')
    <x-alert :message="session('status')" type="success" />
@endsession
```

`regenerate()` changes the session ID after login; `invalidate()` clears state and changes it on logout. Preserve authentication's existing regeneration behavior.

## Cookies

```php
$mode = cookie('display_mode');
cookie('display_mode', 'compact', 3600); // lifetime in seconds
cookie('display_mode', 'compact', [
    'expires' => now()->addDays(30)->timestamp,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
```

Choose cookie options for the consumer and HTTPS deployment. `now()` gives application-aware calendar expiry; use explicit seconds when elapsed duration is intended.

## Drivers and verification

Configure `config/session.php`:

| Driver | Configuration/storage |
| --- | --- |
| database | `connection`, `table` (default sessions); id, base64 payload, last_activity |
| file | Private `path`, normally storage/framework/sessions |
| redis | Connector options, app prefix, TTL |

`lifetime` is idle **minutes** (default 120). Null `session.cookie_settings.secure` follows trusted request HTTPS detection; explicit booleans win. Untrusted forwarding headers cannot enable it. Use shared storage when multiple hosts must share sessions; PSR hosts own transport/session integration.

Verify flash consumption, login ID rotation, logout invalidation, cookie flags behind trusted/untrusted proxies, and isolation between sequential users.
