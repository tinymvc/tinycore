<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class SetOperationsTest extends DatabaseScenarioTestCase
{
    public function test_union(): void
    {
        $query = query('scenario_posts')->select('title')->where('id', 1);
        $query->union(query('scenario_posts')->select('title')->where('id', 2));

        $titles = $query->pluck('title');
        sort($titles);

        $this->assertSame(['Alpha', 'Beta'], $titles);
    }

    public function test_union_removes_duplicates(): void
    {
        $query = query('scenario_posts')->select('title')->where('id', 1);
        $query->union(query('scenario_posts')->select('title')->where('id', 1));

        $this->assertCount(1, $query->all());
    }

    public function test_union_all_preserves_duplicates(): void
    {
        $query = query('scenario_posts')->select('title')->where('id', 1);
        $query->union(query('scenario_posts')->select('title')->where('id', 1), all: true);

        $this->assertCount(2, $query->all());
    }

    public function test_upsert_inserts(): void
    {
        query('scenario_users')->upsert(['id' => 10, 'name' => 'Upsert'], ['id'], ['name']);

        $this->assertSame('Upsert', ScenarioUser::findOrFail(10)->name);
    }

    public function test_upsert_updates(): void
    {
        query('scenario_users')->upsert(['id' => 1, 'name' => 'Upsert'], ['id'], ['name']);

        $this->assertSame('Upsert', ScenarioUser::findOrFail(1)->name);
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_bulk_upsert(): void
    {
        query('scenario_users')->upsert([['id' => 1, 'name' => 'Updated'], ['id' => 10, 'name' => 'New']], ['id'], ['name']);

        $this->assertSame('Updated', ScenarioUser::findOrFail(1)->name);
        $this->assertSame('New', ScenarioUser::findOrFail(10)->name);
        $this->assertSame(4, ScenarioUser::count());
    }

    public function test_ignore_duplicate(): void
    {
        query('scenario_users')->insertOrIgnore(['id' => 1, 'name' => 'Ignored']);

        $this->assertSame('Ada', ScenarioUser::findOrFail(1)->name);
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_empty_update(): void
    {
        $this->assertSame(0, query('scenario_users')->where('id', 1)->update([]));
        $this->assertSame('Ada', ScenarioUser::findOrFail(1)->name);
    }

    public function test_unguarded_update_is_refused(): void
    {
        $this->assertSame(0, query('scenario_users')->update(['name' => 'Unscoped']));
        $this->assertSame('Ada', ScenarioUser::findOrFail(1)->name);
    }

    public function test_unguarded_delete_is_refused(): void
    {
        $this->assertSame(0, query('scenario_users')->delete());
        $this->assertSame(3, ScenarioUser::count());
    }
}
