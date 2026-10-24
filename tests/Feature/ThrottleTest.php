<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Facades\Route;
use Spark\Foundation\Exceptions\ThrottleException;
use Spark\Foundation\Http\Middlewares\ThrottleIncomingRequests;

final class ThrottleTest extends FrameworkTestCase
{
    public function test_throttle_returns_429_and_releases_its_lock_before_the_controller(): void
    {
        $this->app->withMiddleware(register: ['throttle' => TestRequestThrottle::class]);
        Route::get('/limited', fn() => throw new Spark\Exceptions\NotFoundException())->middleware('throttle:1,1,test');
        $this->getJson('/limited')->assertNotFound();
        $this->getJson('/limited')->assertStatus(429);
        Route::get('/separate', fn() => 'ok')->middleware('throttle:1,1,test');
        $this->get('/separate')->assertOk();
    }

    public function test_parallel_requests_share_one_limit(): void
    {
        if (!function_exists('pcntl_fork')) {
            throw new Spark\Testing\SkippedTest('pcntl is needed for process concurrency tests.');
        }

        $request = request();
        $children = [];
        $start = $this->storagePath . '/start';
        for ($worker = 0; $worker < 6; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    $deadline = microtime(true) + 5;
                    while (!is_file($start) && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    $accepted = 0;
                    $middleware = new TestRequestThrottle();
                    for ($hit = 0; $hit < 5; $hit++) {
                        try {
                            $middleware->handle($request, fn() => null, 7, 1, 'parallel');
                            $accepted++;
                        } catch (ThrottleException) {
                        }
                    }
                    file_put_contents($this->storagePath . '/accepted-' . $worker, (string) $accepted);
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite(STDERR, $exception->getMessage());
                    exit(1);
                }
            }
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork throttle test.');
            }
            $children[] = $pid;
        }
        touch($start);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        $accepted = 0;
        for ($worker = 0; $worker < 6; $worker++) {
            $accepted += (int) file_get_contents($this->storagePath . '/accepted-' . $worker);
        }
        $this->assertSame(7, $accepted);
    }
}

final class TestRequestThrottle extends ThrottleIncomingRequests
{
}
