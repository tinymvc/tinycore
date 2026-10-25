# Queries and Database Operations

Read this reference for database queries, model queries, transactions, and locks. All source paths and commands are relative to the application root.

---

## Model-First Query Architecture

> [!IMPORTANT]
> **Always Use Model Queries for Domain Entities**:
> When interacting with database tables that have corresponding application models (`User`, `Post`, `Order`, etc.), **always query through the Model**.
>
> **Never use `DB::table('users')` or `query('users')` for domain models**. Bypassing the model causes severe defects:
> - **Lost Attribute Casting**: Booleans remain integers `0`/`1`, JSON fields remain raw strings, and datetimes are not converted to `Spark\Carbon` instances.
> - **Lost Timestamps**: Bypasses automatic `created_at` and `updated_at` management.
> - **Lost Soft Deletes**: Bypasses `InteractsWithSoftDeletes`, accidentally exposing or overwriting trashed rows.
> - **Lost Relations & Scopes**: Disables `with()`, `whereHas()`, `withCount()`, and domain scopes like `scopePublished()`.
> - **No Hydration**: Returns unhydrated stdClass objects or arrays instead of active Model entities.

```php
// GOOD: Eloquent-style Model querying
$users = User::where('active', true)->latest()->take(20)->get();
$post = Post::findOrFail($id);
$posts = Post::with('author')->paginate(15);
```

### When are `DB::table()` or `query()` Appropriate?
Use raw query builders **only** for:
- Low-level table operations without an associated Model (e.g. migration verification, raw pivot table operations).
- Performance-critical bulk reads that deliberately bypass model hydration.
- Direct database schema maintenance utilities.

---

## Collection-First Querying

Always prefer `->get()` (which returns a `Spark\Support\Collection`) or `->paginate($limit)` (which returns a `Spark\Paginator\Paginator`) over `->all()` (which returns a raw array):

```php
// Collections (Preferred) vs Raw Arrays
$posts = Post::where('published', true)->latest()->get(); // Spark\Support\Collection<Post>
$postsArray = Post::where('published', true)->all();       // plain PHP array<Post>

// Rich Collection Operations
$titles = $posts->pluck('title');
$byAuthor = $posts->groupBy('author_id');
$active = $posts->filter(fn (Post $p) => $p->is_active);
$keyed = $posts->keyBy('id');
```

---

## Arrayable Support in Queries

Both `QueryBuilder` and `Model` query scopes accept `Arrayable` (including `Spark\Http\Input` and `Spark\Support\Collection`) in conditions and writes:

```php
// 1. Arrayable in WHERE conditions (converts to multiple equality checks):
$matchingPosts = Post::where(
    $request->validated()->only('category_id', 'status')
)->get();

// 2. Arrayable in UPDATE queries:
Post::whereKey($id)->update($request->validated());

// 3. Arrayable in INSERT queries:
DB::table('audit_logs')->insert(
    $request->validated()->merge(['created_at' => now()])
);
```

---

## Finding Records & Common Conditions

```php
// Finding records
$post = Post::find($id);                     // Returns Post|null
$post = Post::findOrFail($id);             // Returns Post or throws 404 NotFoundException
$post = Post::where('slug', $slug)->first(); // First matching record or null
$post = Post::where('slug', $slug)->firstOrFail();

// Ordering and Pagination
$posts = Post::latest()->paginate(20);               // Order by created_at DESC, returns Paginator
$postsByDate = Post::latest('published_at')->get();  // Order by custom column DESC
$oldest = Post::oldest('created_at')->first();       // Order by created_at ASC

// Scalar Values and Pluck
$email = User::where('id', $id)->value('email');     // Returns single scalar string|int|null
$roleMap = User::pluck('name', 'id');                // Returns array [id => name, ...]
$tagNames = Tag::pluck('name');                      // Returns array ['php', 'spark', ...]

// Aggregates & Existence
$count = Post::where('published', true)->count();
$exists = Post::where('slug', $slug)->exists();
$missing = Post::where('slug', $slug)->doesntExist();
$totalCredits = User::sum('credits');

// Advanced Where Clauses
$adults = User::whereBetween('age', [18, 65])->get();
$unverified = User::whereNull('email_verified_at')->get();
$verified = User::whereNotNull('email_verified_at')->get();
$recentlyModified = User::whereColumn('updated_at', '>', 'created_at')->get();
$sameNames = User::whereColumn('first_name', 'last_name')->get(); // Operator defaults to '='
$darkModeUsers = User::whereJsonContains('preferences', 'theme', 'dark')->get();

// Subqueries and Existence Checks
$activeCustomers = User::whereExists(function ($query) {
    $query->table('orders')->whereColumn('orders.user_id', 'users.id');
})->get();

$usersWithLastOrder = User::select('id', 'name')
    ->selectSub(function ($query) {
        $query->table('orders')
            ->selectRaw('MAX(created_at)')
            ->whereColumn('orders.user_id', 'users.id');
    }, 'last_order_at')
    ->get();

// Conditional Querying (when / unless)
$posts = Post::query()
    ->when($request->query('search'), function ($query, $search) {
        $query->whereContains('title', $search);
    })
    ->when($request->query('category_id'), function ($query, $categoryId) {
        $query->where('category_id', $categoryId);
    })
    ->latest()
    ->paginate(20);

// Grouped Boolean Logic with Closures
$users = User::where(function ($query) {
    $query->where('role', 'admin')
          ->orWhere('role', 'manager');
})->where('active', true)->get();
```

