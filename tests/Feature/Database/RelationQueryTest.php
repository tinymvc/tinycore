<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class RelationQueryTest extends DatabaseScenarioTestCase
{
    public function test_has_many(): void
    {
        $this->assertSame([1, 2], array_map('intval', ScenarioUser::has('posts')->orderBy('id')->pluck('id')));
    }

    public function test_doesnt_have(): void
    {
        $this->assertSame([3], array_map('intval', ScenarioUser::doesntHave('posts')->orderBy('id')->pluck('id')));
    }

    public function test_has_count(): void
    {
        $this->assertSame([1, 2], array_map('intval', ScenarioUser::has('posts', '>=', 2)->orderBy('id')->pluck('id')));
    }

    public function test_has_greater_count(): void
    {
        $this->assertSame([], array_map('intval', ScenarioUser::has('posts', '>', 2)->orderBy('id')->pluck('id')));
    }

    public function test_has_zero(): void
    {
        $this->assertSame([3], array_map('intval', ScenarioUser::has('posts', '=', 0)->orderBy('id')->pluck('id')));
    }

    public function test_where_has(): void
    {
        $this->assertSame([2], array_map('intval', ScenarioUser::whereHas('posts', fn($q) => $q->where('score', '>=', 20))->orderBy('id')->pluck('id')));
    }

    public function test_where_doesnt_have(): void
    {
        $this->assertSame([1, 3], array_map('intval', ScenarioUser::whereDoesntHave('posts', fn($q) => $q->where('score', '>=', 20))->orderBy('id')->pluck('id')));
    }

    public function test_nested_has(): void
    {
        $this->assertSame([1, 2], array_map('intval', ScenarioUser::has('posts.comments')->orderBy('id')->pluck('id')));
    }

    public function test_nested_where_has(): void
    {
        $this->assertSame([1], array_map('intval', ScenarioUser::whereHas('posts.comments', fn($q) => $q->where('body', 'First'))->orderBy('id')->pluck('id')));
    }

    public function test_nested_absence(): void
    {
        $this->assertSame([3], array_map('intval', ScenarioUser::doesntHave('posts.comments')->orderBy('id')->pluck('id')));
    }

    public function test_where_relation(): void
    {
        $this->assertSame([2], array_map('intval', ScenarioUser::whereRelation('posts', 'score', '>', 20)->orderBy('id')->pluck('id')));
    }

    public function test_where_relation_in(): void
    {
        $this->assertSame([1], array_map('intval', ScenarioUser::whereRelationIn('posts', 'score', [10])->orderBy('id')->pluck('id')));
    }

    public function test_where_relation_null(): void
    {
        $this->assertSame([1, 2], array_map('intval', ScenarioUser::whereRelationNull('posts', 'published_at')->orderBy('id')->pluck('id')));
    }

    public function test_where_relation_not_null(): void
    {
        $this->assertSame([1, 2], array_map('intval', ScenarioUser::whereRelationNotNull('posts', 'published_at')->orderBy('id')->pluck('id')));
    }

    public function test_or_has(): void
    {
        $this->assertSame([1, 2, 3], array_map('intval', ScenarioUser::whereKey(3)->orHas('posts')->orderBy('id')->pluck('id')));
    }

    public function test_or_doesnt_have(): void
    {
        $this->assertSame([1, 3], array_map('intval', ScenarioUser::whereKey(1)->orDoesntHave('posts')->orderBy('id')->pluck('id')));
    }

    public function test_or_where_has(): void
    {
        $this->assertSame([1, 3], array_map('intval', ScenarioUser::whereKey(3)->orWhereHas('posts', fn($q) => $q->where('score', 10))->orderBy('id')->pluck('id')));
    }

    public function test_withCount_projection(): void
    {
        $user = ScenarioUser::withCount('posts')->findOrFail(1);

        $this->assertSame(2, (int) $user->posts_count);
    }

    public function test_withSum_projection(): void
    {
        $user = ScenarioUser::withSum('posts', 'score')->findOrFail(1);

        $this->assertSame(10, (int) $user->posts_sum);
    }

    public function test_withAvg_projection(): void
    {
        $user = ScenarioUser::withAvg('posts', 'score')->findOrFail(1);

        $this->assertSame(5, (int) $user->posts_avg);
    }

    public function test_withMin_projection(): void
    {
        $user = ScenarioUser::withMin('posts', 'score')->findOrFail(1);

        $this->assertSame(0, (int) $user->posts_min);
    }

    public function test_withMax_projection(): void
    {
        $user = ScenarioUser::withMax('posts', 'score')->findOrFail(1);

        $this->assertSame(10, (int) $user->posts_max);
    }

    public function test_withExists_projection(): void
    {
        $user = ScenarioUser::withExists('posts')->findOrFail(1);

        $this->assertSame(1, (int) $user->posts_exists);
    }

    public function test_lazy_has_many(): void
    {
        $this->assertCount(2, ScenarioUser::findOrFail(1)->posts);
    }

    public function test_lazy_empty_has_many(): void
    {
        $this->assertCount(0, ScenarioUser::findOrFail(3)->posts);
    }

    public function test_lazy_belongs_to(): void
    {
        $this->assertSame('Ada', ScenarioPost::findOrFail(1)->user->name);
    }

    public function test_eager_has_many(): void
    {
        $users = ScenarioUser::with('posts')->orderBy('id')->all();

        $this->assertTrue($users[0]->relationLoaded('posts'));
        $this->assertCount(2, $users[0]->posts);
        $this->assertCount(0, $users[2]->posts);
    }

    public function test_eager_belongs_to(): void
    {
        $post = ScenarioPost::with('user')->findOrFail(3);

        $this->assertTrue($post->relationLoaded('user'));
        $this->assertSame('Grace', $post->user->name);
    }

    public function test_eager_nested(): void
    {
        $user = ScenarioUser::with('posts.comments')->findOrFail(1);

        $this->assertCount(2, $user->posts[0]->comments);
        $this->assertCount(0, $user->posts[1]->comments);
    }

    public function test_load_after_fetch(): void
    {
        $user = ScenarioUser::findOrFail(1);
        $user->load('posts');

        $this->assertTrue($user->relationLoaded('posts'));
        $this->assertCount(2, $user->posts);
    }

    public function test_relation_find_scope(): void
    {
        $this->assertNull(ScenarioUser::findOrFail(1)->posts()->find(3));
    }

    public function test_relation_key_scope(): void
    {
        $this->assertSame(0, ScenarioUser::findOrFail(1)->posts()->whereKey(3)->count());
    }

    public function test_relation_not_key(): void
    {
        $this->assertSame('Beta', ScenarioUser::findOrFail(1)->posts()->whereNotKey(1)->first()->title);
    }

    public function test_relation_update_scope(): void
    {
        $this->assertSame(2, ScenarioUser::findOrFail(1)->posts()->update(['score' => 99]));
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
    }

    public function test_has_one(): void
    {
        $this->assertSame('Alpha', ScenarioUser::findOrFail(1)->firstPost->title);
    }

    public function test_aggregate_custom_alias(): void
    {
        $this->assertSame(2, (int) ScenarioUser::withCount('posts as total_posts')->findOrFail(1)->total_posts);
    }

    public function test_filtered_exists(): void
    {
        $user = ScenarioUser::withExists('posts as popular', fn($q) => $q->where('score', '>', 20))->findOrFail(1);

        $this->assertSame(0, (int) $user->popular);
    }

    public function test_filtered_count(): void
    {
        $user = ScenarioUser::withCount('posts', fn($q) => $q->where('status', 'published'))->findOrFail(1);

        $this->assertSame(1, (int) $user->posts_count);
    }
}
