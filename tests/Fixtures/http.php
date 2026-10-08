<?php

// Executed only by PHP's built-in HTTP server, never by the test runner.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Spark\Facades\Route;
use Spark\Foundation\Application;
use Spark\Http\{Request, Response};

final class TransportRequest extends Request
{
}

$storage = getenv('TINYCORE_HTTP_TEST_STORAGE');
$app = (new Application($storage))->withApp(config: [
    'app' => [
        'debug' => false,
        'key' => str_repeat('a', 32),
        'storage_dir' => $storage,
        'views_dir' => $storage,
    ]
]);
$app->singleton(Request::class, TransportRequest::class);
if ($_SERVER['REQUEST_URI'] === '/events') {
    $app->setConfig('app.debug', true);
    $app->on('app:terminated', fn() => file_put_contents($storage . '/events.log', "terminated\n", FILE_APPEND | LOCK_EX));
}
$app->withMiddleware(register: [
    'lifecycle' => function (Request $request, Closure $next) use ($app, $storage) {
        $count = 0;
        $app->prepareResponseUsing(function (Response $response) use (&$count) {
            $response->setHeader('X-Prepared', (string) ++$count);
        });
        $app->defer(function (Request $deferredRequest) use ($request, $storage) {
            file_put_contents($storage . '/terminated.jsonl', json_encode([
                'path' => $deferredRequest->getPath(),
                'same' => $request === $deferredRequest,
            ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        });
        $request->routeParams['middleware'] = true;
        return $next($request);
    }
], queue: ['lifecycle']);
Route::any('/input/{id}', fn(Request $request, string $id) => [
    'method' => $request->getMethod(),
    'query' => $request->query(),
    'body' => $request->post(),
    'id' => $id,
    'same' => $request === request(),
    'custom' => $request instanceof TransportRequest,
    'middleware' => $request->getRouteParams()['middleware'],
    'type' => $request->header('Content-Type'),
]);
Route::get('/html', fn() => '<h1>Hello ✓</h1>');
Route::get('/text', fn() => new Response('literal <b>text</b>', headers: ['content-type' => 'text/plain; charset=utf-8']));
Route::get('/json', fn() => ['ok' => true]);
Route::get('/events', fn() => 'events');
Route::get('/vendor-json', fn() => new Response(['ok' => true], headers: ['Content-Type' => 'application/vnd.test+json']));
Route::get('/status/{status}', fn(string $status) => new Response('must disappear', (int) $status));
Route::redirect('/route-redirect', '/html', 301);
Route::get('/redirect', fn() => (new Response())->redirect('/html', 303));
Route::get('/early', function () {
    (new Response('early', 202))->send();
    exit;
});
Route::get('/forbidden', fn() => throw new \Spark\Exceptions\Http\AuthorizationException('private'));
$app->run();
