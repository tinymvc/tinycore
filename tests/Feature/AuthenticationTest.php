<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class AuthenticationTest extends FrameworkTestCase
{
    public function test_named_guard_examples(): void
    {
        $this->app->singleton(\Spark\Http\Auth::class, fn () => new \Spark\Http\Auth(
            model: CoreFixtureIdentity::class,
            config: ['channels' => ['session'], 'cookie_enabled' => false],
        ));
        \Spark\Http\Auth::register(CoreFixtureIdentity::class, [
            'channels' => ['session'],
            'cookie_enabled' => false,
            'session_key' => 'admin_id',
            'cache_name' => 'admin_auth_cache',
        ], 'admin');
        $this->assertFalse($this->app->resolved('auth.admin'));
        $adminAuth = \Spark\Http\Auth::guard('admin');
        $this->assertSame($adminAuth, auth('admin'));
        $this->assertSame($adminAuth, \Spark\Facades\Auth::guard('admin'));
        $this->assertSame(auth(), auth('default'));
        $this->assertTrue(is_guest('admin'));
        $middleware = new class extends \Spark\Foundation\Http\Middlewares\AuthMiddleware {
            protected function failed(\Spark\Http\Request $request, array $guards): mixed
            {
                return 'denied';
            }
        };
        $this->assertSame('allowed', $middleware->handle(request(), fn () => 'allowed', '!admin'));
        $this->assertSame('denied', $middleware->handle(request(), fn () => 'allowed', 'admin'));
        $this->assertSame('Guest', user('email', 'Guest', 'admin'));

        // An already-verified in-memory identity avoids unrelated database setup.
        $admin = new CoreFixtureIdentity;
        $admin->id = 7;
        $admin->email = 'admin@example.com';
        $adminAuth->login($admin);
        $this->assertSame(7, session('admin_id'));
        $this->assertSame('denied', $middleware->handle(request(), fn () => 'allowed', '!admin'));
        $this->assertSame('allowed', $middleware->handle(request(), fn () => 'allowed', 'admin'));
        $this->assertTrue(is_logged('admin'));
        $this->assertTrue(is_guest());
        $this->assertSame($admin, user(guard: 'admin'));
        $this->assertSame('admin@example.com', request()->user('email', guard: 'admin'));
        $this->assertSame($adminAuth, request()->auth('admin'));
        $this->assertTrue(request()->isAuthenticated('admin'));
        $this->assertFalse(request()->isNotAuthenticated('admin'));

        $views = $this->storagePath . '/guard-views';
        mkdir($views, 0700, true);
        config(['app.views_dir' => $views]);
        file_put_contents(
            $views . '/guards.blade.php',
            "@auth('admin')admin-in@endauth @guest('admin')admin-out@endguest @guest default-out@endguest"
        );
        $rendered = blade()->render('guards');
        $this->assertStringContainsString('admin-in', $rendered);
        $this->assertFalse(str_contains($rendered, 'admin-out'));
        $this->assertStringContainsString('default-out', $rendered);

        // Logging out one guard preserves another guard's session identity.
        $member = new CoreFixtureIdentity;
        $member->id = 9;
        auth()->login($member);
        $adminAuth->logout();
        $this->assertFalse(session()->has('admin_id'));
        $this->assertSame(9, session('user_id'));
        $this->assertTrue(auth()->check());
        $this->assertTrue(is_guest('admin'));
    }

    public function test_jwt_table_tokens_are_independent_and_use_the_payload_expiry(): void
    {
        $user = $this->tokenUser();
        $issuer = $this->tokenAuth(true);
        $issuer->login($user);
        $expiry = time() + 3600;
        $first = $issuer->createToken($user, ['iat' => time() - 1, 'exp' => $expiry]);
        $second = $issuer->createToken($user, ['iat' => time() - 1]);
        $claims = \Spark\Utils\JWT::decode($first, config('app.key'));
        $other = \Spark\Utils\JWT::decode($second, config('app.key'));
        $this->assertFalse($claims->jti === $other->jti);
        $this->assertCount(2, $issuer->tokens());
        $this->assertSame($expiry, carbon(query('docs_tokens')->where('token_hash', $claims->jti)->value('expire_at'))->getTimestamp());
        $this->bearer($first);
        $this->assertTrue($this->tokenAuth(true)->check());
        $this->assertTrue($issuer->revokeToken($claims->jti));
        $this->assertFalse($this->tokenAuth(true)->check());
        $this->bearer($second);
        $reader = $this->tokenAuth(true);
        $this->assertTrue($reader->check());
        $this->assertSame($other->jti, $reader->token());
        $reader->logout();
        $this->assertFalse($this->tokenAuth(true)->check());
    }

    public function test_stateless_jwt_requires_a_valid_user_hash_and_rejects_malformed_claims(): void
    {
        $user = $this->tokenUser();
        $issuer = $this->tokenAuth();
        $token = $issuer->makeToken($user, ['iat' => time() - 1]);
        $this->bearer($token);
        $this->assertTrue($this->tokenAuth()->check());
        $claims = (array) \Spark\Utils\JWT::decode($token, config('app.key'));
        foreach ([null, '', [], 'incorrect'] as $jti) {
            $this->bearer(\Spark\Utils\JWT::encode([...$claims, 'jti' => $jti], config('app.key')));
            $this->assertFalse($this->tokenAuth()->check());
        }
        $this->bearer($issuer->makeToken($user, ['iat' => time() - 1, 'exp' => time() - 1]));
        $this->assertFalse($this->tokenAuth()->check());
        query('docs_token_users')->where('id', $user->id)->update(['password' => bcrypt('changed')]);
        $this->bearer($token);
        $this->assertFalse($this->tokenAuth()->check());
    }

    public function test_jwt_revocation_is_owner_scoped_and_expired_rows_are_rejected(): void
    {
        $user = $this->tokenUser();
        $issuer = $this->tokenAuth(true);
        $issuer->login($user);
        $token = $issuer->createToken($user, ['iat' => time() - 1]);
        $claims = \Spark\Utils\JWT::decode($token, config('app.key'));
        $other = CoreFixtureTokenUser::create(['email' => 'other@example.test', 'username' => 'other', 'password' => 'hash']);
        $otherAuth = $this->tokenAuth(true);
        $otherAuth->login($other);
        $this->assertFalse($otherAuth->revokeToken($claims->jti));
        query('docs_tokens')->where('token_hash', $claims->jti)->update(['expire_at' => now()->modify('-1 minute')]);
        $this->bearer($token);
        $this->assertFalse($this->tokenAuth(true)->check());
        $this->assertCount(0, $issuer->tokens());
    }

    public function test_jwt_logout_handles_guests_and_deleted_users_without_recursion(): void
    {
        $user = $this->tokenUser();
        $issuer = $this->tokenAuth(true);
        $issuer->login($user);
        $token = $issuer->createToken($user);
        $this->bearer($token);
        $reader = $this->tokenAuth(true);
        $this->assertTrue($reader->check());
        query('docs_token_users')->where('id', $user->id)->delete();
        $this->assertFalse($this->tokenAuth(true)->check());
        $this->assertSame(0, query('docs_tokens')->count());
        $guest = $this->tokenAuth(true);
        $guest->logout();
        $this->assertTrue($guest->isGuest());
    }

    public function test_jwt_issuer_checks_origin_not_route_path_and_guest_header_does_not_clear_session(): void
    {
        $user = $this->tokenUser();
        $issuer = $this->tokenAuth();
        foreach ([request()->getRootUrl(), request()->getRootUrl() . '/api/login'] as $iss) {
            $this->bearer($issuer->makeToken($user, ['iss' => $iss]));
            $this->assertTrue($this->tokenAuth()->check());
        }
        foreach (['https://unrelated.example.test', request()->getRootUrl() . ':9999', '/api/login'] as $iss) {
            $this->bearer($issuer->makeToken($user, ['iss' => $iss]));
            $this->assertFalse($this->tokenAuth()->check());
        }
        // A table-backed guard falls back to its session when a token is not registered.
        session(['user_id' => $user->id]);
        $this->bearer($issuer->makeToken($user));
        $mixed = new \Spark\Http\Auth(
            CoreFixtureTokenUser::class,
            ['channels' => ['jwt', 'session'], 'jwt_token_table' => 'docs_tokens']
        );
        $this->assertTrue($mixed->check());
        $mixed->logout();
        $this->assertTrue($mixed->isGuest());
    }

    public function test_basic_channel_accepts_username_or_email(): void
    {
        $this->tokenUser();
        foreach (['ada', 'ada@example.test'] as $identifier) {
            request()->headers->put('authorization', 'Basic ' . base64_encode($identifier . ':secret'));
            $auth = new \Spark\Http\Auth(CoreFixtureTokenUser::class, ['channels' => ['basic']]);
            $this->assertTrue($auth->check());
        }
        request()->headers->put('authorization', 'Basic ' . base64_encode('ada:wrong'));
        $this->assertFalse((new \Spark\Http\Auth(CoreFixtureTokenUser::class, ['channels' => ['basic']]))->check());
    }
}
