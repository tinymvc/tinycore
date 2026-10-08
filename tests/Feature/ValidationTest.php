<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class ValidationTest extends FrameworkTestCase
{
    public function test_validation_and_gate(): void
    {
        $validator = \Spark\Http\Validator::make();
        $input = $validator->validate(['email' => 'required|email'], ['email' => 'ada@example.com']);
        $this->assertSame('ada@example.com', $input->get('email'));
        $this->assertFalse($validator->validate(['email' => 'required|email'], ['email' => 'invalid']));
        $gate = new \Spark\Http\Gate;
        $gate->define('edit', fn (int $owner, int $viewer) => $owner === $viewer);
        $this->assertTrue($gate->allows('edit', 3, 3));
        $this->assertFalse($gate->allows('edit', 3, 4));
    }

    public function test_validation_documented_boundaries(): void
    {
        $validator = \Spark\Http\Validator::make();
        $this->assertFalse($validator->validate(['name' => 'required'], []));
        $this->assertSame([], $validator->validate(['name' => 'filled'], [])->toArray());
        $this->assertFalse($validator->validate(['name' => 'filled'], ['name' => '']));
        $this->assertSame(['name' => null], $validator->validate(['name' => 'prohibited'], ['name' => null])->toArray());
        $input = $validator->validate(['published' => 'required|boolean'], ['published' => 'false']);
        $this->assertSame('false', $input->get('published'));
        $this->assertFalse($input->boolean('published'));
        $this->assertFalse($validator->validate(['count' => 'integer'], ['count' => '1.5']));
        // Unknown rules are currently ignored.
        $this->assertSame('value', $validator->validate(['name' => 'unknown_rule'], ['name' => 'value'])->get('name'));
        $this->assertSame('2026-09-19', $validator->validate(['date' => 'date_format:Y-m-d'], ['date' => '2026-09-19'])->get('date'));
        $this->assertSame('INV-12', $validator->validate(['code' => 'starts_with:INV-'], ['code' => 'INV-12'])->get('code'));
        $validatedRows = [];
        foreach ([['name' => 'Ada', 'admin' => true], ['name' => 'Grace']] as $row) {
            $validatedRows[] = $validator->validate(['name' => 'required|string'], $row)->toArray();
        }
        $this->assertSame([['name' => 'Ada'], ['name' => 'Grace']], $validatedRows);
    }

    public function test_optional_nested_validation_and_parameter_arrays(): void
    {
        $v = \Spark\Http\Validator::make();
        $this->assertSame([], $v->validate(['name' => 'sometimes|required|string'], [])->toArray());
        $this->assertFalse($v->validate(['name' => 'sometimes|required|string'], ['name' => null]));
        $this->assertSame(['name' => null], $v->validate(['name' => 'nullable|string'], ['name' => null])->toArray());
        $this->assertFalse($v->validate(['name' => 'string'], ['name' => null]));
        $data = $v->validate([
            'items' => 'required|array',
            'items.*.name' => 'required|string',
            'settings' => 'required|array',
            'settings.allow_share' => 'required|boolean',
        ], ['items' => [2 => ['name' => 'Ada', 'admin' => true]], 'settings' => ['allow_share' => false, 'secret' => 'excluded']]);
        $this->assertSame(['items' => [2 => ['name' => 'Ada']], 'settings' => ['allow_share' => false]], $data->toArray());
        $this->assertFalse($v->validate(['items.*.name' => 'required'], ['items' => [2 => []]]));
        $this->assertTrue(isset($v->getErrors()['items.2.name']));
        $this->assertSame('Copy,Link', $v->validate(['mode' => ['string', ['in' => ['Copy,Link', 'Native']]]], ['mode' => 'Copy,Link'])->get('mode'));
        $this->assertSame(5, $v->validate(['min' => 'numeric', 'max' => 'numeric|gt:min'], ['min' => 2, 'max' => 5])->get('max'));
        $this->assertFalse($v->validate(['min' => 'numeric', 'max' => 'numeric|gt:min'], ['min' => 5, 'max' => 2]));
        $this->assertSame('ab', $v->validate(['a' => 'string|ls:b', 'b' => 'string'], ['a' => 'ab', 'b' => 'abcd'])->get('a'));
        $request = new \Spark\Http\Request;
        $request->validate(['optional' => 'sometimes|string'], attributes: []);
        $this->assertSame([], $request->validated()->toArray());
        $this->assertSame('fallback', $request->validated('optional', 'fallback'));
    }
}
