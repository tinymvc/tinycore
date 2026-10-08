<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class CorsTest extends FrameworkTestCase
{
    public function test_preflight_and_request_input(): void
    {
        $calls = 0;
        $this->app->withMiddleware(register: [
            'cors' => CoreFixtureCors::class,
        ]);
        \Spark\Facades\Route::post('/api/items', function () use (&$calls) {
            $calls++;
            return ['created' => true];
        })->middleware('cors');
        $this->request('OPTIONS', '/api/items', [], [
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type',
        ])->assertNoContent();
        $this->assertSame(0, $calls);
        $request = new \Spark\Http\Request;
        $request->setQueryParam('name', 'query');
        $request->setPostParam('name', 'body');
        $this->assertSame('body', $request->all()['name']);
        $this->assertSame(['missing' => null], $request->only(['missing']));
    }

    public function test_cors_early_error_responses(): void
    {
        $this->app->withMiddleware(register: ['cors' => CoreFixtureCors::class]);
        $calls = 0;
        \Spark\Facades\Route::post('/api/form', function (CoreFixtureCorsForm $request) use (&$calls) {
            $calls++;
            return ['ok' => true];
        })->middleware('cors');
        \Spark\Facades\Route::post('/api/abort', fn () => abort(403, 'Denied'))->middleware('cors');
        \Spark\Facades\Route::post('/api/early', fn () => response('early', 422)->send())->middleware('cors');
        \Spark\Facades\Route::post('/api/redirect', fn () => response()->redirect('/login')->send())->middleware('cors');
        \Spark\Facades\Route::post('/api/custom', fn () => throw new \LogicException('custom'))->middleware('cors');
        \Spark\Facades\Route::post('/api/plain', fn () => response('plain', 422));
        \Spark\Facades\Route::post('/api/excluded', fn () => response('excluded', 422))->middleware('cors')->withoutMiddleware('cors');
        $this->app->withExceptions([\LogicException::class => fn () => response('handled', 409)]);
        $headers = ['Origin' => 'https://app.example.com'];

        $this->postJson('/api/form', [], $headers)->assertStatus(422)
            ->assertHeader('Access-Control-Allow-Origin', $headers['Origin'])
            ->assertJsonPath('errors.name.0', 'The Name field is required.');
        $this->assertSame(0, $calls);
        foreach (['abort' => 403, 'early' => 422, 'redirect' => 302, 'custom' => 409] as $path => $status) {
            $this->postJson('/api/' . $path, [], $headers)->assertStatus($status)
                ->assertHeader('Access-Control-Allow-Origin', $headers['Origin']);
        }
        // No origin/configuration may leak from a prior request in a long-lived app.
        foreach ([['/api/form', ['Origin' => 'https://denied.example']], ['/api/form', []], ['/api/plain', $headers], ['/api/excluded', $headers]] as [$path, $requestHeaders]) {
            $result = $this->postJson($path, [], $requestHeaders)->assertStatus(422);
            $this->assertFalse(isset($result->response->getHeaders()['Access-Control-Allow-Origin']));
        }
        $this->postJson('/api/form', ['name' => 'Ada'], $headers)->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', $headers['Origin']);
        $this->assertSame(1, $calls);
    }

    public function test_cors_preflight_edge_cases(): void
    {
        $origin = 'https://app.example.com';
        $base = ['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'];
        $policy = ['origin' => [$origin], 'methods' => ['POST', 'HEAD'], 'headers' => ['Content-Type'], 'age' => -10];
        $invoke = function (string $method, array $headers, array $config, string $path = '/api/items', mixed $result = null): array {
            $saved = [$_SERVER, $_GET, $_POST, $_FILES];
            try {
                $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'HTTP_HOST' => 'localhost'];
                foreach ($headers as $key => $value) {
                    $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
                }
                $_GET = $_POST = $_FILES = [];
                $request = new \Spark\Http\Request;
            } finally {
                [$_SERVER, $_GET, $_POST, $_FILES] = $saved;
            }
            $middleware = new class($config) extends \Spark\Foundation\Http\Middlewares\CorsAccessControl {};
            $calls = 0;
            $response = $middleware->handle($request, function () use (&$calls, $result) {
                $calls++;
                return $result ?? response('controller', 202);
            });
            return [new \Spark\Testing\TestResponse($response), $calls];
        };
        foreach ([$base, [...$base, 'Access-Control-Request-Headers' => 'content-type, CONTENT-TYPE'], ['origin' => $origin, 'access-control-request-method' => ' post ']] as $headers) {
            [$response, $calls] = $invoke('options', $headers, $policy);
            $response->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $origin)
                ->assertHeader('Access-Control-Max-Age', '0')
                ->assertHeader('Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');
            $this->assertSame(0, $calls);
        }
        foreach ([['GET', $base], ['OPTIONS', []], ['OPTIONS', ['Origin' => $origin]], ['OPTIONS', ['Access-Control-Request-Method' => 'POST']]] as [$method, $headers]) {
            [$response, $calls] = $invoke($method, $headers, $policy);
            $response->assertStatus(202);
            $this->assertSame(1, $calls);
            $this->assertFalse(isset($response->response->getHeaders()['Access-Control-Allow-Methods']));
        }
        $wildcard = ['origin' => '*', 'methods' => '*', 'headers' => '*'];
        foreach (['', ' ', 'POST, GET', "POST\r\nX-Test: x", 'BAD METHOD'] as $method) {
            [$response, $calls] = $invoke('OPTIONS', [...$base, 'Access-Control-Request-Method' => $method], $wildcard);
            $response->assertStatus(403);
            $this->assertSame(0, $calls);
        }
        foreach (['bad header', 'x:bad', "x-test\r\nInjected: yes"] as $header) {
            [$response, $calls] = $invoke('OPTIONS', [...$base, 'Access-Control-Request-Headers' => $header], $wildcard);
            $response->assertStatus(403);
            $this->assertSame(0, $calls);
        }
        foreach ([['Origin' => ''], ['Origin' => 'https://denied.example'], ['Access-Control-Request-Method' => 'DELETE'], ['Access-Control-Request-Headers' => 'Authorization']] as $override) {
            [$response, $calls] = $invoke('OPTIONS', [...$base, ...$override], $policy);
            $response->assertStatus(403);
            $this->assertSame(0, $calls);
            $this->assertFalse(isset($response->response->getHeaders()['Access-Control-Allow-Origin']));
        }
        foreach (['*', ['*'], 'https://denied.example, *'] as $origins) {
            foreach ([false, true] as $credentials) {
                [$response] = $invoke('OPTIONS', [...$base, 'Access-Control-Request-Headers' => 'Authorization, X-Custom'], [...$wildcard, 'origin' => $origins, 'credentials' => $credentials]);
                $response->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $credentials ? $origin : '*')
                    ->assertHeader('Access-Control-Allow-Methods', 'POST')->assertHeader('Access-Control-Allow-Headers', 'Authorization, X-Custom');
                $this->assertSame($credentials ? 'true' : null, $response->response->getHeaders()['Access-Control-Allow-Credentials'] ?? null);
            }
        }
        foreach (['https://*.example.com', ['https://*.example.com']] as $origins) {
            [$response] = $invoke('OPTIONS', $base, [...$policy, 'origin' => $origins]);
            $response->assertNoContent();
            [$response] = $invoke('OPTIONS', [...$base, 'Origin' => 'https://app.example.com.evil.test'], [...$policy, 'origin' => $origins]);
            $response->assertStatus(403);
        }
        [$response] = $invoke('OPTIONS', [...$base, 'Origin' => 'null'], [...$policy, 'origin' => ['null']]);
        $response->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'null');
        [$response, $calls] = $invoke('OPTIONS', $base, [...$policy, 'paths' => ['api/*']], '/outside');
        $response->assertStatus(202);
        $this->assertSame(1, $calls);
        $this->assertFalse(isset($response->response->getHeaders()['Access-Control-Allow-Origin']));
        foreach (['Accept-Encoding' => 'Accept-Encoding, Origin', 'Origin, Accept-Encoding' => 'Origin, Accept-Encoding', '*' => '*'] as $existing => $expected) {
            [$response] = $invoke('GET', ['Origin' => $origin], $policy, result: response('ok', 200, ['vary' => $existing]));
            $response->assertHeader('Vary', $expected);
        }
    }

    public function test_cors_preflight_routing(): void
    {
        $this->app->withMiddleware(register: ['cors' => fn ($request, $next) => (new class(['origin' => '*', 'methods' => '*']) extends \Spark\Foundation\Http\Middlewares\CorsAccessControl {})->handle($request, $next)]);
        $calls = 0;
        $callback = function () use (&$calls) { $calls++; return 'options'; };
        \Spark\Facades\Route::get('/api/head', $callback)->middleware('cors');
        \Spark\Facades\Route::options('/api/options', $callback)->middleware('cors');
        foreach (['/api/head' => 'HEAD', '/api/options' => 'POST'] as $path => $method) {
            $this->request('OPTIONS', $path, [], ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => $method])
                ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
        }
        $this->assertSame(0, $calls);
        $this->request('OPTIONS', '/api/options', [], ['Origin' => 'https://app.example.com'])->assertOk();
        $this->assertSame(1, $calls);
    }
}