---

## Simple Pagination

Use `simplePaginate(int $perPage = 15, array|string $columns = ['*'], string $pageName = 'page', ?int $page = null)` when you need next/previous links without a total count. It returns `Spark\Paginator\SimplePaginator`, reads the named page query parameter by default, and fetches `$perPage + 1` rows using LIMIT/OFFSET. Only page items reach eager loading and custom mappers. The source builder remains reusable.

```php
$posts = Post::with('author')->orderBy('id')->simplePaginate(20);
return PostResource::collection($posts);

// Explicit page, selected columns, and a separate query parameter:
$page = Post::orderBy('id')->simplePaginate(20, ['id', 'title'], 'posts_page', 2);
$page->items();
$page->currentPage();
$page->hasMorePages();
$page->nextPageUrl();
```

Malformed request pages default to 1; non-positive sizes/pages normalize to 1. Out-of-range pages remain empty at the requested page number because there is no total to clamp against. There are no `total()`, `lastPage()`, or last-page URL methods on simple pagination.

`Paginator`, `SimplePaginator`, and `CursorPaginator` live in `Spark\Paginator` and share `AbstractPaginator` for collections, iteration, array access, transformations, JSON, escaped HTML links, and resource metadata. Numbered `Paginator` reuses `SimplePaginator` page navigation. Existing numbered `through()` still returns a transformed array without mutation; simple/cursor `through()` transforms items and returns the paginator. Use `map()` for in-place transformations on all three. URL paths and filters are captured when a paginator is constructed.

## Cursor Pagination

Use `cursorPaginate(int $perPage = 15, array|string $columns = ['*'], string $cursorName = 'cursor', Cursor|string|null $cursor = null)` for next/previous navigation. It returns `Spark\Paginator\CursorPaginator`, fetches at most `$perPage + 1` rows, and uses ordered comparisons instead of a count query or SQL offset.

```php
$posts = Post::with('author')
    ->where('published', true)
    ->orderBy('created_at', 'DESC')
    ->orderBy('id', 'DESC')
    ->cursorPaginate(20);

return PostResource::collection($posts);

// Explicit cursor, selected columns, and an independent URL parameter:
$page = Post::orderBy('id')->cursorPaginate(
    perPage: 20,
    columns: ['id', 'title'],
    cursorName: 'posts_cursor',
    cursor: $encodedCursor,
);

$page->items();             // Hydrated models; eager loading and soft deletes still apply
$page->nextCursor();        // Cursor|null; ->encode() produces the URL token
$page->previousPageUrl();   // string|null
$page->hasMorePages();      // Whether a next page exists
```

- Explicit ordering is required, including on model queries. The ordered columns must be selected, non-null, and uniquely identify a row together (usually add the primary key as the last order). Index the ordering columns for large lists.
- Repeated `orderBy()` calls now append ordering columns. `order('id ASC')` replaces an existing order; `orderAsc()` / `orderDesc()` retain their replacement behavior.
- Plain qualified columns and mixed ASC/DESC directions are supported. Ordering expressions, selected aliases, grouped queries, HAVING, and unions are unsupported and fail explicitly.
- A null cursor reads the named request query parameter. Malformed URL tokens start at the first page; valid cursors missing a required ordered column throw `InvalidArgumentException`. Cursors are encoded values, not authorization tokens; retain all access filters on every request.
- Existing selections are preserved with the default `$columns`. Cursor values come from database rows before model casting or custom mapping. The source builder remains reusable.
- Links preserve query filters. `items()`, iteration, `count()`, `getCollection()`, `through()`, `links()`, and JSON serialization are supported. `through()` transforms items in place while keeping navigation boundaries. There are no totals, numbered pages, or last-page URL.

