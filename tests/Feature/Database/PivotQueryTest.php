<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class PivotQueryTest extends DatabaseScenarioTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Spark\Database\Schema\Schema::create('scenario_roles', function ($table) {
            $table->id();
            $table->string('name');
        });

        \Spark\Database\Schema\Schema::create('scenario_role_user', function ($table) {
            $table->integer('user_id');
            $table->integer('role_id');
            $table->integer('level')->default(0);
            $table->unique(['user_id', 'role_id']);
        });

        ScenarioRole::create(['name' => 'Admin']);
        ScenarioRole::create(['name' => 'Editor']);
    }

    private function roles(): \Spark\Database\Relation\BelongsToMany
    {
        return ScenarioUser::findOrFail(1)->roles();
    }

    public function test_attach_one(): void
    {
        $this->roles()->attach(1);

        $this->assertSame(['Admin'], $this->roles()->pluck('name'));
    }

    public function test_attach_many(): void
    {
        $this->roles()->attach([1, 2]);

        $this->assertSame(2, $this->roles()->count());
    }

    public function test_attach_attributes(): void
    {
        $this->roles()->attach(1, ['level' => 7]);

        $this->assertSame(7, (int) query('scenario_role_user')->where('user_id', 1)->value('level'));
    }

    public function test_attach_per_id_attributes(): void
    {
        $this->roles()->attach([1 => ['level' => 7], 2 => ['level' => 9]]);

        $this->assertSame(9, (int) query('scenario_role_user')->where('role_id', 2)->value('level'));
    }

    public function test_detach_one(): void
    {
        $this->roles()->attach([1, 2]);

        $this->assertSame(1, $this->roles()->detach(1));
        $this->assertSame(['Editor'], $this->roles()->pluck('name'));
    }

    public function test_detach_all(): void
    {
        $this->roles()->attach([1, 2]);

        $this->assertSame(2, $this->roles()->detach());
        $this->assertSame(0, $this->roles()->count());
    }

    public function test_detach_parent_isolation(): void
    {
        $this->roles()->attach(1);
        ScenarioUser::findOrFail(2)->roles()->attach(1);
        $this->roles()->detach();

        $this->assertSame(1, ScenarioUser::findOrFail(2)->roles()->count());
    }

    public function test_sync_replaces(): void
    {
        $this->roles()->attach(1);
        $changes = $this->roles()->sync([2]);

        $this->assertSame(['attached' => [2], 'detached' => [1]], $changes);
        $this->assertSame(['Editor'], $this->roles()->pluck('name'));
    }

    public function test_sync_empty(): void
    {
        $this->roles()->attach([1, 2]);
        $this->roles()->sync([]);

        $this->assertSame(0, $this->roles()->count());
    }

    public function test_sync_without_detaching(): void
    {
        $this->roles()->attach(1);
        $this->roles()->syncWithoutDetaching([2]);

        $this->assertSame(2, $this->roles()->count());
    }

    public function test_sync_idempotent(): void
    {
        $this->roles()->attach(1);
        $this->roles()->sync([1]);
        $this->roles()->sync([1]);

        $this->assertSame(1, $this->roles()->count());
    }

    public function test_update_pivot(): void
    {
        $this->roles()->attach(1);
        $this->roles()->updateExistingPivot(1, ['level' => 9]);

        $this->assertSame(9, (int) query('scenario_role_user')->value('level'));
    }

    public function test_update_pivot_parent_isolation(): void
    {
        $this->roles()->attach(1);
        ScenarioUser::findOrFail(2)->roles()->attach(1);
        $this->roles()->updateExistingPivot(1, ['level' => 9]);

        $this->assertSame(0, (int) query('scenario_role_user')->where('user_id', 2)->value('level'));
    }

    public function test_where_pivot(): void
    {
        $this->roles()->attach([1 => ['level' => 7], 2 => ['level' => 9]]);

        $this->assertSame(['Editor'], $this->roles()->wherePivot('level', 9)->pluck('name'));
    }

    public function test_with_pivot(): void
    {
        $this->roles()->attach(1, ['level' => 7]);

        $this->assertSame(7, (int) $this->roles()->withPivot('level')->first()->pivot['level']);
    }

    public function test_eager_pivot(): void
    {
        $this->roles()->attach([1, 2]);

        $this->assertCount(2, ScenarioUser::with('roles')->findOrFail(1)->roles);
    }

    public function test_has_pivot(): void
    {
        $this->roles()->attach(1);

        $this->assertSame(1, ScenarioUser::has('roles')->count());
    }

    public function test_count_pivot(): void
    {
        $this->roles()->attach([1, 2]);

        $this->assertSame(2, (int) ScenarioUser::withCount('roles')->findOrFail(1)->roles_count);
    }

    public function test_exists_pivot(): void
    {
        $this->roles()->attach(1);

        $this->assertSame(1, (int) ScenarioUser::withExists('roles')->findOrFail(1)->roles_exists);
    }

    public function test_toggle(): void
    {
        $this->roles()->attach(1);
        $this->roles()->toggle([1, 2]);

        $this->assertSame(['Editor'], $this->roles()->pluck('name'));
    }
}
