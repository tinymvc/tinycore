<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';
require_once dirname(__DIR__) . '/Fixtures/Models.php';

use Spark\Database\DB;
use Spark\Database\QueryBuilder;
use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Grammar;
use Spark\Database\Schema\Schema;

final class DatabasePrefixTest extends FrameworkTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(DB::class)->reset(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'app_']);
    }

    public function test_schema_queries_and_foreign_keys_share_the_connection_prefix(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
        });
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
        });
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('main.users'));
        $this->assertTrue(Schema::hasColumns('main.users', ['id', 'name']));
        $this->assertFalse(Schema::hasTable('app_users'));
        $this->assertSame(['id', 'name'], Schema::getColumnListing('users'));
        $this->assertSame('app_users', app(DB::class)->query('PRAGMA foreign_key_list(app_posts)')->fetch(PDO::FETCH_ASSOC)['table']);

        query('users')->insert(['name' => 'Ada']);
        query('posts')->insert(['user_id' => 1, 'title' => 'Prefix']);
        $this->assertThrows(PDOException::class, fn() => query('posts')->insert(['user_id' => 999, 'title' => 'Invalid']));
        $this->assertSame('Ada', query('users')->join('posts', 'posts.user_id', '=', 'users.id')->value('users.name'));
        $this->assertSame('Ada', query('users as u')->join('posts as p', 'p.user_id', '=', 'u.id')->where('p.title', 'Prefix')->value('u.name'));
        $this->assertSame(1, query('users as u')->whereExists(fn($q) => $q->table('posts')->whereColumn('posts.user_id', 'u.id'))->count());
        $this->assertSame(1, query('users')->whereIn('users.id', fn($q) => $q->table('posts')->select('posts.user_id'))->count());
        $this->assertSame('Ada', query('users')->orderBy('users.id')->cursorPaginate(1)->items()[0]->name);
        $this->assertSame('Ada', query('users')->latest('users.id')->value('name'));

        Schema::table('users', fn(Blueprint $table) => $table->integer('score')->default(0));
        $this->assertTrue(Schema::hasColumn('users', 'score'));
        $this->assertSame(1, query('users')->where('users.id', 1)->update(['score' => 3]));
        $this->assertSame(1, query('users')->where('id', 1)->delete());
        $this->assertSame(0, query('posts')->count());
        Schema::drop('posts');
        Schema::rename('users', 'members');
        $this->assertTrue(Schema::hasTable('members'));
        $this->assertFalse(Schema::hasTable('users'));
        Schema::dropIfExists('members');
        $this->assertFalse(Schema::hasTable('members'));
    }

    public function test_prefix_overrides_subqueries_clones_connections_and_truncate(): void
    {
        Schema::create('rows', fn(Blueprint $table) => $table->id());
        query('rows')->insert(['id' => 8]);
        $this->assertSame(1, query('unused')->from('rows')->truncate());
        $this->assertSame(1, query('rows')->insert(['id' => null]));
        app(DB::class)->exec('CREATE TABLE other_rows (id INTEGER)');
        app(DB::class)->exec('INSERT INTO other_rows VALUES (42)');
        $base = query('rows');
        $other = $base->clone()->prefix('other_');
        $this->assertSame(42, (int) $other->whereIn('id', fn($q) => $q->select('id'))->value('id'));
        $this->assertSame(1, (int) $base->value('id'));
        $this->assertSame(42, (int) query('other_rows')->prefix('')->value('id'));
        $this->assertStringContainsString('"next_rows"', $base->setDB(new DB(['driver' => 'sqlite', 'prefix' => 'next_']))->toSql());
        $this->assertSame('"main"."app_rows"', app(DB::class)->wrapTable('main.rows'));
        app(DB::class)->reset(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'new_']);
        Schema::create('rows', fn(Blueprint $table) => $table->id());
        $this->assertTrue(Schema::hasTable('rows'));
        $this->assertSame('new_rows', app(DB::class)->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name != 'sqlite_sequence'")->fetchColumn());
    }

    public function test_models_relations_aggregates_and_soft_deletes_use_prefixed_tables(): void
    {
        Schema::create('query_owners', fn(Blueprint $table) => $table->id());
        Schema::create('query_keys', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->integer('owner_id');
            $table->integer('score');
            $table->softDeletes();
        });
        DatabaseFixtureQueryOwner::create(['id' => 1]);
        DatabaseFixtureQueryKey::create(['code' => 'a', 'owner_id' => 1, 'score' => 10]);
        $owner = DatabaseFixtureQueryOwner::with('records')->withCount('records')->withMax('records', 'score')->findOrFail(1);
        $this->assertCount(1, $owner->records);
        $this->assertSame(1, (int) $owner->records_count);
        $this->assertSame(10, (int) $owner->records_max);
        $this->assertSame(1, DatabaseFixtureQueryOwner::whereHas('records', fn($q) => $q->as('r')->where('r.score', 10))->count());
        $this->assertSame(10, (int) DatabaseFixtureQueryOwner::withMax('records', 'score', fn($q) => $q->as('r'))->findOrFail(1)->records_max);
        $this->assertSame(1, DatabaseFixtureQueryKey::where('code', 'a')->delete());
        $this->assertSame(0, DatabaseFixtureQueryOwner::has('records')->count());
        $this->assertSame(1, DatabaseFixtureQueryKey::withTrashed()->count());
    }

    public function test_framework_storage_and_migration_ledger_use_the_prefix(): void
    {
        app(DB::class)->reset(['driver' => 'sqlite', 'database' => $this->storagePath . '/prefixed.sqlite', 'prefix' => 'app_']);
        $runner = new \Spark\Database\Migration(dirname(__DIR__) . '/Fixtures/migrations');
        ob_start();
        try {
            $runner->up();
            $this->assertSame(2, query('migrations')->count());
            $cache = new \Spark\Cache\Storage\DatabaseStorage();
            $cache->store('key', 'value');
            $this->assertSame('value', $cache->retrieve('key'));
            $this->assertTrue($cache->lock('lock', 'owner', 60, 0));
            $this->assertTrue($cache->unlock('lock', 'owner'));
            $session = new \Spark\Http\Session\Handler\DatabaseHandler();
            $session->open('', 'test');
            $this->assertTrue($session->write('session', 'payload'));
            $this->assertSame('payload', $session->read('session'));
            $queue = new \Spark\Queue\Storage\DatabaseStorage();
            $queue->push(new \Spark\Queue\Job('strlen', ['job'], now()->subSeconds(10)));
            $this->assertSame(1, query('jobs')->count());
            $runner->down(['all' => true]);
            $this->assertFalse(Schema::hasTable('jobs'));
            $this->assertSame(0, query('migrations')->count());
        } finally {
            ob_end_clean();
        }
    }

    public function test_prefix_sql_compiles_for_all_supported_drivers(): void
    {
        foreach (DB::SUPPORTED_DRIVERS as $driver) {
            $db = new DB(['driver' => $driver, 'prefix' => 'app_']);
            $grammar = new Grammar($driver, 'app_');
            $quote = $driver === 'mysql' ? '`' : '"';
            $this->assertSame("{$quote}public{$quote}.{$quote}app_users{$quote}", $db->wrapTable('public.users'));
            $query = (new QueryBuilder($db))->table('users')->join('posts', 'posts.user_id', '=', 'users.id')->select('users.id')->where('posts.id', 1)->orderBy('users.id');
            $sql = $query->toSql();
            $this->assertStringContainsString("{$quote}app_users{$quote}.{$quote}id{$quote}", $sql);
            $this->assertStringContainsString("{$quote}app_posts{$quote}.{$quote}user_id{$quote}", $sql);
            $this->assertStringContainsString('app_users', $grammar->compileIndex('users', ['type' => 'index', 'columns' => ['id']]));
        }
    }

    public function test_named_connections_inherit_or_override_the_base_prefix(): void
    {
        config(['database' => [
            'default' => 'primary',
            'prefix' => 'shared_',
            'connections' => [
                'primary' => ['driver' => 'sqlite'],
                'secondary' => ['driver' => 'sqlite'],
                'isolated' => ['driver' => 'sqlite', 'prefix' => 'other_'],
                'plain' => ['driver' => 'sqlite', 'prefix' => ''],
            ],
        ]]);
        $this->assertSame('shared_', DB::connection('secondary')->getTablePrefix());
        $this->assertSame('other_', DB::connection('isolated')->getTablePrefix());
        $this->assertSame('', DB::connection('plain')->getTablePrefix());
        $this->assertSame('app_', app(DB::class)->getTablePrefix());
    }
}
