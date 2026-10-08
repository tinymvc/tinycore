<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class QueryStateContractTest extends DatabaseScenarioTestCase
{
    public function test_count_exists_then_read_empty_in(): void
    {
        $query = ScenarioPost::whereIn('id', []);
        $this->assertSame(0, $query->count());
        $this->assertSame(false, $query->exists());
        $this->assertSame([], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_count_exists_then_read_sparse_in(): void
    {
        $query = ScenarioPost::whereIn('id', [3 => 2, 9 => 4]);
        $this->assertSame(2, $query->count());
        $this->assertSame(true, $query->exists());
        $this->assertSame([2, 4], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_count_exists_then_read_not_in(): void
    {
        $query = ScenarioPost::whereNotIn('id', [2, 4]);
        $this->assertSame(3, $query->count());
        $this->assertSame(true, $query->exists());
        $this->assertSame([1, 3, 5], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_count_exists_then_read_null_foreign_key(): void
    {
        $query = ScenarioPost::whereNull('user_id');
        $this->assertSame(1, $query->count());
        $this->assertSame(true, $query->exists());
        $this->assertSame([5], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_count_exists_then_read_not_null_foreign_key(): void
    {
        $query = ScenarioPost::whereNotNull('user_id');
        $this->assertSame(4, $query->count());
        $this->assertSame(true, $query->exists());
        $this->assertSame([1, 2, 3, 4], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_count_exists_then_read_grouped_or(): void
    {
        $query = ScenarioPost::where(fn($q) => $q->where('score', 0)->orWhere('score', 20))->where('user_id', 2);
        $this->assertSame(1, $query->count());
        $this->assertSame(true, $query->exists());
        $this->assertSame([3], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_count_exists_then_read_between(): void
    {
        $query = ScenarioPost::whereBetween('score', [10, 30]);
        $this->assertSame(3, $query->count());
        $this->assertSame(true, $query->exists());
        $this->assertSame([2, 3, 4], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_count_exists_then_read_not_between(): void
    {
        $query = ScenarioPost::whereNotBetween('score', [10, 30]);
        $this->assertSame(2, $query->count());
        $this->assertSame(true, $query->exists());
        $this->assertSame([1, 5], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_count_exists_then_read_bound_raw(): void
    {
        $query = ScenarioPost::whereRaw('score >= ? AND status = ?', [10, 'published']);
        $this->assertSame(3, $query->count());
        $this->assertSame(true, $query->exists());
        $this->assertSame([2, 3, 5], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_count_exists_then_read_subquery(): void
    {
        $query = ScenarioPost::whereIn('user_id', query('scenario_users')->select('id')->where('name', 'Ada'));
        $this->assertSame(2, $query->count());
        $this->assertSame(true, $query->exists());
        $this->assertSame([1, 2], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_count_exists_then_read_date(): void
    {
        $query = ScenarioPost::whereDate('published_at', '2026-02-20');
        $this->assertSame(1, $query->count());
        $this->assertSame(true, $query->exists());
        $this->assertSame([3], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_count_exists_then_read_column_comparison(): void
    {
        $query = ScenarioPost::whereColumn('id', '=', 'user_id');
        $this->assertSame(1, $query->count());
        $this->assertSame(true, $query->exists());
        $this->assertSame([1], $this->ids($query));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_aggregate_scope_published(): void
    {
        $this->assertSame(70.0, ScenarioPost::where('status', 'published')->sum('score'));
        $this->assertEqualsWithDelta(23.333333333333332, ScenarioPost::where('status', 'published')->avg('score'), 0.0001);
        $this->assertSame(10.0, ScenarioPost::where('status', 'published')->min('score'));
        $this->assertSame(40.0, ScenarioPost::where('status', 'published')->max('score'));
    }

    public function test_aggregate_scope_draft(): void
    {
        $this->assertSame(30.0, ScenarioPost::where('status', 'draft')->sum('score'));
        $this->assertEqualsWithDelta(15, ScenarioPost::where('status', 'draft')->avg('score'), 0.0001);
        $this->assertSame(0.0, ScenarioPost::where('status', 'draft')->min('score'));
        $this->assertSame(30.0, ScenarioPost::where('status', 'draft')->max('score'));
    }

    public function test_aggregate_scope_orphan(): void
    {
        $this->assertSame(40.0, ScenarioPost::whereNull('user_id')->sum('score'));
        $this->assertEqualsWithDelta(40, ScenarioPost::whereNull('user_id')->avg('score'), 0.0001);
        $this->assertSame(40.0, ScenarioPost::whereNull('user_id')->min('score'));
        $this->assertSame(40.0, ScenarioPost::whereNull('user_id')->max('score'));
    }

    public function test_aggregate_scope_ada_posts(): void
    {
        $this->assertSame(10.0, ScenarioPost::where('user_id', 1)->sum('score'));
        $this->assertEqualsWithDelta(5, ScenarioPost::where('user_id', 1)->avg('score'), 0.0001);
        $this->assertSame(0.0, ScenarioPost::where('user_id', 1)->min('score'));
        $this->assertSame(10.0, ScenarioPost::where('user_id', 1)->max('score'));
    }

    public function test_aggregate_scope_grace_posts(): void
    {
        $this->assertSame(50.0, ScenarioPost::where('user_id', 2)->sum('score'));
        $this->assertEqualsWithDelta(25, ScenarioPost::where('user_id', 2)->avg('score'), 0.0001);
        $this->assertSame(20.0, ScenarioPost::where('user_id', 2)->min('score'));
        $this->assertSame(30.0, ScenarioPost::where('user_id', 2)->max('score'));
    }

    public function test_relation_mutation_count_zero(): void
    {
        ScenarioUser::has('posts', '=', 0)->update(['name' => 'Selected']);
        $this->assertSame([3], array_map('intval', query('scenario_users')->where('name', 'Selected')->orderBy('id')->pluck('id')));
        $this->assertSame(3, ScenarioUser::count());
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_relation_mutation_count_one(): void
    {
        ScenarioUser::has('posts', '=', 1)->update(['name' => 'Selected']);
        $this->assertSame([], array_map('intval', query('scenario_users')->where('name', 'Selected')->orderBy('id')->pluck('id')));
        $this->assertSame(3, ScenarioUser::count());
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_relation_mutation_count_two(): void
    {
        ScenarioUser::has('posts', '=', 2)->update(['name' => 'Selected']);
        $this->assertSame([1, 2], array_map('intval', query('scenario_users')->where('name', 'Selected')->orderBy('id')->pluck('id')));
        $this->assertSame(3, ScenarioUser::count());
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_relation_mutation_count_less_two(): void
    {
        ScenarioUser::has('posts', '<', 2)->update(['name' => 'Selected']);
        $this->assertSame([3], array_map('intval', query('scenario_users')->where('name', 'Selected')->orderBy('id')->pluck('id')));
        $this->assertSame(3, ScenarioUser::count());
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_relation_mutation_count_greater_two(): void
    {
        ScenarioUser::has('posts', '>', 2)->update(['name' => 'Selected']);
        $this->assertSame([], array_map('intval', query('scenario_users')->where('name', 'Selected')->orderBy('id')->pluck('id')));
        $this->assertSame(3, ScenarioUser::count());
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_relation_mutation_filtered(): void
    {
        ScenarioUser::whereHas('posts', fn($q) => $q->where('score', '>=', 20))->update(['name' => 'Selected']);
        $this->assertSame([2], array_map('intval', query('scenario_users')->where('name', 'Selected')->orderBy('id')->pluck('id')));
        $this->assertSame(3, ScenarioUser::count());
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_relation_mutation_inverse_orphan(): void
    {
        ScenarioUser::whereDoesntHave('posts')->update(['name' => 'Selected']);
        $this->assertSame([3], array_map('intval', query('scenario_users')->where('name', 'Selected')->orderBy('id')->pluck('id')));
        $this->assertSame(3, ScenarioUser::count());
        $this->assertSame(5, ScenarioPost::count());
    }

}
