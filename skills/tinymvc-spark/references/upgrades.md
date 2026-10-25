# Upgrades

Read this reference for spark 4.0 upgrade. Source paths and command working directories refer to the application root unless explicitly stated otherwise. Check the installed package when versions differ.

## Spark 4.0 upgrade

This reference targets the 4.0 source. The skeleton dependency is `tinymvc/tinycore: ^4.0`; release tags are still managed separately. Driver configuration, migration history, public/private paths, and removed APIs require an explicit application upgrade.

Back up the database and files. Stop workers/producers and drain old queues before changing storage formats. Migration history is database-only; the core does not read, import, or detect old JSON ledgers. Before running migrations on an existing schema, baseline its applied filenames in the SQL ledger through reviewed application-specific upgrade tooling. An empty ledger means every migration is pending.

Keep historical migrations unchanged. Add a new migration for missing framework tables; do not replace an already-applied users migration with the fresh skeleton file. Rebuild the public uploads link to `storage/app/public` after moving files; private data belongs under `storage/app/private`. Old SQLite cache/queue files and Redis queue indexes are not automatically migrated.

`fireline()`, `Router::fireline()`, and `Request::isFirelineRequest()` have been removed. The optional `tinymvc/fireline-php` adapter registers `Route::fire()`, `fire()`, and `is_fire_js()` for FireLine v2; these are not core APIs. Follow [FireLine](fireline.md) for its provider, middleware, and conditional layouts. Use `app.locale`, `app.locale_dir`, and `locale_dir()` for localization. Existing native session locations, cookie names, and old stateless remember-cookie formats may require fresh logins.

`cache:clear` clears the default cache and compiled views/configuration while preserving active locks and unrelated temporary files. Clear other named caches explicitly with `cache($name)->flush()`. `key:generate` preserves existing application keys.


## Nullable single-row lookups

`find()` and `first()` now return `null` when no record matches, replacing `false`. `last()` follows the same empty-result contract; `value()` returns null for a missing row or a SQL NULL column. Change lookup-specific strict false checks and wrapper return annotations to null, and guard property/array access. Use `findOrFail()` / `firstOrFail()` when absence should throw `Spark\Exceptions\NotFoundException` (normally a 404).

Do not replace unrelated false checks: writes, standalone validation, and raw PDO calls retain their own contracts. Scalar fetches can contain zero, false, or an empty string. Re-test absent users/tokens, route-bound records, soft-deleted records, empty relations, and lookup-or-create flows against the installed version.

## HTTP and routing interoperability

- Prefer `Request::METHOD_*` and `Response::STATUS_*` constants in PHP. Both native HTTP classes and client contracts inherit the standard FIG constants; `Response::STATUS_PAGE_EXPIRED` adds the framework's 419 code.

- Expect 405 plus `Allow` for an unsupported method on an existing path; fallback handlers apply to missing paths.
- Global middleware now runs on fallback/404/405 paths too. Review middleware that assumes every request has a particular route parameter.
- Named-route values are now percent-encoded per segment and required parameters must be supplied. Pass raw values rather than pre-encoded values.
- `Request::getPath()` retains percent encoding; captured route arguments are decoded once. Forwarded scheme headers require a configured trusted proxy.
- Raw JSON parsing follows `Content-Type`; use `application/json` or a `+json` media type. URL-encoded PUT/PATCH/DELETE bodies are parsed as forms.
- Response header names/values and status codes now use Nyholm validation. PSR imports preserve streams and do not infer an absent media type. Read non-seekable bodies only when ready to consume them.
- The package includes the two PSR-15 interface packages for middleware/handler integration. Native request/response helper APIs remain available.

## Cursor pagination and compound ordering

Pagination classes moved from `Spark\Utils` to `Spark\Paginator`: update imports for `Paginator`, `CursorPaginator`, and `Cursor`. The numbered paginator contract moved to `Spark\Paginator\Contracts\PaginatorContract`; no aliases remain in `Utils`. `paginator()` and existing `paginate()` arguments remain unchanged. `simplePaginate()` adds LIMIT/OFFSET pagination with no count query. All three paginator variants share item/URL/rendering behavior and resource integration through `AbstractPaginator`; numbered pagination retains its legacy non-mutating `through()` return. Generated HTML now consistently escapes URLs, CSS classes, and labels, and links retain the request URL/filter snapshot from construction.

`cursorPaginate()` adds next/previous pagination without a total-count query. Explicitly order by selected, non-null columns that form a unique key. Repeated `orderBy()` calls now append columns, allowing `orderBy('created_at')->orderBy('id')`; callers that previously used a second `orderBy()` to replace the first should use `order('id ASC')`. Existing `orderAsc()` and `orderDesc()` replacement behavior is unchanged. See [queries](queries.md#cursor-pagination) for restrictions and [responses](responses.md#cursor-pagination-envelopes) for resource metadata.
