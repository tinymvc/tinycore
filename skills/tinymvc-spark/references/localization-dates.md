# Localization and Date Boundaries

Read this reference for translations, locale handling, and date manipulation with `Spark\Carbon`. All source paths and commands are relative to the application root.

---

## Use Spark Carbon for application dates

Use `now()`, `carbon()`, and `Spark\Carbon` for calendar dates, timestamps, parsing, and arithmetic instead of procedural `date()`, `gmdate()`, `time()`, or `strtotime()` expressions. Application boot sets PHP's default timezone to `config('app.timezone', 'UTC')`; `now()` follows that timezone unless explicitly overridden. It is UTC only when configured that way. Resource serialization normalizes Carbon to UTC independently of the display timezone.

Calendar arithmetic and elapsed seconds are different across daylight-saving transitions: choose `addDays(1)` or a duration deliberately. Performance measurement is a separate concern from application date handling.

### The Modern Spark Date Standard

| Intent / existing expression | Spark / Carbon pattern |
| --- | --- |
| UTC timestamp: `gmdate('Y-m-d H:i:s')` | `now('UTC')->toDateTimeString()` |
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
$now = now();                  // Current date and time (Spark\Carbon in the configured app timezone)
$today = Carbon::today();       // Today at 00:00:00
$tomorrow = Carbon::tomorrow(); // Tomorrow at 00:00:00
$yesterday = Carbon::yesterday(); // Yesterday at 00:00:00

// 2. Parsing Dates
$date = carbon('2026-10-16 14:30:00');
$parsed = Carbon::parse('next Monday');
$fromFormat = Carbon::createFromFormat('d/m/Y', '16/10/2026');
$fromTimestamp = Carbon::createFromTimestamp(1729094400);

// 3. Relative calculations
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

## Localized dates

Carbon reuses application language files (`app.locale_dir`) and defaults to `app.locale`, or English. `Carbon::setLocale('bn')` changes the process-wide default; `Carbon::setLocale(null)` restores the application default. Prefer an instance override when serving multiple users in one process:

```php
$date = Carbon::parse('2026-01-05 12:00:00')->locale('bn'); // returns a copy
$date->diffForHumans();
$date->diffForHumansShort();
$date->translatedFormat('l, j F Y');
$date->getDayName();
$date->getMonthName();
```

Add translations to `resources/languages/bn.php`, for example:

```php
return [
    '%d minute ago' => '%d মিনিট আগে',
    '%d minutes ago' => '%d মিনিট আগে',
    'in %d minute' => '%d মিনিট পরে',
    'in %d minutes' => '%d মিনিট পরে',
    '%dm ago' => '%dমি আগে',
    'in %dm' => '%dমি পরে',
    '%dm' => '%dমি',
    'now' => 'এখন',
    'a few seconds ago' => 'কয়েক সেকেন্ড আগে',
    'in a few seconds' => 'কয়েক সেকেন্ড পরে',
    'Monday' => 'সোমবার',
    'January' => 'জানুয়ারি',
];
```

Translate complete phrases for the other units in the same way: `second`, `minute`, `hour`, `day`, `month`, `year` (plural forms end in `s`); short units are `s`, `m`, `h`, `d`, `mo`, `y`. Complete phrases allow each language to choose word order. Missing translations fall back to English. `translatedFormat()` translates `l`, `D`, `F`, `M`, `a`, and `A` using their English names as keys. `format()`, ISO output, date parsing, and JSON serialization remain locale-independent. Standalone Carbon without an application uses English fallback.

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

Pass Carbon directly to resources for the default UTC serialization. Format explicitly when an existing API contract requires another representation, or when producing human-readable display strings for views or notifications.

---

## Translation and Localization

Use PHP arrays in `resources/languages/{locale}.php` (`app.locale_dir`). The translator uses `app.locale` and `app.fallback_locale` (default `en`), caches each catalogue, and returns missing keys unchanged.

```php
// resources/languages/en.php
return [
    'greeting' => 'Hello, :name.',
    'account' => ['title' => 'Account'],
    'messages' => '{0} No messages|{1} One message|[2,*] :count messages',
];
```

```php
__('greeting', ['name' => 'Ada']);
trans('account.title');
trans('greeting', ['name' => 'Ada'], 'fr'); // override for this call
trans_choice('messages', 5);
_e('greeting', ['name' => '<Ada>']); // returns escaped HTML
trans()->setLocale('fr'); // select from the application's supported locales first
```

`trans()` returns the `Spark\Translator\Translator` singleton (contract: `Spark\Translator\Contracts\TranslatorContract`). The constructor accepts language directories, not a file; use `addLanguageFile()` for individual files. `get($key, $replace, $locale, fallback: false)` disables fallback for one call. Otherwise lookup tries the locale, its parent locales, then the configured fallback and its parents. `setFallback(null)` removes only the configured fallback. Use `withLocale($locale)` for an isolated copy in concurrent work; Carbon uses this to retain custom paths and runtime lines.

Group files such as `resources/languages/en/auth.php` are loaded lazily by `get('auth.failed')`. Exact flat keys win, followed by group files, then nested flat keys. `addPath($directory)` adds a higher-priority source; `prepend: true` supplies defaults. `addLines($lines, $locale, $group)` overrides a group (`*` for flat lines). Files stay cached until `flush()`, which also removes runtime additions. `all($locale, $group)` inspects a catalogue. A missing-key callback can be registered with `handleMissingKeysUsing()`; its return value is ignored.

`choice()` / `trans_choice()` accept numeric counts, arrays, or Countable objects and supply `:count`. Use named CLDR category arrays for multilingual plurals; pipe/indexed forms follow `PluralRules::categories($locale)`. Exact counts and inclusive ranges override category selection. Rules cover the listed languages, with English-style fallback for others; numeric counts cannot retain visible trailing zeroes or ICU compact exponents. Use `:count` with `choice()`; `__()` delegates named, positional, and printf formatting to `Translator::format()`; `:name`, `:Name`, and `:NAME` support replacement case. In Blade, use `{{ __('greeting', ['name' => $name]) }}` for escaped output.
