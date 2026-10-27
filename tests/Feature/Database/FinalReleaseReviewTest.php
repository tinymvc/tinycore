<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

use Spark\Database\{DB, Model, ModelContext, QueryBuilder};
use Spark\Database\Exceptions\QueryBuilderException;
use Spark\Database\Relation\HasMany;
use Spark\Database\Schema\{Blueprint, Schema};

class FinalReviewScopedPost extends ScenarioPost
{
    public static bool $suspendFactory = false;
    public static bool $throwFromFactory = false;

    public static function query(): QueryBuilder
    {
        if (self::$suspendFactory && Fiber::getCurrent() !== null) {
            Fiber::suspend();
        }
        if (self::$throwFromFactory) {
            throw new RuntimeException('query factory failed');
        }
        return parent::query()->where('score', '>', 0);
    }
}

class FinalReviewScopedUser extends ScenarioUser
{
    public function posts(): HasMany
    {
        return $this->hasMany(FinalReviewScopedPost::class, foreignKey: 'user_id');
    }
}

class FinalReviewCustomBuilder extends QueryBuilder
{
}

class FinalReviewUnopenedDatabase extends DB
{
    public function getPdo(): PDO
    {
        throw new LogicException('Query compilation should not open a connection.');
    }
}

class FinalReviewGuardedUser extends ScenarioUser
{
    protected array $guarded = ['id'];
}

class FinalReviewNode extends Model
{
    protected string $table = 'scenario_schema';
    protected const USE_TIMESTAMPS = false;
    public static bool $qualifiedScope = false;

    public static function query(): QueryBuilder
    {
        return parent::query()->where((self::$qualifiedScope ? 'scenario_schema.' : '') . 'visible', 1);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, foreignKey: 'parent_id');
    }
}

class FinalReviewUnscopedNode extends Model
{
    protected string $table = 'scenario_schema';
    protected const USE_TIMESTAMPS = false;

    public function children(): HasMany
    {
        return $this->hasMany(self::class, foreignKey: 'parent_id');
    }
}

