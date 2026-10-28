<?php

// Executed only by PHP's built-in HTTP server, never by the test runner.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Spark\Facades\Route;
use Spark\Foundation\Application;
use Spark\Http\{Request, Response};

final class TransportRequest extends Request
{
}

final class TransportCsrfProtection extends Spark\Foundation\Http\Middlewares\CsrfProtection
{
}

$storage = getenv('TINYCORE_HTTP_TEST_STORAGE');
$app = (new Application($storage))->withApp(config: [
    'app' => [
        'debug' => false,
        'key' => str_repeat('a', 32),
        'storage_dir' => $storage,
        'temp_dir' => "$storage/temp",
        'views_dir' => $storage,
    ]
]);
$app->singleton(Request::class, TransportRequest::class);
if (in_array($_SERVER['REQUEST_URI'], ['/csrf/trusted', '/remember/trusted'], true)) {
    $app->setConfig('app.trusted_proxies', ['127.0.0.1']);
}
$app->withMiddleware(register: ['csrf' => TransportCsrfProtection::class]);
Route::get('/csrf/{kind}', fn() => 'ok')->middleware('csrf');
Route::get('/remember/{kind}', function () {
    $user = new class extends \Spark\Database\Model {};
    $user->id = 7;
    $user->password = 'test-password-fingerprint';
    (new \Spark\Http\Auth($user::class, ['channels' => ['session']]))->login($user, true);
    return 'remembered';
});
Route::get('/session/{action}', function (string $action) {
    $session = session();
    if ($action === 'write') {
        $session->put('value', 0);
    } elseif ($action === 'invalidate') {
        $session->invalidate();
    }
    return ['id' => $session->id(), 'value' => $session->get('value')];
});
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
Route::post('/validate-upload/{kind}', function (Request $request, string $kind) {
    $request = $kind === 'psr' ? Request::fromPsrRequest($request->toPsrRequest()) : $request;
    $request->validate(['file' => 'required|file|min:0.01|max:1|between:0.01,1|mimes:text/plain']);
    return 'valid upload';
});
Route::post('/upload/{kind}', function (Request $request, string $kind) use ($storage) {
    $file = match ($kind) {
        'psr' => $request->toPsrRequest()->getUploadedFiles()['file'],
        'columns' => $_FILES['file'],
        'tree' => $_FILES,
        'field' => 'file',
        default => $request->file('file'),
    };
    $uploader = new Spark\Storage\Uploader(uploadDir: $storage . '/uploads', extensions: ['txt']);
    $path = $uploader->upload($file);
    $contents = fn(string $path) => file_get_contents($storage . '/uploads/' . $path);
    return ['contents' => is_array($path) ? array_map($contents, $path) : $contents($path)];
});
Route::post('/psr-request', function (Request $request) {
    $psr = $request->toPsrRequest();
    return ['raw' => (string) $psr->getBody(), 'parsed' => $psr->getParsedBody()];
});
Route::get('/psr-response', fn() => new Nyholm\Psr7\Response(200, ['Set-Cookie' => ['first=1; Path=/', 'second=2; Path=/']], 'cookies'));
Route::get('/download/{kind}', function (string $kind) use ($storage) {
    $content = str_repeat('download-content', 8192);
    if ($kind === 'file') {
        file_put_contents($storage . '/download.txt', $content);
        (new Response())->file($storage . '/download.txt');
    } else {
        (new Response())->download($content, 'download.txt');
    }
});
Route::get('/pipe', fn() => new Nyholm\Psr7\Response(200, [], Nyholm\Psr7\Stream::create(popen('printf streamed-pipe', 'r'))));
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
if ($_SERVER['REQUEST_URI'] === '/psr-entry') {
    $message = $app->handlePsr(new Nyholm\Psr7\ServerRequest('GET', 'http://localhost/early'));
    header('Content-Type: application/json');
    echo json_encode(['status' => $message->getStatusCode(), 'body' => (string) $message->getBody(), 'prepared' => $message->getHeaderLine('X-Prepared')]);
    $app->terminate();
    return;
}
$app->run();
