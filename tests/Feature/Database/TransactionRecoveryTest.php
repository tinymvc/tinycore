<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

use Spark\Database\DB;

final class TransactionRecoveryTest extends DatabaseScenarioTestCase
{
    public function test_nested_commit_is_rolled_back_with_outer_transaction(): void
    {
        $db = app(DB::class);
        $this->assertThrows(RuntimeException::class, fn () => $db->transaction(function () use ($db) {
            $db->transaction(fn () => ScenarioUser::create(['name' => 'Nested']));
            throw new RuntimeException('outer failed');
        }));
        $this->assertSame(3, ScenarioUser::count());
        $this->assertFalse($db->inTransaction());
    }

    public function test_caught_inner_failure_preserves_outer_writes(): void
    {
        $db = app(DB::class);
        $db->transaction(function ($connection) use ($db) {
            $this->assertSame($db, $connection);
            ScenarioUser::create(['name' => 'Outer']);
            $this->assertThrows(RuntimeException::class, fn () => $db->transaction(function () {
                ScenarioUser::create(['name' => 'Inner']);
                throw new RuntimeException('inner failed');
            }));
            $this->assertTrue($db->inTransaction());
            ScenarioUser::create(['name' => 'After']);
        });
        $this->assertSame(['After', 'Outer'], ScenarioUser::whereIn('name', ['Outer', 'Inner', 'After'])->orderBy('name')->pluck('name'));
    }

    public function test_constraint_failure_savepoint_can_recover_and_commit(): void
    {
        $db = app(DB::class);
        $db->transaction(function () use ($db) {
            $this->assertThrows(PDOException::class, fn () => $db->transaction(fn () => query('scenario_users')->insert(['id' => 1, 'name' => 'Duplicate'])));
            ScenarioUser::create(['name' => 'Recovered']);
        });
        $this->assertSame(4, ScenarioUser::count());
        $this->assertSame('Ada', ScenarioUser::findOrFail(1)->name);
    }

    public function test_error_rolls_back_and_connection_is_reusable(): void
    {
        $db = app(DB::class);
        $error = new Error('original');
        try {
            $db->transaction(function () use ($error) {
                ScenarioUser::whereKey(1)->update(['name' => 'Changed']);
                throw $error;
            });
            $this->fail('Expected callback error');
        } catch (Error $caught) {
            $this->assertSame($error, $caught);
        }
        $this->assertFalse($db->inTransaction());
        $this->assertSame('Ada', ScenarioUser::findOrFail(1)->name);
        $this->assertSame('ready', $db->transaction(fn () => 'ready'));
    }

    public function test_manual_outer_transaction_remains_owned_by_caller(): void
    {
        $db = app(DB::class);
        $db->beginTransaction();
        try {
            $db->transaction(fn () => ScenarioUser::create(['name' => 'Nested']));
            $this->assertTrue($db->inTransaction());
        } finally {
            $db->rollBack();
        }
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_three_levels_preserve_successful_sibling_savepoints(): void
    {
        $db = app(DB::class);
        $db->transaction(function () use ($db) {
            $db->transaction(function () use ($db) {
                $this->assertThrows(RuntimeException::class, fn () => $db->transaction(function () {
                    ScenarioUser::create(['name' => 'Discard']);
                    throw new RuntimeException();
                }));
                ScenarioUser::create(['name' => 'Keep']);
            });
        });
        $this->assertSame(0, ScenarioUser::where('name', 'Discard')->count());
        $this->assertSame(1, ScenarioUser::where('name', 'Keep')->count());
    }
}
