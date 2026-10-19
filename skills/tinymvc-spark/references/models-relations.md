# Models and Relationships

Read this reference for models, casts, accessors, mutators, state inspection, query scopes, model events, and ORM relationships. All source paths and commands are relative to the application root.

---

## Defining Models

Application models extend **`Spark\Database\Model`**.

```php
<?php

namespace App\Models;

use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\BelongsTo;
use Spark\Database\Relation\HasMany;
use Spark\Database\Events;
use Spark\Database\Casts\Attribute;
use App\Jobs\SendPostPublishedMail;
use Spark\Facades\Cache;

class Post extends Model
{
    protected string $table = 'posts';

    protected array $fillable = [
        'user_id',
        'title',
        'slug',
        'content',
        'published',
        'published_at',
        'meta',
    ];

    /**
     * Attribute type casting.
     * 'date', 'datetime', and 'timestamp' casts automatically hydrate Spark\Carbon instances.
     * Supported built-ins: 'int'/'integer', 'float', 'bool'/'boolean', 'string', 'array', 'json', 'datetime', 'date', 'timestamp', 'encrypted'.
     */
    protected array $casts = [
        'published' => 'boolean',
        'published_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * Local Query Scope.
     * Called statically or fluently: Post::published()->get()
     */
    public function scopePublished(QueryBuilder $query): QueryBuilder
    {
        return $query->where('published', true)->whereNotNull('published_at');
    }

    /**
     * Native Model Lifecycle Reactions.
     */
    protected function events(): Events
    {
        return Events::make(
            created: fn() => SendPostPublishedMail::dispatch($this->id)->onQueue('emails'),
            updated: fn() => Cache::forget("post:{$this->id}"),
            deleted: fn() => Cache::forget("post:{$this->id}"),
        );
    }

    /**
     * Relation: Post belongs to an author User.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, foreignKey: 'user_id');
    }

    /**
     * Relation: Post has many Comments.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, foreignKey: 'post_id');
    }
}
```

### Model Defaults & Conventions
- **Table Name**: Defaults to the snake_case plural of the class name (`posts` for `Post`).
- **Primary Key**: Defaults to `id`.
- **Timestamps**: Enabled by default (`created_at` and `updated_at`). Automatically hydrated as `Spark\Carbon` instances.
  - Disable with `protected const USE_TIMESTAMPS = false;`.
- **Soft Deletes**: Enable with `protected const USE_SOFT_DELETES = true;` (requires a nullable `deleted_at` timestamp).

---

## Accessors, Mutators, and Custom Casts

TinyCore supports both modern closure-based attribute objects and classic accessor/mutator methods:

### 1. Modern Attribute Casts (`Spark\Database\Casts\Attribute`)

Define getters and setters in a single method returning `Spark\Database\Casts\Attribute`:

```php
use Spark\Database\Casts\Attribute;

protected function title(): Attribute
{
    return Attribute::make(
        get: fn ($value) => ucfirst($value),
        set: fn ($value) => strtolower(trim($value)),
    );
}

// Read-only virtual attribute (e.g. $user->full_name)
protected function fullName(): Attribute
{
    return Attribute::make(
        get: fn () => "{$this->first_name} {$this->last_name}",
        set: fn ($value) => $value,
    );
}
```

### 2. Classic Accessors and Mutators

```php
// Accessor: invoked when accessing $user->full_name or $user->first_name
public function getFullNameAttribute(): string
{
    return "{$this->first_name} {$this->last_name}";
}

// Mutator: invoked when setting $user->password = 'secret'
public function setPasswordAttribute(string $value): void
{
    $this->set('password', \Spark\Facades\Hash::make($value));
}
```

### 3. Custom Cast Classes

Create a class implementing `Spark\Database\Contracts\CastsAttributes`:

```php
namespace App\Casts;

use Spark\Database\Contracts\CastsAttributes;

class AddressCast implements CastsAttributes
{
    public function get($value)
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }

    public function set($value)
    {
        return is_array($value) ? json_encode($value) : $value;
    }
}

// In Model:
protected array $casts = [
    'address' => \App\Casts\AddressCast::class,
];
```

---

## Model State & Inspection

TinyCore provides rich built-in methods to inspect model state and changes:

