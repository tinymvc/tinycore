<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Http\Validator;

final class ValidatorStateTest extends FrameworkTestCase
{
    public function test_reuse_clears_previous_errors_and_validated_values(): void
    {
        $v = Validator::make();
        $this->assertFalse($v->validate(['name' => 'required'], []));
        $this->assertTrue($v->fails());
        $this->assertSame(['name' => 'Ada'], $v->validate(['name' => 'required'], ['name' => 'Ada', 'admin' => true])->toArray());
        $this->assertSame([], $v->errors());
        $this->assertFalse($v->fails());
        $this->assertFalse($v->validate(['email' => 'required|email'], ['email' => 'invalid']));
        $this->assertThrows(RuntimeException::class, fn () => $v->validated('name'));
    }

    public function test_confirmation_requires_matching_confirmation(): void
    {
        $v = Validator::make();
        $rules = ['password' => 'required|string|confirmed'];
        $this->assertFalse($v->validate($rules, ['password' => 'secret']));
        $this->assertFalse($v->validate($rules, ['password' => 'secret', 'password_confirmation' => 'wrong']));
        $this->assertSame(['password' => 'secret'], $v->validate($rules, ['password' => 'secret', 'password_confirmation' => 'secret'])->toArray());
    }

    public function test_nested_wildcard_reports_exact_failing_index(): void
    {
        $v = Validator::make();
        $this->assertFalse($v->validate(['items.*.email' => 'required|email'], ['items' => [['email' => 'ada@example.test'], ['email' => 'wrong']]]));
        $this->assertArrayHasKey('items.1.email', $v->errors());
        $this->assertArrayNotHasKey('items.0.email', $v->errors());
    }
}
