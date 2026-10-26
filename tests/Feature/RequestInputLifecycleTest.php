<?php

namespace Tests\Feature;

require_once dirname(__DIR__) . '/Support/LifecycleTestCase.php';

use Spark\Facades\Route;
use Spark\Http\Header;
use Spark\Http\Request;
use Spark\Http\Response;
use Nyholm\Psr7\ServerRequest;
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

    public function testHeaderNamesPreserveHttpInsideTheNameAndRoundtrip(): void
    {
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = Request::METHOD_PATCH;
        $_SERVER['HTTP_X_METHOD_OVERRIDE'] = 'separate';
        $_SERVER['HTTP_HTTP_TRACE'] = 'trace';
        $headers = [Header::X_HTTP_METHOD_OVERRIDE => Request::METHOD_PATCH, 'X-Method-Override' => 'separate', 'HTTP-Trace' => 'trace'];

        foreach ([new Request(), Request::fromPsrRequest(new ServerRequest(Request::METHOD_POST, '/', $headers))] as $request) {
            foreach ($headers as $name => $value) {
                $this->assertSame($value, $request->header($name));
                $this->assertSame($value, $request->toPsrRequest()->getHeaderLine($name));
            }
            $this->assertSame(Request::METHOD_PATCH, $request->header('HTTP_X_HTTP_METHOD_OVERRIDE'));
        }
    }

    public function testServerMutatorsUseTheSameKeysAsReadsAndPsrExport(): void
    {
        $request = new Request();
        $request->setServerParam('REMOTE_ADDR', '192.0.2.1');
        $this->assertSame('192.0.2.1', $request->ip());
        $request->mergeServerParams(['REMOTE_ADDR' => '192.0.2.2', 'SERVER_PORT' => 8443, 'CUSTOM_VALUE' => ['nested' => true]]);
        $this->assertSame('192.0.2.2', $request->server('remote-addr'));
        $this->assertSame(8443, $request->getServerParams()->get('server-port'));
        $this->assertSame(['nested' => true], $request->getServerParams()->get('custom-value'));
        $this->assertSame('192.0.2.2', $request->toPsrRequest()->getServerParams()['REMOTE_ADDR']);
    }

    public function testFilledAcceptsZeroAndFalseButRejectsBlankValues(): void
    {
        $request = Request::fromPsrRequest((new ServerRequest(Request::METHOD_POST, '/'))->withParsedBody([
            'zero' => 0,
            'stringZero' => '0',
            'false' => false,
            'float' => 0.0,
            'empty' => '',
            'space' => " \t\n",
            'null' => null,
            'array' => [],
        ]));
        $this->assertTrue($request->filled(['zero', 'stringZero', 'false', 'float']));
        foreach (['empty', 'space', 'null', 'array', 'missing'] as $key) {
            $this->assertFalse($request->filled($key));
        }
    }

    public function testWildcardPathsUseTheSameSlashNormalizationAsExactPaths(): void
    {
        $request = Request::fromPsrRequest(new ServerRequest(Request::METHOD_GET, '/account/settings/'));
        foreach (['account/settings', '/account/settings/', 'account/*', '/account/*/'] as $pattern) {
            $this->assertTrue($request->is($pattern), $pattern);
        }
        $this->assertFalse($request->is('other/*'));
    }

    public function testReplacingARequestClearsPreviouslyValidatedData(): void
    {
        $request = new Request();
        $request->validate(['name' => 'required'], ['name' => 'old']);
        $this->assertSame('old', $request->validated('name'));
        $request->initializeFrom(new Request());
        $this->assertThrows(\RuntimeException::class, fn() => $request->validated());
    }

    public function testFailedRevalidationDoesNotExposePreviouslyValidatedData(): void
    {
        $request = Request::fromPsrRequest(new ServerRequest(Request::METHOD_POST, '/', [Header::ACCEPT => 'application/json']));
        $request->validate(['name' => 'required'], ['name' => 'old']);
        $this->assertSame('old', $request->validated('name'));
        $this->assertThrows(\Spark\Testing\ResponseException::class, fn() => $request->validate(['name' => 'required'], []));
        $this->assertThrows(\RuntimeException::class, fn() => $request->validated());
    }

    public function testPrecognitionFieldListsHandleWhitespaceAndRepeatedHeaders(): void
    {
        Route::post('/validate', fn(Request $request) => $request->validate(['name' => 'required', 'email' => 'required']));
        foreach (['name, email', ['name', 'email']] as $fields) {
            $request = (new ServerRequest(Request::METHOD_POST, '/validate', [
                Header::PRECOGNITION => 'true',
                Header::PRECOGNITION_VALIDATE_ONLY => $fields,
                Header::ACCEPT => 'application/json',
            ]))->withParsedBody(['name' => 'Ada']);
            $response = $this->app->handlePsr($request);
            $this->assertSame(Response::STATUS_UNPROCESSABLE_ENTITY, $response->getStatusCode());
            $this->assertArrayHasKey('email', json_decode((string) $response->getBody(), true)['errors']);
        }
    }
}
