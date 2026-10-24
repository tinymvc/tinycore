<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Nyholm\Psr7\{Response as PsrResponse, ServerRequest, Stream, UploadedFile};
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Spark\Facades\Route;
use Spark\Http\{Request, Response};

final class PsrHttpLifecycleTest extends FrameworkTestCase
{
    public function test_psr_requests_roundtrip_without_reading_globals_or_consuming_streams(): void
    {
        $_SERVER['HTTP_HOST'] = 'invalid host';
        $body = Stream::create('raw body');
        $body->seek(3);
        $upload = new UploadedFile(Stream::create('upload'), 6, UPLOAD_ERR_OK, 'a.txt');
        $parsed = (object) ['title' => 'hello'];
        $psr = (new ServerRequest('PATCH', 'https://[::1]:8443/a%2Fb?q=old', ['X-Multi' => ['a', 'b']], $body, '1.0', ['customKey' => ['nested' => true], 'REQUEST_URI' => '/stale']))
            ->withRequestTarget('/custom-target')
            ->withCookieParams(['session' => 'value'])
            ->withQueryParams(['q' => 'new'])
            ->withParsedBody($parsed)
            ->withAttribute('user', (object) ['id' => 7])
            ->withUploadedFiles([
                'files' => ['nested' => [$upload], 'tmp_name' => $upload],
                'group' => array_fill_keys(['name', 'type', 'tmp_name', 'error', 'size'], [$upload]),
            ]);
        $request = Request::fromPsrRequest($psr);
        $roundtrip = $request->toPsrRequest();
        $this->assertSame($body, $roundtrip->getBody());
        $this->assertSame(3, $body->tell());
        $this->assertSame('raw body', $request->getRawBody());
        $this->assertSame(3, $body->tell());
        $this->assertSame($parsed, $roundtrip->getParsedBody());
        $this->assertSame($psr->getServerParams(), $roundtrip->getServerParams());
        $this->assertSame($psr->getCookieParams(), $roundtrip->getCookieParams());
        $this->assertSame($psr->getAttributes(), $roundtrip->getAttributes());
        $this->assertSame('/custom-target', $roundtrip->getRequestTarget());
        $this->assertSame('/custom-target', $request->getUri());
        $this->assertSame('/a%2Fb?q=old', Request::fromPsrRequest(new ServerRequest('GET', '/a%2Fb?q=old'))->getUri());
        $this->assertSame(['a', 'b'], $roundtrip->getHeader('X-Multi'));
        $this->assertSame('a, b', $request->header('X-Multi'));
        $this->assertSame('/a%2Fb', $request->getPath());
        $this->assertSame([], $request->getRouteParams());
        $this->assertTrue($request->hasFile('files'));
        $this->assertSame($upload, $request->file('files')['nested'][0]);
        $this->assertSame($psr->getUploadedFiles(), $roundtrip->getUploadedFiles());
        $this->assertSame($psr->getUploadedFiles()['group'], $request->file('group'));
        $request->setPostParam('title', 'changed');
        $this->assertSame(['title' => 'changed'], $request->toPsrRequest()->getParsedBody());
        $this->assertNull(Request::fromPsrRequest($psr->withParsedBody(null))->toPsrRequest()->getParsedBody());
    }

    public function test_proxy_configuration_is_available_before_capture_and_ipv6_hosts_work(): void
    {
        $this->assertFalse($this->app->resolved(Request::class));
        $_SERVER['HTTP_HOST'] = '[::1]:8080';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertSame('http://[::1]:8080', (new Request())->getRootUrl());
        $this->app->setConfig('app.trusted_proxies', ['192.0.2.10']);
        $this->assertSame('https://[::1]:8080', request()->getRootUrl());
        $this->assertThrows(InvalidArgumentException::class, function () {
            $_SERVER['HTTP_HOST'] = 'user@example.test';
            new Request();
        });
    }

