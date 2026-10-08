<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Http\Session;

final class SessionApiTest extends FrameworkTestCase
{
    public function test_falsy_values_are_present_except_null(): void
    {
        Session::put(['zero' => 0, 'false' => false, 'empty' => '', 'null' => null]);
        foreach (['zero' => 0, 'false' => false, 'empty' => ''] as $key => $value) {
            $this->assertTrue(Session::has($key));
            $this->assertSame($value, Session::get($key, 'default'));
        }
        $this->assertFalse(Session::has('null'));
        $this->assertSame('default', Session::get('missing', 'default'));
    }

    public function test_pull_removes_even_null_and_preserves_other_keys(): void
    {
        Session::put(['a' => null, 'b' => 2]);
        $this->assertNull(Session::pull('a', 'default'));
        $this->assertSame('default', Session::pull('a', 'default'));
        $this->assertSame(['b' => 2], Session::all());
    }

    public function test_flash_is_consumed_once_including_null(): void
    {
        Session::flash('message', null);
        $this->assertTrue(Session::hasFlash('message'));
        $this->assertNull(Session::getFlash('message', 'default'));
        $this->assertFalse(Session::hasFlash('message'));
        $this->assertSame('default', Session::getFlash('message', 'default'));
        $this->assertArrayNotHasKey('_flash', Session::all());
    }

    public function test_clear_flash_preserves_regular_session_values(): void
    {
        Session::set('user', 7);
        Session::flash('message', 'ok');
        Session::clearFlash();
        $this->assertSame(['user' => 7], Session::all());
    }

    public function test_forget_and_destroy_clear_expected_state(): void
    {
        Session::put(['a' => 1, 'b' => 2, 'c' => 3]);
        Session::forget(['a', 'b']);
        $this->assertSame(['c' => 3], Session::all());
        Session::destroy();
        $this->assertSame([], Session::all());
    }

    public function test_invalidate_clears_data_in_application_tests(): void
    {
        Session::put(['user' => 7]);
        Session::flash('message', 'old');
        $this->assertTrue(Session::invalidate());
        $this->assertSame([], Session::all());
    }
}
