<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class InputConversionTest extends FrameworkTestCase
{
    public function test_boolean_true_word(): void
    {
        $input = new \Spark\Http\Input(['value' => 'true']);
        $this->assertSame(true, $input->boolean('value'));
        $this->assertSame('true', $input->get('value'));
    }

    public function test_boolean_false_word(): void
    {
        $input = new \Spark\Http\Input(['value' => 'false']);
        $this->assertSame(false, $input->boolean('value'));
        $this->assertSame('false', $input->get('value'));
    }

    public function test_boolean_yes(): void
    {
        $input = new \Spark\Http\Input(['value' => 'yes']);
        $this->assertSame(true, $input->boolean('value'));
        $this->assertSame('yes', $input->get('value'));
    }

    public function test_boolean_no(): void
    {
        $input = new \Spark\Http\Input(['value' => 'no']);
        $this->assertSame(false, $input->boolean('value'));
        $this->assertSame('no', $input->get('value'));
    }

    public function test_boolean_on(): void
    {
        $input = new \Spark\Http\Input(['value' => 'on']);
        $this->assertSame(true, $input->boolean('value'));
        $this->assertSame('on', $input->get('value'));
    }

    public function test_boolean_off(): void
    {
        $input = new \Spark\Http\Input(['value' => 'off']);
        $this->assertSame(false, $input->boolean('value'));
        $this->assertSame('off', $input->get('value'));
    }

    public function test_boolean_one(): void
    {
        $input = new \Spark\Http\Input(['value' => '1']);
        $this->assertSame(true, $input->boolean('value'));
        $this->assertSame('1', $input->get('value'));
    }

    public function test_boolean_zero(): void
    {
        $input = new \Spark\Http\Input(['value' => '0']);
        $this->assertSame(false, $input->boolean('value'));
        $this->assertSame('0', $input->get('value'));
    }

    public function test_boolean_invalid(): void
    {
        $input = new \Spark\Http\Input(['value' => 'maybe']);
        $this->assertSame(null, $input->boolean('value'));
        $this->assertSame('maybe', $input->get('value'));
    }

    public function test_email_spaces(): void
    {
        $input = new \Spark\Http\Input(['value' => ' ada @example.test ']);
        $this->assertSame('ada@example.test', $input->email('value'));
        $this->assertSame(' ada @example.test ', $input->get('value'));
    }

    public function test_email_empty(): void
    {
        $input = new \Spark\Http\Input(['value' => '']);
        $this->assertSame(null, $input->email('value'));
        $this->assertSame('', $input->get('value'));
    }

    public function test_text_markup(): void
    {
        $input = new \Spark\Http\Input(['value' => '<b>Ada</b>']);
        $this->assertSame('Ada', $input->text('value'));
        $this->assertSame('<b>Ada</b>', $input->get('value'));
    }

    public function test_text_null(): void
    {
        $input = new \Spark\Http\Input(['value' => null]);
        $this->assertSame(null, $input->text('value'));
        $this->assertSame(null, $input->get('value'));
    }

    public function test_string_integer(): void
    {
        $input = new \Spark\Http\Input(['value' => 42]);
        $this->assertSame('42', $input->string('value'));
        $this->assertSame(42, $input->get('value'));
    }

    public function test_string_array(): void
    {
        $input = new \Spark\Http\Input(['value' => ['a' => 1]]);
        $this->assertSame('{"a":1}', $input->string('value'));
        $this->assertSame(['a' => 1], $input->get('value'));
    }

    public function test_string_null(): void
    {
        $input = new \Spark\Http\Input(['value' => null]);
        $this->assertSame(null, $input->string('value'));
        $this->assertSame(null, $input->get('value'));
    }

    public function test_number_negative(): void
    {
        $input = new \Spark\Http\Input(['value' => '-12']);
        $this->assertSame(-12, $input->number('value'));
        $this->assertSame('-12', $input->get('value'));
    }

    public function test_number_decorated(): void
    {
        $input = new \Spark\Http\Input(['value' => '$123']);
        $this->assertSame(123, $input->number('value'));
        $this->assertSame('$123', $input->get('value'));
    }

    public function test_number_letters(): void
    {
        $input = new \Spark\Http\Input(['value' => 'abc']);
        $this->assertSame(null, $input->number('value'));
        $this->assertSame('abc', $input->get('value'));
    }

    public function test_number_empty(): void
    {
        $input = new \Spark\Http\Input(['value' => '']);
        $this->assertSame(null, $input->number('value'));
        $this->assertSame('', $input->get('value'));
    }

    public function test_float_fraction(): void
    {
        $input = new \Spark\Http\Input(['value' => '-12.5']);
        $this->assertSame(-12.5, $input->float('value'));
        $this->assertSame('-12.5', $input->get('value'));
    }

    public function test_float_exponent(): void
    {
        $input = new \Spark\Http\Input(['value' => '1e3']);
        $this->assertSame(1000.0, $input->float('value'));
        $this->assertSame('1e3', $input->get('value'));
    }

    public function test_float_empty(): void
    {
        $input = new \Spark\Http\Input(['value' => '']);
        $this->assertSame(null, $input->float('value'));
        $this->assertSame('', $input->get('value'));
    }

    public function test_ip_v4(): void
    {
        $input = new \Spark\Http\Input(['value' => '10.0.0.1']);
        $this->assertSame('10.0.0.1', $input->ip('value'));
        $this->assertSame('10.0.0.1', $input->get('value'));
    }

    public function test_ip_v6(): void
    {
        $input = new \Spark\Http\Input(['value' => '::1']);
        $this->assertSame('::1', $input->ip('value'));
        $this->assertSame('::1', $input->get('value'));
    }

    public function test_ip_invalid(): void
    {
        $input = new \Spark\Http\Input(['value' => '999.1.1.1']);
        $this->assertSame(null, $input->ip('value'));
        $this->assertSame('999.1.1.1', $input->get('value'));
    }

    public function test_alpha_unicode(): void
    {
        $input = new \Spark\Http\Input(['value' => 'বাংলা123!']);
        $this->assertSame('বাংলা', $input->alpha('value'));
        $this->assertSame('বাংলা123!', $input->get('value'));
    }

    public function test_alpha_punctuation(): void
    {
        $input = new \Spark\Http\Input(['value' => 'a-b_c']);
        $this->assertSame('abc', $input->alpha('value'));
        $this->assertSame('a-b_c', $input->get('value'));
    }

    public function test_alpha_digits(): void
    {
        $input = new \Spark\Http\Input(['value' => '123']);
        $this->assertSame(null, $input->alpha('value'));
        $this->assertSame('123', $input->get('value'));
    }

    public function test_alphaNum_punctuation(): void
    {
        $input = new \Spark\Http\Input(['value' => 'ab-12!']);
        $this->assertSame('ab12', $input->alphaNum('value'));
        $this->assertSame('ab-12!', $input->get('value'));
    }

    public function test_alphaNum_unicode(): void
    {
        $input = new \Spark\Http\Input(['value' => 'é2!']);
        $this->assertSame('é2', $input->alphaNum('value'));
        $this->assertSame('é2!', $input->get('value'));
    }

    public function test_alphaDash_preserve(): void
    {
        $input = new \Spark\Http\Input(['value' => 'ab-12_!']);
        $this->assertSame('ab-12_', $input->alphaDash('value'));
        $this->assertSame('ab-12_!', $input->get('value'));
    }

    public function test_alphaDash_space(): void
    {
        $input = new \Spark\Http\Input(['value' => 'a b']);
        $this->assertSame('ab', $input->alphaDash('value'));
        $this->assertSame('a b', $input->get('value'));
    }

    public function test_digits_formatted(): void
    {
        $input = new \Spark\Http\Input(['value' => '(012) 345']);
        $this->assertSame('012345', $input->digits('value'));
        $this->assertSame('(012) 345', $input->get('value'));
    }

    public function test_digits_letters(): void
    {
        $input = new \Spark\Http\Input(['value' => 'abc']);
        $this->assertSame(null, $input->digits('value'));
        $this->assertSame('abc', $input->get('value'));
    }

    public function test_phone_international(): void
    {
        $input = new \Spark\Http\Input(['value' => '+880 (123) 456']);
        $this->assertSame('+880123456', $input->phone('value'));
        $this->assertSame('+880 (123) 456', $input->get('value'));
    }

    public function test_phone_empty(): void
    {
        $input = new \Spark\Http\Input(['value' => '']);
        $this->assertSame(null, $input->phone('value'));
        $this->assertSame('', $input->get('value'));
    }

    public function test_date_valid(): void
    {
        $input = new \Spark\Http\Input(['value' => '2024-02-29']);
        $this->assertSame('2024-02-29', $input->date('value'));
        $this->assertSame('2024-02-29', $input->get('value'));
    }

    public function test_date_invalid(): void
    {
        $input = new \Spark\Http\Input(['value' => 'not-a-date']);
        $this->assertSame(null, $input->date('value'));
        $this->assertSame('not-a-date', $input->get('value'));
    }

    public function test_json_object(): void
    {
        $input = new \Spark\Http\Input(['value' => '{"a":1}']);
        $this->assertSame('{"a":1}', $input->json('value'));
        $this->assertSame('{"a":1}', $input->get('value'));
    }

    public function test_json_invalid(): void
    {
        $input = new \Spark\Http\Input(['value' => '{']);
        $this->assertSame(null, $input->json('value'));
        $this->assertSame('{', $input->get('value'));
    }

    public function test_json_empty(): void
    {
        $input = new \Spark\Http\Input(['value' => '']);
        $this->assertSame(null, $input->json('value'));
        $this->assertSame('', $input->get('value'));
    }

    public function test_password_trim(): void
    {
        $input = new \Spark\Http\Input(['value' => ' secret ']);
        $this->assertSame('secret', $input->password('value'));
        $this->assertSame(' secret ', $input->get('value'));
    }

    public function test_password_null(): void
    {
        $input = new \Spark\Http\Input(['value' => null]);
        $this->assertSame(null, $input->password('value'));
        $this->assertSame(null, $input->get('value'));
    }

    public function test_safe_strip(): void
    {
        $input = new \Spark\Http\Input(['value' => '<p>Ada</p>']);
        $this->assertSame('Ada', $input->safe('value'));
        $this->assertSame('<p>Ada</p>', $input->get('value'));
    }

    public function test_safe_null(): void
    {
        $input = new \Spark\Http\Input(['value' => null]);
        $this->assertSame(null, $input->safe('value'));
        $this->assertSame(null, $input->get('value'));
    }

    public function test_text_preserves_tags_when_requested(): void
    {
        $input = new \Spark\Http\Input(['value' => '<b>Ada</b>']);
        $this->assertSame('<b>Ada</b>', $input->text('value', false));
        $this->assertSame('<b>Ada</b>', $input->get('value'));
    }

    public function test_phone_can_remove_plus(): void
    {
        $input = new \Spark\Http\Input(['value' => '+880 123']);
        $this->assertSame('880123', $input->phone('value', false));
        $this->assertSame('+880 123', $input->get('value'));
    }

    public function test_alpha_ascii_removes_unicode(): void
    {
        $input = new \Spark\Http\Input(['value' => 'éAb']);
        $this->assertSame('Ab', $input->alpha('value', true));
        $this->assertSame('éAb', $input->get('value'));
    }

    public function test_json_decoded_object(): void
    {
        $input = new \Spark\Http\Input(['value' => '{"a":1}']);
        $this->assertSame(['a' => 1], $input->json('value', true));
        $this->assertSame('{"a":1}', $input->get('value'));
    }

    public function test_json_decoded_list(): void
    {
        $input = new \Spark\Http\Input(['value' => '[1,2]']);
        $this->assertSame([1, 2], $input->json('value', true));
        $this->assertSame('[1,2]', $input->get('value'));
    }

    public function test_only_is_immutable(): void
    {
        $input = new \Spark\Http\Input(['a' => 1, 'b' => 2]);
        $this->assertSame(['a' => 1], $input->only('a')->toArray());
        $this->assertCount(2, $input->all());
    }

    public function test_except_is_immutable(): void
    {
        $input = new \Spark\Http\Input(['a' => 1, 'b' => 2]);
        $this->assertSame(['b' => 2], $input->except('a')->toArray());
        $this->assertCount(2, $input->all());
    }

    public function test_copy_mutation_is_isolated(): void
    {
        $input = new \Spark\Http\Input(['a' => 1]);
        $copy = $input->copy();
        $copy->set('a', 2);
        $this->assertSame(1, $input->get('a'));
        $this->assertSame(2, $copy->get('a'));
    }

    public function test_array_access_unset_preserves_neighbor(): void
    {
        $input = new \Spark\Http\Input(['a' => 0, 'b' => false]);
        $this->assertTrue(isset($input['a']));
        unset($input['a']);
        $this->assertFalse(isset($input['a']));
        $this->assertSame(['b' => false], $input->toArray());
    }

    public function test_array_preserves_zero_and_keys(): void
    {
        $input = new \Spark\Http\Input(['a' => [0, '', null, '0', 3]]);
        $this->assertSame([0 => 0, 3 => '0', 4 => 3], $input->array('a'));
        $this->assertCount(5, $input->get('a'));
    }

    public function test_array_callback_maps_without_mutation(): void
    {
        $input = new \Spark\Http\Input(['a' => [1, 2]]);
        $this->assertSame([2, 4], $input->array('a', fn($n) => $n * 2));
        $this->assertSame([1, 2], $input->get('a'));
    }

    public function test_has_any_and_all_distinguish_missing(): void
    {
        $input = new \Spark\Http\Input(['zero' => 0, 'false' => false]);
        $this->assertTrue($input->hasAll(['zero', 'false']));
        $this->assertFalse($input->hasAll(['zero', 'missing']));
        $this->assertTrue($input->hasAny(['missing', 'false']));
        $this->assertFalse($input->hasAny([]));
    }

}