## Date and Time Conditions with `now()`

Use `now()`, `today()`, or `Spark\Carbon` directly in date conditions:

```php
// Compare against current timestamp
$activeEvents = Event::where('starts_at', '<=', now())
    ->where('ends_at', '>=', now())
    ->get();

// Specific date parts
$todayPosts = Post::whereDate('created_at', '=', today()->toDateString())->get();
$currentYearPosts = Post::whereYear('created_at', '=', now()->year)->get();
$currentMonthPosts = Post::whereMonth('created_at', '=', now()->month)->get();
```

---

## Builder Methods Reference

Common QueryBuilder / Model methods:

- **Selection**: `select('id', 'title')`, `selectRaw('COUNT(*) as total')`, `selectSub($subquery, 'alias')`, `distinct()`, `column('title')`
- **Conditions**: `where('status', 'active')`, `orWhere('role', 'admin')`, `whereNull('deleted_at')`, `whereNotNull('published_at')`, `whereColumn('updated_at', '>', 'created_at')`
- **Lists & Ranges**: `whereIn('id', [1, 2, 3])`, `whereNotIn('status', ['archived'])`, `whereBetween('age', [18, 65])`, `whereNotBetween('score', [1, 5])`, `between('age', 18, 65)`
- **Strings**: `whereContains('bio', 'engineer')`, `whereStartsWith('sku', 'SPARK-')`, `whereEndsWith('email', '@example.com')`, `like('name', '%John%')`
- **JSON**: `whereJsonContains('settings', 'theme', 'dark')`, `whereJsonNotContains('settings', 'beta', true)`
- **Existence**: `whereExists(Closure $callback)`, `whereNotExists(Closure $callback)`, `exists()`, `doesntExist()`
- **Dates**: `whereDate('created_at', '=', today()->toDateString())`, `whereYear('created_at', '=', now()->year)`, `whereMonth('created_at', '=', now()->month)`
- **Relations**: `with()`, `withExists()`, `withCount()`, `withSum()`, `withAvg()`, `whereHas()`, `whereDoesntHave()`, `whereRelation()`
- **Ordering**: `orderBy('id', 'DESC')`, `orderDesc('id')`, `orderAsc('name')`, `latest('created_at')`, `oldest('created_at')`, `random()`
- **Limiting**: `take(10)`, `skip(20)`, `limit(offset: 0, limit: 10)`
- **Terminal Execution**: `first()`, `firstOrFail()`, `get()`, `all()`, `paginate(limit: 20)`, `simplePaginate(perPage: 20)`, `cursorPaginate(perPage: 20)`, `pluck('name', 'id')`, `value('title')`
- **Mutations & Writes**: `create([...])`, `firstOrCreate([...], [...])`, `firstOrNew([...], [...])`, `createOrUpdate([...])`, `upsert(...)`, `update([...])`, `delete()`, `destroy($id)`, `increment('views')`, `decrement('stock')`

---

## Upserts and Mass Writes

```php
Product::upsert(
    [
        ['sku' => 'SPARK-01', 'name' => 'Starter', 'price' => 25],
        ['sku' => 'SPARK-02', 'name' => 'Team', 'price' => 50],
    ],
    conflict: ['sku'],
    update: ['name', 'price'],
);
```

- Signature: `upsert(array|Arrayable $data, ?array $conflict = null, ?array $update = null)`.
- `conflict` specifies the unique constraint columns (defaults to `['id']`).
- `update` specifies the columns to update on conflict (omitted/null updates all non-conflict columns).

---

## Database Transactions and Row Locks

Use `Spark\Facades\DB::transaction()` to execute multi-step database writes atomically:

```php
use Spark\Facades\DB;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

$order = DB::transaction(function () use ($userId, $items): Order {
    $order = Order::create([
        'user_id' => $userId,
        'status' => 'pending',
        'ordered_at' => now(),
    ]);

    foreach ($items as $item) {
        // Lock product row for atomic inventory update
        $product = Product::whereKey($item['product_id'])
            ->lockForUpdate()
            ->firstOrFail();

        abort_if($product->stock < $item['quantity'], \Spark\Http\Response::STATUS_UNPROCESSABLE_ENTITY, 'Insufficient stock.');

        $product->decrement('stock', $item['quantity']);

        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => $item['quantity'],
            'unit_price' => $product->price,
        ]);
    }

    return $order;
});
```

- `lockForUpdate()` requests an exclusive SELECT lock (`FOR UPDATE`).
- SQLite uses database-level locks rather than row-level locks; design transactions accordingly when targeting SQLite.
