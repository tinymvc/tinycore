<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

use Spark\Database\{DB, QueryBuilder};
use Spark\Database\Exceptions\QueryBuilderException;
use Spark\Database\Schema\{Blueprint, Schema};

final class ReleaseRegressionTest extends DatabaseScenarioTestCase
{
    private function tenant(): string
    {
        Schema::create('tenant_scenario_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('tenant_scenario_posts', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('title');
            $table->integer('score')->default(0);
            $table->softDeletes();
        });
        Schema::create('tenant_scenario_comments', function (Blueprint $table) {
            $table->id();
            $table->integer('post_id');
            $table->string('body');
        });
        Schema::create('tenant_scenario_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('tenant_scenario_role_user', function (Blueprint $table) {
            $table->integer('user_id');
            $table->integer('role_id');
        });
        query('tenant_scenario_users')->insert(['name' => 'Tenant']);
        query('tenant_scenario_posts')->insert(['user_id' => 1, 'title' => 'Tenant post']);
        query('tenant_scenario_comments')->insert(['post_id' => 1, 'body' => 'Tenant comment']);
        query('tenant_scenario_roles')->insert(['name' => 'Tenant role']);
        return app(DB::class)->getTablePrefix() . 'tenant_';
    }

    protected function tearDown(): void
    {
        try {
            foreach (['schema', 'role_user', 'roles', 'comments', 'posts', 'users'] as $table) {
                Schema::dropIfExists('tenant_scenario_' . $table);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_prefix_is_retained_by_eager_lazy_nested_and_through_relations(): void
    {
        $prefix = $this->tenant();
        $user = ScenarioUser::query()->prefix($prefix)->with(['posts.comments', 'comments', 'firstPost'])
            ->withCount('posts')->withExists('posts')->first();
        $this->assertSame('Tenant', $user->name);
        $this->assertSame(1, (int) $user->posts_count);
        $this->assertSame(1, (int) $user->posts_exists);
        $this->assertCount(1, $user->posts);
        $this->assertSame('Tenant comment', $user->posts[0]->comments[0]->body);
        $this->assertSame('Tenant comment', $user->comments[0]->body);
        $this->assertSame('Tenant post', $user->firstPost->title);
        $this->assertSame('Tenant', $user->posts[0]->user->name);
        $this->assertSame(1, $user->posts()->count());
        $this->assertTrue($user->posts()->exists());
        $user->roles()->attach(1);
        $this->assertSame('Tenant role', $user->roles()->first()->name);
        $eager = ScenarioUser::query()->prefix($prefix)->with('roles')->first();
        $this->assertSame('Tenant role', $eager->roles[0]->name);
        $this->assertSame(1, $user->roles()->detach(1));
    }

    public function test_instance_writes_refresh_restore_and_serialization_keep_the_prefix(): void
    {
        $prefix = $this->tenant();
        $user = ScenarioUser::query()->prefix($prefix)->first();
        $user->name = 'Updated tenant';
        $this->assertTrue($user->save());
        $this->assertSame('Ada', ScenarioUser::find(1)->name);
        $this->assertSame('Updated tenant', $user->refresh()->name);
        $cached = unserialize(serialize($user));
        $this->assertSame('Tenant post', $cached->posts()->first()->title);
        $created = $cached->posts()->create(['title' => 'Created']);
        $this->assertSame(2, $cached->posts()->count());
        $created->title = 'Edited';
        $this->assertTrue($created->save());
        $this->assertSame('Edited', $created->refresh()->title);
        $this->assertTrue($created->remove());
        $archived = ScenarioPost::query()->prefix($prefix)->withTrashed()->whereKey($created->id)->first();
        $this->assertTrue($archived->restore());
        $this->assertSame(2, $cached->posts()->count());
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertTrue($cached->remove());
        $this->assertFalse(ScenarioUser::query()->prefix($prefix)->exists());
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_pagination_and_mixed_context_collection_loading_keep_tenants_separate(): void
    {
        $prefix = $this->tenant();
        foreach (['paginate', 'simplePaginate', 'cursorPaginate'] as $method) {
            $page = ScenarioUser::query()->prefix($prefix)->with('posts')->orderBy('id')->$method(1);
            $this->assertSame('Tenant post', $page->items()[0]->posts[0]->title);
        }
        $models = [ScenarioUser::find(1), ScenarioUser::query()->prefix($prefix)->first()];
        ScenarioUser::loadRelations('posts', $models);
        $this->assertSame(2, count($models[0]->posts));
        $this->assertSame('Tenant post', $models[1]->posts[0]->title);
    }

    public function test_an_explicit_database_survives_hydration_writes_and_default_reset(): void
    {
        $db = new DB(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'other_']);
        $db->exec('CREATE TABLE other_scenario_users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE other_scenario_posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, deleted_at TEXT)');
        $db->exec("INSERT INTO other_scenario_users VALUES (1, 'Other')");
        $db->exec("INSERT INTO other_scenario_posts VALUES (1, 1, 'Other post', NULL)");
        $user = ScenarioUser::query()->setDB($db)->with('posts')->first();
        $this->assertSame('Other post', $user->posts[0]->title);
        $db->reset(['driver' => 'sqlite', 'database' => ':memory:']);
        $user->name = 'Changed';
        $this->assertTrue($user->save());
        $this->assertSame('Changed', $user->refresh()->name);
        $this->assertSame('Ada', ScenarioUser::find(1)->name);
        $serialized = serialize($user);
        $this->assertStringNotContainsString('PDO', $serialized);
        $this->assertThrows(LogicException::class, fn() => unserialize($serialized)->refresh());
    }

    public function test_query_creation_and_relation_writes_keep_the_prefix(): void
    {
        $prefix = $this->tenant();
        $created = ScenarioUser::query()->prefix($prefix)->create(['name' => 'New tenant']);
        $created->name = 'Changed tenant';
        $this->assertTrue($created->save());
        $this->assertSame('Changed tenant', $created->refresh()->name);
        $found = ScenarioUser::query()->prefix($prefix)->firstOrCreate(['name' => 'Another tenant']);
        $this->assertSame('Another tenant', $found->refresh()->name);
        $parent = ScenarioUser::query()->prefix($prefix)->whereKey(1)->first();
        $posts = $parent->posts()->createMany([['title' => 'A'], ['title' => 'B']]);
        $this->assertSame('A', $posts[0]->refresh()->title);
        $post = $parent->posts()->createOrUpdate(['title' => 'A'], ['score' => 9]);
        $this->assertSame(9, $post->refresh()->score);
        $same = $parent->posts()->firstOrCreate(['title' => 'A'], ['score' => 99]);
        $this->assertSame(9, $same->score);
        $new = (new ScenarioPost())->fill(['title' => 'Saved']);
        $parent->posts()->save($new);
        $this->assertSame('Saved', $new->refresh()->title);
        $this->assertSame(1, $parent->posts()->deleteAll(['title' => 'A']));
        $this->assertSame(3, $parent->posts()->count());
        $this->assertSame(5, ScenarioPost::count());
        $explicit = $parent->posts()->create(['id' => 100, 'title' => 'Explicit key']);
        $this->assertSame('Explicit key', $explicit->refresh()->title);
    }

    public function test_morph_targets_inherit_the_parent_prefix(): void
    {
        $prefix = $this->tenant();
        Schema::create('tenant_scenario_schema', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->integer('subject_id');
        });
        query('tenant_scenario_schema')->insert(['subject_type' => 'post', 'subject_id' => 1]);
        $activity = ScenarioActivity::query()->prefix($prefix)->morphWith('subject', [
            'post' => ['class' => ScenarioPost::class, 'relations' => ['comments']],
        ])->first();
        $this->assertSame('Tenant post', $activity->subject->title);
        $this->assertSame('Tenant comment', $activity->subject->comments[0]->body);
    }

    public function test_serialized_named_connections_reconnect_without_serializing_credentials(): void
    {
        $this->app->setConfig('database.connections.model_context', [
            'driver' => 'sqlite', 'database' => $this->storagePath . '/model-context.sqlite',
            'prefix' => 'context_', 'password' => 'secret-must-not-be-serialized',
        ]);
        $db = DB::connection('model_context');
        $db->exec('CREATE TABLE context_scenario_users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec("INSERT INTO context_scenario_users VALUES (1, 'Named tenant')");
        $model = ScenarioUser::query()->setDB($db)->first();
        $serialized = serialize($model);
        $this->assertStringNotContainsString('secret-must-not-be-serialized', $serialized);
        $model = unserialize($serialized);
        $model->name = 'Changed';
        $this->assertTrue($model->save());
        $this->assertSame('Changed', $model->refresh()->name);
        $this->assertSame('Ada', ScenarioUser::find(1)->name);
    }

    public function test_serialized_in_memory_models_cannot_fall_back_to_another_database(): void
    {
        $config = ['driver' => 'sqlite', 'database' => ':memory:'];
        $default = app(DB::class);
        $first = new DB($config);
        $second = new DB($config);
        foreach ([$first, $second] as $db) {
            $db->exec('CREATE TABLE scenario_users (id INTEGER PRIMARY KEY, name TEXT)');
            $db->exec("INSERT INTO scenario_users VALUES (1, 'Memory tenant')");
        }
        try {
            $this->app->instance(DB::class, $first);
            $model = ScenarioUser::find(1);
            $serialized = serialize($model);
            $this->assertSame('Memory tenant', unserialize($serialized)->refresh()->name);
            $this->app->instance(DB::class, $second);
            $this->assertThrows(LogicException::class, fn() => unserialize($serialized)->refresh());
            $this->assertSame('Memory tenant', $model->refresh()->name);
        } finally {
            $this->app->instance(DB::class, $default);
        }
    }

    public function test_hydrated_model_writes_share_the_active_transaction(): void
    {
        $db = app(DB::class);
        $db->beginTransaction();
        try {
            $model = ScenarioUser::find(1);
            $model->name = 'Rolled back';
            $this->assertTrue($model->save());
            $this->assertSame('Rolled back', ScenarioUser::find(1)->name);
        } finally {
            $db->rollBack();
        }
        $this->assertSame('Ada', ScenarioUser::find(1)->name);
    }

    public function test_unsupported_write_constraints_fail_before_changing_rows(): void
    {
        $constraints = [
            fn($q) => $q->join('scenario_users', 'scenario_users.id', '=', 'scenario_posts.user_id'),
            fn($q) => $q->take(1),
            fn($q) => $q->offset(1),
            fn($q) => $q->orderBy('id'),
            fn($q) => $q->groupBy('user_id'),
            fn($q) => $q->having('COUNT(*) > 0'),
            fn($q) => $q->union(ScenarioPost::query()),
            fn($q) => $q->selectRaw('? AS marker', [1]),
        ];
        foreach ($constraints as $constrain) {
            foreach (['update', 'delete', 'forceDelete', 'increment', 'decrement', 'restore'] as $operation) {
                $query = $constrain(ScenarioPost::whereRaw('1 = 1'));
                $args = match ($operation) {
                    'update' => [['score' => 999]],
                    'increment', 'decrement' => ['score'],
                    default => [],
                };
                $this->assertThrows(QueryBuilderException::class, fn() => $query->$operation(...$args));
            }
        }
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame(0, query('scenario_posts')->where('score', 999)->count());
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_positional_bindings_follow_sql_order_and_survive_composition(): void
    {
        $query = query('scenario_posts')->whereRaw('id = ?', [1])->selectRaw('? AS marker', [99]);
        $this->assertSame(99, (int) (clone $query)->first()->marker);
        $this->assertSame(1, $query->count());
        $this->assertTrue($query->exists());
        $selected = query('scenario_posts')->whereRaw('id = ?', [1])->selectSub(
            query('scenario_users')->selectRaw('? AS marker', [88])->whereRaw('id = ?', [1]), 'marker'
        )->first();
        $this->assertSame(88, (int) $selected->marker);
        $users = ScenarioUser::query()->whereRaw('id = ?', [1])
            ->withCount('posts', fn($q) => $q->whereRaw('score >= ?', [0]))->first();
        $this->assertSame(2, (int) $users->posts_count);
        $rows = query('scenario_users')->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END, id', [2])
            ->whereRaw('id < ?', [3])->selectRaw('id, ? AS marker', [77])->all();
        $this->assertSame([2, 1], array_map('intval', array_column($rows, 'id')));
        $this->assertSame([77, 77], array_map('intval', array_column($rows, 'marker')));
        $rows = query('scenario_users')->whereRaw('id = ?', [1])->selectRaw('? AS marker', [10])
            ->union(query('scenario_users')->whereRaw('id = ?', [2])->selectRaw('? AS marker', [20]))->all();
        $this->assertSame([10, 20], array_map('intval', array_column($rows, 'marker')));
    }

    public function test_join_and_group_bindings_use_sql_order(): void
    {
        $users = app(DB::class)->wrapTable('scenario_users');
        $posts = app(DB::class)->wrapTable('scenario_posts');
        $rows = query('scenario_posts')->whereRaw("$posts.id = ?", [1])
            ->joinRaw("JOIN $users ON $users.id = $posts.user_id AND $users.name = ?", ['Ada'])
            ->selectRaw('? AS marker', [23])->all();
        $this->assertCount(1, $rows);
        $this->assertSame(23, (int) $rows[0]->marker);
        $grouped = query('scenario_posts')->groupByRaw('score + CAST(? AS DECIMAL(10, 0))', [0])
            ->whereRaw('id = ?', [1])->selectRaw('COUNT(*) AS total, ? AS marker', [42])->first();
        $this->assertSame(1, (int) $grouped->total);
        $this->assertSame(42, (int) $grouped->marker);
        $named = query('scenario_users')->selectRaw(':id AS marker', ['id' => 99])->where('id', 1)->first();
        $this->assertSame(99, (int) $named->marker);
    }

    public function test_replaced_clauses_discard_their_old_bindings(): void
    {
        foreach ([['?', [9]], [':value', ['value' => 9]]] as [$placeholder, $bindings]) {
            $query = query('scenario_users')->selectRaw("$placeholder AS marker", $bindings)
                ->orderByRaw("CASE WHEN id = $placeholder THEN 0 ELSE 1 END", $bindings)
                ->groupByRaw("id + $placeholder", $bindings)
                ->select('id')->groupBy('id')->order('id ASC');
            $this->assertSame([1, 2, 3], array_map('intval', $query->pluck('id')));
        }
    }

    public function test_empty_numeric_aggregates_have_defined_results(): void
    {
        $this->assertSame(0.0, ScenarioPost::whereKey(-1)->sum('score'));
        foreach (['avg', 'min', 'max'] as $method) {
            $this->assertNull(ScenarioPost::whereKey(-1)->$method('score'));
        }
        $this->assertTrue(ScenarioPost::sum('score') > 0);
    }
}
