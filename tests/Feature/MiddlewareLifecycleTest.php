<?php

namespace Tests\Feature;

require_once dirname(__DIR__) . '/Support/LifecycleTestCase.php';

use Spark\Facades\Route;
use Spark\Http\{Request, Response};
use Tests\Support\LifecycleTestCase;

final class MiddlewareLifecycleTest extends LifecycleTestCase
{
    public function testGlobalAndRouteMiddlewareWrapControllerInOrder(): void
    {
        $trace = [];
        $middleware = function (Request $request, \Closure $next, string $name) use (&$trace) {
            $trace[] = "$name:before:" . $request->getRouteParams()['id'];
            $result = $next($request);
            $trace[] = "$name:after";
            return $result;
        };
        $this->app->withMiddleware(register: ['trace' => $middleware], queue: ['trace:global']);
        Route::get('/order/{id}', function (Request $request, string $id) use (&$trace) {
            $trace[] = 'controller:' . $id;
            $this->assertSame(request(), $request);
            return new Response('done');
        })->middleware('trace:route');
        $this->get('/order/42')->assertContent('done');
        $this->assertSame(['global:before:42', 'route:before:42', 'controller:42', 'route:after', 'global:after'], $trace);
    }

    public function testExclusionsAndDuplicateMiddlewareDoNotExecuteTwice(): void
    {
        $calls = [];
        $this->app->withMiddleware(register: [
            'keep' => function ($request, $next) use (&$calls) {
                $calls[] = 'keep';
                return $next($request); },
            'omit' => function ($request, $next) use (&$calls) {
                $calls[] = 'omit';
                return $next($request); },
        ], queue: ['keep', 'omit']);
        Route::get('/filtered', fn() => 'done')->middleware(['keep', 'omit'])->withoutMiddleware('omit');
        $this->get('/filtered')->assertOk();
        $this->assertSame(['keep'], $calls);
    }

    public function testShortCircuitSkipsInnerMiddlewareAndController(): void
    {
        $calls = [];
        $this->app->withMiddleware(register: [
            'outer' => function ($request, $next) use (&$calls) {
                $calls[] = 'before';
                $response = $next($request);
                $calls[] = 'after';
                return $response; },
            'stop' => fn() => new Response('blocked', 401),
            'inner' => function () {
                $this->fail('Inner middleware must not run.'); },
        ], queue: ['outer', 'stop', 'inner']);
        Route::get('/blocked', function () {
            $this->fail('Controller must not run.'); });
        $this->get('/blocked')->assertUnauthorized()->assertContent('blocked');
        $this->assertSame(['before', 'after'], $calls);
    }

    public function testRawMiddlewareResponsesAreNormalized(): void
    {
        $this->app->withMiddleware(register: ['stop' => fn() => ['blocked' => true]], queue: ['stop']);
        Route::get('/raw', fn() => 'unreachable');
        $this->get('/raw')->assertExactJson(['blocked' => true])->assertHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function testMiddlewareExceptionsUnwindFinallyAndReachApplicationHandler(): void
    {
        $finally = false;
        $this->app->withMiddleware(register: [
            'outer' => function ($request, $next) use (&$finally) {
                try {
                    return $next($request); } finally {
                    $finally = true; } },
            'throws' => fn() => throw new \DomainException('middleware failed'),
        ], queue: ['outer', 'throws']);
        $this->app->withExceptions([\DomainException::class => fn($e) => new Response($e->getMessage(), 409)]);
        Route::get('/failure', fn() => 'unreachable');
        $this->get('/failure')->assertStatus(409)->assertContent('middleware failed');
        $this->assertTrue($finally);
    }

    public function testEarlySendSkipsControllerAndStillPreparesAndTerminates(): void
    {
        $trace = [];
        $this->app->withMiddleware(register: [
            'early' => function () use (&$trace) {
                $this->app->prepareResponseUsing(function (Response $response) use (&$trace) {
                    $trace[] = 'prepare';
                    $response->setHeader('X-Prepared', 'yes');
                });
                $this->app->defer(function () use (&$trace) {
                    $trace[] = 'terminate'; });
                (new Response('early', 202))->send();
                $this->fail('Testing must capture the early response.');
            }
        ], queue: ['early']);
        Route::get('/early', function () {
            $this->fail('Controller must not run.'); });
        $this->get('/early')->assertAccepted()->assertContent('early')->assertHeader('X-Prepared', 'yes');
        $this->assertSame(['prepare', 'terminate'], $trace);
    }
}
