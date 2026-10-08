<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Facades\Route;
use Spark\Foundation\Http\Middlewares\CsrfProtection;

final class CsrfTest extends FrameworkTestCase
{
    private function protectRoutes(): void
    {
        $this->app->withMiddleware(register: [
            'csrf' => get_class(new class extends CsrfProtection {
                protected array $except = ['hooks/*'];
            }),
        ]);
        Route::get('/csrf', fn () => ['token' => session('csrf_token')])->middleware('csrf');
        Route::post('/csrf', fn () => ['saved' => true])->middleware('csrf');
        Route::post('/hooks/provider', fn () => ['saved' => true])->middleware('csrf');
    }

    public function test_safe_requests_create_a_token_and_valid_tokens_are_accepted(): void
    {
        $this->protectRoutes();
        $this->getJson('/csrf')->assertOk();
        $token = session('csrf_token');
        $this->assertTrue(is_string($token) && strlen($token) > 0);
        $this->postJson('/csrf', ['_token' => $token])->assertOk()->assertJsonPath('saved', true);
        $this->postJson('/csrf', [], ['X-CSRF-TOKEN' => $token])->assertOk();
        $this->postJson('/csrf', [], ['X-XSRF-TOKEN' => \Spark\Facades\Hash::encrypt($token)])->assertOk();
        $this->assertSame($token, session('csrf_token'));
    }

    public function test_missing_wrong_and_non_string_tokens_are_rejected(): void
    {
        $this->protectRoutes();

        foreach ([[], ['_token' => 'wrong'], ['_token' => ['invalid']], ['_token' => 42]] as $input) {
            $this->postJson('/csrf', $input)->assertStatus(419);
        }

        $this->postJson('/csrf', [], ['X-XSRF-TOKEN' => 'not-encrypted'])->assertStatus(419);
        $this->postJson('/hooks/provider')->assertOk();
    }
}
