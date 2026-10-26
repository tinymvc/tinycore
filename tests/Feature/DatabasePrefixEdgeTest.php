<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';
require_once dirname(__DIR__) . '/Fixtures/Models.php';

use Spark\Database\DB;
use Spark\Database\QueryBuilder;
use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;

final class DatabasePrefixEdgeTest extends FrameworkTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(DB::class)->reset(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'app_']);
    }

    public function test_prefix_changes_cannot_mix_precompiled_namespaces(): void
    {
        $query = query('users')->join('posts', 'posts.user_id', '=', 'users.id');
        $this->assertThrows(\LogicException::class, fn() => $query->prefix('other_'));
        $this->assertThrows(\LogicException::class, fn() => $query->setDB(new DB(['driver' => 'sqlite', 'prefix' => 'other_'])));
        $this->assertSame($query, $query->prefix('app_'));
    }

    public function test_builder_table_replacement_discards_old_aliases(): void
    {
        Schema::create('u', fn(Blueprint $table) => $table->id());
        query('u')->insert(['id' => 12]);
        $this->assertSame(12, (int) query('users as u')->table('u')->where('u.id', 12)->value('u.id'));
        $this->assertSame(12, (int) query('users as u')->from('u')->where('u.id', 12)->value('u.id'));
        $this->assertSame(12, (int) query('users')->from('missing as m')->table('u')->where('u.id', 12)->value('u.id'));
    }

    public function test_inner_table_names_shadow_inherited_outer_aliases(): void
    {
        Schema::create('users', fn(Blueprint $table) => $table->id());
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
        });
        query('users')->insert(['id' => 5]);
        query('posts')->insert(['id' => 9, 'user_id' => 5]);
        $this->assertSame(1, query('users as posts')->whereIn('posts.id', fn($q) => $q->table('posts')->select('posts.user_id'))->count());
    }

    public function test_physical_qualification_does_not_change_other_logical_tables(): void
    {
        Schema::create('users', fn(Blueprint $table) => $table->id());
        Schema::create('app_users', fn(Blueprint $table) => $table->id());
        query('users')->insert(['id' => 1]);
        query('app_users')->insert(['id' => 2]);
        $query = query('users');
        $this->assertSame('app_users.id', $query->withAlias('id'));
        $row = $query->join('app_users')->select(['users.id AS first_id', 'app_users.id AS second_id'])->first();
        $this->assertSame(1, (int) $row->first_id);
        $this->assertSame(2, (int) $row->second_id);
    }

    public function test_qualified_sqlite_truncate_resets_the_correct_sequence(): void
    {
        Schema::create('main.rows', fn(Blueprint $table) => $table->id());
        query('main.rows')->insert(['id' => 12]);
        query('main.rows')->truncate();
        $this->assertSame(1, query('main.rows')->insert(['id' => null]));
    }

    public function test_qualified_schema_indexes_foreign_keys_and_rename(): void
    {
        app(DB::class)->exec("ATTACH DATABASE ':memory:' AS audit");
        Schema::create('audit.parents', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
        });
        Schema::create('audit.children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('audit.parents')->cascadeOnDelete();
        });
        query('audit.parents')->insert(['id' => 1, 'name' => 'Parent']);
        query('audit.children')->insert(['parent_id' => 1]);
        Schema::table('audit.parents', fn(Blueprint $table) => $table->dropIndex(['name']));
        Schema::rename('audit.parents', 'audit.renamed');
        $this->assertTrue(Schema::hasTable('audit.renamed'));
        query('audit.renamed')->where('id', 1)->delete();
        $this->assertSame(0, query('audit.children')->count());
        Schema::drop('audit.children');
        Schema::drop('audit.renamed');
    }

    public function test_invalid_prefixes_fail_before_creating_any_tables(): void
    {
        foreach (['bad.prefix', 'bad prefix', 'bad;', ['bad']] as $prefix) {
            $this->assertThrows(\InvalidArgumentException::class, fn() => new DB(['driver' => 'sqlite', 'prefix' => $prefix]));
        }
        $this->assertThrows(\InvalidArgumentException::class, fn() => query('users')->prefix('bad.prefix'));
        Schema::create('retained', fn(Blueprint $table) => $table->id());
        query('retained')->insert(['id' => 7]);
        $this->assertThrows(\InvalidArgumentException::class, fn() => app(DB::class)->reset(['driver' => 'sqlite', 'prefix' => 'bad.prefix']));
        $this->assertSame(7, (int) query('retained')->value('id'));
        $this->assertSame('base_', (new DB([
            'default' => 'main', 'prefix' => 'base_',
            'connections' => ['main' => ['driver' => 'sqlite', 'prefix' => null]],
        ]))->getTablePrefix());
    }

    public function test_cursor_boundaries_and_numeric_prefixes(): void
    {
        app(DB::class)->reset(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '0_']);
        Schema::create('rows', fn(Blueprint $table) => $table->id());
        query('rows')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);
        $this->assertSame(3, (int) query('rows')->last()->id);
        $this->assertSame(3, (int) query('rows')->latest('id')->first()->id);
        $first = query('rows')->orderBy('rows.id')->cursorPaginate(1);
        $next = query('rows')->orderBy('rows.id')->cursorPaginate(1, cursor: $first->nextCursor());
        $this->assertSame(2, (int) $next->items()[0]->id);
        $previous = query('rows')->orderBy('rows.id')->cursorPaginate(1, cursor: $next->previousCursor());
        $this->assertSame(1, (int) $previous->items()[0]->id);
    }

    public function test_aliases_declared_after_use_fail_instead_of_changing_the_table(): void
    {
        $query = query('users')->select('u.id');
        $this->assertThrows(\LogicException::class, fn() => $query->as('u'));
        $this->assertThrows(\LogicException::class, fn() => $query->join('posts as u'));
    }

    public function test_subqueries_can_reuse_an_outer_alias_in_their_own_scope(): void
    {
        Schema::create('users', fn(Blueprint $table) => $table->id());
        Schema::create('u', fn(Blueprint $table) => $table->id());
        query('users')->insert(['id' => 1]);
        query('u')->insert(['id' => 1]);
        $this->assertSame(1, query('users as u')->where('u.id', 1)->whereExists(
            fn($q) => $q->table('u as u')->where('u.id', 1)
        )->count());
        $this->assertSame(1, (int) query(' users AS u ')->where(' u.id ', 1)->value('u.id'));
    }

    public function test_named_storage_connections_keep_their_own_prefixes(): void
    {
        $file = $this->storagePath . '/named-prefix.sqlite';
        config(['database' => [
            'default' => 'primary',
            'connections' => [
                'primary' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => 'app_'],
                'audit' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => 'audit_'],
            ],
        ]]);
        $primary = app(DB::class)->reset();
        Schema::create('caches', function (Blueprint $table) {
            $table->string('key');
            $table->string('group');
            $table->text('data');
            $table->integer('expiration')->default(0);
            $table->primary(['group', 'key']);
        });
        $this->app->instance(DB::class, $audit = DB::connection('audit'));
        try {
            $migration = require dirname(__DIR__) . '/Fixtures/migrations/migration_2024_10_02_082930_framework.php';
            $migration->up();
        } finally {
            $this->app->instance(DB::class, $primary);
            Schema::getConnection();
        }
        $cache = new \Spark\Cache\Storage\DatabaseStorage('audit', ['connection' => 'audit', 'lock_connection' => 'audit']);
        $cache->store('key', 'audit value');
        $this->assertSame('audit value', $cache->retrieve('key'));
        $this->assertSame(0, query('caches')->count());
        $this->assertSame(1, $audit->table('caches')->count());
        $this->assertTrue($cache->lock('lock', 'owner', 60, 0));
        $this->assertSame(1, $audit->table('locks')->count());
        $this->assertTrue($cache->unlock('lock', 'owner'));
        $session = new \Spark\Http\Session\Handler\DatabaseHandler(['connection' => 'audit']);
        $session->open('', 'test');
        $session->write('id', 'payload');
        $this->assertSame('payload', $session->read('id'));
        $this->assertSame(1, $audit->table('sessions')->count());
        $queue = new \Spark\Queue\Storage\DatabaseStorage(['connection' => 'audit']);
        $queue->push(new \Spark\Queue\Job('strlen', ['job'], now()->subSeconds(10)));
        $this->assertSame(1, $audit->table('jobs')->count());
        $this->assertTrue($queue->getNextJob() !== false);
        $queue->markJobAsFailed(1, new \RuntimeException('expected'), 1);
        $this->assertSame(1, $audit->table('failed_jobs')->count());
        $queue->removeJobById(1);
        $this->assertSame(0, $audit->table('failed_jobs')->count());
    }

    public function test_relation_subqueries_inherit_a_builder_prefix_override(): void
    {
        Schema::create('archive_query_owners', fn(Blueprint $table) => $table->id());
        Schema::create('archive_query_keys', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->integer('owner_id');
            $table->integer('score');
            $table->softDeletes();
        });
        query('archive_query_owners')->insert(['id' => 1]);
        query('archive_query_keys')->insert(['code' => 'a', 'owner_id' => 1, 'score' => 9]);
        $owner = DatabaseFixtureQueryOwner::query()->prefix('app_archive_')
            ->whereHas('records', fn($q) => $q->as('r')->where('r.score', 9))
            ->withCount('records')->first();
        $this->assertSame(1, (int) $owner->records_count);
    }
}
