<?php

namespace Tests\Feature;

require_once dirname(__DIR__) . '/Support/LifecycleTestCase.php';

use Spark\Facades\Route;
use Spark\Http\Request;
use Tests\Support\LifecycleTestCase;

final class TerminationLifecycleTest extends LifecycleTestCase
{
    public function testLifecycleEventsAreOrderedAndTerminationIsOncePerRequest(): void
    {
        $this->app->setConfig('app.debug', true);
        $trace = [];
        foreach (['booting', 'booted', 'routeMatched', 'routeDispatched', 'middlewaresHandled', 'terminated'] as $event) {
            $this->app->on('app:' . $event, function () use ($event, &$trace) {
                $trace[] = $event; });
        }
        Route::get('/events', fn() => 'done');
        $this->get('/events')->assertOk();
        $this->app->terminate();
        $this->get('/events')->assertOk();
        $this->app->terminate();
        $this->assertSame([
            'booting',
            'booted',
            'routeMatched',
            'routeDispatched',
            'middlewaresHandled',
            'terminated',
            'routeMatched',
            'routeDispatched',
            'middlewaresHandled',
            'terminated',
        ], $trace);
    }

    public function testTerminationListenerFailureDoesNotPreventDeferredWork(): void
    {
        $this->app->setConfig('app.debug', true);
        $trace = [];
        $this->app->on('app:terminated', fn() => throw new \DomainException('listener failed'));
        $this->app->withExceptions([\DomainException::class => function ($e) use (&$trace) {
            $trace[] = $e->getMessage(); }]);
        Route::get('/events', function () use (&$trace) {
            $this->app->defer(function () use (&$trace) {
                $trace[] = 'deferred'; });
            return 'done';
        });
        $this->get('/events')->assertContent('done');
        $this->assertSame(['listener failed', 'deferred'], $trace);
    }

    public function testNewWorkQueuedAfterTerminationCanStillBeDrained(): void
    {
        $calls = [];
        Route::get('/done', fn() => 'done');
        $this->get('/done')->assertOk();
        $this->app->defer(function () use (&$calls) {
            $calls[] = 'later'; });
        $this->app->terminate();
        $this->app->terminate();
        $this->assertSame(['later'], $calls);
    }

    public function testDirectHandleWaitsForExplicitTerminationAndCallbacksRunOnce(): void
    {
        $calls = [];
        Route::get('/', function () use (&$calls) {
            $this->app->defer(function (Request $request) use (&$calls) {
                $this->assertSame(request(), $request);
                $calls[] = $request->getPath();
            });
            return 'done';
        });
        $this->assertSame('done', $this->app->handle(new Request())->getContent());
        $this->assertSame([], $calls);
        $this->app->terminate();
        $this->app->terminate();
        $this->assertSame(['/'], $calls);
    }

    public function testNestedCallbacksAndRecursiveTerminationKeepRegistrationOrder(): void
    {
        $calls = [];
        Route::get('/nested', function () use (&$calls) {
            $this->app->defer(function () use (&$calls) {
                $calls[] = 'one';
                $this->app->defer(function () use (&$calls) {
                    $calls[] = 'three'; });
                $this->app->terminate();
            });
            $this->app->defer(function () use (&$calls) {
                $calls[] = 'two'; });
            return 'done';
        });
        $this->get('/nested')->assertOk();
        $this->assertSame(['one', 'two', 'three'], $calls);
    }

    public function testDeferredFailureIsReportedAndLaterCallbacksStillRun(): void
    {
        $calls = [];
        $this->app->withExceptions([\DomainException::class => function ($e) use (&$calls) {
            $calls[] = $e->getMessage(); }]);
        Route::get('/deferred-error', function () use (&$calls) {
            $this->app->defer(fn() => throw new \DomainException('reported'));
            $this->app->defer(function () use (&$calls) {
                $calls[] = 'continued'; });
            return 'response unchanged';
        });
        $this->get('/deferred-error')->assertOk()->assertContent('response unchanged');
        $this->assertSame(['reported', 'continued'], $calls);
    }

    public function testSequentialRequestsTerminateWithTheirOwnRequest(): void
    {
        $requests = [];
        Route::get('/defer/{id}', function () use (&$requests) {
            $this->app->defer(function (Request $request) use (&$requests) {
                $requests[] = $request; });
            return 'done';
        });
        $this->get('/defer/first')->assertOk();
        $this->get('/defer/second')->assertOk();
        $this->assertSame('first', $requests[0]->getRouteParams()['id']);
        $this->assertSame('second', $requests[1]->getRouteParams()['id']);
        $this->assertFalse($requests[0] === $requests[1]);
    }
}
