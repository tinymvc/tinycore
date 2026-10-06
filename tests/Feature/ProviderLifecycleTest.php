<?php

namespace Tests\Feature;

require_once dirname(__DIR__) . '/Support/LifecycleTestCase.php';

use Spark\Facades\Route;
use Spark\Foundation\Providers\ServiceProvider;
use Spark\Http\{Auth, InputErrors, Request, Response};
use Spark\View\Blade;
use Tests\Support\LifecycleTestCase;

final class ProviderLifecycleTest extends LifecycleTestCase
{
    public function testProvidersRegisterBeforeBootAndBootOnceWithIncomingRequest(): void
    {
        $trace = new \ArrayObject();
        foreach (['one', 'two'] as $name) {
            $this->app->addServiceProvider(
                new class ($trace, $name) extends ServiceProvider {
                public function __construct(private \ArrayObject $trace, private string $name)
                {
                    parent::__construct(); }
                public function register(): void
                {
                    $this->trace[] = $this->name . ':register'; }
                public function boot(): void
                {
                    $this->trace[] = $this->name . ':boot:' . request()->getPath(); }
                }
            );
        }
        Route::get('/boot/{id}', fn() => 'done');
        $this->assertSame(['one:register', 'two:register'], $trace->getArrayCopy());
        $this->get('/boot/first')->assertOk();
        $this->get('/boot/second')->assertOk();
        $this->assertSame(['one:register', 'two:register', 'one:boot:/boot/first', 'two:boot:/boot/first'], $trace->getArrayCopy());
    }

    public function testFailedProviderBootCanBeRetriedWithFreshRequest(): void
    {
        $provider = new class extends ServiceProvider {
            public int $attempts = 0;
            public array $paths = [];
            public function register(): void
            {
            }
            public function boot(): void
            {
                $this->paths[] = request()->getPath();
                if (++$this->attempts === 1) {
                    throw new \DomainException('boot failed');
                }
            }
        };
        $this->app->addServiceProvider($provider);
        Route::get('/boot/{id}', fn() => 'done');
        try {
            $this->get('/boot/first');
            $this->fail('Expected boot failure.');
        } catch (\DomainException $e) {
            $this->assertSame('boot failed', $e->getMessage());
        }
        $this->get('/boot/second')->assertContent('done');
        $this->assertSame(['/boot/first', '/boot/second'], $provider->paths);
    }

    public function testRequestScopedServicesRefreshWhileApplicationSingletonsPersist(): void
    {
        // Sentinel factories exercise reset boundaries without testing auth or view features.
        foreach ([Response::class, InputErrors::class, Auth::class, Blade::class, 'persistent'] as $key) {
            $this->app->singleton($key, fn() => new \stdClass());
        }
        $snapshots = [];
        Route::get('/scope/{id}', function () use (&$snapshots) {
            $snapshot = [];
            foreach ([Request::class, Response::class, InputErrors::class, Auth::class, Blade::class, 'persistent'] as $key) {
                $snapshot[$key] = $this->app->get($key);
            }
            $snapshots[] = $snapshot;
            return 'done';
        });
        $this->get('/scope/first')->assertOk();
        $this->get('/scope/second')->assertOk();
        foreach ([Request::class, Response::class, InputErrors::class, Auth::class, Blade::class] as $key) {
            $this->assertFalse($snapshots[0][$key] === $snapshots[1][$key]);
        }
        $this->assertSame($snapshots[0]['persistent'], $snapshots[1]['persistent']);
    }
}
