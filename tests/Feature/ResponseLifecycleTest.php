<?php

namespace Tests\Feature;

require_once dirname(__DIR__) . '/Support/LifecycleTestCase.php';

use Spark\Facades\Route;
use Spark\Http\{Request, Response};
use Tests\Support\LifecycleTestCase;

final class ResponseLifecycleTest extends LifecycleTestCase
{
    public function testPreparationIsOrderedAndIdempotentForEachResponse(): void
    {
        $calls = [];
        Route::get('/prepare', function () use (&$calls) {
            foreach (['one', 'two'] as $label) {
                $this->app->prepareResponseUsing(function (Response $response) use (&$calls, $label) {
                    $calls[] = $label;
                    $response->write($label);
                });
            }
            $response = new Response();
            $this->assertSame($response, $this->app->prepareResponse($response));
            $this->app->prepareResponse($response);
            return $response;
        });
        $response = $this->get('/prepare')->assertContent('onetwo')->response;
        $this->app->prepareResponse($response);
        $this->assertSame(['one', 'two'], $calls);
    }

    public function testRecursivePreparationDoesNotRepeatLaterCallbacks(): void
    {
        $calls = [];
        Route::get('/recursive', function () use (&$calls) {
            $this->app->prepareResponseUsing(function (Response $response) use (&$calls) {
                $calls[] = 'first';
                $this->app->prepareResponse($response);
            });
            $this->app->prepareResponseUsing(function () use (&$calls) {
                $calls[] = 'second'; });
            return new Response('done');
        });
        $this->get('/recursive')->assertOk();
        $this->assertSame(['first', 'second'], $calls);
    }

    public function testCallbacksAddedDuringPreparationAndDistinctResponses(): void
    {
        $calls = [];
        Route::get('/late', function () use (&$calls) {
            $this->app->prepareResponseUsing(function (Response $response) use (&$calls) {
                $calls[] = 'first';
                $this->app->prepareResponseUsing(function () use (&$calls) {
                    $calls[] = 'late'; });
            });
            $first = new Response('first');
            $this->app->prepareResponse($first);
            return new Response('second');
        });
        $this->get('/late')->assertContent('second');
        $this->assertSame(['first', 'late', 'first', 'late', 'late'], $calls);
    }

    public function testPreparationStateResetsEvenWhenResponseObjectIsReused(): void
    {
        $shared = new Response('shared');
        $calls = [];
        Route::get('/reuse/{id}', function (string $id) use ($shared, &$calls) {
            $this->app->prepareResponseUsing(function () use ($id, &$calls) {
                $calls[] = $id; });
            return $shared;
        });
        Route::get('/clean', fn() => $shared);
        $this->get('/reuse/one')->assertContent('shared');
        $this->get('/reuse/two')->assertContent('shared');
        $this->get('/clean')->assertContent('shared');
        $this->assertSame(['one', 'two'], $calls);
    }

    public function testPreparationFailureDoesNotLeakDeferredWorkIntoNextRequest(): void
    {
        $calls = [];
        $failure = new \RuntimeException('preparation failed');
        Route::get('/bad', function () use (&$calls, $failure) {
            $this->app->defer(function () use (&$calls) {
                $calls[] = 'stale'; });
            $this->app->prepareResponseUsing(fn() => throw $failure);
            return new Response('bad');
        });
        Route::get('/good', fn() => 'good');
        try {
            $this->get('/bad');
            $this->fail('Expected preparation failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame($failure, $e);
        }
        $this->get('/good')->assertContent('good');
        $this->assertSame([], $calls);
    }

    public function testRouteReturnValuesAreNormalized(): void
    {
        Route::get('/null', fn() => null);
        Route::get('/status', fn() => 202);
        Route::get('/stringable', fn() => new class implements \Stringable {
            public function __toString(): string
                {
                    return 'stringable'; } });
        $explicit = new Response('explicit', 201, ['X-Test' => 'kept']);
        Route::get('/explicit', fn() => $explicit);
        $this->get('/null')->assertOk()->assertContent('');
        $this->get('/status')->assertAccepted()->assertContent('');
        $this->get('/stringable')->assertContent('stringable')->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertSame($explicit, $this->get('/explicit')->assertCreated()->assertHeader('X-Test', 'kept')->response);
    }
}
