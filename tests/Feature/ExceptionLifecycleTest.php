<?php

namespace Tests\Feature;

require_once dirname(__DIR__) . '/Support/LifecycleTestCase.php';

use Spark\Facades\Route;
use Spark\Foundation\Exceptions\{InvalidCsrfTokenException, ThrottleException, ValidationException};
use Spark\Exceptions\Http\AuthorizationException;
use Spark\Exceptions\NotFoundException;
use Spark\Support\ItemNotFoundException;
use Spark\Http\Response;
use Tests\Support\LifecycleTestCase;

final class ExceptionLifecycleTest extends LifecycleTestCase
{
    public function testKnownExceptionsBecomePreparedJsonResponsesAndTerminate(): void
    {
        $terminated = [];
        $cases = [
            [NotFoundException::class, 404, 'Not found'],
            [ItemNotFoundException::class, 404, 'Item not found'],
            [AuthorizationException::class, 403, 'Forbidden'],
            [InvalidCsrfTokenException::class, 419, 'Page Expired'],
            [ThrottleException::class, 429, 'Too many requests'],
        ];
        foreach ($cases as $index => [$class, $status, $message]) {
            Route::get('/error/' . $index, function () use ($class, $index, &$terminated) {
                $this->app->prepareResponseUsing(fn(Response $response) => $response->setHeader('X-Error', 'prepared'));
                $this->app->defer(function () use ($index, &$terminated) {
                    $terminated[] = $index;
                });
                throw new $class('private detail');
            });
            $this->getJson('/error/' . $index)->assertStatus($status)->assertExactJson(['message' => $message, 'code' => $status])
                ->assertHeader('X-Error', 'prepared')->assertDontSee('private detail');
        }
        $this->assertSame(array_keys($cases), $terminated);
    }

    public function testMissingRoutesNegotiateJsonAndHtml(): void
    {
        $this->getJson('/missing')->assertNotFound()->assertJsonPath('message', 'Route not found');
        $this->get('/missing')->assertNotFound()->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertSee('Route not found');
    }

    public function testFallbackReceivesCurrentRequestAndCompletesLifecycle(): void
    {
        $terminated = false;
        Route::fallback(function (\Spark\Http\Request $request) use (&$terminated) {
            $this->assertSame(request(), $request);
            $this->app->prepareResponseUsing(fn(Response $response) => $response->setHeader('X-Fallback', 'prepared'));
            $this->app->defer(function () use (&$terminated) {
                $terminated = true;
            });
            return new Response($request->getPath(), 404);
        });
        $this->get('/fallback')->assertNotFound()->assertContent('/fallback')->assertHeader('X-Fallback', 'prepared');
        $this->assertTrue($terminated);
    }

    public function testPhpErrorsReachRunnerWithoutBeingTurnedIntoSuccessfulResponses(): void
    {
        Route::get('/php-error', fn() => throw new \TypeError('route type error'));
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('route type error');
        $this->get('/php-error');
    }

    public function testValidationExceptionProducesJson422OrRedirectWithFlash(): void
    {
        Route::post('/validate', fn() => throw ValidationException::withMessages(['name' => ['Name required.']], ['name' => '']));
        $this->postJson('/validate')->assertUnprocessable()->assertJsonValidationErrors('name')->assertJsonPath('message', 'Validation failed.');
        $this->post('/validate', headers: ['Referer' => 'http://localhost/form'])->assertRedirect('http://localhost/form');
        $this->assertSame(['name' => ['Name required.']], session()->getFlash('errors'));
        $this->assertSame(['name' => ''], session()->getFlash('input'));
    }

    public function testCustomHandlerAcceptsSubclassAndItsEarlySendIsCaptured(): void
    {
        $this->app->withExceptions([
            \RuntimeException::class => function ($e) {
                (new Response($e->getMessage(), 409))->send();
            }
        ]);
        Route::get('/custom', fn() => throw new \OverflowException('custom'));
        $this->get('/custom')->assertStatus(409)->assertContent('custom');
    }

    public function testUnhandledFailureRestoresGlobalsAndDropsDeferredWork(): void
    {
        $before = [$_SERVER, $_GET, $_POST, $_FILES, $_REQUEST];
        $ran = false;
        $failure = new \LogicException('unexpected');
        Route::post('/failure', function () use (&$ran, $failure) {
            $this->app->defer(function () use (&$ran) {
                $ran = true;
            });
            throw $failure;
        });
        Route::get('/healthy', fn() => 'healthy');
        try {
            $this->post('/failure?query=value', ['body' => 'value']);
            $this->fail('Expected original failure.');
        } catch (\LogicException $e) {
            $this->assertSame($failure, $e);
        }
        $this->assertSame($before, [$_SERVER, $_GET, $_POST, $_FILES, $_REQUEST]);
        $this->get('/healthy')->assertContent('healthy');
        $this->assertFalse($ran);
    }

    public function testHandlerReturningNoResponseDoesNotSwallowOriginalFailure(): void
    {
        $this->app->withExceptions([\DomainException::class => fn() => null]);
        Route::get('/unhandled', fn() => throw new \DomainException('original'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('original');
        $this->get('/unhandled');
    }

    public function testHandlerFailureReachesRunnerAndApplicationRecovers(): void
    {
        $this->app->withExceptions([\DomainException::class => fn() => throw new \LogicException('handler failed')]);
        Route::get('/handler-failure', fn() => throw new \DomainException('original'));
        Route::get('/healthy', fn() => 'healthy');
        try {
            $this->get('/handler-failure');
            $this->fail('Expected handler failure.');
        } catch (\LogicException $e) {
            $this->assertSame('handler failed', $e->getMessage());
        }
        $this->get('/healthy')->assertOk();
    }
}
