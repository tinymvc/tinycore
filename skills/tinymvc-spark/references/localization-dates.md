# Localization and Dates

> **Read when:** translating content, choosing timezones, or working with application dates.
> **Related:** [Helpers](helpers.md) · [Models and relations](models-relations.md) (casts) · [Responses](responses.md) (serialization)
> Use `Spark\Carbon`; application paths are relative to the app root.

Contents:

- [Application dates and timezones](#application-dates-and-timezones)
- [Translation catalogues](#translation-catalogues)
- [Localized dates](#localized-dates)

## Application dates and timezones

Use `now()`, `today()`, `carbon()`, or `Spark\Carbon`. Application boot sets `app.timezone` (default UTC); current dates follow that timezone unless overridden. Calendar arithmetic differs from elapsed seconds across daylight-saving transitions. Performance timers are separate from application dates.

| Need | Native API |
| --- | --- |
| Current UTC datetime | `now('UTC')->toDateTimeString()` |
| Today's date | `today()->toDateString()` |
| Unix timestamp | `now()->timestamp` |
| Tomorrow / 24 elapsed hours | `now()->addDay()` / `now()->addSeconds(86400)` |
| Parse input / specific format | `carbon($text)` / `Carbon::createFromFormat('d/m/Y', $text)` |
| From Unix seconds | `Carbon::createFromTimestamp($timestamp)` |
| Calendar boundaries | `now()->startOfDay()`, `now()->endOfMonth()` |
| Relative state | `$date->isPast()`, `$date->isFuture()`, `$date->diffForHumans()` |
| Display / ISO | `$date->format('M j, Y')`, `toIsoString()`, `toIsoUtcString()` |

Pass Carbon directly to query conditions and writes:

```php
$posts = Post::where('published_at', '<=', now())->latest()->get();
$today = Post::whereDate('created_at', '=', today()->toDateString())->get();
```

Model timestamps and `date`/`datetime` casts already return Carbon. Pass them directly through resources: Carbon normalizes to UTC ISO-8601; other `DateTimeInterface` values use ATOM. Format explicitly for display or an existing API contract. Let model writes maintain automatic timestamps.

## Translation catalogues

Store PHP arrays in `resources/languages/{locale}.php` (`app.locale_dir`). The translator uses `app.locale` and `app.fallback_locale` (default `en`); missing keys return unchanged.

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
trans('greeting', ['name' => 'Ada'], 'fr');
trans_choice('messages', 5);
_e('greeting', ['name' => '<Ada>']); // returns escaped HTML
```

- `trans()` returns the Translator singleton. Select an allowed locale before `setLocale()`; use `withLocale()` for an isolated copy in concurrent work.
- Lookup tries the locale, its parents, then fallback and its parents. `get(..., fallback: false)` disables fallback for one call; `setFallback(null)` removes the configured fallback.
- Exact flat keys win, then lazy group files (`en/auth.php` for `auth.failed`), then nested flat keys.
- Constructors take language directories. `addLanguageFile()` adds one file; `addPath()` adds a higher-priority directory (`prepend: true` supplies defaults).
- `addLines($lines, $locale, $group)` overrides a group (`*` for flat lines). Catalogues stay cached until `flush()`, which also removes runtime additions. `all()` inspects a catalogue; `handleMissingKeysUsing()` observes misses and ignores callback returns.
- `choice()` accepts numeric counts, arrays, or Countable objects and supplies `:count`. Named CLDR category arrays suit multilingual plurals; pipe/indexed forms follow `PluralRules::categories($locale)`. Exact counts/ranges override categories. Unsupported locales use English-style rules; numeric values cannot retain visible trailing zeroes or ICU compact exponents.
- `__()` supports named, positional, and printf replacements; `:name`, `:Name`, `:NAME` control case. In Blade use `{{ __('greeting', ['name' => $name]) }}`.

## Localized dates

Carbon reuses the app's language paths and runtime lines. Prefer per-instance locales when serving multiple users:

```php
$date = carbon('2026-01-05 12:00:00')->locale('bn'); // returns a copy
$date->diffForHumans();
$date->diffForHumansShort();
$date->translatedFormat('l, j F Y');
$date->getDayName();
$date->getMonthName();
```

`Carbon::setLocale('bn')` changes the process default; `setLocale(null)` restores the app default. Standalone Carbon falls back to English.

Language files translate complete phrases (`'%d minute ago'`, `'in %d minutes'`, `'%dm ago'`, `'in %dm'`, `'%dm'`, `'now'`, `'a few seconds ago'`, `'in a few seconds'`). Other units are second/hour/day/month/year; short units are `s`, `m`, `h`, `d`, `mo`, `y`. Missing phrases fall back to English.

`translatedFormat()` translates `l`, `D`, `F`, `M`, `a`, `A` using English names as keys, such as `'Monday' => 'সোমবার'`. Parsing, ordinary `format()`, ISO output, and JSON stay locale-independent.

Verify timezone boundaries, daylight-saving arithmetic, fallback/plural selection, and two users with different locales in one process.