    public function test_routing_preserves_encoded_segments_and_named_routes_encode_values(): void
    {
        Route::get('/files/{name}', fn(string $name) => ['name' => $name])->name('file');
        $this->get('/files/a+b%2Fc%252F')->assertJsonPath('name', 'a+b/c%2F');
        $this->assertSame('/files/a%2Fb%3Fc%23d', Route::route('file', ['name' => 'a/b?c#d']));
        $this->assertThrows(Spark\Http\Routing\Exceptions\InvalidNamedRouteException::class, fn() => Route::route('file'));
        Route::get('/café', fn() => 'unicode');
        $this->get('/caf%C3%A9')->assertContent('unicode');
    }

    public function test_global_middleware_wraps_missing_wrong_method_and_fallback_responses_once(): void
    {
        $trace = [];
        $this->app->withMiddleware(register: [
            'trace' => function ($request, $next) use (&$trace) {
                $trace[] = 'before';
                $response = $next($request);
                $trace[] = 'after';
                return $response->setHeader('X-Global', 'yes');
            }
        ], queue: ['trace']);
        Route::get('/known', fn() => 'known');
        $this->get('/missing')->assertNotFound()->assertHeader('X-Global', 'yes');
        $this->post('/known')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD')->assertHeader('X-Global', 'yes');
        Route::fallback(fn() => new PsrResponse(404, [], 'fallback'));
        $this->get('/fallback')->assertContent('fallback')->assertHeader('X-Global', 'yes');
        $this->assertSame(array_merge(...array_fill(0, 3, ['before', 'after'])), $trace);
    }

    public function test_explicit_head_and_options_take_precedence_and_head_keeps_representation_headers(): void
    {
        Route::get('/method', fn() => new Response('get body', headers: ['X-Method' => 'GET']));
        Route::head('/method', fn() => new Response('head body', headers: ['X-Method' => 'HEAD']));
        Route::options('/method', fn() => new Response('options', headers: ['X-Method' => 'OPTIONS']));
        $this->head('/method')->assertContent('')->assertHeader('X-Method', 'HEAD');
        $this->options('/method', ['Origin' => 'https://example.test', 'Access-Control-Request-Method' => 'GET'])
            ->assertContent('options')->assertHeader('X-Method', 'OPTIONS');
        Route::get('/fallback-head', fn() => new PsrResponse(200, ['Content-Length' => '3'], 'abc'));
        $response = $this->app->handlePsr(new ServerRequest('HEAD', '/fallback-head'));
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame('3', $response->getHeaderLine('Content-Length'));
    }

    public function test_psr_response_factories_and_custom_error_handlers_are_used(): void
    {
        $this->app->bind(ResponseFactoryInterface::class, fn() => new class implements ResponseFactoryInterface {
            public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
                {
                    return new PsrResponse($code, ['X-Factory' => 'custom'], null, '1.1', $reasonPhrase);
                }
            });
        Route::get('/native', fn() => 'native');
        $this->get('/native')->assertHeader('X-Factory', 'custom');
        Route::get('/psr', fn() => new PsrResponse(202, ['Set-Cookie' => ['a=1', 'b=2']], 'psr'));
        $this->get('/psr')->assertStatus(202)->assertContent('psr')->assertHeaderMissing('Content-Type');
        $this->app->withExceptions([DomainException::class => fn() => new PsrResponse(409, [], 'conflict')]);
        Route::get('/error', fn() => throw new DomainException());
        $this->get('/error')->assertStatus(409)->assertContent('conflict');
        $this->assertThrows(InvalidArgumentException::class, fn() => (new Response())->setHeader('X-Test', "bad\r\nvalue"));
    }