final class FinalReleaseReviewTest extends DatabaseScenarioTestCase
{
    private function prefixedParent(): FinalReviewScopedUser
    {
        Schema::create('review_scenario_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('review_scenario_posts', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('title');
            $table->integer('score');
            $table->softDeletes();
        });
        query('review_scenario_users')->insert(['name' => 'Isolated']);
        query('review_scenario_posts')->insert([
            ['user_id' => 1, 'title' => 'Visible', 'score' => 10],
            ['user_id' => 1, 'title' => 'Hidden', 'score' => 0],
        ]);
        return FinalReviewScopedUser::query()->prefix(app(DB::class)->getTablePrefix() . 'review_')->first();
    }

    protected function tearDown(): void
    {
        FinalReviewScopedPost::$suspendFactory = FinalReviewScopedPost::$throwFromFactory = false;
        FinalReviewNode::$qualifiedScope = false;
        try {
            Schema::dropIfExists('review_scenario_posts');
            Schema::dropIfExists('review_scenario_users');
        } finally {
            parent::tearDown();
        }
    }

    public function test_custom_query_filters_survive_prefixed_eager_lazy_and_aggregate_relations(): void
    {
        $parent = $this->prefixedParent();
        $this->assertSame(1, $parent->posts()->count());
        $this->assertSame('Visible', $parent->posts[0]->title);
        $this->assertCount(1, $parent->posts);
        $loaded = $parent->newQuery()->with('posts')->withCount('posts')->withExists('posts')->first();
        $this->assertCount(1, $loaded->posts);
        $this->assertSame(1, (int) $loaded->posts_count);
        $this->assertSame(1, (int) $loaded->posts_exists);
        $this->assertSame(1, $parent->newQuery()->whereHas('posts')->count());
        $this->assertSame(1, $parent->posts()->first()->newQuery()->count());
    }

    public function test_instance_writes_retain_custom_query_filters(): void
    {
        $parent = $this->prefixedParent();
        $post = $parent->posts()->first();
        query('review_scenario_posts')->where('id', $post->id)->update(['score' => 0]);
        $post->title = 'Must not be written';
        $this->assertFalse($post->save());
        $this->assertFalse($post->remove());
        $this->assertSame('Visible', query('review_scenario_posts')->where('id', $post->id)->value('title'));
    }

    public function test_context_is_cleaned_up_after_factory_exceptions(): void
    {
        $parent = $this->prefixedParent();
        FinalReviewScopedPost::$throwFromFactory = true;
        try {
            $this->assertThrows(RuntimeException::class, fn() => $parent->posts()->count());
        } finally {
            FinalReviewScopedPost::$throwFromFactory = false;
        }
        $this->assertNull(ModelContext::currentQueryContext(FinalReviewScopedPost::class));
        $this->assertSame(app(DB::class)->getTablePrefix(), FinalReviewScopedPost::query()->getTablePrefix());
    }

    public function test_query_context_is_isolated_between_suspended_fibers(): void
    {
        $parent = $this->prefixedParent();
        $defaultParent = FinalReviewScopedUser::find(1);
        FinalReviewScopedPost::$suspendFactory = true;
        $tenant = new Fiber(fn() => $parent->posts()->first()->title);
        $default = new Fiber(fn() => $defaultParent->posts()->first()->title);
        $tenant->start();
        $default->start();
        $this->assertNull(ModelContext::currentQueryContext(FinalReviewScopedPost::class));
        $default->resume();
        $tenant->resume();
        $this->assertSame('Visible', $tenant->getReturn());
        $this->assertSame('Beta', $default->getReturn());
    }

    public function test_context_queries_preserve_a_container_bound_query_builder(): void
    {
        $parent = $this->prefixedParent();
        $this->app->bind(QueryBuilder::class, FinalReviewCustomBuilder::class);
        $this->assertInstanceOf(FinalReviewCustomBuilder::class, $parent->newQuery());
        $this->assertInstanceOf(FinalReviewCustomBuilder::class, $parent->posts()->query());
    }

    public function test_conflicting_named_placeholders_never_silently_change_values(): void
    {
        $this->assertThrows(QueryBuilderException::class, fn() => query('scenario_users')
            ->where('id', 1)->selectRaw(':id AS marker', ['id' => 99])->all());
        $this->assertThrows(QueryBuilderException::class, fn() => query('scenario_users')
            ->selectRaw(':value AS marker', ['value' => 99])->whereRaw('id = :value', ['value' => 1])->all());
        $this->assertThrows(QueryBuilderException::class, fn() => query('scenario_users')
            ->whereRaw('id = :value', ['value' => 1])->whereRaw('id = :value', [':value' => 2])->all());
        $result = query('scenario_users')->where('id', 1)->selectRaw(':marker AS marker', ['marker' => 99])->first();
        $this->assertSame(99, (int) $result->marker);
    }

    public function test_relationship_aggregates_drop_order_bindings_and_honor_zero_limits(): void
    {
        foreach ([['?', [9]], [':rank', ['rank' => 9]]] as [$placeholder, $bindings]) {
            $user = ScenarioUser::withCount('posts', fn($q) => $q->orderByRaw("score + $placeholder", $bindings))->find(1);
            $this->assertSame(2, (int) $user->posts_count);
        }
        $this->assertSame(0, ScenarioUser::whereHas('posts', fn($q) => $q->take(0))->count());
        $this->assertSame(3, ScenarioUser::whereDoesntHave('posts', fn($q) => $q->take(0))->count());
        $this->assertSame(0, (int) ScenarioUser::withCount('posts', fn($q) => $q->take(0))->find(1)->posts_count);
        $user = ScenarioUser::withExists('posts', fn($q) => $q->take(0))->find(1);
        $this->assertSame(0, (int) $user->posts_exists);
    }

    public function test_relationship_sql_compilation_does_not_open_a_database_connection(): void
    {
        $database = new FinalReviewUnopenedDatabase(['driver' => 'mysql', 'prefix' => 'compile_']);
        $sql = ScenarioUser::query()->setDB($database)->withCount('posts')->withExists('posts')->toSql();
        $this->assertStringContainsString('`compile_scenario_posts`', $sql);
        $this->assertStringContainsString('`compile_scenario_users`', $sql);
    }

    public function test_rejected_raw_binding_conflicts_leave_the_query_usable(): void
    {
        $query = query('scenario_users')->selectRaw(':marker AS marker', ['marker' => 7])->where('id', 1);
        $this->assertThrows(QueryBuilderException::class, fn() => $query->selectRaw(':id AS marker', ['id' => 99]));
        $this->assertSame(7, (int) (clone $query)->first()->marker);
        $this->assertThrows(QueryBuilderException::class, fn() => $query->whereRaw('id = :id', ['id' => 99]));
        $this->assertSame(7, (int) $query->first()->marker);
    }

    public function test_unsupported_aggregate_constraints_are_rejected_instead_of_dropped(): void
    {
        foreach ([fn($q) => $q->groupBy('user_id'), fn($q) => $q->having('COUNT(*) > 100'), fn($q) => $q->union(ScenarioPost::query())] as $constraint) {
            $this->assertThrows(QueryBuilderException::class, fn() => ScenarioUser::whereHas('posts', $constraint)->count());
            $this->assertThrows(QueryBuilderException::class, fn() => ScenarioUser::withCount('posts', $constraint)->all());
            $this->assertThrows(QueryBuilderException::class, fn() => ScenarioUser::withExists('posts', $constraint)->all());
        }
    }

    public function test_changing_a_loaded_primary_key_never_overwrites_a_different_row(): void
    {
        $user = ScenarioUser::find(1);
        $user->id = 2;
        $user->name = 'Must not overwrite Grace';
        $this->assertThrows(PDOException::class, fn() => $user->save());
        $this->assertSame('Ada', ScenarioUser::find(1)->name);
        $this->assertSame('Grace', ScenarioUser::find(2)->name);
        $this->assertTrue($user->remove());
        $this->assertNull(ScenarioUser::find(1));
        $this->assertSame('Grace', ScenarioUser::find(2)->name);
    }

    public function test_instance_writes_remain_scoped_when_the_current_key_is_null(): void
    {
        $user = ScenarioUser::find(1);
        $user->id = null;
        $this->assertSame(1, $user->update(['name' => 'Scoped']));
        $this->assertSame('Scoped', $user->refresh()->name);
        $this->assertSame('Grace', ScenarioUser::find(2)->name);
        $user->id = 99;
        $this->assertTrue($user->increment('id', 10));
        $this->assertSame(11, (int) $user->refresh()->id);
        $user->id = null;
        $this->assertTrue($user->decrement('id', 1));
        $this->assertSame(10, (int) $user->refresh()->id);
    }

    public function test_primary_key_changes_survive_subsequent_writes_serialization_and_refresh(): void
    {
        $user = ScenarioUser::find(1);
        $user->id = 100;
        $this->assertTrue($user->save());
        $this->assertNull(ScenarioUser::find(1));
        $this->assertSame(1, $user->update(['name' => 'Changed']));
        $user = unserialize(serialize($user));
        $user->id = 2;
        $this->assertSame(100, (int) $user->refresh()->id);
        $this->assertSame('Changed', $user->name);
        $this->assertTrue($user->remove());
        $this->assertSame('Grace', ScenarioUser::find(2)->name);
    }

    public function test_primary_identity_respects_prefixes_and_guarded_key_attributes(): void
    {
        $parent = $this->prefixedParent();
        $parent->id = 100;
        $this->assertTrue($parent->save());
        $this->assertSame(100, (int) $parent->refresh()->id);
        $this->assertSame('Ada', ScenarioUser::find(1)->name);
        $user = FinalReviewGuardedUser::find(1);
        $user->id = 2;
        $user->name = 'Guarded';
        $this->assertTrue($user->save());
        $this->assertSame(1, (int) $user->refresh()->id);
        $this->assertSame('Guarded', ScenarioUser::find(1)->name);
        $this->assertSame('Grace', ScenarioUser::find(2)->name);
    }

    public function test_string_and_zero_primary_keys_retain_their_stored_identity(): void
    {
        Schema::create('scenario_schema', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('name');
        });
        query('scenario_schema')->insert([['code' => '0', 'name' => 'Zero'], ['code' => 'b', 'name' => 'Other']]);
        $model = ScenarioCustomKey::find('0');
        $model->code = 'new';
        $this->assertTrue($model->save());
        $this->assertSame(1, $model->update(['name' => 'Renamed']));
        $this->assertSame('Renamed', $model->refresh()->name);
        $model->code = 'b';
        $this->assertTrue($model->remove());
        $this->assertSame('Other', ScenarioCustomKey::find('b')->name);
    }

    public function test_soft_restore_targets_the_stored_row_when_key_attributes_change(): void
    {
        ScenarioPost::whereIn('id', [1, 2])->delete();
        $post = ScenarioPost::withTrashed()->find(1);
        $post->id = 2;
        $this->assertTrue($post->restore());
        $this->assertNotNull(ScenarioPost::find(1));
        $this->assertNull(ScenarioPost::find(2));
    }

    public function test_expanded_array_binding_names_cannot_collide_with_scalar_bindings(): void
    {
        $this->assertThrows(QueryBuilderException::class, fn() => query('scenario_users')->whereIn('id', [1])
            ->selectRaw(':id_0 AS marker', ['id_0' => 2])->all());
        $this->assertThrows(QueryBuilderException::class, fn() => query('scenario_users')->selectRaw(':id_0 AS marker', ['id_0' => 2])
            ->whereIn('id', [1])->all());
        $this->assertSame(1, query('scenario_users')->whereIn('id', [1, 2])->whereIn('id', [1])->count());
        $this->assertSame(99, (int) query('scenario_users')->whereIn('id', [1])
            ->selectRaw(':marker AS marker', ['marker' => 99])->first()->marker);
    }

    public function test_literal_question_marks_do_not_change_the_binding_style(): void
    {
        $row = query('scenario_users')->selectRaw("'?' AS marker, :value AS bound", ['value' => 7])->where('id', 1)->first();
        $this->assertSame('?', $row->marker);
        $this->assertSame(7, (int) $row->bound);
        $this->assertSame(1, query('scenario_users')->whereRaw("name = :name AND '?' = '?'", ['name' => 'Ada'])->count());
        $this->assertSame(1, query('scenario_users')->whereRaw('id = ?', [9 => 1])->count());
    }

    public function test_arithmetic_write_bindings_are_isolated_from_where_bindings(): void
    {
        $this->assertTrue(query('scenario_posts')->whereRaw('id = :increment', ['increment' => 1])->increment('score', 7));
        $this->assertSame(7, ScenarioPost::find(1)->score);
        $this->assertTrue(query('scenario_posts')->whereRaw('id = :decrement', ['decrement' => 1])->decrement('score', 3));
        $this->assertSame(4, ScenarioPost::find(1)->score);
        $post = ScenarioPost::find(1);
        $this->assertTrue($post->increment('id', 100));
        $this->assertSame(101, (int) $post->refresh()->id);
        $this->assertTrue($post->decrement('id', 1));
        $this->assertSame(100, (int) $post->refresh()->id);
    }

    public function test_self_relation_aggregate_scopes_cannot_accidentally_filter_the_outer_row(): void
    {
        Schema::create('scenario_schema', function (Blueprint $table) {
            $table->id();
            $table->integer('parent_id')->nullable();
            $table->integer('visible');
        });
        query('scenario_schema')->insert([
            ['id' => 1, 'parent_id' => null, 'visible' => 1],
            ['id' => 2, 'parent_id' => 1, 'visible' => 1],
            ['id' => 3, 'parent_id' => 1, 'visible' => 0],
        ]);
        $this->assertSame(1, (int) FinalReviewNode::withCount('children')->find(1)->children_count);
        $this->assertSame(1, (int) FinalReviewNode::withExists('children')->find(1)->children_exists);
        $this->assertSame(1, FinalReviewNode::whereHas('children')->count());
        $this->assertSame(1, (int) FinalReviewNode::withSum('children', 'visible')->find(1)->children_sum);
        $node = FinalReviewNode::withCount('children', fn($q) => $q->where($q->getAlias() . '.visible', 1))->find(1);
        $this->assertSame(1, (int) $node->children_count);

        $this->assertThrows(QueryBuilderException::class, fn() => FinalReviewNode::withCount(
            'children',
            fn($q) => $q->where('scenario_schema.visible', 1)
        )->find(1));
        $this->assertThrows(QueryBuilderException::class, fn() => FinalReviewNode::withSum('children', 'scenario_schema.visible')->find(1));
        $table = app(DB::class)->wrapTable('scenario_schema');
        $this->assertThrows(QueryBuilderException::class, fn() => FinalReviewNode::withExists(
            'children',
            fn($q) => $q->whereRaw("$table.visible = 1")
        )->find(1));
        $node = FinalReviewNode::withCount('children', fn($q) => $q->whereRaw("'$table.visible' = '$table.visible' /* $table.visible */"))->find(1);
        $this->assertSame(1, (int) $node->children_count);

        FinalReviewNode::$qualifiedScope = true;
        $this->assertSame(1, FinalReviewNode::find(1)->children()->count());
        $this->assertThrows(QueryBuilderException::class, fn() => FinalReviewNode::withCount('children')->find(1));
        $this->assertThrows(QueryBuilderException::class, fn() => FinalReviewNode::withExists('children')->find(1));
        $this->assertThrows(QueryBuilderException::class, fn() => FinalReviewNode::whereHas('children')->count());
        $this->assertThrows(QueryBuilderException::class, fn() => FinalReviewUnscopedNode::query()->as('parent_node')
            ->withCount('children', fn($q) => $q->as('parent_node'))->find(1));
    }
}
