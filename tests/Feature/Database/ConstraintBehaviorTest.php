<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

use Spark\Database\Schema\Schema;

final class ConstraintBehaviorTest extends DatabaseScenarioTestCase
{
    private function child(string $delete = 'RESTRICT', string $update = 'CASCADE'): void
    {
        Schema::create('scenario_schema', function ($table) use ($delete, $update) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('scenario_users')->onDelete($delete)->onUpdate($update);
        });
    }

    public function test_foreign_key_rejects_missing_parent(): void
    {
        $this->child();
        $this->assertThrows(PDOException::class, fn () => query('scenario_schema')->insert(['user_id' => 999]));
        $this->assertSame(0, query('scenario_schema')->count());
    }

    public function test_nullable_foreign_key_accepts_null(): void
    {
        $this->child();
        query('scenario_schema')->insert(['user_id' => null]);
        $this->assertSame(1, query('scenario_schema')->whereNull('user_id')->count());
    }

    public function test_restricted_delete_preserves_parent_and_child(): void
    {
        $this->child();
        query('scenario_schema')->insert(['user_id' => 1]);
        $this->assertThrows(PDOException::class, fn () => query('scenario_users')->where('id', 1)->delete());
        $this->assertSame('Ada', ScenarioUser::findOrFail(1)->name);
        $this->assertSame(1, query('scenario_schema')->count());
    }

    public function test_cascade_delete_removes_only_matching_children(): void
    {
        $this->child('CASCADE');
        query('scenario_schema')->insert([['user_id' => 1], ['user_id' => 2]]);
        query('scenario_users')->where('id', 1)->delete();
        $this->assertSame([2], array_map('intval', query('scenario_schema')->pluck('user_id')));
    }

    public function test_set_null_delete_preserves_child(): void
    {
        $this->child('SET NULL');
        query('scenario_schema')->insert(['user_id' => 1]);
        query('scenario_users')->where('id', 1)->delete();
        $this->assertSame(1, query('scenario_schema')->whereNull('user_id')->count());
    }

    public function test_cascade_update_moves_foreign_key(): void
    {
        $this->child();
        query('scenario_schema')->insert(['user_id' => 1]);
        query('scenario_users')->where('id', 1)->update(['id' => 100]);
        $this->assertSame(100, (int) query('scenario_schema')->value('user_id'));
    }

    public function test_composite_unique_constraint_allows_distinct_pairs(): void
    {
        Schema::create('scenario_schema', function ($table) {
            $table->id();
            $table->integer('tenant');
            $table->string('code');
            $table->unique(['tenant', 'code']);
        });
        query('scenario_schema')->insert([['tenant' => 1, 'code' => 'A'], ['tenant' => 2, 'code' => 'A']]);
        $this->assertThrows(PDOException::class, fn () => query('scenario_schema')->insert(['tenant' => 1, 'code' => 'A']));
        $this->assertSame(2, query('scenario_schema')->count());
    }
}
