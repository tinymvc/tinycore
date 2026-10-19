# Migrations and Schema

Read this reference for database migrations, schema definitions, and table alterations. Source paths and command working directories refer to the application root unless explicitly stated otherwise. Check the installed package when versions differ.

---

## Defining Migrations

Migration files return an anonymous class with `up()` and `down()`. Always use `Spark\Facades\Schema` (or `Spark\Database\Schema\Schema`) and `Spark\Database\Schema\Blueprint`.

```php
<?php

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

---

## Blueprint Column Types and Modifiers

### Common Column Types

- **Primary Keys**: `$table->id()`, `$table->increments('id')`, `$table->bigIncrements('id')`
- **Strings**: `$table->string('title', 255)`, `$table->char('code', 4)`, `$table->text('body')`, `$table->longText('payload')`
- **Numbers**: `$table->integer('votes')`, `$table->tinyInteger('status')`, `$table->bigInteger('views')`, `$table->decimal('amount', 10, 2)`, `$table->float('rating')`
- **Booleans & Enums**: `$table->boolean('is_active')->default(true)`, `$table->enum('status', ['draft', 'published', 'archived'])`
- **Dates & Times**: `$table->date('due_date')`, `$table->dateTime('starts_at')`, `$table->timestamp('published_at')`
- **Standard Helpers**: `$table->timestamps()`, `$table->nullableTimestamps()`, `$table->softDeletes()`, `$table->rememberToken()`

### Fluent Foreign Key Constraints

TinyCore provides fluent foreign key constraint methods:

```php
// Cascade on delete:
$table->foreignId('user_id')->constrained()->cascadeOnDelete();

// Set null on delete (note: column must be nullable):
$table->foreignId('category_id')->nullable()->constrained()->setNullOnDelete();

// Restrict on delete:
$table->foreignId('author_id')->constrained('users')->restrictOnDelete();

// Full constraint methods available on foreignId:
$table->foreignId('order_id')
    ->constrained('orders')
    ->cascadeOnDelete()
    ->cascadeOnUpdate()
    ->restrictOnDelete()
    ->restrictOnUpdate()
    ->setNullOnDelete()
    ->setNullOnUpdate()
    ->noActionOnDelete()
    ->noActionOnUpdate();
```

### Modifiers and Indexes

```php
$table->string('email')->unique();
$table->string('slug')->index();
$table->text('notes')->nullable();
$table->boolean('active')->default(true);
$table->timestamp('verified_at')->nullable();
```

---

## Migration Commands

```bash
# Create a new migration file
php spark make:migration create_posts_table

# Create a pivot table migration (prompts for the 2 table names)
php spark make:migration --pivot

# Run pending migrations
php spark migrate

# Rollback last migration batch
php spark migrate:rollback --step=1

# Reset and re-run all migrations with seeders
php spark migrate:fresh --seed
```
