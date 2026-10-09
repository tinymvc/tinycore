<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class SoftDeleteTest extends DatabaseScenarioTestCase
{
    public function test_remove_hides_record(): void
    {
        $post = ScenarioPost::findOrFail(1);

        $this->assertTrue($post->remove());
        $this->assertFalse(ScenarioPost::find(1));
        $this->assertSame(5, ScenarioPost::withTrashed()->count());
    }

    public function test_with_trashed(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertSame('Alpha', ScenarioPost::withTrashed()->findOrFail(1)->title);
    }

    public function test_only_trashed(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertSame([1], $this->ids(ScenarioPost::onlyTrashed()));
    }

    public function test_restore(): void
    {
        ScenarioPost::whereKey(1)->delete();
        ScenarioPost::onlyTrashed()->whereKey(1)->restore();

        $this->assertSame('Alpha', ScenarioPost::findOrFail(1)->title);
    }

    public function test_force_delete(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertSame(1, ScenarioPost::onlyTrashed()->whereKey(1)->forceDelete());
        $this->assertNull(ScenarioPost::withTrashed()->find(1));
    }

    public function test_count_excludes_deleted(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertSame(4, ScenarioPost::count());
    }

    public function test_aggregate_excludes_deleted(): void
    {
        ScenarioPost::whereKey(5)->delete();

        $this->assertSame(60.0, ScenarioPost::sum('score'));
    }

    public function test_or_does_not_bypass_scope(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertSame([2], $this->ids(ScenarioPost::whereKey(2)->orWhere('id', 1)));
    }

    public function test_relation_excludes_deleted(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertCount(1, ScenarioUser::findOrFail(1)->posts);
    }

    public function test_eager_excludes_deleted(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertCount(1, ScenarioUser::with('posts')->findOrFail(1)->posts);
    }

    public function test_has_excludes_deleted(): void
    {
        ScenarioPost::where('user_id', 1)->delete();

        $this->assertSame(1, ScenarioUser::has('posts')->count());
    }

    public function test_count_projection_excludes_deleted(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertSame(1, (int) ScenarioUser::withCount('posts')->findOrFail(1)->posts_count);
    }

    public function test_exists_projection_excludes_deleted(): void
    {
        ScenarioPost::where('user_id', 1)->delete();

        $this->assertSame(0, (int) ScenarioUser::withExists('posts')->findOrFail(1)->posts_exists);
    }

    public function test_relation_delete_is_scoped(): void
    {
        ScenarioUser::findOrFail(1)->posts()->delete();

        $this->assertSame(3, ScenarioPost::count());
        $this->assertSame(2, ScenarioPost::onlyTrashed()->count());
    }

    public function test_without_trashed_resets_scope(): void
    {
        ScenarioPost::whereKey(1)->delete();

        $this->assertSame(4, ScenarioPost::withTrashed()->withoutTrashed()->count());
    }
}
