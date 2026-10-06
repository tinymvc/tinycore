<?php

namespace Tests\Feature;

use Spark\Foundation\Application;
use Spark\Foundation\Providers\ServiceProvider;
use Spark\Http\{Request, Response};
use Spark\Facades\Route;
use Spark\Testing\ApplicationTestCase;

class RequestProbeMiddleware
{
    public static Request $received;

    public function __construct(private Request $injected)
    {
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        self::$received = $request;
        $request->routeParams['middleware_injection_matches'] = $this->injected === $request;
        $request->routeParams['marker'] = 'changed in middleware';
        return $next($request);
    }
}

class RequestProbeProvider extends ServiceProvider
{
    public static ?Request $bootRequest = null;
    public function register(): void
    {
    }
    public function boot(): void
    {
        self::$bootRequest = request();
    }
}

final class RequestLifecycleTest extends ApplicationTestCase
{
    protected function createApplication(): Application
    {
        RequestProbeProvider::$bootRequest = null;
        $app = (new Application(dirname(__DIR__)))->withApp(
            config: ['app' => ['debug' => false, 'key' => str_repeat('a', 32), 'storage_dir' => $this->storagePath]],
            providers: [RequestProbeProvider::class],
        )->withMiddleware(queue: [RequestProbeMiddleware::class]);
        Route::get('/probe/{id}', fn(Request $request) => [
            'same' => $request === RequestProbeMiddleware::$received && $request === request(),
            'path' => $request->getPath(),
            'id' => request()->getRouteParams()['id'] ?? null,
            'marker' => request()->getRouteParams()['marker'] ?? null,
            'middleware_same' => request()->getRouteParams()['middleware_injection_matches'] ?? false,
            'header' => $request->header('X-Probe'),
        ]);
        Route::get('/html', fn() => '<h1>Hello</h1>');
        Route::get('/json', fn() => ['ok' => true]);
        Route::get('/text', fn() => new Response('literal <b>text</b>', headers: ['content-type' => 'text/plain; charset=utf-8']));
        Route::get('/early', function () {
            (new Response('<h1>Early</h1>'))->send();
        });
        Route::get('/empty', fn() => (new Response())->noContent());
        return $app;
    }

    public function testPreDispatchRequestResolutionRemainsASingleton(): void
    {
        $first = $this->app->get(Request::class);
        $this->assertSame($first, request());
        $this->assertSame($first, $this->app->make(Request::class));
    }

    public function testNativeHttpTestingSharesRequestAcrossAllConsumers(): void
    {
        $this->get('/probe/123', ['X-Probe' => 'first'])->assertOk()
            ->assertJsonPath('same', true)->assertJsonPath('id', '123')
            ->assertJsonPath('middleware_same', true)->assertJsonPath('marker', 'changed in middleware');
        $this->assertSame(RequestProbeProvider::$bootRequest, RequestProbeMiddleware::$received);
        $this->assertSame(request(), RequestProbeMiddleware::$received);
    }

    public function testSequentialFeatureRequestsReplaceTheCurrentRequest(): void
    {
        $this->get('/probe/first', ['X-Probe' => 'first'])->assertJsonPath('header', 'first');
        $first = request();
        $this->get('/probe/second', ['X-Probe' => 'second'])->assertJsonPath('id', 'second')
            ->assertJsonPath('path', '/probe/second')->assertJsonPath('same', true)->assertJsonPath('header', 'second');
        $this->assertFalse($first === request());
    }

    public function testDirectHandleReplacesAnAlreadyResolvedRequest(): void
    {
        foreach (['first', 'second'] as $id) {
            $_SERVER['REQUEST_URI'] = '/probe/' . $id;
            $incoming = new Request();
            $response = $this->app->handle($incoming);
            $this->assertSame($incoming, request());
            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($id, $data['id']);
            $this->assertTrue($data['same']);
        }
    }

    public function testNormalizedAndEarlyResponsesHaveContentTypes(): void
    {
        $this->get('/html')->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertContent('<h1>Hello</h1>');
        $this->get('/json')->assertHeader('Content-Type', 'application/json; charset=utf-8')->assertExactJson(['ok' => true]);
        $this->get('/text')->assertHeader('Content-Type', 'text/plain; charset=utf-8')->assertContent('literal <b>text</b>');
        $this->get('/early')->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertContent('<h1>Early</h1>');
        $this->get('/empty')->assertNoContent()->assertHeaderMissing('Content-Type');
    }

    public function testHeadRetainsTheGetRepresentationTypeWithoutItsBody(): void
    {
        $this->head('/json')->assertOk()->assertContent('')->assertHeader('Content-Type', 'application/json; charset=utf-8');
        $this->head('/html')->assertOk()->assertContent('')->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->head('/text')->assertOk()->assertContent('')->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
