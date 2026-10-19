# Soft Deletes

Read this reference for soft deletes. Source paths and command working directories refer to the application root unless explicitly stated otherwise. Check the installed package when versions differ.

## Soft Deletes

### Model and schema setup

Add a nullable deletion column and enable the model constant:

```php
use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;

// In a new migration for an existing table:
Schema::table('posts', function (Blueprint $table) {
    $table->softDeletes();
});
```

```php
namespace App\Models;

use Spark\Database\Model;

class Post extends Model
{
    protected const USE_SOFT_DELETES = true;
    protected string $table = 'posts';
    protected array $fillable = ['title', 'body', 'user_id'];
    protected array $casts = ['deleted_at' => 'datetime'];
}
```

`softDeletes()` takes no arguments and returns void; do not chain modifiers. The current Model already includes the soft-delete trait, attaches itself to queries, and composes scoped WHERE clauses. No application compatibility override or Laravel trait is needed. For an older installed package, verify these capabilities before generating code that relies on them.

Apply the column migration before enabling the model. A rollback uses `$table->dropColumn('deleted_at')`; remove the model behavior before dropping the column. For a custom column, set `SOFT_DELETE_COLUMN = 'archived_at'`, cast it if needed, and create `$table->timestamp('archived_at')->nullable()`.

### Select, archive, restore, and purge

```php
$active = Post::latest()->get();
$trash = Post::onlyTrashed()->latest()->paginate(20);
$all = Post::withTrashed()->latest()->get();
$activeAgain = Post::withoutTrashed()->get();

$post = Post::findOrFail($id);
$post->remove(); // Soft deletes the instance
$archived = Post::onlyTrashed()->findOrFail($id);
$isArchived = $archived->trashed();
$restored = $archived->restore();

$restoredAny = Post::onlyTrashed()->where('user_id', $userId)->restore();
$purged = Post::onlyTrashed()->where('user_id', $userId)->forceDelete();
```

| Call on a soft-delete model | Effect |
| --- | --- |
| Normal read / `withoutTrashed()` / `withTrashed(false)` | Active rows only |
| `onlyTrashed()` | Archived rows only |
| `withTrashed()` | Active and archived rows |
| `where(...)->delete()` | Archives matching active rows by default |
| `onlyTrashed()->delete()` | Re-stamps archived rows; does not physically delete them |
| `withTrashed()->delete()` | Stamps both active and archived rows |
| `onlyTrashed()->restore()` / `withTrashed()->restore()` | Restores archived rows, optionally narrowed by WHERE |
| `withoutTrashed()->restore()` | No changes: selection contains only active rows |
| `onlyTrashed()->forceDelete()` | Permanently deletes the entire trash |
| `withTrashed()->forceDelete()` | Permanently deletes all rows |
| `withoutTrashed()->forceDelete()` | Permanently deletes active rows |
| `where('id', $id)->forceDelete()` | Deletes that ID whether active or archived |
| Bare `Post::query()->delete()` / `forceDelete()` / `restore()` | Returns zero/false because there is no explicit selection |

The last trash-scope call wins and keeps existing WHERE conditions. An explicit scope satisfies the bulk-write guard; it is not ownership authorization. Add account/tenant conditions before archive/restore/purge operations that belong to one user.

`remove()` and both restore entry points return bool; builder delete/forceDelete return affected-row counts. Call bulk restore on a builder, since `Post::restore()` collides with the non-static model method. A loaded `$post->forceDelete()` is constrained by its primary key. Deletion does not update a previously loaded object's deletion timestamp: re-fetch with `onlyTrashed()` / `withTrashed()` before `trashed()`. Ordinary `refresh()` cannot fetch archived records.

### Relationship and database boundaries

- Enable soft deletes independently on each related model. `$user->posts()->onlyTrashed()->forceDelete()` and `restore()` retain the parent foreign-key condition.
- Eager loading, relationship existence checks, and aggregates all use the related model's prepared scope. `User::has('posts')` ignores archived posts; `doesntHave('posts')` includes users whose posts are all archived. `withCount('posts')` counts active posts; `withCount('posts as all_posts', fn($q) => $q->withTrashed())` includes archives. Custom deletion-column names work too.
- Set archive scopes independently on parent and related queries, and on each loading/filtering/aggregate operation. `User::withTrashed()->with('posts')` still loads only active posts. Nested paths scope every model; a `whereHas('posts.comments', $callback)` callback changes only the deepest (`comments`) query. Use nested callbacks to override intermediate scopes.
- A `belongsTo` owner hidden by its soft-delete scope loads as null; include it explicitly with `with(['user' => fn($q) => $q->withTrashed()])` when needed. Relationship-definition callbacks apply to lazy/eager reads, but existence/aggregate queries use only their own supplied callback.
- Automatic deletion predicates are driver-quoted and qualified using the read alias or actual table name. Qualify user-written join conditions separately. Writes target the physical table and do not emit read aliases; use unaliased write conditions.
- `hasManyThrough()` filters both the final model and a soft-deletable intermediate model by default. `withTrashedParents()` includes archived intermediates; `withTrashedParents(false)` restores their active-only scope. It works on the relation definition, direct relation, or loading/existence/aggregate callback. `withTrashed()` controls final records independently. Custom intermediate deletion columns are respected.
- Plain pivot tables have no model scope. When links deliberately have a deletion column, declare `->wherePivotNull('deleted_at')` on the relation; it applies to lazy/eager reads, `has()`, and aggregates. A separate `wherePivotNotNull()` relation can read archived links. Neither `withTrashed()` nor `withTrashedParents()` removes a pivot condition. `detach()` / `sync()` still physically delete links; this filter does not add a pivot archive lifecycle.
- Arbitrary `join()` calls do not infer soft-delete settings for other tables. Supply explicit conditions for those joins; ordinary pivot tables without deletion columns need no change.
- `query('posts')`, raw SQL, and non-soft-delete models have no automatic archive behavior. Trash switches on those builders do not authorize unfiltered writes.
- Soft deletion is an update, so it does not trigger foreign-key delete cascades or release a normal unique constraint. Decide explicitly whether a conflicting archived record should be restored.
- Bulk writes do not fire callbacks once per row or cascade archive/restore operations. There are no dedicated restoring/restored callbacks in the current model event set.

### Regression cases for a trash feature

Use an isolated database with active and archived rows belonging to at least two owners. Verify ordinary reads hide archived rows, trash reads hide active rows, restore clears the timestamp, and permanent deletion removes only the selected rows. Check wrong-owner IDs, repeated operations, custom columns if used, relationship boundaries, and explicit scope switching. Use actual affected-row/state assertions, not just a successful HTTP status.
