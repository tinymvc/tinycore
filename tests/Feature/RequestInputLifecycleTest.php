<?php

namespace Tests\Feature;

require_once dirname(__DIR__) . '/Support/LifecycleTestCase.php';

use Spark\Facades\Route;
use Spark\Http\Request;
use Tests\Support\LifecycleTestCase;

final class RequestInputLifecycleTest extends LifecycleTestCase
{
    private function registerProbe(): void
    {
        Route::any('/input/{id}', fn(Request $request, string $id) => [
            'id' => $id,
            'method' => $request->getMethod(),
            'query' => $request->query(),
            'body' => $request->post(),
            'header' => $request->header('X-Probe'),
        ]);
    }

    public function testMethodsQueryBodyAndRouteParametersReachInjectedRequest(): void
    {
        $this->registerProbe();
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $response = $this->request($method, '/input/42?search=a%20b', ['value' => '✓'], ['X-Probe' => 'present']);
            $response->assertOk()->assertJsonPath('method', $method)->assertJsonPath('id', '42')
                ->assertJsonPath('query.search', 'a b')->assertJsonPath('header', 'present');
            $response->assertJsonPath($method === 'GET' ? 'query.value' : 'body.value', '✓');
        }
    }

    public function testMethodOverridesArePostOnlyAndHeaderTakesPrecedence(): void
    {
        $this->registerProbe();
        foreach (['put', 'PATCH', 'delete'] as $method) {
            $this->post('/input/id', ['_method' => $method])->assertJsonPath('method', strtoupper($method));
        }
        $this->post('/input/id', ['_method' => 'DELETE'], ['X-HTTP-Method-Override' => 'PATCH'])->assertJsonPath('method', 'PATCH');
        $this->post('/input/id', ['_method' => 'TRACE'])->assertJsonPath('method', 'POST');
        $this->get('/input/id?_method=DELETE', ['X-HTTP-Method-Override' => 'PATCH'])->assertJsonPath('method', 'GET');
    }

    public function testMalformedFormOverrideDoesNotCrashRequestConstruction(): void
    {
        $this->registerProbe();
        $this->post('/input/id', ['_method' => ['DELETE']])->assertJsonPath('method', 'POST');
    }

    public function testCanonicalCgiContentHeadersAreAvailableToMiddlewareAndController(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['CONTENT_LENGTH'] = '12';
        unset($_SERVER['HTTP_CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_LENGTH']);
        Route::get('/', fn(Request $request) => [
            'type' => $request->header('Content-Type'),
            'length' => $request->header('Content-Length'),
        ]);
        $response = $this->app->handle(new Request());
        $this->assertSame(['type' => 'application/json', 'length' => '12'], json_decode($response->getContent(), true));
    }

    public function testSuccessfulRequestsRestoreGlobalsAndDoNotLeakInputOrHeaders(): void
    {
        $this->registerProbe();
        $before = [$_SERVER, $_GET, $_POST, $_FILES, $_REQUEST];
        $this->post('/input/first?search=yes', ['name' => 'first'], ['X-Probe' => 'first'])->assertJsonPath('body.name', 'first');
        $this->assertSame($before, [$_SERVER, $_GET, $_POST, $_FILES, $_REQUEST]);
        $this->get('/input/second')->assertJsonPath('body', [])->assertJsonPath('query', [])->assertJsonPath('header', null);
    }
}
