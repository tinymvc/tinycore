<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class QueryRegressionTest extends DatabaseScenarioTestCase
{
    public function test_distinct_before_select(): void
    {
        $this->assertSame(['draft', 'published'], ScenarioPost::distinct()->select('status')->orderBy('status')->pluck('status'));
    }

    public function test_distinct_column(): void
    {
        $this->assertCount(2, query('scenario_posts')->distinct()->column('status')->all());
    }

    public function test_distinct_count(): void
    {
        $this->assertSame(2, ScenarioPost::distinct('status')->count());
    }

    public function test_nested_filtered_absence(): void
    {
        $this->assertSame([2, 3], array_map('intval', ScenarioUser::whereDoesntHave('posts.comments', fn ($q) => $q->where('body', 'First'))->orderBy('id')->pluck('id')));
    }

    public function test_nested_or_absence(): void
    {
        $this->assertSame([1, 3], array_map('intval', ScenarioUser::whereKey(1)->orDoesntHave('posts.comments')->orderBy('id')->pluck('id')));
    }

    public function test_nested_absence_after_deletion(): void
    {
        ScenarioComment::where('post_id', 1)->delete();

        $this->assertSame([1, 3], array_map('intval', ScenarioUser::doesntHave('posts.comments')->orderBy('id')->pluck('id')));
    }

    public function test_pagination_total(): void
    {
        $page = ScenarioPost::orderBy('id')->paginate(2);

        $this->assertSame(5, $page->total());
        $this->assertCount(2, $page->items());
    }

    public function test_pagination_with_exists(): void
    {
        $page = ScenarioPost::withExists('comments')->orderBy('id')->paginate(2);

        $this->assertSame(5, $page->total());
        $this->assertSame(1, (int) $page->items()[0]->comments_exists);
    }

    public function test_pagination_with_count(): void
    {
        $page = ScenarioPost::withCount('comments')->orderBy('id')->paginate(2);

        $this->assertSame(5, $page->total());
        $this->assertSame(2, (int) $page->items()[0]->comments_count);
    }

    public function test_eager_loading_query_count_is_bounded(): void
    {
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries) {
            $queries++;
        });

        ScenarioPost::with('user')->take(1)->all();
        $small = $queries;
        $queries = 0;
        ScenarioPost::with('user')->take(5)->all();

        $this->assertSame(2, $small);
        $this->assertSame($small, $queries);
    }

    public function test_eager_serialization_executes_no_queries(): void
    {
        $posts = ScenarioPost::with(['user', 'comments'])->all();
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries) {
            $queries++;
        });

        foreach ($posts as $post) {
            $post->toArray();
        }

        $this->assertSame(0, $queries);
    }

    public function test_loaded_relation_is_cached(): void
    {
        $user = ScenarioUser::with('posts')->findOrFail(1);
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries) {
            $queries++;
        });

        $this->assertCount(2, $user->posts);
        $this->assertCount(2, $user->posts);
        $this->assertSame(0, $queries);
    }
}
