<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class WriteQueryTest extends DatabaseScenarioTestCase
{
    public function test_insert(): void
    {
        $id = query('scenario_users')->insert(['name' => 'New']);

        $this->assertSame('New', ScenarioUser::findOrFail($id)->name);
    }

    public function test_bulk_insert(): void
    {
        query('scenario_users')->insert([['name' => 'One'], ['name' => 'Two']]);

        $this->assertSame(5, ScenarioUser::count());
    }

    public function test_arrayable_insert(): void
    {
        query('scenario_users')->insert(new \Spark\Http\Input(['name' => 'Input']));

        $this->assertSame(1, ScenarioUser::where('name', 'Input')->count());
    }

    public function test_update_filtered(): void
    {
        $this->assertSame(2, query('scenario_posts')->where('user_id', 1)->update(['score' => 99]));
        $this->assertSame(20, ScenarioPost::findOrFail(3)->score);
    }

    public function test_update_missing(): void
    {
        $this->assertSame(0, query('scenario_posts')->where('id', 99)->update(['score' => 99]));
    }

    public function test_arrayable_update(): void
    {
        query('scenario_posts')->where('id', 1)->update(new \Spark\Http\Input(['score' => 99]));

        $this->assertSame(99, ScenarioPost::findOrFail(1)->score);
    }

    public function test_delete_filtered(): void
    {
        $this->assertSame(1, query('scenario_posts')->where('id', 1)->delete());
        $this->assertSame(4, ScenarioPost::withTrashed()->count());
    }

    public function test_delete_missing(): void
    {
        $this->assertSame(0, query('scenario_posts')->where('id', 99)->delete());
    }

    public function test_increment(): void
    {
        $this->assertTrue(ScenarioPost::whereKey(2)->increment('score', 5));
        $this->assertSame(15, ScenarioPost::findOrFail(2)->score);
    }

    public function test_decrement(): void
    {
        $this->assertTrue(ScenarioPost::whereKey(2)->decrement('score', 5));
        $this->assertSame(5, ScenarioPost::findOrFail(2)->score);
    }

    public function test_update_or_insert_existing(): void
    {
        query('scenario_users')->updateOrInsert(['name' => 'Ada'], ['name' => 'Updated']);

        $this->assertSame(3, ScenarioUser::count());
        $this->assertSame('Updated', ScenarioUser::findOrFail(1)->name);
    }

    public function test_update_or_insert_missing(): void
    {
        query('scenario_users')->updateOrInsert(['name' => 'New']);

        $this->assertSame(4, ScenarioUser::count());
    }

    public function test_bound_write_value(): void
    {
        $name = "Robert'); DROP TABLE scenario_users; --";
        $id = query('scenario_users')->insert(['name' => $name]);

        $this->assertSame($name, ScenarioUser::findOrFail($id)->name);
        $this->assertSame(4, ScenarioUser::count());
    }

    public function test_commit(): void
    {
        $db = $this->app->get(\Spark\Database\DB::class);
        $result = $db->transaction(function () {
            ScenarioUser::create(['name' => 'Committed']);

            return 42;
        });

        $this->assertSame(42, $result);
        $this->assertSame(4, ScenarioUser::count());
    }

    public function test_rollback(): void
    {
        $db = $this->app->get(\Spark\Database\DB::class);
        $this->assertThrows(\RuntimeException::class, fn () => $db->transaction(function () {
            ScenarioUser::create(['name' => 'Rolled back']);

            throw new \RuntimeException('rollback');
        }));

        $this->assertSame(3, ScenarioUser::count());
    }
}
