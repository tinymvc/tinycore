<?php

require_once dirname(__DIR__) . '/Support/DatabaseScenarioTestCase.php';

use Spark\Contracts\Http\RuleContract;
use Spark\Http\Validation\Rule;
use Spark\Http\Validation\Validator as CoreValidator;
use Spark\Http\Validator;

final class FluentValidationRulesTest extends DatabaseScenarioTestCase
{
    public function test_standalone_rules_and_closures_are_not_silently_ignored(): void
    {
        $this->assertTrue(Validator::make(['role' => Rule::in(['admin'])], ['role' => 'guest'])->fails());
        $this->assertTrue(Validator::make(['role' => fn($field, $value, $fail) => $fail('Invalid role')], ['role' => 'guest'])->fails());
    }

    public function test_password_letters_are_distinct_from_mixed_case_and_require_strings(): void
    {
        $letters = Rule::password(3)->letters();
        $this->assertTrue($letters->passes('password', 'abc'));
        $this->assertTrue($letters->passes('password', '中文密碼'));
        $this->assertFalse($letters->passes('password', '123'));
        $this->assertFalse(Rule::password(3)->passes('password', 1234));
        $this->assertFalse(Rule::password(3)->mixedCase()->passes('password', 'abc'));
    }

    public function test_nested_database_rules_infer_leaf_columns_and_keep_all_callbacks(): void
    {
        $this->assertTrue(Validator::make(['users.*.name' => Rule::exists('scenario_users')], ['users' => [['name' => 'Ada']]])->passes());
        foreach (['exists', 'notExists', 'unique'] as $method) {
            $rule = Rule::$method('scenario_users', 'name')
                ->where(fn($q) => $q->where('id', 999))
                ->where(fn($q) => $q->where('name', 'Ada'));
            $this->assertSame($method !== 'exists', $rule->passes('name', 'Ada'));
        }
    }

    public function test_database_rules_skip_fields_that_already_failed(): void
    {
        $calls = 0;
        $rule = Rule::exists('scenario_users')->where(function () use (&$calls) {
            $calls++; });
        $this->assertTrue(Validator::make(['name' => ['email', $rule]], ['name' => 'Ada'])->fails());
        $this->assertSame(0, $calls);
    }

    public function test_database_rule_strings_preserve_default_columns_and_string_ignore_ids(): void
    {
        $this->assertTrue(Validator::make(['name' => (string) Rule::exists('scenario_users')], ['name' => 'Ada'])->passes());
        $this->assertTrue(Validator::make(['name' => (string) Rule::unique('scenario_users')->ignore('Ada', 'name')], ['name' => 'Ada'])->passes());
        foreach (['exists', 'notExists', 'unique'] as $method) {
            $rule = Rule::$method('scenario_users')->where('id', 1)->where('id', 999);
            $this->assertSame($method !== 'exists', $rule->passes('name', 'Ada'));
            $this->assertThrows(LogicException::class, fn() => (string) $rule);
        }
    }

