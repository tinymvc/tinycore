# Migrations and Schema

> **Read when:** creating or altering tables, indexes, foreign keys, or migration history.
> **Related:** [Queries](queries.md#prefixes-and-raw-sql) · [Models and relations](models-relations.md) · [Soft deletes](soft-deletes.md) · [Operations](operations.md)
> Use the target database driver and application migration directory; schema changes affect persisted data.

Contents:

- [Define migrations](#define-migrations)
- [Columns, constraints, and alterations](#columns-constraints-and-alterations)
- [Commands and verification](#commands-and-verification)

## Define migrations

Files return an anonymous class with `up()` and `down()`:

```php
use Spark\Database\Schema\Blueprint;
use Spark\Facades\Schema;

return new class {
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->setNullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content');
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

Schema and the ledger use the default connection's prefix. Pass logical table names, including foreign-key targets. Generated index/constraint names include the prefix; explicit names stay unchanged.

## Columns, constraints, and alterations

| Type | Examples |
| --- | --- |
| Keys | `id()`, `increments('id')`, `bigIncrements('id')` |
| Text | `string('title', 255)`, `char('code', 4)`, `text('body')`, `longText('payload')` |
| Numbers | `integer('votes')`, `tinyInteger('status')`, `bigInteger('views')`, `decimal('amount', 10, 2)`, `float('rating')` |
| Flags | `boolean('active')->default(true)`, `enum('status', ['draft', 'published'])` |
| Dates | `date('due_date')`, `dateTime('starts_at')`, `timestamp('published_at')` |
| Helpers | `timestamps()`, `nullableTimestamps()`, `softDeletes()`, `rememberToken()` |

Column modifiers include `nullable()`, `default()`, `unique()`, and `index()`. `softDeletes()` returns void; do not chain modifiers onto it.

`foreignId()->constrained('users')` supports `cascadeOnDelete/Update()`, `restrictOnDelete/Update()`, `setNullOnDelete/Update()`, and `noActionOnDelete/Update()`. Choose one action for each operation; SET NULL requires a nullable column.

Use `Schema::table()` for alterations. Verify the installed Blueprint/compiler supports the intended operation on the target engine. Preserve applied migration files; add a migration for a new change. Check existing data before adding unique/foreign-key constraints and define a rollback suited to that data.

## Commands and verification

```sh
php spark make:migration create_posts_table
php spark make:migration --pivot
php spark migrate
php spark migrate:rollback --step=1
```

`make:migration --pivot` prompts for table names. `migrate:fresh --seed` rebuilds tables and is destructive; use it only against an intended disposable database.

Verify forward migration, expected columns/indexes/constraints, rollback where supported, and the next forward run on an isolated instance of the production database engine. SQLite passing does not prove MySQL/PostgreSQL DDL compatibility.
