<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class FrameworkServicesTest extends FrameworkTestCase
{
    public function test_http(): void
    {
        \Spark\Facades\Route::get('/health', fn () => ['status' => 'ok']);
        $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
        $this->getJson('/missing')->assertNotFound();
    }

    public function test_response_preparation_lifecycle(): void
    {
        $calls = 0;
        $this->app->withMiddleware(register: ['prepare' => function ($request, $next) use (&$calls) {
            $this->app->prepareResponseUsing(function ($response) use (&$calls) {
                $calls++;
                $response->setHeader('X-Prepared', (string) $calls);
            });
            return $next($request);
        }]);
        \Spark\Facades\Route::get('/prepared', fn () => response('ok'))->middleware('prepare');
        \Spark\Facades\Route::get('/sent', fn () => response('early')->send())->middleware('prepare');
        \Spark\Facades\Route::get('/plain', fn () => response('plain'));
        $response = $this->get('/prepared')->assertOk()->assertHeader('X-Prepared', '1')->response;
        $this->app->prepareResponse($response);
        $this->assertSame(1, $calls);
        $this->get('/sent')->assertOk()->assertHeader('X-Prepared', '2');
        $this->assertSame(2, $calls);
        $response = $this->get('/plain')->assertOk()->response;
        $this->assertFalse(isset($response->getHeaders()['X-Prepared']));
        $this->assertSame(2, $calls);
    }

    public function test_request_accept_negotiation(): void
    {
        $request = new \Spark\Http\Request;
        $request->path = '/profile';
        $request->headers->forget('x-requested-with');
        foreach ([
            ['application/json;q=0.1, text/html;q=0.9', false],
            ['text/html;q=0.1, application/json;q=0.9', true],
            ['application/json;q=0, text/html', false],
            ['APPLICATION/PROBLEM+JSON', true],
            ['application/jsonp', false],
            ['text/html, application/json', false],
            ['application/json, text/html', true],
            ['application/json;profile="urn:a,b;q=0"', true],
        ] as [$accept, $json]) {
            $request->headers->put('accept', $accept);
            $this->assertSame($json, $request->wantsJson());
            $this->assertSame($json, $request->expectsJson());
        }
        foreach (['2', '-1', 'NaN', '1oops', '0.1234', ''] as $quality) {
            $request->headers->put('accept', 'application/json;q=' . $quality);
            $this->assertFalse($request->accept('application/json'));
            $this->assertFalse($request->wantsJson());
        }
        $request->headers->put('accept', 'application/json;q=0, application/*;q=0.5, */*');
        $this->assertFalse($request->accepts('application/json'));
        $this->assertTrue($request->accepts('application/xml'));
        $this->assertTrue($request->accepts('text/html'));
        $this->assertFalse($request->accept('application/xml'));
        $request->headers->put('accept', 'application/x~custom');
        $this->assertTrue($request->accept('application/x~custom'));
        $request->headers->put('accept', 'application/json;q=0, application/*;q=0.5, */*');
        $request->headers->put('x-requested-with', 'XMLHttpRequest');
        $this->assertFalse($request->expectsJson());
        foreach (['*/*;q=0', '*;q=0', 'invalid'] as $accept) {
            $request->headers->put('accept', $accept);
            $this->assertFalse($request->acceptsAnyContentType());
            $this->assertFalse($request->expectsJson());
        }
        $request->headers->put('accept', '');
        $this->assertTrue($request->acceptsAnyContentType());
        $this->assertTrue($request->accepts('text/html'));
        $this->assertTrue($request->expectsJson());
        $request->headers->forget('x-requested-with');
        foreach (['/api', '/api/items', '/webhook', '/webhook/events'] as $path) {
            $request->path = $path;
            $this->assertTrue($request->expectsJson());
        }
        foreach (['/apiculture', '/webhooks', '/profile/api'] as $path) {
            $request->path = $path;
            $this->assertFalse($request->expectsJson());
        }
        \Spark\Facades\Route::post('/profile-errors', fn () => throw \Spark\Foundation\Exceptions\ValidationException::withMessages(['name' => ['Required']]));
        $this->request('POST', '/profile-errors', [], ['Accept' => 'text/html;q=0.1, application/json;q=0.9'])->assertStatus(422)->assertJsonPath('errors.name.0', 'Required');
        $this->request('POST', '/profile-errors', [], ['Accept' => 'application/json;q=0, text/html'])->assertStatus(302);
    }

    public function test_request_trusted_proxy_boundaries(): void
    {
        $ip = function ($trusted, string $remote, array $headers = [], string $header = 'x-forwarded-for') {
            config(['app.trusted_proxies' => $trusted, 'app.trusted_proxy_header' => $header]);
            $request = new \Spark\Http\Request;
            $request->server->put('remote-addr', $remote);
            $request->headers = collect($headers);
            return $request->ip();
        };
        $forwarded = ['x-forwarded-for' => '198.51.100.8', 'cf-connecting-ip' => '203.0.113.99'];
        $this->assertSame('192.0.2.4', $ip([], '192.0.2.4', $forwarded));
        $this->assertSame('198.51.100.8', $ip(['10.0.0.0/8'], '10.1.2.3', $forwarded));
        $this->assertSame('203.0.113.99', $ip(['10.0.0.0/8'], '10.1.2.3', $forwarded, 'cf-connecting-ip'));
        $this->assertSame('192.0.2.4', $ip([], '192.0.2.4', $forwarded, 'cf-connecting-ip'));
        $this->assertSame('10.1.2.3', $ip(['10.0.0.0/8'], '10.1.2.3', ['x-forwarded-for' => '198.51.100.8'], 'cf-connecting-ip'));
        $this->assertSame('198.51.100.8', $ip([' 10.0.0.0/8 ', null, []], '10.1.2.3', ['x-forwarded-for' => '203.0.113.99, 198.51.100.8, 10.2.3.4']));
        $this->assertSame('198.51.100.8', $ip(['10.0.0.0/8'], '10.1.2.3', ['x-forwarded-for' => 'spoofed-invalid-prefix, 198.51.100.8, 10.2.3.4']));
        foreach (['', 'bogus', '32junk', '-1', '33', '8/0'] as $mask) {
            $this->assertSame('192.0.2.4', $ip(['10.0.0.0/' . $mask], '192.0.2.4', $forwarded));
        }
        $this->assertSame('198.51.100.8', $ip(['192.0.2.4/32'], '192.0.2.4', $forwarded));
        $this->assertSame('192.0.2.5', $ip(['192.0.2.4/32'], '192.0.2.5', $forwarded));
        $this->assertSame('198.51.100.8', $ip(['0.0.0.0/0'], '192.0.2.4', $forwarded));
        $this->assertSame('198.51.100.8', $ip('*', '192.0.2.4', $forwarded));
        $this->assertSame('2001:db8:1::5', $ip(['2001:db8:2::/64'], '2001:db8:2::9', ['x-forwarded-for' => '"[2001:db8:1::5]:443"']));
        $this->assertSame('2001:db8:3::9', $ip(['2001:db8:2::/64'], '2001:db8:3::9', $forwarded));
        $this->assertSame('198.51.100.8', $ip(['2001:db8:2::8/127'], '2001:db8:2::9', $forwarded));
        $this->assertSame('2001:db8:2::a', $ip(['2001:db8:2::8/127'], '2001:db8:2::a', $forwarded));
        $this->assertSame('198.51.100.8', $ip(['10.0.0.0/8'], '10.1.2.3', ['x-forwarded-for' => '198.51.100.8:443']));
        foreach (['198.51.100.8:garbage', '198.51.100.8:99999', '198.51.100.8, unknown', '198.51.100.8,', '[2001:db8::1]:99999'] as $malformed) {
            $this->assertSame('10.1.2.3', $ip(['10.0.0.0/8'], '10.1.2.3', ['x-forwarded-for' => $malformed]));
        }
        $this->assertSame('10.1.2.3', $ip(['10.0.0.0/8'], '10.1.2.3'));
        $this->assertFalse($ip(['*'], '', $forwarded));
        $app = \Spark\Foundation\Application::$app;
        try {
            \Spark\Foundation\Application::$app = null;
            $request = new \Spark\Http\Request;
            $request->server->put('remote-addr', '192.0.2.4');
            $this->assertSame('192.0.2.4', $request->ip());
        } finally {
            \Spark\Foundation\Application::$app = $app;
        }
    }

    public function test_create_token_explicit_and_current_user(): void
    {
        $user = $this->tokenUser();
        $other = CoreFixtureTokenUser::create(['email' => 'other@example.com', 'username' => 'other', 'password' => bcrypt('secret')]);
        foreach ([false, true] as $registered) {
            $issuer = $this->tokenAuth($registered);
            $this->assertFalse($issuer->check());
            $token = $issuer->createToken($user, ['iat' => time() - 1, 'label' => 'explicit']);
            $claims = \Spark\Utils\JWT::decode($token, config('app.key'));
            $this->assertSame((int) $user->id, (int) $claims->sub);
            $this->assertSame('explicit', $claims->label);
            $this->assertFalse($issuer->check()); // Issuing a token does not log in.
            if ($registered) {
                $this->assertSame((int) $user->id, (int) query('docs_tokens')->where('token_hash', $claims->jti)->value('user_id'));
            }
            try {
                $issuer->createToken();
                $this->fail('Guest token issuance without a user must fail.');
            } catch (\RuntimeException $error) {
                $this->assertSame('No authenticated user found to create JWT token.', $error->getMessage());
            }
            $issuer->login($user);
            $claims = \Spark\Utils\JWT::decode($issuer->createToken($other), config('app.key'));
            $this->assertSame((int) $other->id, (int) $claims->sub);
            $this->assertSame((int) $user->id, (int) $issuer->getUser()->id);
            foreach ([$issuer->createToken(payload: ['label' => 'current']), $issuer->createToken(null, ['label' => 'current'])] as $token) {
                $claims = \Spark\Utils\JWT::decode($token, config('app.key'));
                $this->assertSame((int) $user->id, (int) $claims->sub);
                $this->assertSame('current', $claims->label);
            }
            $issuer->logout();
        }
    }

    public function test_shared_json_normalization(): void
    {
        $missing = \Spark\Http\Resources\MissingValue::Missing;
        $serializable = new class implements \JsonSerializable {
            public function jsonSerialize(): array {
                return ['date' => new \DateTimeImmutable('2026-09-29T12:00:00+06:00')];
            }
        };
        $payload = [
            'url' => new \Spark\Url('https://example.com/items'),
            'date' => new \DateTimeImmutable('2026-09-29T00:00:00+00:00'),
            'carbon' => \Spark\Carbon::parse('2026-09-29T06:00:00+06:00'),
            'lazy' => fn () => collect([CoreFixtureResource::make(['id' => 1, 'name' => 'Ada'])]),
            'object' => $serializable,
            'omit' => $missing,
            'values' => [null, false, 0, $missing, ''],
        ];
        $expected = [
            'url' => 'https://example.com/items',
            'date' => '2026-09-29T00:00:00+00:00',
            'carbon' => $payload['carbon']->toIsoUtcString(),
            'lazy' => [['id' => 1, 'name' => 'Ada']],
            'object' => ['date' => '2026-09-29T12:00:00+06:00'],
            'values' => [null, false, 0, ''],
        ];
        $this->assertSame($expected, \Spark\Http\Resources\JsonResource::normalize($payload));
        foreach ([response($payload), (new \Spark\Http\Response)->json($payload, 202), (new \Spark\Http\Response)->withJson($payload, 202)] as $response) {
            $this->assertSame($expected, json_decode($response->getContent(), true));
            $this->assertSame('application/json; charset=utf-8', $response->getHeaders()['Content-Type']);
        }
        $resource = CoreFixtureResource::make(['id' => 1, 'name' => 'Ada'])->additional(['ok' => true]);
        $this->assertSame(['data' => ['id' => 1, 'name' => 'Ada'], 'ok' => true], json_decode(response($resource)->getContent(), true));
        $this->assertSame(['id' => 1, 'name' => 'Ada'], \Spark\Http\Resources\JsonResource::normalize($resource));
        $request = new \Spark\Http\Request;
        $request->headers->put('x-show-secret', 'yes');
        $this->assertSame('visible', \Spark\Http\Resources\JsonResource::normalize(CoreFixtureResource::make(['secret' => 'visible']), $request)['secret']);
    }

    public function test_request_defaults_pagination_and_dates(): void
    {
        $r = request();
        $r->setQueryParam('count', '0');
        $r->setQueryParam('enabled', 'false');
        $this->assertSame(0, $r->integer('count', 9));
        $this->assertSame(0.0, $r->float('count', 9.0));
        $this->assertFalse($r->boolean('enabled', true));
        $this->assertTrue($r->boolean('absent', true));
        $r->getHeaders()->put('authorization', 'bEaReR token-123');
        $this->assertSame('token-123', $r->bearerToken());
        $r->getHeaders()->put('authorization', 'Basic Bearer token-123');
        $this->assertNull($r->bearerToken());
        $r->setQueryParam('page', '2');
        $p = \Spark\Utils\Paginator::make(25, 10)->setData([11, 12]);
        $this->assertSame(2, $p->currentPage());
        $this->assertSame(3, $p->lastPage());
        $this->assertSame([22, 24], $p->through(fn ($n) => $n * 2));
        $this->assertSame([11, 12], $p->items());
        $this->assertSame(2, $p->toArray()['current_page']);
        $this->assertFalse(array_key_exists('page', $p->toArray()));
        $r->setQueryParam('page', 'invalid');
        $empty = \Spark\Utils\Paginator::make(0, 10);
        $this->assertSame(1, $empty->currentPage());
        $this->assertNull($empty->toArray()['from']);
        $date = carbon('2026-01-10 12:00:00', 'UTC');
        $this->assertSame('18:00', $date->setTimezone('Asia/Dhaka')->format('H:i'));
        $this->assertSame('12:00 +06:00', $date->shiftTimezone('Asia/Dhaka')->format('H:i P'));
        $this->assertSame('2026-01-11', $date->modify(fn ($d) => $d->modify('+1 day'))->toDateString());
        $this->assertSame('2026-01-10', $date->toDateString());
    }

    public function test_user_agent_cannot_reset_throttling(): void
    {
        $middleware = new class extends \Spark\Foundation\Http\Middlewares\ThrottleIncomingRequests {};
        $request = request();
        $request->getHeaders()->put('user-agent', 'first');
        $this->assertSame('ok', $middleware->handle($request, fn () => 'ok', 1, 1, 'docs'));
        $request->getHeaders()->put('user-agent', 'different');
        $this->expectException(\Spark\Foundation\Exceptions\ThrottleException::class);
        $middleware->handle($request, fn () => 'incorrect', 1, 1, 'docs');
    }

    public function test_scoped_gate_and_service_examples(): void
    {
        $record = (object) ['owner_id' => 7];
        $gate = new \Spark\Http\Gate;
        $gate->define('update', fn ($post, int $viewer) => $post->owner_id === $viewer);
        $this->assertTrue($gate->allows('update', $record, 7));
        $this->assertFalse($gate->allows('update', $record, 8));
        $events = new \Spark\Events;
        $seen = [];
        $events->addListener('ready', function () use (&$seen) {
            $seen[] = 'late';
        });
        $events->addListener('ready', function () use (&$seen) {
            $seen[] = 'first';
            return false;
        }, 10);
        $events->dispatch('ready');
        $this->assertSame(['first'], $seen);
        session()->flash('notice', 'Saved');
        $this->assertSame('Saved', session()->getFlash('notice'));
        $this->assertFalse(session()->hasFlash('notice'));
        $lock = lock(name: 'docs-smoke');
        $this->assertSame('completed', $lock->withLock('report', fn () => 'completed', 10, 0));
        $this->assertFalse($lock->isLocked('report'));
        $cache = cache('batch');
        $cache->storeManyWithExpiry([
            'counts' => ['value' => [1, 2], 'expire' => '+1 minute'],
            'expired' => ['value' => 'stale', 'expire' => '-1 second'],
        ]);
        $this->assertSame([1, 2], $cache->retrieve('counts'));
        $this->assertNull($cache->retrieve('expired'));
        $cache->store('nullable', null);
        $this->assertTrue($cache->has('nullable'));
        $results = \Spark\Concurrency::run([
            'ok' => fn () => 4,
            'failure' => function () {
                throw new \RuntimeException('Task failed');
            },
        ]);
        $this->assertSame(4, $results['ok']);
        $this->assertSame(['error' => true, 'message' => 'Task failed'], $results['failure']);
    }

    public function test_pipeline_and_dates(): void
    {
        $result = \Spark\Pipeline::make(['name' => '  Ada  '])->through(
            function ($payload, $next) {
                $payload['name'] = trim($payload['name']);
                return $next($payload);
            },
            function ($payload, $next) {
                $payload['slug'] = strtolower($payload['name']);
                return $next($payload);
            },
        )->thenReturn();
        $this->assertSame(['name' => 'Ada', 'slug' => 'ada'], $result);
        $date = \Spark\Carbon::parse('2026-09-17', 'UTC');
        $next = $date->addDays(1);
        $this->assertSame('2026-09-17', $date->format('Y-m-d'));
        $this->assertSame('2026-09-18', $next->format('Y-m-d'));
    }
}
