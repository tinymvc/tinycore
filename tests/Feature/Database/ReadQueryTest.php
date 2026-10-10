<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class ReadQueryTest extends DatabaseScenarioTestCase
{
    public function test_find_existing(): void
    {
        $this->assertSame('Beta', ScenarioPost::find(2)->title);
    }

    public function test_find_missing(): void
    {
        $this->assertNull(ScenarioPost::find(99));
    }

    public function test_find_or_fail_existing(): void
    {
        $this->assertSame('Beta', ScenarioPost::findOrFail(2)->title);
    }

    public function test_first(): void
    {
        $this->assertSame('Alpha', ScenarioPost::orderBy('id')->first()->title);
    }

    public function test_last(): void
    {
        $this->assertSame('Orphan', ScenarioPost::last()->title);
    }

    public function test_first_missing(): void
    {
        $this->assertNull(ScenarioPost::whereKey(99)->first());
    }

    public function test_missing_single_rows_in_each_fetch_mode(): void
    {
        $this->assertNull(query('scenario_posts')->where('id', 99)->first());
        $this->assertNull(query('scenario_posts')->where('id', 99)->fetchAssoc()->first());
        $this->assertNull(query('scenario_posts')->where('id', 99)->column('title')->first());
        $this->assertNull(ScenarioPost::whereKey(99)->last());
    }

    public function test_missing_single_rows_throw_not_found(): void
    {
        $this->assertThrows(\Spark\Exceptions\NotFoundException::class, fn() => ScenarioPost::findOrFail(99));
        $this->assertThrows(\Spark\Exceptions\NotFoundException::class, fn() => ScenarioPost::whereKey(99)->firstOrFail());
        $this->assertThrows(\Spark\Exceptions\NotFoundException::class, fn() => query('scenario_posts')->where('id', 99)->firstOrFail());
    }

    public function test_value_missing_and_nullable_columns(): void
    {
        $this->assertNull(ScenarioPost::whereKey(99)->value('title'));
        $this->assertNull(query('scenario_posts')->where('id', 99)->value('title'));
        $this->assertNull(query('scenario_posts')->where('id', 99)->fetchAssoc()->value('title'));
        $this->assertNull(ScenarioPost::whereKey(1)->value('published_at'));
        $this->assertSame(0, (int) ScenarioPost::whereKey(1)->value('score'));
    }

    public function test_falsey_existing_values_are_not_missing(): void
    {
        query('scenario_posts')->where('id', 1)->update(['title' => '']);

        $this->assertSame('', query('scenario_posts')->where('id', 1)->column('title')->firstOrFail());
        $this->assertSame(0, (int) query('scenario_posts')->where('id', 1)->column('score')->firstOrFail());
        $this->assertSame('', query('scenario_posts')->where('id', 1)->fetchAssoc()->value('title'));
    }

    public function test_count_filtered(): void
    {
        $this->assertSame(3, ScenarioPost::where('status', 'published')->count());
    }

    public function test_count_empty(): void
    {
        $this->assertSame(0, ScenarioPost::whereKey(99)->count());
    }

    public function test_exists(): void
    {
        $this->assertTrue(ScenarioPost::whereKey(1)->exists());
    }

    public function test_doesnt_exist(): void
    {
        $this->assertTrue(ScenarioPost::whereKey(99)->doesntExist());
    }

    public function test_sum(): void
    {
        $this->assertSame(100.0, ScenarioPost::sum('score'));
    }

    public function test_average(): void
    {
        $this->assertSame(20.0, ScenarioPost::avg('score'));
    }

    public function test_minimum(): void
    {
        $this->assertSame(0.0, ScenarioPost::min('score'));
    }

    public function test_maximum(): void
    {
        $this->assertSame(40.0, ScenarioPost::max('score'));
    }

    public function test_filtered_sum(): void
    {
        $this->assertSame(70.0, ScenarioPost::where('status', 'published')->sum('score'));
    }

    public function test_value(): void
    {
        $this->assertSame('Beta', ScenarioPost::whereKey(2)->value('title'));
    }

    public function test_pluck(): void
    {
        $this->assertSame(['Alpha', 'Beta'], ScenarioPost::where('user_id', 1)->orderBy('id')->pluck('title'));
    }

    public function test_keyed_pluck(): void
    {
        $this->assertSame([1 => 'Alpha', 2 => 'Beta'], ScenarioPost::where('user_id', 1)->orderBy('id')->pluck('title', 'id'));
    }

    public function test_limit(): void
    {
        $this->assertSame(['Alpha', 'Beta'], ScenarioPost::orderBy('id')->take(2)->pluck('title'));
    }

    public function test_offset(): void
    {
        $this->assertSame(['Gamma', 'Delta'], ScenarioPost::orderBy('id')->skip(2)->take(2)->pluck('title'));
    }

    public function test_descending(): void
    {
        $this->assertSame(['Orphan', 'Delta'], ScenarioPost::orderBy('score', 'DESC')->take(2)->pluck('title'));
    }

    public function test_raw_order(): void
    {
        $this->assertSame('Gamma', ScenarioPost::orderByRaw('ABS(score - 21)')->first()->title);
    }

    public function test_distinct(): void
    {
        $this->assertSame(['draft', 'published'], ScenarioPost::select('status')->distinct()->orderBy('status')->pluck('status'));
    }

    public function test_projection(): void
    {
        $row = ScenarioPost::whereKey(1)->select('id')->first();

        $this->assertSame(['id' => 1], $row->toArray());
    }

    public function test_raw_projection(): void
    {
        $this->assertSame(11, (int) ScenarioPost::whereKey(2)->selectRaw('score + 1 AS incremented')->first()->incremented);
    }

    public function test_clone_independence(): void
    {
        $query = ScenarioPost::where('status', 'published');
        $clone = clone $query;

        $this->assertSame(1, $clone->where('user_id', 1)->count());
        $this->assertSame(3, $query->count());
    }

    public function test_inner_join(): void
    {
        $this->assertSame(4, ScenarioPost::join('scenario_users', 'scenario_users.id', '=', 'scenario_posts.user_id')->count());
    }

    public function test_left_join(): void
    {
        $this->assertSame(5, ScenarioPost::leftJoin('scenario_users', 'scenario_users.id', '=', 'scenario_posts.user_id')->count());
    }

    public function test_join_filter(): void
    {
        $this->assertSame(['Alpha', 'Beta'], ScenarioPost::join('scenario_users', 'scenario_users.id', '=', 'scenario_posts.user_id')->where('scenario_users.name', 'Ada')->orderBy('scenario_posts.id')->pluck('title'));
    }

    public function test_group_count(): void
    {
        $this->assertSame(2, ScenarioPost::select('status')->groupBy('status')->count());
    }

    public function test_all_hydrates(): void
    {
        $rows = ScenarioPost::orderBy('id')->all();

        $this->assertCount(5, $rows);
        $this->assertInstanceOf(ScenarioPost::class, $rows[0]);
    }

    public function test_fetch_assoc(): void
    {
        $this->assertSame(['title' => 'Alpha'], query('scenario_posts')->select('title')->orderBy('id')->fetchAssoc()->first());
    }
}
