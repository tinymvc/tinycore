# Models and Relationships

> **Read when:** defining models, casts, scopes, lifecycle callbacks, or ORM relationships.
> **Related:** [Queries](queries.md) · [Soft deletes](soft-deletes.md) · [Responses](responses.md) · [Advanced workflows](advanced-workflows.md)
> Use `Spark\Database\Model`; model binding and relations do not replace authorization.

Contents:

- [Define a model](#define-a-model)
- [Casts, accessors, and mutators](#casts-accessors-and-mutators)
- [Writes and state](#writes-and-state)
- [Lifecycle callbacks](#lifecycle-callbacks)
- [Define and load relations](#define-and-load-relations)
- [Pivot writes and related creation](#pivot-writes-and-related-creation)

## Define a model

```php
namespace App\Models;

use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\BelongsTo;

class Post extends Model
{
    protected string $table = 'posts';
    protected array $fillable = ['title', 'slug', 'content', 'published', 'published_at'];
    protected array $casts = ['published' => 'boolean', 'published_at' => 'datetime'];

    public function scopePublished(QueryBuilder $query): QueryBuilder
    {
        return $query->where('published', true)->whereNotNull('published_at');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, foreignKey: 'user_id');
    }
}
```

Defaults: snake-case plural table, primary key `id`, automatic `created_at`/`updated_at` Carbon timestamps. Disable timestamps with `protected const USE_TIMESTAMPS = false`; enable soft deletes with `USE_SOFT_DELETES = true` and the required nullable column. Assign server-owned identity explicitly; `$fillable` is not authorization.

## Casts, accessors, and mutators

Built-in casts: `int`/`integer`, `float`, `bool`/`boolean`, `string`, `array`, `json`, `datetime`, `date`, `timestamp`, `encrypted`. Date casts return `Spark\Carbon`; pass them directly to resources.

```php
use Spark\Database\Casts\Attribute;

protected function title(): Attribute
{
    return Attribute::make(
        get: fn($value) => ucfirst($value),
        set: fn($value) => strtolower(trim($value)),
    );
}
```

Classic `getFullNameAttribute()` / `setPasswordAttribute($value)` methods are also supported. A password mutator uses `$this->set('password', Hash::password($value))`, with `Spark\Facades\Hash`; `Hash::make()` is HMAC, not password hashing. Avoid hashing an already-hashed value twice.

Custom cast classes implement `Spark\Database\Contracts\CastsAttributes::get($value)` and `set($value)` and appear by class name in `$casts`. Inspect that contract before adding signatures.

## Writes and state

| Operation | Contract |
| --- | --- |
| `Post::create($input)` | Persisted model; accepts array/Arrayable |
| `firstOrCreate($attributes, $values)` | Find or insert |
| `firstOrNew($attributes, $values)` | Find or return an unpersisted model |
| `createOrUpdate($input, uniqueBy: ['slug'], values: [...])` | Native create/update helper; verify uniqueness in schema |
| `$post->fill($input)->save()` | bool; handle false when success matters |
| `$post->remove()` | bool; instance deletion API |
| `Post::destroy($id)` / `destroy([$id1, $id2])` | Delete by keys; inspect lifecycle requirements |
| Query `update()` / `delete()` | Bulk write; affected rows, no per-model callback guarantee |

Use `$request->validated()` directly where Arrayable is accepted. On instances, use `remove()`, not query `delete()`.

`isDirty($field)`, `isClean()`, `getOriginal($field)`, and `getChanges()` inspect state. After saving, `wasChanged($field)`, `wasCreated()`/`wasNewlyCreated()`, and `wasUpdated()` describe the last write. `is($other)` compares row identity; `refresh()` reloads it. `makeHidden()` / `makeVisible()` adjust serialization, but explicit resources still define public fields.

Loaded models retain their stored primary key for writes/refresh even if the key attribute changes. A successful key write updates that identity; conflicts raise the database error. Hydrated connection/prefix context also survives instance writes and relations; see [queries](queries.md#prefixes-and-raw-sql).

## Lifecycle callbacks

```php
use Spark\Database\Events;
use Spark\Facades\Cache;

protected function events(): Events
{
    return Events::make(
        updated: fn() => Cache::forget("post:{$this->id}"),
        deleted: fn() => Cache::forget("post:{$this->id}"),
    );
}
```

These are model callbacks, distinct from the application dispatcher. `model.action` telemetry observes the model's triggered actions; neither callback nor telemetry implies transaction commit. External effects need an explicit [commit/dispatch policy](advanced-workflows.md#commit-dispatch-and-external-effects).

## Define and load relations

| Relation | Native expression inside model |
| --- | --- |
| One-to-one | `$this->hasOne(Profile::class, foreignKey: 'user_id')` |
| One-to-many | `$this->hasMany(Comment::class, foreignKey: 'post_id')` |
| Belongs-to | `$this->belongsTo(User::class, foreignKey: 'user_id')` |
| Many-to-many | `$this->belongsToMany(Tag::class, table: 'post_tags', foreignPivotKey: 'post_id', relatedPivotKey: 'tag_id')` |
| Through | `$this->hasManyThrough(Comment::class, through: Post::class, firstKey: 'user_id', secondKey: 'post_id')` |

Return the matching class from `Spark\Database\Relation`. Prefer relations when they express the domain association.

```php
$posts = Post::with(['author', 'tags'])->withCount('comments')->latest()->get();
$post->load(['author', 'comments.author']);
$posts = Post::whereHas('author', fn($q) => $q->where('is_active', true))->get();
$posts = Post::whereRelation('author', 'role', 'admin')->get();
$posts = Post::withSum('votes', 'score')->get();
```

`whereDoesntHave()` selects absence. Eager-load before serialization and use resource `whenLoaded()` / `whenCounted()`. Retain tenant/ownership filters on both parent and related queries. Archive scopes are independent on each relation; see [soft deletes](soft-deletes.md#relationship-and-database-boundaries).

## Pivot writes and related creation

```php
$post->tags()->attach([1, 2]);
$post->tags()->detach([1]);
$post->tags()->sync($request->validated('tag_ids', []));
$post->tags()->toggle([1, 2]);
$post = $user->posts()->create($input);
$post = $user->posts()->firstOrCreate(['slug' => $slug], $input->except('slug'));
```

`sync()` replaces associations; `toggle()` switches them. String IDs must be passed in an array (`['editor', 'reviewer']`); zero IDs and attribute maps keyed by zero are supported. Validate ownership of related IDs before attaching them. Related create helpers accept array/Arrayable and retain the parent's foreign-key condition.

Verify casts and failed writes, stored identity, relation query counts, pivot replacement, wrong-owner access, and the persisted result of callbacks/bulk writes.
