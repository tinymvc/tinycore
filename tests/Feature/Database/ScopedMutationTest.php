<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class ScopedMutationTest extends DatabaseScenarioTestCase
{
    public function test_update_empty_in(): void
    {
        $affected = query('scenario_posts')->whereIn('id', [])->update(['title' => 'Changed']);
        $this->assertSame(0, $affected);
        $this->assertSame([], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Alpha', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Gamma', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Delta', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Orphan', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_empty_in(): void
    {
        $this->assertSame(0, query('scenario_posts')->whereIn('id', [])->delete());
        $this->assertSame([1, 2, 3, 4, 5], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_empty_in(): void
    {
        ScenarioPost::whereIn('id', [])->delete();
        $this->assertSame([], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_empty_in(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->whereIn('id', [])->restore();
        $this->assertSame([], $this->ids(ScenarioPost::query()));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_update_sparse_in(): void
    {
        $affected = query('scenario_posts')->whereIn('id', [3 => 2, 9 => 4])->update(['title' => 'Changed']);
        $this->assertSame(2, $affected);
        $this->assertSame([2, 4], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Alpha', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Gamma', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Orphan', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_sparse_in(): void
    {
        $this->assertSame(2, query('scenario_posts')->whereIn('id', [3 => 2, 9 => 4])->delete());
        $this->assertSame([1, 3, 5], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_sparse_in(): void
    {
        ScenarioPost::whereIn('id', [3 => 2, 9 => 4])->delete();
        $this->assertSame([2, 4], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([1, 3, 5], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_sparse_in(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->whereIn('id', [3 => 2, 9 => 4])->restore();
        $this->assertSame([2, 4], $this->ids(ScenarioPost::query()));
        $this->assertSame([1, 3, 5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_update_not_in(): void
    {
        $affected = query('scenario_posts')->whereNotIn('id', [2, 4])->update(['title' => 'Changed']);
        $this->assertSame(3, $affected);
        $this->assertSame([1, 3, 5], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Changed', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Delta', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_not_in(): void
    {
        $this->assertSame(3, query('scenario_posts')->whereNotIn('id', [2, 4])->delete());
        $this->assertSame([2, 4], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_not_in(): void
    {
        ScenarioPost::whereNotIn('id', [2, 4])->delete();
        $this->assertSame([1, 3, 5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([2, 4], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_not_in(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->whereNotIn('id', [2, 4])->restore();
        $this->assertSame([1, 3, 5], $this->ids(ScenarioPost::query()));
        $this->assertSame([2, 4], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_update_null_foreign_key(): void
    {
        $affected = query('scenario_posts')->whereNull('user_id')->update(['title' => 'Changed']);
        $this->assertSame(1, $affected);
        $this->assertSame([5], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Alpha', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Gamma', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Delta', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_null_foreign_key(): void
    {
        $this->assertSame(1, query('scenario_posts')->whereNull('user_id')->delete());
        $this->assertSame([1, 2, 3, 4], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_null_foreign_key(): void
    {
        ScenarioPost::whereNull('user_id')->delete();
        $this->assertSame([5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([1, 2, 3, 4], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_null_foreign_key(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->whereNull('user_id')->restore();
        $this->assertSame([5], $this->ids(ScenarioPost::query()));
        $this->assertSame([1, 2, 3, 4], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_update_not_null_foreign_key(): void
    {
        $affected = query('scenario_posts')->whereNotNull('user_id')->update(['title' => 'Changed']);
        $this->assertSame(4, $affected);
        $this->assertSame([1, 2, 3, 4], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Changed', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Orphan', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_not_null_foreign_key(): void
    {
        $this->assertSame(4, query('scenario_posts')->whereNotNull('user_id')->delete());
        $this->assertSame([5], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_not_null_foreign_key(): void
    {
        ScenarioPost::whereNotNull('user_id')->delete();
        $this->assertSame([1, 2, 3, 4], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([5], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_not_null_foreign_key(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->whereNotNull('user_id')->restore();
        $this->assertSame([1, 2, 3, 4], $this->ids(ScenarioPost::query()));
        $this->assertSame([5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_update_grouped_or(): void
    {
        $affected = query('scenario_posts')->where(fn($q) => $q->where('score', 0)->orWhere('score', 20))->where('user_id', 2)->update(['title' => 'Changed']);
        $this->assertSame(1, $affected);
        $this->assertSame([3], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Alpha', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Delta', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Orphan', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_grouped_or(): void
    {
        $this->assertSame(1, query('scenario_posts')->where(fn($q) => $q->where('score', 0)->orWhere('score', 20))->where('user_id', 2)->delete());
        $this->assertSame([1, 2, 4, 5], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_grouped_or(): void
    {
        ScenarioPost::where(fn($q) => $q->where('score', 0)->orWhere('score', 20))->where('user_id', 2)->delete();
        $this->assertSame([3], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([1, 2, 4, 5], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_grouped_or(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->where(fn($q) => $q->where('score', 0)->orWhere('score', 20))->where('user_id', 2)->restore();
        $this->assertSame([3], $this->ids(ScenarioPost::query()));
        $this->assertSame([1, 2, 4, 5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_update_between(): void
    {
        $affected = query('scenario_posts')->whereBetween('score', [10, 30])->update(['title' => 'Changed']);
        $this->assertSame(3, $affected);
        $this->assertSame([2, 3, 4], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Alpha', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Orphan', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_between(): void
    {
        $this->assertSame(3, query('scenario_posts')->whereBetween('score', [10, 30])->delete());
        $this->assertSame([1, 5], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_between(): void
    {
        ScenarioPost::whereBetween('score', [10, 30])->delete();
        $this->assertSame([2, 3, 4], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([1, 5], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_between(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->whereBetween('score', [10, 30])->restore();
        $this->assertSame([2, 3, 4], $this->ids(ScenarioPost::query()));
        $this->assertSame([1, 5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_update_not_between(): void
    {
        $affected = query('scenario_posts')->whereNotBetween('score', [10, 30])->update(['title' => 'Changed']);
        $this->assertSame(2, $affected);
        $this->assertSame([1, 5], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Changed', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Gamma', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Delta', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_not_between(): void
    {
        $this->assertSame(2, query('scenario_posts')->whereNotBetween('score', [10, 30])->delete());
        $this->assertSame([2, 3, 4], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_not_between(): void
    {
        ScenarioPost::whereNotBetween('score', [10, 30])->delete();
        $this->assertSame([1, 5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([2, 3, 4], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_not_between(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->whereNotBetween('score', [10, 30])->restore();
        $this->assertSame([1, 5], $this->ids(ScenarioPost::query()));
        $this->assertSame([2, 3, 4], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_update_bound_raw(): void
    {
        $affected = query('scenario_posts')->whereRaw('score >= ? AND status = ?', [10, 'published'])->update(['title' => 'Changed']);
        $this->assertSame(3, $affected);
        $this->assertSame([2, 3, 5], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Alpha', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Delta', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_bound_raw(): void
    {
        $this->assertSame(3, query('scenario_posts')->whereRaw('score >= ? AND status = ?', [10, 'published'])->delete());
        $this->assertSame([1, 4], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_bound_raw(): void
    {
        ScenarioPost::whereRaw('score >= ? AND status = ?', [10, 'published'])->delete();
        $this->assertSame([2, 3, 5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([1, 4], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_bound_raw(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->whereRaw('score >= ? AND status = ?', [10, 'published'])->restore();
        $this->assertSame([2, 3, 5], $this->ids(ScenarioPost::query()));
        $this->assertSame([1, 4], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_update_subquery(): void
    {
        $affected = query('scenario_posts')->whereIn('user_id', query('scenario_users')->select('id')->where('name', 'Ada'))->update(['title' => 'Changed']);
        $this->assertSame(2, $affected);
        $this->assertSame([1, 2], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Changed', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Gamma', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Delta', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Orphan', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_subquery(): void
    {
        $this->assertSame(2, query('scenario_posts')->whereIn('user_id', query('scenario_users')->select('id')->where('name', 'Ada'))->delete());
        $this->assertSame([3, 4, 5], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_subquery(): void
    {
        ScenarioPost::whereIn('user_id', query('scenario_users')->select('id')->where('name', 'Ada'))->delete();
        $this->assertSame([1, 2], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([3, 4, 5], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_subquery(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->whereIn('user_id', query('scenario_users')->select('id')->where('name', 'Ada'))->restore();
        $this->assertSame([1, 2], $this->ids(ScenarioPost::query()));
        $this->assertSame([3, 4, 5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_update_date(): void
    {
        $affected = query('scenario_posts')->whereDate('published_at', '2026-02-20')->update(['title' => 'Changed']);
        $this->assertSame(1, $affected);
        $this->assertSame([3], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Alpha', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Changed', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Delta', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Orphan', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_date(): void
    {
        $this->assertSame(1, query('scenario_posts')->whereDate('published_at', '2026-02-20')->delete());
        $this->assertSame([1, 2, 4, 5], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_date(): void
    {
        ScenarioPost::whereDate('published_at', '2026-02-20')->delete();
        $this->assertSame([3], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([1, 2, 4, 5], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_date(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->whereDate('published_at', '2026-02-20')->restore();
        $this->assertSame([3], $this->ids(ScenarioPost::query()));
        $this->assertSame([1, 2, 4, 5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_update_column_comparison(): void
    {
        $affected = query('scenario_posts')->whereColumn('id', '=', 'user_id')->update(['title' => 'Changed']);
        $this->assertSame(1, $affected);
        $this->assertSame([1], $this->ids(query('scenario_posts')->where('title', 'Changed')));
        $this->assertSame(5, query('scenario_posts')->count());
        $this->assertSame('Changed', ScenarioPost::findOrFail(1)->title);
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Gamma', ScenarioPost::findOrFail(3)->title);
        $this->assertSame('Delta', ScenarioPost::findOrFail(4)->title);
        $this->assertSame('Orphan', ScenarioPost::findOrFail(5)->title);
    }

    public function test_delete_column_comparison(): void
    {
        $this->assertSame(1, query('scenario_posts')->whereColumn('id', '=', 'user_id')->delete());
        $this->assertSame([2, 3, 4, 5], $this->ids(query('scenario_posts')));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_soft_delete_column_comparison(): void
    {
        ScenarioPost::whereColumn('id', '=', 'user_id')->delete();
        $this->assertSame([1], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame([2, 3, 4, 5], $this->ids(ScenarioPost::query()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_restore_column_comparison(): void
    {
        ScenarioPost::whereIn('id', [1, 2, 3, 4, 5])->delete();
        ScenarioPost::onlyTrashed()->whereColumn('id', '=', 'user_id')->restore();
        $this->assertSame([1], $this->ids(ScenarioPost::query()));
        $this->assertSame([2, 3, 4, 5], $this->ids(ScenarioPost::onlyTrashed()));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

}
