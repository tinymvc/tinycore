<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class PredicateTest extends DatabaseScenarioTestCase
{
    public function test_equality(): void
    {
        $this->assertSame([2], $this->ids(ScenarioPost::where('score', 10)));
    }

    public function test_inequality(): void
    {
        $this->assertSame([1, 3, 4, 5], $this->ids(ScenarioPost::where('score', '!=', 10)));
    }

    public function test_greater(): void
    {
        $this->assertSame([4, 5], $this->ids(ScenarioPost::where('score', '>', 20)));
    }

    public function test_greater_equal(): void
    {
        $this->assertSame([3, 4, 5], $this->ids(ScenarioPost::where('score', '>=', 20)));
    }

    public function test_less(): void
    {
        $this->assertSame([1, 2], $this->ids(ScenarioPost::where('score', '<', 20)));
    }

    public function test_less_equal(): void
    {
        $this->assertSame([1, 2, 3], $this->ids(ScenarioPost::where('score', '<=', 20)));
    }

    public function test_zero(): void
    {
        $this->assertSame([1], $this->ids(ScenarioPost::where('score', 0)));
    }

    public function test_array_conditions(): void
    {
        $this->assertSame([2], $this->ids(ScenarioPost::where(['status' => 'published', 'user_id' => 1])));
    }

    public function test_closure_group(): void
    {
        $this->assertSame([3], $this->ids(ScenarioPost::where(fn ($q) => $q->where('score', 0)->orWhere('score', 20))->where('user_id', 2)));
    }

    public function test_or_equality(): void
    {
        $this->assertSame([1, 5], $this->ids(ScenarioPost::where('score', 0)->orWhere('score', 40)));
    }

    public function test_null(): void
    {
        $this->assertSame([5], $this->ids(ScenarioPost::whereNull('user_id')));
    }

    public function test_not_null(): void
    {
        $this->assertSame([1, 2, 3, 4], $this->ids(ScenarioPost::whereNotNull('user_id')));
    }

    public function test_or_null(): void
    {
        $this->assertSame([1, 5], $this->ids(ScenarioPost::where('score', 0)->orWhereNull('user_id')));
    }

    public function test_or_not_null(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], $this->ids(ScenarioPost::where('score', 40)->orWhereNotNull('user_id')));
    }

    public function test_in(): void
    {
        $this->assertSame([1, 3], $this->ids(ScenarioPost::whereIn('id', [1, 3])));
    }

    public function test_not_in(): void
    {
        $this->assertSame([2, 4, 5], $this->ids(ScenarioPost::whereNotIn('id', [1, 3])));
    }

    public function test_empty_in(): void
    {
        $this->assertSame([], $this->ids(ScenarioPost::whereIn('id', [])));
    }

    public function test_empty_not_in(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], $this->ids(ScenarioPost::whereNotIn('id', [])));
    }

    public function test_sparse_in(): void
    {
        $this->assertSame([1, 3], $this->ids(ScenarioPost::whereIn('id', [4 => 1, 9 => 3])));
    }

    public function test_duplicate_in(): void
    {
        $this->assertSame([1, 3], $this->ids(ScenarioPost::whereIn('id', [1, 1, 3])));
    }

    public function test_or_in(): void
    {
        $this->assertSame([1, 3, 5], $this->ids(ScenarioPost::where('id', 1)->orWhereIn('id', [3, 5])));
    }

    public function test_or_not_in(): void
    {
        $this->assertSame([1, 5], $this->ids(ScenarioPost::where('id', 1)->orWhereNotIn('id', [1, 2, 3, 4])));
    }

    public function test_between_inclusive(): void
    {
        $this->assertSame([2, 3, 4], $this->ids(ScenarioPost::whereBetween('score', [10, 30])));
    }

    public function test_not_between(): void
    {
        $this->assertSame([1, 5], $this->ids(ScenarioPost::whereNotBetween('score', [10, 30])));
    }

    public function test_or_between(): void
    {
        $this->assertSame([1, 4, 5], $this->ids(ScenarioPost::where('id', 1)->orWhereBetween('score', [30, 40])));
    }

    public function test_or_not_between(): void
    {
        $this->assertSame([1, 3, 5], $this->ids(ScenarioPost::where('id', 3)->orWhereNotBetween('score', [10, 30])));
    }

    public function test_raw_bound(): void
    {
        $this->assertSame([4, 5], $this->ids(ScenarioPost::whereRaw('score > :minimum', ['minimum' => 20])));
    }

    public function test_or_raw(): void
    {
        $this->assertSame([1, 5], $this->ids(ScenarioPost::where('id', 1)->orWhereRaw('score > :minimum', ['minimum' => 30])));
    }

    public function test_column_equal(): void
    {
        $this->assertSame([1], $this->ids(ScenarioPost::whereColumn('id', 'user_id')));
    }

    public function test_column_greater(): void
    {
        $this->assertSame([2, 3, 4], $this->ids(ScenarioPost::whereColumn('id', '>', 'user_id')));
    }

    public function test_like(): void
    {
        $this->assertSame([1], $this->ids(ScenarioPost::like('title', 'Al%')));
    }

    public function test_not_like(): void
    {
        $this->assertSame([2, 3, 4, 5], $this->ids(ScenarioPost::notLike('title', 'Al%')));
    }

    public function test_contains(): void
    {
        $this->assertSame([3], $this->ids(ScenarioPost::whereContains('title', 'amm')));
    }

    public function test_starts_with(): void
    {
        $this->assertSame([1], $this->ids(ScenarioPost::whereStartsWith('title', 'Al')));
    }

    public function test_ends_with(): void
    {
        $this->assertSame([2, 4], $this->ids(ScenarioPost::whereEndsWith('title', 'ta')));
    }

    public function test_or_contains(): void
    {
        $this->assertSame([1, 3], $this->ids(ScenarioPost::where('id', 1)->orWhereContains('title', 'amm')));
    }

    public function test_date(): void
    {
        $this->assertSame([3], $this->ids(ScenarioPost::whereDate('published_at', '2026-02-20')));
    }

    public function test_year(): void
    {
        $this->assertSame([3, 5], $this->ids(ScenarioPost::whereYear('published_at', 2026)));
    }

    public function test_month(): void
    {
        $this->assertSame([2], $this->ids(ScenarioPost::whereMonth('published_at', 1)));
    }

    public function test_or_year(): void
    {
        $this->assertSame([1, 2], $this->ids(ScenarioPost::where('id', 1)->orWhereYear('published_at', 2025)));
    }

    public function test_key(): void
    {
        $this->assertSame([2], $this->ids(ScenarioPost::whereKey(2)));
    }

    public function test_keys(): void
    {
        $this->assertSame([1, 3], $this->ids(ScenarioPost::whereKey([1, 3])));
    }

    public function test_empty_keys(): void
    {
        $this->assertSame([], $this->ids(ScenarioPost::whereKey([])));
    }

    public function test_not_key(): void
    {
        $this->assertSame([1, 3, 4, 5], $this->ids(ScenarioPost::whereNotKey(2)));
    }

    public function test_not_keys(): void
    {
        $this->assertSame([2, 4, 5], $this->ids(ScenarioPost::whereNotKey([1, 3])));
    }

    public function test_not_empty_keys(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], $this->ids(ScenarioPost::whereNotKey([])));
    }

    public function test_bound_injection(): void
    {
        $this->assertSame([], $this->ids(ScenarioPost::where('title', "' OR 1=1 --")));
    }

    public function test_subquery(): void
    {
        $this->assertSame([1, 3], $this->ids(ScenarioPost::whereIn('id', query('scenario_comments')->select('post_id'))));
    }

    public function test_negative_subquery(): void
    {
        $this->assertSame([2, 4, 5], $this->ids(ScenarioPost::whereNotIn('id', query('scenario_comments')->select('post_id'))));
    }

    public function test_closure_subquery(): void
    {
        $this->assertSame([1, 3], $this->ids(ScenarioPost::whereIn('id', fn ($q) => $q->table('scenario_comments')->select('post_id'))));
    }

    public function test_raw_subquery(): void
    {
        $this->assertSame([1, 3], $this->ids(ScenarioPost::whereIn('id', 'SELECT post_id FROM scenario_comments')));
    }

    public function test_exists(): void
    {
        $this->assertSame([1, 3], $this->ids(ScenarioPost::whereExists(query('scenario_comments')->selectRaw('1')->whereColumn('post_id', 'scenario_posts.id'))));
    }

    public function test_not_exists(): void
    {
        $this->assertSame([2, 4, 5], $this->ids(ScenarioPost::whereNotExists(query('scenario_comments')->selectRaw('1')->whereColumn('post_id', 'scenario_posts.id'))));
    }

    public function test_subquery_binding_collision(): void
    {
        $this->assertSame([], $this->ids(ScenarioPost::where('id', 1)->whereIn('id', query('scenario_comments')->select('post_id')->where('id', 3))));
    }
}
