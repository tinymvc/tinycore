<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class ArithmeticWriteTest extends DatabaseScenarioTestCase
{
    public function test_increment_empty_in(): void
    {
        $this->assertSame(false, ScenarioPost::whereIn('id', [])->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_empty_in(): void
    {
        $this->assertSame(false, ScenarioPost::whereIn('id', [])->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_increment_sparse_in(): void
    {
        $this->assertSame(true, ScenarioPost::whereIn('id', [3 => 2, 9 => 4])->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(13, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(33, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_sparse_in(): void
    {
        $this->assertSame(true, ScenarioPost::whereIn('id', [3 => 2, 9 => 4])->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(7, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(27, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_increment_not_in(): void
    {
        $this->assertSame(true, ScenarioPost::whereNotIn('id', [2, 4])->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(3, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(23, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(43, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_not_in(): void
    {
        $this->assertSame(true, ScenarioPost::whereNotIn('id', [2, 4])->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(-3, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(17, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(37, ScenarioPost::findOrFail(5)->score);
    }

    public function test_increment_null_foreign_key(): void
    {
        $this->assertSame(true, ScenarioPost::whereNull('user_id')->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(43, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_null_foreign_key(): void
    {
        $this->assertSame(true, ScenarioPost::whereNull('user_id')->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(37, ScenarioPost::findOrFail(5)->score);
    }

    public function test_increment_not_null_foreign_key(): void
    {
        $this->assertSame(true, ScenarioPost::whereNotNull('user_id')->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(3, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(13, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(23, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(33, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_not_null_foreign_key(): void
    {
        $this->assertSame(true, ScenarioPost::whereNotNull('user_id')->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(-3, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(7, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(17, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(27, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_increment_grouped_or(): void
    {
        $this->assertSame(true, ScenarioPost::where(fn($q) => $q->where('score', 0)->orWhere('score', 20))->where('user_id', 2)->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(23, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_grouped_or(): void
    {
        $this->assertSame(true, ScenarioPost::where(fn($q) => $q->where('score', 0)->orWhere('score', 20))->where('user_id', 2)->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(17, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_increment_between(): void
    {
        $this->assertSame(true, ScenarioPost::whereBetween('score', [10, 30])->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(13, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(23, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(33, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_between(): void
    {
        $this->assertSame(true, ScenarioPost::whereBetween('score', [10, 30])->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(7, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(17, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(27, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_increment_not_between(): void
    {
        $this->assertSame(true, ScenarioPost::whereNotBetween('score', [10, 30])->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(3, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(43, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_not_between(): void
    {
        $this->assertSame(true, ScenarioPost::whereNotBetween('score', [10, 30])->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(-3, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(37, ScenarioPost::findOrFail(5)->score);
    }

    public function test_increment_bound_raw(): void
    {
        $this->assertSame(true, ScenarioPost::whereRaw('score >= ? AND status = ?', [10, 'published'])->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(13, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(23, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(43, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_bound_raw(): void
    {
        $this->assertSame(true, ScenarioPost::whereRaw('score >= ? AND status = ?', [10, 'published'])->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(7, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(17, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(37, ScenarioPost::findOrFail(5)->score);
    }

    public function test_increment_subquery(): void
    {
        $this->assertSame(true, ScenarioPost::whereIn('user_id', query('scenario_users')->select('id')->where('name', 'Ada'))->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(3, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(13, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_subquery(): void
    {
        $this->assertSame(true, ScenarioPost::whereIn('user_id', query('scenario_users')->select('id')->where('name', 'Ada'))->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(-3, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(7, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_increment_date(): void
    {
        $this->assertSame(true, ScenarioPost::whereDate('published_at', '2026-02-20')->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(23, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_date(): void
    {
        $this->assertSame(true, ScenarioPost::whereDate('published_at', '2026-02-20')->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(0, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(17, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_increment_column_comparison(): void
    {
        $this->assertSame(true, ScenarioPost::whereColumn('id', '=', 'user_id')->increment('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(3, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_decrement_column_comparison(): void
    {
        $this->assertSame(true, ScenarioPost::whereColumn('id', '=', 'user_id')->decrement('score', 3));
        $this->assertSame(5, ScenarioPost::count());
        $this->assertSame(-3, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
        $this->assertSame(30, ScenarioPost::findOrFail(4)->score);
        $this->assertSame(40, ScenarioPost::findOrFail(5)->score);
    }

    public function test_positional_update_multiple_set_values(): void
    {
        $this->assertSame(1, query('scenario_posts')->whereRaw('id = ? AND title = ?', [2, 'Beta'])->update(['title' => 'Edited', 'score' => 77, 'published_at' => null]));
        $post = ScenarioPost::findOrFail(2);
        $this->assertSame('Edited', $post->title);
        $this->assertSame(77, $post->score);
        $this->assertNull($post->published_at);
        $this->assertSame('Alpha', ScenarioPost::findOrFail(1)->title);
    }

    public function test_positional_update_repeated_raw_clauses(): void
    {
        $this->assertSame(1, query('scenario_posts')->whereRaw('score >= ?', [10])->whereRaw('title = ?', ['Beta'])->update(['title' => 'Edited']));
        $this->assertSame('Edited', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Gamma', ScenarioPost::findOrFail(3)->title);
    }

    public function test_named_update_keeps_set_and_where_values_distinct(): void
    {
        $this->assertSame(1, query('scenario_posts')->whereRaw('title = :title', ['title' => 'Beta'])->update(['title' => 'Edited']));
        $this->assertSame('Edited', ScenarioPost::findOrFail(2)->title);
        $this->assertSame('Alpha', ScenarioPost::findOrFail(1)->title);
    }

    public function test_mixed_bindings_rejected_for_update(): void
    {
        $query = ScenarioPost::whereRaw('score >= ?', [10])->whereRaw('title = :title', ['title' => 'Beta']);
        $this->assertThrows(\Spark\Database\Exceptions\QueryBuilderException::class, fn() => $query->update(['title' => 'wrong']));
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
    }

    public function test_mixed_bindings_rejected_for_increment(): void
    {
        $query = ScenarioPost::whereRaw('score >= ?', [10])->whereRaw('title = :title', ['title' => 'Beta']);
        $this->assertThrows(\Spark\Database\Exceptions\QueryBuilderException::class, fn() => $query->increment('score'));
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
    }

    public function test_mixed_bindings_rejected_for_decrement(): void
    {
        $query = ScenarioPost::whereRaw('score >= ?', [10])->whereRaw('title = :title', ['title' => 'Beta']);
        $this->assertThrows(\Spark\Database\Exceptions\QueryBuilderException::class, fn() => $query->decrement('score'));
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
    }

    public function test_mixed_bindings_rejected_for_delete(): void
    {
        $query = ScenarioPost::whereRaw('score >= ?', [10])->whereRaw('title = :title', ['title' => 'Beta']);
        $this->assertThrows(\Spark\Database\Exceptions\QueryBuilderException::class, fn() => $query->delete());
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
        $this->assertSame(10, ScenarioPost::findOrFail(2)->score);
    }

}