    public function test_prohibited_rule_accepts_unselected_uploads(): void
    {
        $this->assertTrue(Validator::make(['file' => Rule::prohibitedIf(true)], [
            'file' => ['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE],
        ])->passes());
        $this->assertTrue(Validator::make(['file' => Rule::requiredIf(true)], [
            'file' => ['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE],
        ])->fails());
    }

    public function test_container_preserves_legacy_validator_type_hints(): void
    {
        $this->assertInstanceOf(Validator::class, app(Validator::class));
        $this->assertInstanceOf(CoreValidator::class, app('validator'));
        $this->assertInstanceOf(CoreValidator::class, app(\Spark\Contracts\Http\ValidatorContract::class));
    }

    public function test_rule_in_and_not_in(): void
    {
        $rules = [
            'role' => ['required', Rule::in(['admin', 'editor', 'author'])],
            'status' => ['required', Rule::notIn(['banned', 'deleted'])],
        ];

        $v1 = Validator::make($rules, ['role' => 'editor', 'status' => 'active']);
        $this->assertTrue($v1->passes());
        $this->assertSame('editor', $v1->validated('role'));

        $v2 = Validator::make($rules, ['role' => 'guest', 'status' => 'banned']);
        $this->assertTrue($v2->fails());
        $this->assertArrayHasKey('role', $v2->errors());
        $this->assertArrayHasKey('status', $v2->errors());
    }

    public function test_rule_unique_and_ignore(): void
    {
        // 'Ada' exists in scenario_users with id 1
        $uniqueRule = Rule::unique('scenario_users', 'name');
        $this->assertFalse(Validator::make(['name' => ['required', $uniqueRule]], ['name' => 'Ada'])->passes());
        $this->assertTrue(Validator::make(['name' => ['required', $uniqueRule]], ['name' => 'NewUser'])->passes());

        // With ignore id 1
        $ignoreRule = Rule::unique('scenario_users', 'name')->ignore(1);
        $this->assertTrue(Validator::make(['name' => ['required', $ignoreRule]], ['name' => 'Ada'])->passes());

        // Ignore id 2 (Grace) should still fail for Ada
        $ignoreOther = Rule::unique('scenario_users', 'name')->ignore(2);
        $this->assertFalse(Validator::make(['name' => ['required', $ignoreOther]], ['name' => 'Ada'])->passes());
    }

    public function test_rule_unique_with_where_clauses(): void
    {
        // Custom query condition
        $rule = Rule::unique('scenario_users', 'name')->where('id', 999);
        // Even though Ada exists with id 1, where id = 999 yields 0 rows, so it passes
        $this->assertTrue(Validator::make(['name' => ['required', $rule]], ['name' => 'Ada'])->passes());

        // Closure where condition
        $ruleClosure = Rule::unique('scenario_users', 'name')->where(fn($q) => $q->where('id', 1));
        // Ada with id 1 exists, so count is 1, so unique fails
        $this->assertFalse(Validator::make(['name' => ['required', $ruleClosure]], ['name' => 'Ada'])->passes());
    }

    public function test_rule_exists_and_not_exists(): void
    {
        $this->assertTrue(Validator::make(['name' => ['required', Rule::exists('scenario_users', 'name')]], ['name' => 'Ada'])->passes());
        $this->assertFalse(Validator::make(['name' => ['required', Rule::exists('scenario_users', 'name')]], ['name' => 'NonExistent'])->passes());

        $this->assertTrue(Validator::make(['name' => ['required', Rule::notExists('scenario_users', 'name')]], ['name' => 'NonExistent'])->passes());
        $this->assertFalse(Validator::make(['name' => ['required', Rule::notExists('scenario_users', 'name')]], ['name' => 'Ada'])->passes());
    }

    public function test_rule_required_if_and_unless(): void
    {
        $ruleTrue = Rule::requiredIf(true);
        $this->assertFalse(Validator::make(['title' => [$ruleTrue]], ['title' => ''])->passes());
        $this->assertTrue(Validator::make(['title' => [$ruleTrue]], ['title' => 'Spark'])->passes());

        $ruleFalse = Rule::requiredIf(false);
        $this->assertTrue(Validator::make(['title' => [$ruleFalse]], ['title' => ''])->passes());

        $ruleCallable = Rule::requiredIf(fn() => true);
        $this->assertFalse(Validator::make(['title' => [$ruleCallable]], ['title' => ''])->passes());

        $unlessTrue = Rule::requiredUnless(true);
        $this->assertTrue(Validator::make(['title' => [$unlessTrue]], ['title' => ''])->passes());

        $unlessFalse = Rule::requiredUnless(false);
        $this->assertFalse(Validator::make(['title' => [$unlessFalse]], ['title' => ''])->passes());
    }

    public function test_rule_prohibited_if(): void
    {
        $rule = Rule::prohibitedIf(true);
        $this->assertFalse(Validator::make(['secret' => [$rule]], ['secret' => 'present'])->passes());
        $this->assertTrue(Validator::make(['secret' => [$rule]], ['secret' => ''])->passes());
        $this->assertTrue(Validator::make(['secret' => [$rule]], ['secret' => null])->passes());

        $ruleNotProhibited = Rule::prohibitedIf(false);
        $this->assertTrue(Validator::make(['secret' => [$ruleNotProhibited]], ['secret' => 'present'])->passes());
    }

    public function test_rule_password_fluent(): void
    {
        $rule = Rule::password(8)->mixedCase()->numbers()->symbols();

        // Too short
        $this->assertFalse(Validator::make(['password' => [$rule]], ['password' => 'Pass1!'])->passes());
        // Missing uppercase
        $this->assertFalse(Validator::make(['password' => [$rule]], ['password' => 'pass1234!'])->passes());
        // Missing lowercase
        $this->assertFalse(Validator::make(['password' => [$rule]], ['password' => 'PASS1234!'])->passes());
        // Missing number
        $this->assertFalse(Validator::make(['password' => [$rule]], ['password' => 'Password!'])->passes());
        // Missing symbol
        $this->assertFalse(Validator::make(['password' => [$rule]], ['password' => 'Password12'])->passes());

        // Valid
        $v = Validator::make(['password' => [$rule]], ['password' => 'Secret123!']);
        $this->assertTrue($v->passes());
        $this->assertSame('Secret123!', $v->validated('password'));
    }

    public function test_closure_validation_rule(): void
    {
        $rules = [
            'title' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === 'forbidden') {
                        $fail('The :attribute cannot be forbidden.');
                    }
                },
            ],
        ];

        $vFail = Validator::make($rules, ['title' => 'forbidden']);
        $this->assertTrue($vFail->fails());
        $this->assertSame('The Title cannot be forbidden.', $vFail->getFirstError());

        $vPass = Validator::make($rules, ['title' => 'allowed']);
        $this->assertTrue($vPass->passes());
    }

    public function test_custom_rule_contract_implementation(): void
    {
        $customRule = new class implements RuleContract {
            public function passes(string $attribute, mixed $value): bool
            {
                return is_string($value) && str_starts_with($value, 'SPARK-');
            }

            public function message(): string
            {
                return 'The %s must start with SPARK- prefix.';
            }
        };

        $vFail = Validator::make(['code' => ['required', $customRule]], ['code' => 'TEST-123']);
        $this->assertTrue($vFail->fails());
        $this->assertSame('The Code must start with SPARK- prefix.', $vFail->getFirstError());

        $vPass = Validator::make(['code' => ['required', $customRule]], ['code' => 'SPARK-456']);
        $this->assertTrue($vPass->passes());
        $this->assertSame('SPARK-456', $vPass->validated('code'));
    }

    public function test_core_and_facade_and_subclass_equivalence(): void
    {
        $rules = ['email' => 'required|email'];
        $data = ['email' => 'ada@example.test'];

        $core = CoreValidator::make($rules, $data);
        $sub = Validator::make($rules, $data);

        $this->assertTrue($core->passes());
        $this->assertTrue($sub->passes());
        $this->assertSame($core->validated()->toArray(), $sub->validated()->toArray());
    }
}
