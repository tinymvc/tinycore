# Localization and Date Boundaries

Read this reference for translations, locale handling, and date manipulation with `Spark\Carbon`. All source paths and commands are relative to the application root.

---

## Strict Rule: Modern Date Handling with `Spark\Carbon` and `now()`

> [!CAUTION]
> **Prohibition of Legacy PHP Date Functions**:
> **NEVER** use procedural PHP date/time functions in application code:
> - ❌ `gmdate('Y-m-d H:i:s')`
> - ❌ `date('Y-m-d')`
> - ❌ `time()` or `time() + 86400`
> - ❌ `strtotime('+1 day')`
> - ❌ `microtime()`
>
> **Why?** TinyCore's application boot already sets PHP's default timezone to `config('app.timezone', 'UTC')` via `date_default_timezone_set()`. The native **`Spark\Carbon`** class and global **`now()`** helper are fully timezone-aware, elegant, and default to application UTC. Procedural date functions are un-smart, error-prone, and bypass Spark's hydration and serialization pipeline.

### The Modern Spark Date Standard

| Legacy / Non-Smart Pattern | Smart, Elegant Spark / Carbon Standard |
| --- | --- |
| `gmdate('Y-m-d H:i:s')` | `now()->toDateTimeString()` |
| `date('Y-m-d')` | `now()->toDateString()` or `today()->toDateString()` |
| `time()` | `now()->timestamp` |
| `time() + 86400` | `now()->addDay()->timestamp` or `now()->addSeconds(86400)->timestamp` |
| `strtotime('+30 days')` | `now()->addDays(30)->timestamp` |
| `date('Y')` | `now()->year` or `now()->format('Y')` |
| `date('H:i:s')` | `now()->toTimeString()` |
| `$model->update(['updated_at' => gmdate(...)])` | Handled automatically by Spark ORM, or use `now()` |

---

## `Spark\Carbon`: Native DateTime Utility

TinyMVC provides **`Spark\Carbon`** as its native date and time library. Use global helpers `now()`, `carbon()`, or static methods on `\Spark\Carbon`.

```php
use Spark\Carbon;

// 1. Current Time & Presets
$now = now();                  // Current date and time (Spark\Carbon in app UTC)
$today = Carbon::today();       // Today at 00:00:00
$tomorrow = Carbon::tomorrow(); // Tomorrow at 00:00:00
$yesterday = Carbon::yesterday(); // Yesterday at 00:00:00

// 2. Parsing Dates
$date = carbon('2026-10-16 14:30:00');
$parsed = Carbon::parse('next Monday');
$fromFormat = Carbon::createFromFormat('d/m/Y', '16/10/2026');
$fromTimestamp = Carbon::createFromTimestamp(1729094400);

// 3. Relative Calculations (Fluent & Immutable-friendly)
$nextWeek = now()->addDays(7);
$nextMonth = now()->addMonth();
$pastHours = now()->subHours(3);
$startOfDay = now()->startOfDay();
$endOfMonth = now()->endOfMonth();
$humanReadable = $post->created_at->diffForHumans();

// 4. Formatting Values
$dateTimeStr = now()->toDateTimeString();     // '2026-10-16 21:05:00'
$dateStr     = now()->toDateString();         // '2026-10-16'
$timeStr     = now()->toTimeString();         // '21:05:00'
$isoUtc      = now()->toIsoUtcString();       // '2026-10-16T21:05:00.000000Z'
$atom        = now()->toIsoString();          // '2026-10-16T21:05:00+00:00'
$formatted   = now()->format('M j, Y g:i A'); // 'Oct 16, 2026 9:05 PM'
$timestamp   = now()->timestamp;              // int Unix timestamp

// 5. Comparisons & State Checks
if ($post->published_at->isPast()) {
    // Already published
}
if ($token->expires_at->isFuture()) {
    // Still valid
}
```

---

## Database Queries with Dates

Pass `Carbon` instances, `now()`, or `today()` directly to the query builder:

```php
use App\Models\Post;

// Direct comparison using now()
$publishedPosts = Post::where('published_at', '<=', now())
    ->where('status', 'published')
    ->latest()
    ->get();

// Date filtering methods
$todaysPosts = Post::whereDate('created_at', '=', today()->toDateString())->get();
$thisYearPosts = Post::whereYear('created_at', '=', now()->year)->get();
$thisMonthPosts = Post::whereMonth('created_at', '=', now()->month)->get();

// Mass update with now()
Post::where('status', 'draft')
    ->where('scheduled_at', '<=', now())
    ->update([
        'status' => 'published',
        'published_at' => now(),
    ]);
```

---

## Automatic API Date Serialization

> [!IMPORTANT]
> **Do NOT Manually Parse or Format Carbon in JsonResource**:
> TinyMVC's `JsonResource::normalize()` automatically inspects returned resource arrays recursively. When it encounters:
> - An instance of `Spark\Carbon`: it automatically converts it into an ISO-8601 UTC string via `$value->toIsoUtcString()`.
> - An instance of `DateTimeInterface`: it automatically formats it via `DateTimeInterface::ATOM`.
>
> Because model timestamps (`created_at`, `updated_at`) and attributes cast with `'datetime'` or `'date'` are **already** `Spark\Carbon` instances.

```php
// BAD - Redundant, noisy, and un-smart:
$data = [
    'created_at' => Carbon::parse($this->created_at)->toIsoUtcString(),
    'updated_at' => $this->updated_at?->toIsoUtcString(),
    'published_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($this->published_at)),
];

// GOOD - Clean, elegant, and idiomatic Spark:
$data = [
    'created_at' => $this->created_at,
    'updated_at' => $this->updated_at,
    'published_at' => $this->published_at,
];
```

In API responses, always pass the Carbon instance directly to the resource array. Format dates using `format(...)` or `diffForHumans()` only when specifically generating human-readable display strings for HTML views or notification emails.

---

## Translation and Localization

### Language Files

Language files reside in `resources/languages` (configured via `app.locale_dir`):

```php
// resources/languages/en.php
return [
    'greeting' => 'Hello, :name.',
    'welcome' => 'Welcome to TinyMVC!',
    'message_count' => ['%d message', '%d messages'],
];
```

### Translation Helpers

- `__('greeting', ['name' => 'Ada'])`: Returns translated string with placeholders replaced.
- `_e('greeting', ['name' => $name])`: Returns HTML-escaped translation.
- `__('message_count', 2)`: Selects singular/plural from an indexed array.

```blade
{{-- In Blade templates (automatically escaped) --}}
<h1>{{ __('welcome') }}</h1>
<p>{{ __('greeting', ['name' => $user->name]) }}</p>
```

### Switching Request Locale

To dynamically switch locale per request:

```php
$locale = $request->query('lang', 'en');

abort_unless(in_array($locale, ['en', 'bn', 'es'], true), 400, 'Unsupported language.');

app()->instance(
    \Spark\Translator::class,
    new \Spark\Translator(locale_dir($locale . '.php')),
);
```
