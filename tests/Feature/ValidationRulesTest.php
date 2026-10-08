<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class ValidationRulesTest extends FrameworkTestCase
{
    public function test_required_missing(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'required'],
            [],
        );

        $this->assertSame(false, $result);
    }

    public function test_required_null(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'required'],
            ['value' => null],
        );

        $this->assertSame(false, $result);
    }

    public function test_required_empty(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'required'],
            ['value' => ''],
        );

        $this->assertSame(false, $result);
    }

    public function test_required_zero(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'required'],
            ['value' => 0],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_required_false(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'required'],
            ['value' => false],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_sometimes_missing(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'sometimes|required|string'],
            [],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_sometimes_null(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'sometimes|required|string'],
            ['value' => null],
        );

        $this->assertSame(false, $result);
    }

    public function test_sometimes_present(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'sometimes|required|string'],
            ['value' => 'yes'],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_nullable_null(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'nullable|string'],
            ['value' => null],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_nullable_invalid(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'nullable|string'],
            ['value' => []],
        );

        $this->assertSame(false, $result);
    }

    public function test_nullable_required(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'nullable|required'],
            ['value' => null],
        );

        $this->assertSame(false, $result);
    }

    public function test_integer_decimal(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'integer'],
            ['value' => '1.5'],
        );

        $this->assertSame(false, $result);
    }

    public function test_integer_exponent(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'integer'],
            ['value' => '1e3'],
        );

        $this->assertSame(false, $result);
    }

    public function test_integer_zero(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'integer'],
            ['value' => 0],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_integer_negative(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'integer'],
            ['value' => '-12'],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_integer_boolean(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'integer'],
            ['value' => true],
        );

        $this->assertSame(false, $result);
    }

    public function test_numeric_decimal(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'numeric'],
            ['value' => '1.5'],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_numeric_invalid(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'numeric'],
            ['value' => 'abc'],
        );

        $this->assertSame(false, $result);
    }

    public function test_string_min_boundary(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'string|min:3'],
            ['value' => 'abc'],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_string_min_short(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'string|min:3'],
            ['value' => 'ab'],
        );

        $this->assertSame(false, $result);
    }

    public function test_string_max_boundary(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'string|max:3'],
            ['value' => 'abc'],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_string_max_long(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'string|max:3'],
            ['value' => 'abcd'],
        );

        $this->assertSame(false, $result);
    }

    public function test_numeric_min(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'numeric|min:3'],
            ['value' => 2],
        );

        $this->assertSame(false, $result);
    }

    public function test_numeric_max(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'numeric|max:3'],
            ['value' => 4],
        );

        $this->assertSame(false, $result);
    }

    public function test_array_min(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'array|min:2'],
            ['value' => [1]],
        );

        $this->assertSame(false, $result);
    }

    public function test_array_max(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'array|max:2'],
            ['value' => [1, 2, 3]],
        );

        $this->assertSame(false, $result);
    }

    public function test_ascii_alpha_dash(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'alpha_dash:ascii'],
            ['value' => 'abc_12-Z'],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_ascii_rejects_unicode(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'alpha_dash:ascii'],
            ['value' => 'café'],
        );

        $this->assertSame(false, $result);
    }

    public function test_unicode_alpha_dash(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'alpha_dash'],
            ['value' => 'café'],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_email_valid(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'email'],
            ['value' => 'ada@example.test'],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_email_invalid(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['value' => 'email'],
            ['value' => 'invalid'],
        );

        $this->assertSame(false, $result);
    }

    public function test_nested_boolean(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['settings.value' => 'required|boolean'],
            ['settings' => ['value' => false]],
        );

        $this->assertNotSame(false, $result);
    }

    public function test_nested_missing(): void
    {
        $result = \Spark\Http\Validator::make()->validate(
            ['settings.value' => 'required'],
            ['settings' => []],
        );

        $this->assertSame(false, $result);
    }
}