    public function test_psr_middleware_changes_reach_native_helpers_and_do_not_leak_between_requests(): void
    {
        $this->app->withMiddleware(register: [
            'psr' => new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute('user', 7)->withParsedBody(['name' => 'changed']))
                    ->withAddedHeader('X-Psr', 'after');
            }
            }
        ]);
        Route::post('/mixed/{id}', function (Request $request, ServerRequestInterface $psr, string $id) {
            return [
                'same' => $request === request(),
                'input' => app('input')->get('name'),
                'user' => $psr->getAttribute('user'),
                'id' => $id,
            ];
        })->middleware('psr');
        $this->post('/mixed/42', ['name' => 'original'])->assertHeader('X-Psr', 'after')
            ->assertExactJson(['same' => true, 'input' => 'changed', 'user' => 7, 'id' => '42']);
        Route::post('/plain', fn() => ['name' => app('input')->get('name'), 'user' => request()->attributes['user'] ?? null]);
        $this->post('/plain', ['name' => 'fresh'])->assertExactJson(['name' => 'fresh', 'user' => null]);
    }

    public function test_psr_handler_short_circuit_and_termination_use_the_existing_lifecycle(): void
    {
        $this->app->withMiddleware(register: [
            'stop' => new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new PsrResponse(401, [], 'blocked');
            }
            }
        ]);
        Route::get('/blocked', function () {
            throw new LogicException('Controller must not run');
        })->middleware('stop');
        $handler = $this->app->get(RequestHandlerInterface::class);
        $response = $handler->handle(new ServerRequest('GET', '/blocked'));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('blocked', (string) $response->getBody());
        $calls = 0;
        $this->app->defer(function () use (&$calls) {
            $calls++;
        });
        $this->app->terminate();
        $this->app->terminate();
        $this->assertSame(1, $calls);
    }

    public function test_psr_middleware_can_decorate_native_early_responses_and_preserves_request_after_errors(): void
    {
        $this->app->withMiddleware(register: [
            'psr' => new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute('temporary', true))->withHeader('X-After', 'yes');
            }
            }
        ], queue: ['psr']);
        $prepared = 0;
        Route::get('/early', function () use (&$prepared) {
            app()->prepareResponseUsing(function ($response) use (&$prepared) {
                $prepared++;
                $response->setHeader('X-Prepared', 'yes');
            });
            response('early', 202)->send();
        });
        $this->get('/early')->assertAccepted()->assertContent('early')->assertHeader('X-After', 'yes')->assertHeader('X-Prepared', 'yes');
        $this->assertSame(1, $prepared);
        Route::get('/failure', fn() => throw new DomainException('failure'));
        $incoming = Request::fromPsrRequest(new ServerRequest('GET', '/failure'));
        $this->assertThrows(DomainException::class, fn() => $this->app->handle($incoming));
        $this->assertSame($incoming, request());
        $this->assertSame([], request()->attributes);
    }

    public function test_stream_import_export_preserves_identity_cursor_and_absent_media_type(): void
    {
        $stream = Stream::create(str_repeat('body', 262144));
        $stream->seek(5);
        $response = Response::fromPsrResponse(new PsrResponse(200, [], $stream));
        $this->assertSame([], $response->getHeaders());
        $this->assertSame($stream, $response->toPsrResponse()->getBody());
        $this->assertSame(5, $stream->tell());
        $this->assertSame(1048576, strlen($response->getContent()));
        $this->assertSame(5, $stream->tell());

        $pipe = popen('printf nonseekable', 'r');
        $body = Stream::create($pipe);
        $response = Response::fromPsrResponse(new PsrResponse(200, [], $body));
        $this->assertFalse($body->isSeekable());
        $this->assertSame($body, $response->toPsrResponse()->getBody());
        $response->prepareForRequest(Request::fromPsrRequest(new ServerRequest('HEAD', '/')));
        $this->assertSame('', $response->getContent());
        $this->assertSame('nonseekable', $body->getContents());
        $body->close();
    }

    public function test_production_cli_psr_host_captures_early_responses_and_negotiates_errors(): void
    {
        $testing = new ReflectionProperty(Spark\Foundation\Application::class, 'testing');
        $testing->setValue($this->app, false);
        try {
            Route::get('/early-host', fn() => response('early', 202)->send());
            $response = $this->app->handlePsr(new ServerRequest('GET', '/early-host'));
            $this->assertSame(202, $response->getStatusCode());
            $this->assertSame('early', (string) $response->getBody());
            $response = $this->app->handlePsr(new ServerRequest('GET', '/missing', ['Accept' => 'application/json']));
            $this->assertSame(404, $response->getStatusCode());
            $this->assertSame(['message' => 'Route not found', 'code' => 404], json_decode((string) $response->getBody(), true));
        } finally {
            $testing->setValue($this->app, true);
        }
    }
}
