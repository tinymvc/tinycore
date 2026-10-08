<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class ThroughRelationTest extends DatabaseScenarioTestCase
{
    public function test_lazy(): void
    {
        $this->assertCount(2, ScenarioUser::findOrFail(1)->comments);
    }

    public function test_empty(): void
    {
        $this->assertCount(0, ScenarioUser::findOrFail(3)->comments);
    }

    public function test_eager(): void
    {
        $users = ScenarioUser::with('comments')->orderBy('id')->all();

        $this->assertCount(2, $users[0]->comments);
        $this->assertCount(1, $users[1]->comments);
        $this->assertCount(0, $users[2]->comments);
    }

    public function test_has(): void
    {
        $this->assertSame(2, ScenarioUser::has('comments')->count());
    }

    public function test_absence(): void
    {
        $this->assertSame('Linus', ScenarioUser::doesntHave('comments')->first()->name);
    }

    public function test_count(): void
    {
        $this->assertSame(2, (int) ScenarioUser::withCount('comments')->findOrFail(1)->comments_count);
    }

    public function test_exists(): void
    {
        $this->assertSame(1, (int) ScenarioUser::withExists('comments')->findOrFail(1)->comments_exists);
    }

    public function test_where_has(): void
    {
        $this->assertSame('Grace', ScenarioUser::whereHas('comments', fn ($q) => $q->where('body', 'Third'))->first()->name);
    }

    public function test_soft_deleted_intermediate(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertCount(0, ScenarioUser::findOrFail(1)->comments);
    }

    public function test_eager_soft_deleted_intermediate(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertCount(0, ScenarioUser::with('comments')->findOrFail(1)->comments);
    }

    public function test_aggregate_soft_deleted_intermediate(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertSame(0, (int) ScenarioUser::withCount('comments')->findOrFail(1)->comments_count);
    }

    public function test_find_is_scoped(): void
    {
        $this->assertFalse(ScenarioUser::findOrFail(1)->comments()->find(3));
    }
}
