# Queries and Database Operations

> **Read when:** querying records, selecting pagination, writing SQL, or coordinating database writes.
> **Related:** [Models and relations](models-relations.md) · [Responses](responses.md) · [Advanced workflows](advanced-workflows.md) · [Migrations](migrations.md)
> Prefer model queries for domain entities; use `DB::table()` deliberately for raw rows and bulk operations.

Contents:

- [Connections and result contracts](#connections-and-result-contracts)
- [Prefixes and raw SQL](#prefixes-and-raw-sql)
- [Conditions, selection, and relations](#conditions-selection-and-relations)
- [Simple pagination](#simple-pagination)
- [Cursor pagination](#cursor-pagination)
- [Upserts and mass writes](#upserts-and-mass-writes)
- [Transactions and row locks](#transactions-and-row-locks)

## Connections and result contracts

Use `Spark\Facades\DB` or `Spark\Database\DB`. `DB::DRIVER_MYSQL`, `DRIVER_PGSQL`, and `DRIVER_SQLITE` identify supported SQL engines; other PDO constants do not imply grammar support. Connection names are application-defined strings.

`DB::connection()`, `connection(null)`, and `connection([])` return the shared default connection. `connection('reporting')` selects configured settings; `pdo()` aliases `getPdo()`.

| Read | Result |
| --- | --- |
| `get()` | Collection; prefer for collection operations |
| `all()` | Plain array |
| `first()`, `find($id)`, `last()` | Row/model or null |
| `firstOrFail()`, `findOrFail()` | Row/model or not-found exception |
| `value('email')` | Scalar or null |
| `pluck('name', 'id')` | Array keyed by id |
| `exists()`, `doesntExist()` | bool |
| `sum('credits')` | 0.0 when empty; avg/min/max return null |

Model queries retain scopes, casts, hydration, and archive filtering. `DB::table()` / `query()` bypass these; account for soft deletes and ownership yourself. Model bulk writes also differ from instance saves.

## Prefixes and raw SQL

Set `prefix` globally or per connection (`''` disables it). Use logical table names in models, queries, schema, and database-backed storage. Qualified columns get prefixes; aliases do not. Raw SQL stays verbatim: use the executing connection's `wrapTable('users')` for quoted/prefixed identifiers. Configuration does not rename existing tables.

Hydrated models retain their connection/prefix for writes, refresh, and relations. `$model->newQuery()` retains that context; static `Model::query()` starts from the default. Serialized models require a matching configured connection and throw when unavailable.

Set builder `prefix()`/`setDB()` and aliases before clauses; they cannot rewrite compiled SQL. `DB::raw()` creates a trusted expression; `run($sql, $bindings)` executes/fetches associative rows; `statement()` returns success/failure.

Bind input values. Positional raw bindings follow SQL clause order independently of method order. Do not mix named/positional placeholders; distinct values need distinct names. Conflicts, including expanded `whereIn()` placeholders, throw.

## Conditions, selection, and relations

```php
$posts = Post::with('author')
    ->when($filters->filled('q'), fn($query) => $query->whereContains('title', $filters->string('q')))
    ->where(function ($query) {
        $query->where('status', 'published')->orWhere('featured', true);
    })
    ->latest()
    ->get();
```

| Intent | Native methods/examples |
| --- | --- |
| Select | `select('id', 'title')`, `selectRaw('COUNT(*) AS total')`, `selectSub($query, 'alias')`, `distinct()` |
| Compare | `where('status', 'active')`, `orWhere()`, `whereNull()`, `whereNotNull()`, `whereColumn('updated_at', '>', 'created_at')` |
| Lists/ranges | `whereIn()`, `whereNotIn()`, `whereBetween('age', [18, 65])`, `whereNotBetween()`, `between('age', 18, 65)` |
| Text | `whereContains()`, `whereStartsWith()`, `whereEndsWith()`, `like('name', '%Ada%')` |
| JSON | `whereJsonContains('settings', 'theme', 'dark')`, `whereJsonNotContains()` |
| Subqueries | `whereExists(fn($q) => $q->table('orders')->whereColumn('orders.user_id', 'users.id'))`, `whereNotExists()` |
| Dates | `where('published_at', '<=', now())`, `whereDate('created_at', '=', today()->toDateString())`, `whereYear()`, `whereMonth()` |
| Relations | `with()`, `withExists()`, `withCount()`, `withSum()`, `withAvg()`, `whereHas()`, `whereDoesntHave()`, `whereRelation()` |
| Order | `orderBy('id', 'DESC')`, `latest('created_at')`, `oldest()`, `random()` |
| Bound results | `take(10)`, `skip(20)`, `limit(offset: 0, limit: 10)` |

Arrayable values, including validated Input, work in equality conditions and writes. Keep related IDs authorized. `orderBy()` appends columns; `order('id ASC')`, `orderAsc()`, and `orderDesc()` replace ordering.

## Simple pagination

`simplePaginate(int $perPage = 15, array|string $columns = ['*'], string $pageName = 'page', ?int $page = null)` returns `Spark\Paginator\SimplePaginator`. It reads the named query parameter and fetches perPage + 1 rows with LIMIT/OFFSET, without COUNT.

```php
return PostResource::collection(Post::with('author')->orderBy('id')->simplePaginate(20));
$page = Post::orderBy('id')->simplePaginate(20, ['id', 'title'], 'posts_page', 2);
```

Only page items reach eager loading/mappers. The source builder remains reusable. Malformed request pages default to 1; non-positive sizes/pages normalize to 1. Out-of-range pages stay empty at the requested number. There are no totals, last-page values, or last-page URLs.

## Cursor pagination

`cursorPaginate(int $perPage = 15, array|string $columns = ['*'], string $cursorName = 'cursor', Cursor|string|null $cursor = null)` returns `Spark\Paginator\CursorPaginator`. It uses ordered comparisons and at most perPage + 1 rows, without COUNT/OFFSET.

```php
return PostResource::collection(
    Post::with('author')->where('published', true)
        ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->cursorPaginate(20)
);
```

- Require explicit, selected, non-null ordering columns that uniquely identify rows together; add the primary key as a tiebreaker. Index them for large lists.
- Plain qualified columns and mixed directions work. Expressions, selected aliases, GROUP BY/HAVING, and unions fail explicitly.
- Null cursor reads the named request parameter. Malformed URL tokens start at page one; valid cursors missing required columns throw. Cursor encoding is not authorization: retain access filters on every request.
- Default columns preserve existing selections. Boundaries use raw row values before casts/mappers; declared model properties are populated before construction. Source queries remain reusable.
- `nextCursor()`/`previousCursor()` return Cursor or null; `encode()` gives the token. `nextPageUrl()`, `previousPageUrl()`, and `hasMorePages()` provide navigation. No totals, numbered pages, or last-page URL.

All three paginator types live in `Spark\Paginator` and share AbstractPaginator's collection/iteration/array/JSON/link/resource behavior. Paths and filters are captured at construction. `map()` transforms items in place; numbered `through()` returns a transformed array, while simple/cursor `through()` transforms and returns the paginator. Numbered `paginate()` consumes/resets its builder; simple/cursor retain it. Page ranges use actual items, with null resource ranges on empty pages.

## Upserts and mass writes

```php
Product::upsert(
    [['sku' => 'SPARK-01', 'price' => 25], ['sku' => 'SPARK-02', 'price' => 50]],
    conflict: ['sku'],
    update: ['price'],
);
```

Signature: `upsert(array|Arrayable $data, ?array $conflict = null, ?array $update = null)`. Conflict defaults to `['id']`; null update selects all non-conflict columns. Back conflict columns with the appropriate unique constraint.

`update()`, `delete()`/`forceDelete()`, `restore()`, `increment()`, and `decrement()` reject joins, grouping/HAVING, unions, ordering, limits/offsets, and bindings outside WHERE. Select intended IDs first, then write through a fresh constrained query; unsupported clauses are not silently dropped.

Relationship aggregate callbacks reject GROUP BY/HAVING/UNION, support row conditions/joins, discard ordering and its bindings, and retain limits/offsets. Self-relations alias their inner table: use unqualified scope/aggregate columns or `$query->getAlias()`. Hard-coded original-table qualifications can target the outer row and are rejected.

## Transactions and row locks

```php
use Spark\Facades\DB;

DB::transaction(function () use ($productId, $quantity) {
    $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
    abort_if($product->stock < $quantity, \Spark\Http\Response::STATUS_UNPROCESSABLE_ENTITY, 'Insufficient stock.');
    $product->decrement('stock', $quantity);
});
```

`lockForUpdate()` requests an exclusive SELECT lock; SQLite uses database-level locking. Use the same connection for coordinated writes. Follow [advanced workflows](advanced-workflows.md) for nesting, failure handling, retries, and external effects; there is no assumed after-commit API.

Verify query results, bindings/prefixes, affected rows, ordering/pagination boundaries, relation query counts, rollback, and target-driver behavior.