```php
$user = User::find(1);

// Change an attribute
$user->name = 'Jane Doe';

// 1. Dirty & Clean checks
$user->isDirty();            // true (any attribute modified)
$user->isDirty('name');      // true ('name' modified)
$user->isDirty('email');     // false ('email' not modified)
$user->isClean();            // false

// 2. Original values vs changes
$user->getOriginal('name');  // 'John Doe' (original DB value)
$user->getChanges();         // ['name' => 'Jane Doe']

// 3. Persist and check lifecycle flags
$user->save();
$user->wasChanged('name');   // true
$user->wasNewlyCreated();    // false (it was an update)
$user->wasCreated();         // false
$user->wasUpdated();         // true

// 4. Model Equivalence Check (checks ID and table match)
if ($user->is($currentUser)) {
    // Both instances represent the exact same database row
}

// 5. Reload Fresh Data from DB
$user->refresh();

// 6. Dynamic Visibility for API serialization
$user->makeVisible(['api_token']);
$user->makeHidden(['created_at', 'updated_at']);
```

---

## Model Actions & Lifecycle

All creation and mutation methods in TinyCore natively accept `array|Arrayable` (including instances of `Spark\Http\Input` returned by `$request->validated()`):

```php
// 1. Creation - returns newly persisted, hydrated Model instance
$post = Post::create($request->validated());

// 2. First or Create - finds matching record or creates it
$post = Post::firstOrCreate(
    ['slug' => $slug],
    $request->validated()->except('slug')
);

// 3. First or New - finds matching record or returns an unpersisted instance
$post = Post::firstOrNew(['slug' => $slug]);

// 4. Create or Update - creates or updates based on unique keys
$post = Post::createOrUpdate(
    $request->validated(),
    uniqueBy: ['slug'],
    values: ['updated_at' => now()]
);

// 5. In-Memory Filling & Saving
$post->fill($request->validated());
if (!$post->save()) {
    abort(500, 'Unable to update the post.');
}

// 6. Deleting a Model Instance
// Always use ->remove() to delete a loaded model instance:
if (!$post->remove()) {
    abort(500, 'Unable to remove the post.');
}

// 7. Destroy by Primary Key
Post::destroy($id);
Post::destroy([1, 2, 3]);
```

> [!IMPORTANT]
> **Deletion Method Name**:
> On a loaded model instance, the method to delete is **`$model->remove()`**, which returns a `bool`.
> Do **not** call `$model->delete()` on an instance; query-builder `delete()` is for queries (`Post::whereKey($id)->delete()`).

---

## Native Relationships

Prefer TinyCore's native ORM relationships over manual `join()` or `whereRaw()` queries whenever the relationship expresses the requirement.

### 1. Defining Relationships

```php
// One-to-One
public function profile(): HasOne
{
    return $this->hasOne(Profile::class, foreignKey: 'user_id');
}

// One-to-Many
public function posts(): HasMany
{
    return $this->hasMany(Post::class, foreignKey: 'user_id');
}

// Inverse / Belongs To
public function author(): BelongsTo
{
    return $this->belongsTo(User::class, foreignKey: 'user_id');
}

// Many-to-Many
public function tags(): BelongsToMany
{
    return $this->belongsToMany(
        related: Tag::class,
        table: 'post_tags',
        foreignPivotKey: 'post_id',
        relatedPivotKey: 'tag_id',
    );
}

// Has-Many-Through (e.g. User -> Post -> Comment)
public function comments(): HasManyThrough
{
    return $this->hasManyThrough(
        related: Comment::class,
        through: Post::class,
        firstKey: 'user_id',
        secondKey: 'post_id',
    );
}
```

### 2. Querying with Relations

```php
// Eager Loading (prevents N+1 queries)
$posts = Post::with(['author', 'tags'])
    ->withCount('comments')
    ->latest()
    ->get();

// Eager Loading on an already-loaded model instance
$post->load(['author', 'comments.author']);

// Filtering models based on relationship existence (whereHas / whereDoesntHave)
$postsWithComments = Post::whereHas('comments')->get();

$activeAuthorPosts = Post::whereHas('author', function ($query) {
    $query->where('is_active', true);
})->get();

// Relationship attribute conditions (whereRelation)
$adminPosts = Post::whereRelation('author', 'role', 'admin')->get();

// Aggregate Relations
$posts = Post::withCount('comments')
    ->withSum('votes', 'score')
    ->get();
```

### 3. Many-to-Many Pivot Operations

Manage `belongsToMany` pivot table associations using fluent methods on the relation:

```php
// Attach tags
$post->tags()->attach([1, 2]);

// Detach tags
$post->tags()->detach([1]);

// Sync tags (replaces current associations with the specified list)
$post->tags()->sync($request->validated('tag_ids', []));

// Toggle tags (attaches if detached, detaches if attached)
$post->tags()->toggle([1, 2]);
```

### 4. Creating Related Records

Create related models directly through relationship instances using `array|Arrayable`:

```php
// Create a new post for an author user
$post = $user->posts()->create($request->validated());

// First or create through relation
$post = $user->posts()->firstOrCreate(
    ['slug' => $slug],
    $request->validated()->except('slug')
);
```
