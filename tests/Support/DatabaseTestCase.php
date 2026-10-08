<?php

require_once __DIR__ . '/FrameworkTestCase.php';
require_once dirname(__DIR__) . '/Fixtures/Models.php';

use Spark\Database\Schema\Schema;
use Spark\Database\Schema\Blueprint;
use Spark\Facades\DB;

abstract class DatabaseTestCase extends FrameworkTestCase
{
    protected function checkRelationshipScopes(): void
    {
        Schema::create('scope_countries', fn (Blueprint $table) => $table->id());
        Schema::create('scope_users', function (Blueprint $table) {
            $table->id();
            $table->integer('country_id');
            $table->softDeletes();
        });
        Schema::create('scope_posts', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('views');
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('scope_comments', function (Blueprint $table) {
            $table->id();
            $table->integer('post_id');
            $table->softDeletes();
        });
        Schema::create('scope_roles', function (Blueprint $table) {
            $table->id();
            $table->softDeletes();
        });
        Schema::create('scope_role_user', function (Blueprint $table) {
            $table->integer('user_id');
            $table->integer('role_id');
        });
        $deleted = '2026-01-01 12:00:00';
        query('scope_countries')->insert(['id' => 1]);
        query('scope_users')->insert([
            ['id' => 1, 'country_id' => 1, 'deleted_at' => null],
            ['id' => 2, 'country_id' => 1, 'deleted_at' => null],
            ['id' => 3, 'country_id' => 1, 'deleted_at' => $deleted],
            ['id' => 4, 'country_id' => 1, 'deleted_at' => null],
        ]);
        query('scope_posts')->insert([
            ['id' => 1, 'user_id' => 1, 'views' => 10, 'archived_at' => null],
            ['id' => 2, 'user_id' => 1, 'views' => 30, 'archived_at' => $deleted],
            ['id' => 3, 'user_id' => 2, 'views' => 40, 'archived_at' => $deleted],
            ['id' => 4, 'user_id' => 3, 'views' => 50, 'archived_at' => null],
        ]);
        query('scope_comments')->insert([
            ['id' => 1, 'post_id' => 1, 'deleted_at' => null],
            ['id' => 2, 'post_id' => 1, 'deleted_at' => $deleted],
            ['id' => 3, 'post_id' => 2, 'deleted_at' => null],
            ['id' => 4, 'post_id' => 4, 'deleted_at' => $deleted],
        ]);
        query('scope_roles')->insert([
            ['id' => 1, 'deleted_at' => null],
            ['id' => 2, 'deleted_at' => $deleted],
            ['id' => 3, 'deleted_at' => null],
        ]);
        query('scope_role_user')->insert([
            ['user_id' => 1, 'role_id' => 1],
            ['user_id' => 1, 'role_id' => 2],
            ['user_id' => 2, 'role_id' => 3],
        ]);

        // Loading and direct counts: parent and child switches are independent.
        $user = DatabaseFixtureRelationUser::with('posts')->findOrFail(1);
        $this->assertCount(1, $user->posts);
        $this->assertCount(1, DatabaseFixtureRelationUser::findOrFail(1)->posts);
        $this->assertSame(1, $user->posts()->count());
        $this->assertSame(2, $user->posts()->withTrashed()->count());
        $this->assertSame(1, $user->posts()->onlyTrashed()->count());
        $this->assertCount(2, DatabaseFixtureRelationUser::with(['posts' => fn ($q) => $q->withTrashed()])->findOrFail(1)->posts);
        $this->assertSame(2, (int) DatabaseFixtureRelationUser::with(['posts' => fn ($q) => $q->onlyTrashed()])->findOrFail(1)->posts[0]->id);
        $this->assertCount(1, DatabaseFixtureRelationUser::withTrashed()->with('posts')->findOrFail(3)->posts);
        $this->assertSame(3, DatabaseFixtureRelationUser::with(['posts' => fn ($q) => $q->withTrashed()])->count());
        $this->assertCount(0, DatabaseFixtureRelationUser::with('posts')->findOrFail(2)->posts);
        $this->assertNull(DatabaseFixtureRelationUser::with('firstPost')->findOrFail(2)->firstPost);
        $this->assertSame(3, (int) DatabaseFixtureRelationUser::with(['firstPost' => fn ($q) => $q->onlyTrashed()])->findOrFail(2)->firstPost->id);

        // Existence, comparisons, OR variants, bindings, and explicit scope resets.
        $this->assertSame(1, DatabaseFixtureRelationUser::has('posts')->count());
        $this->assertSame(2, DatabaseFixtureRelationUser::doesntHave('posts')->count());
        $this->assertSame(0, DatabaseFixtureRelationUser::has('posts', '>=', 2)->count());
        $this->assertSame(2, DatabaseFixtureRelationUser::whereHas('posts', fn ($q) => $q->withTrashed())->count());
        $this->assertSame(2, DatabaseFixtureRelationUser::whereHas('posts', fn ($q) => $q->onlyTrashed())->count());
        $this->assertSame(1, DatabaseFixtureRelationUser::whereDoesntHave('posts', fn ($q) => $q->withTrashed())->count());
        $this->assertSame(1, DatabaseFixtureRelationUser::whereHas('posts', fn ($q) => $q->withTrashed()->withoutTrashed())->count());
        $this->assertSame(1, DatabaseFixtureRelationUser::whereHas('posts', fn ($q) => $q->onlyTrashed()->withTrashed(false))->count());
        $this->assertSame(0, DatabaseFixtureRelationUser::whereHas('posts', fn ($q) => $q->where('views', '>', 20))->count());
        $this->assertSame(2, DatabaseFixtureRelationUser::whereHas('posts', fn ($q) => $q->withTrashed()->where('views', '>', 20))->count());
        $this->assertSame(0, DatabaseFixtureRelationUser::whereRelation('posts', 'views', '>', 20)->count());
        $this->assertSame(2, DatabaseFixtureRelationUser::where('id', 4)->orHas('posts')->count());
        $this->assertSame(3, DatabaseFixtureRelationUser::has('posts')->orDoesntHave('posts')->count());

        $totals = DatabaseFixtureRelationUser::withCount('posts')
            ->withCount('posts as all_posts', fn ($q) => $q->withTrashed())
            ->withCount('posts as archived_posts', fn ($q) => $q->onlyTrashed())
            ->withSum('posts', 'views')->withAvg('posts', 'views')
            ->withMin('posts', 'views')->withMax('posts', 'views')->findOrFail(1);
        $this->assertSame([1, 2, 1, 10, 10, 10, 10], array_map(fn ($key) => (int) $totals->$key,
            ['posts_count', 'all_posts', 'archived_posts', 'posts_sum', 'posts_avg', 'posts_min', 'posts_max']));
        $this->assertSame(0, (int) DatabaseFixtureRelationUser::withCount('posts')->findOrFail(2)->posts_count);
        $this->assertSame(40, (int) DatabaseFixtureRelationUser::withSum('posts', 'views', fn ($q) => $q->withTrashed())->findOrFail(1)->posts_sum);
        $this->assertSame(30, (int) DatabaseFixtureRelationUser::withSum('posts', 'views', fn ($q) => $q->onlyTrashed())->findOrFail(1)->posts_sum);

        // Inverse and nested relations scope each related model independently.
        $this->assertNull(DatabaseFixtureRelationPost::with('user')->findOrFail(4)->user);
        $this->assertSame(3, (int) DatabaseFixtureRelationPost::with(['user' => fn ($q) => $q->withTrashed()])->findOrFail(4)->user->id);
        $this->assertSame(1, DatabaseFixtureRelationPost::has('user')->count());
        $this->assertSame(1, DatabaseFixtureRelationPost::doesntHave('user')->count());
        $this->assertSame(2, DatabaseFixtureRelationPost::whereHas('user', fn ($q) => $q->withTrashed())->count());
        $this->assertSame(0, (int) DatabaseFixtureRelationPost::withCount('user')->findOrFail(4)->user_count);
        $this->assertSame(1, DatabaseFixtureRelationUser::withTrashed()->has('posts.comments')->count());
        $this->assertSame(2, DatabaseFixtureRelationUser::withTrashed()->whereHas('posts.comments', fn ($q) => $q->withTrashed())->count());
        $nested = DatabaseFixtureRelationUser::with(['posts' => fn ($q) => $q->with('comments')])->findOrFail(1);
        $this->assertCount(1, $nested->posts);
        $this->assertCount(1, $nested->posts[0]->comments);
        $this->assertSame(1, (int) DatabaseFixtureRelationUser::withCount('posts.comments as commented_posts')->findOrFail(1)->commented_posts);

        // Joined relations scope final and soft-deletable intermediate models, but not plain pivot tables.
        $this->assertCount(1, DatabaseFixtureRelationUser::with('roles')->findOrFail(1)->roles);
        $this->assertSame(1, DatabaseFixtureRelationUser::findOrFail(1)->roles()->count());
        $this->assertSame(1, (int) DatabaseFixtureRelationUser::withCount('roles')->findOrFail(1)->roles_count);
        $this->assertSame(2, (int) DatabaseFixtureRelationUser::withCount('roles', fn ($q) => $q->withTrashed())->findOrFail(1)->roles_count);
        $this->assertSame(1, DatabaseFixtureRelationUser::whereHas('roles', fn ($q) => $q->onlyTrashed())->count());
        $this->assertCount(1, DatabaseFixtureRelationCountry::with('posts')->findOrFail(1)->posts);
        $this->assertSame(1, DatabaseFixtureRelationCountry::findOrFail(1)->posts()->count());
        $this->assertSame(1, (int) DatabaseFixtureRelationCountry::withCount('posts')->findOrFail(1)->posts_count);
        $this->assertSame(2, DatabaseFixtureRelationCountry::findOrFail(1)->posts()->withTrashedParents()->count());
        $this->assertCount(2, DatabaseFixtureRelationCountry::with(['posts' => fn ($q) => $q->withTrashedParents()])->findOrFail(1)->posts);
        $this->assertSame(2, (int) DatabaseFixtureRelationCountry::withCount('posts', fn ($q) => $q->withTrashedParents())->findOrFail(1)->posts_count);
        $activeThrough = fn ($q) => $q->whereNull('scope_users.deleted_at');
        $this->assertCount(1, DatabaseFixtureRelationCountry::with(['posts' => $activeThrough])->findOrFail(1)->posts);
        $this->assertSame(1, (int) DatabaseFixtureRelationCountry::withCount('posts', $activeThrough)->findOrFail(1)->posts_count);
        $this->assertSame(0, DatabaseFixtureRelationCountry::whereHas('posts', $activeThrough, '>=', 2)->count());

        $this->assertSame(1, (int) DatabaseFixtureRelationUser::withExists('posts.comments')->findOrFail(1)->posts_comments_exists);
        $this->assertSame(0, (int) DatabaseFixtureRelationUser::withExists('posts.comments', fn ($q) => $q->where('id', -1))->findOrFail(1)->posts_comments_exists);

        // Relation pagination keeps EXISTS projections in its count subquery.
        $page = DatabaseFixtureRelationUser::findOrFail(1)->posts()
            ->withExists('comments as viewer_liked', fn ($q) => $q->where('id', 1))
            ->withExists('comments as viewer_saved', fn ($q) => $q->where('id', 999))
            ->with('user')->orderByRaw('scope_posts.views DESC')->paginate(10);
        $this->assertSame(1, $page->total());
        $this->assertCount(1, $page->items());
        $post = $page->items()[0];
        $this->assertSame(1, (int) $post->viewer_liked);
        $this->assertSame(0, (int) $post->viewer_saved);
        $this->assertSame(1, (int) $post->user->id);

        // Both tables may use deleted_at: the model scope is qualified; pivot filtering is opt-in.
        Schema::table('scope_role_user', fn (Blueprint $table) => $table->softDeletes());
        query('scope_role_user')->where('user_id', 2)->update(['deleted_at' => $deleted]);
        $this->assertSame(1, (int) DatabaseFixtureRelationUser::withCount('roles')->findOrFail(2)->roles_count);
        $activeRoles = fn ($q) => $q->whereNull('scope_role_user.deleted_at');
        $this->assertCount(1, DatabaseFixtureRelationUser::with(['roles' => $activeRoles])->findOrFail(1)->roles);
        $this->assertCount(0, DatabaseFixtureRelationUser::with(['roles' => $activeRoles])->findOrFail(2)->roles);
        $this->assertSame(1, DatabaseFixtureRelationUser::whereHas('roles', $activeRoles)->count());
        $this->assertSame(0, (int) DatabaseFixtureRelationUser::withCount('roles', $activeRoles)->findOrFail(2)->roles_count);
    }
}
