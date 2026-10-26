# Spark 4.0 upgrade

Check the installed source and back up data before upgrading. Paths below refer to the application root.

## Storage and deployment

- Stop workers/producers and drain old queues before changing drivers or formats. Old SQLite cache/queue files, Redis queue indexes, and JSON migration ledgers are not imported automatically.
- Baseline applied migration filenames in the SQL ledger before migrating an existing schema; an empty ledger treats every migration as pending. Keep historical migrations and add new ones for missing framework tables.
- Move uploads to `storage/app/public`, private files to `storage/app/private`, and rebuild the public uploads link. Session/cookie format changes may require fresh logins.
- `cache:clear` clears the default cache plus compiled views/config, preserving locks; clear named caches explicitly. `key:generate` preserves existing keys.

## Database and pagination

- Rename execute-and-fetch `DB::raw(...)` / `$query->raw(...)` calls to `run(...)`; `raw()` now creates an SQL expression.
- Configure connection `prefix` for queries, schema, and database-backed services. Keep table names logical/unprefixed; existing tables are not renamed. See [queries](queries.md#prefixes-and-raw-sql).
- `find()`, `first()`, and `last()` return `null` when absent; update strict `false` checks and guard dereferences. `value()` returns null for a missing row or SQL NULL. Use `findOrFail()` / `firstOrFail()` for required records; unrelated false-return contracts remain unchanged.
- Move paginator imports from `Spark\Utils` to `Spark\Paginator`, including its `Contracts\PaginatorContract`. Repeated `orderBy()` calls now append; use `order()` to replace ordering. Cursor pagination requires selected, non-null columns forming a unique order. See [queries](queries.md#cursor-pagination).

## HTTP and adapters

- Existing paths with unsupported methods return 405 plus `Allow`; global middleware also runs on 404/405 paths. Review route-parameter assumptions.
- Pass unencoded named-route values and supply required parameters. `getPath()` retains encoding; route arguments decode once. Forwarded schemes require trusted proxies.
- JSON parsing requires a JSON `Content-Type`; URL-encoded PUT/PATCH/DELETE bodies parse as forms. Headers/statuses use Nyholm validation; PSR imports preserve streams and do not infer media types.
- Replace removed `fireline()`, `Router::fireline()`, and `Request::isFirelineRequest()` through the optional [FireLine adapter](fireline.md). Localization uses `app.locale`, `app.locale_dir`, and `locale_dir()`.
