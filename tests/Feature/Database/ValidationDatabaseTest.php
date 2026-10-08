<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

use Spark\Http\Validator;

final class ValidationDatabaseTest extends DatabaseScenarioTestCase
{
    public function test_unique_rejects_existing_value(): void
    {
        $this->assertFalse(Validator::make()->validate(['name' => 'unique:scenario_users,name'], ['name' => 'Ada']));
    }

    public function test_unique_accepts_new_value(): void
    {
        $this->assertSame('New', Validator::make()->validate(['name' => 'unique:scenario_users,name'], ['name' => 'New'])->get('name'));
    }

    public function test_unique_can_exclude_current_primary_key(): void
    {
        $this->assertSame('Ada', Validator::make()->validate(['name' => 'unique:scenario_users,name,1,id'], ['name' => 'Ada'])->get('name'));
        $this->assertFalse(Validator::make()->validate(['name' => 'unique:scenario_users,name,2,id'], ['name' => 'Ada']));
    }

    public function test_exists_and_not_exists_have_inverse_results(): void
    {
        $v = Validator::make();
        $this->assertSame(1, $v->validate(['user' => 'exists:scenario_users,id'], ['user' => 1])->get('user'));
        $this->assertFalse($v->validate(['user' => 'exists:scenario_users,id'], ['user' => 999]));
        $this->assertFalse($v->validate(['user' => 'not_exists:scenario_users,id'], ['user' => 1]));
        $this->assertSame(999, $v->validate(['user' => 'not_exists:scenario_users,id'], ['user' => 999])->get('user'));
    }

    public function test_invalid_field_skips_database_query(): void
    {
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries) { $queries++; });
        $this->assertFalse(Validator::make()->validate(['user' => 'integer|exists:scenario_users,id'], ['user' => 'invalid']));
        $this->assertSame(0, $queries);
    }

    public function test_database_validation_binds_untrusted_values(): void
    {
        $name = "Ada' OR 1=1 --";
        $this->assertFalse(Validator::make()->validate(['name' => 'exists:scenario_users,name'], ['name' => $name]));
        $this->assertSame(3, ScenarioUser::count());
    }
}
