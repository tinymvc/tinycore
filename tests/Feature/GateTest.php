<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Http\Gate;
use Spark\Exceptions\Http\AuthorizationException;

final class GateTest extends FrameworkTestCase
{
    public function test_unknown_ability_denies_and_authorization_throws(): void
    {
        $gate = new Gate();
        $this->assertFalse($gate->has('edit'));
        $this->assertTrue($gate->denies('edit'));
        $this->assertThrows(AuthorizationException::class, fn() => $gate->authorize('edit'));
    }

    public function test_arguments_and_redefinition(): void
    {
        $gate = new Gate();
        $gate->define('edit', fn(int $owner, int $viewer) => $owner === $viewer);
        $this->assertTrue($gate->allows('edit', 7, 7));
        $this->assertFalse($gate->allows('edit', 7, 8));
        $gate->define('edit', fn() => false);
        $this->assertCount(1, $gate->abilities());
        $this->assertFalse($gate->allows('edit'));
    }

    public function test_before_false_short_circuits_ability_and_after(): void
    {
        $gate = new Gate();
        $gate->before(fn() => null);
        $gate->before(fn() => false);
        $gate->define('edit', fn() => $this->fail('Ability must not execute'));
        $gate->after(fn() => $this->fail('After must not execute'));
        $this->assertFalse($gate->allows('edit'));
    }

    public function test_before_true_can_allow_undefined_ability(): void
    {
        $gate = new Gate();
        $gate->before(fn() => true);
        $this->assertTrue($gate->allows('undefined'));
        $gate->authorize('undefined');
    }

    public function test_after_observes_result_and_arguments_and_can_override(): void
    {
        $gate = new Gate(['edit' => fn(int $id) => $id === 7]);
        $observed = [];
        $gate->after(function ($ability, $result, $id) use (&$observed) {
            $observed = [$ability, $result, $id];
            return null;
        });
        $gate->after(fn() => false);
        $this->assertFalse($gate->allows('edit', 7));
        $this->assertSame(['edit', true, 7], $observed);
    }

    public function test_any_and_none_accept_generators_and_empty_sets(): void
    {
        $gate = new Gate(['yes' => fn() => true, 'no' => fn() => false]);
        $this->assertFalse($gate->any([]));
        $this->assertTrue($gate->none([]));
        $this->assertTrue($gate->any((function () {
            yield 'no';
            yield 'yes'; })()));
        $this->assertFalse($gate->none('yes'));
        $this->assertTrue($gate->none(['no', 'missing']));
    }

    public function test_any_short_circuits_after_first_allowed_ability(): void
    {
        $gate = new Gate(['yes' => fn() => true, 'fail' => fn() => $this->fail('Unexpected ability evaluation')]);
        $this->assertTrue($gate->any(['yes', 'fail']));
    }
}
