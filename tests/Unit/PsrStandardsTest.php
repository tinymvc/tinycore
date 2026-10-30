<?php

namespace Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Spark\Cache\Cache;
use Spark\Cache\SimpleCache;
use Spark\Carbon;
use Spark\Container;
use Spark\Events;
use Spark\Facades\Log;
use Spark\Logger;
use Spark\Support\Clock;
use Spark\Utils\Tracer;

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

interface PsrEventMarker
{
}

class PsrTestEvent implements StoppableEventInterface, PsrEventMarker
{
    public bool $stopped = false;

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}

final class PsrStandardsTest extends \FrameworkTestCase
{
    public function test_container_aliases_missing_entries_and_application_identity(): void
    {
        $container = new Container();
        $container->alias('missing.alias', 'missing');
        $this->assertFalse($container->has('missing.alias'));
        $this->assertThrows(NotFoundExceptionInterface::class, fn() => $container->get('missing.alias'));
        $container->bind('broken', 'MissingPsrService');
        $this->assertTrue($container->has('broken'));
        $failure = $this->assertThrows(\Spark\Exceptions\Container\BuildServiceException::class, fn() => $container->get('broken'));
        $this->assertInstanceOf(NotFoundExceptionInterface::class, $failure->getPrevious());
        $container->bind('missing.dependency', fn() => $container->get('missing'));
        $this->assertThrows(\Spark\Exceptions\Container\BuildServiceException::class, fn() => $container->get('missing.dependency'));
        $container->singleton('shared', fn() => new \stdClass());
        $this->assertTrue($container->has('shared'));
        $this->assertSame($container->get('shared'), $container->get('shared'));
        $this->assertSame(app(), app(ContainerInterface::class));
        $this->assertSame(app('events'), app(EventDispatcherInterface::class));
        $this->assertInstanceOf(CacheInterface::class, app(CacheInterface::class));
        $this->assertSame(app(ClockInterface::class), app(Clock::class));
        $this->assertSame(app(LoggerInterface::class), app(Logger::class));
    }

    public function test_clock_timezone_and_carbon_exports_are_independent(): void
    {
        $before = time();
        $now = (new Clock('Asia/Dhaka'))->now();
        $this->assertInstanceOf(DateTimeImmutable::class, app(ClockInterface::class)->now());
        $this->assertSame('Asia/Dhaka', $now->getTimezone()->getName());
        $this->assertTrue($now->getTimestamp() >= $before && $now->getTimestamp() <= time());
        $carbon = new Carbon('2026-01-02 03:04:05', 'UTC');
        $carbon->toDateTime()->modify('+1 year');
        $this->assertSame('2026-01-02 03:04:05', $carbon->toDateTimeImmutable()->format('Y-m-d H:i:s'));
    }

    public function test_logger_interpolates_validates_levels_and_tolerates_context(): void
    {
        $path = $this->storagePath . '/psr.log';
        new Tracer($path, registerHandlers: false);
        $logger = new Logger();
        $logger->info('User {name} logged in', ['name' => 'Ada']);
        $this->assertStringContainsString('local.INFO: User Ada logged in', file_get_contents($path));
        $this->assertThrows(\Psr\Log\InvalidArgumentException::class, fn() => $logger->log('unknown', 'bad'));
        $logger->info('Context {broken}', [
            'broken' => new class implements \Stringable, \JsonSerializable {
            public function __toString(): string
            {
                throw new \RuntimeException('bad string');
            }

            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('bad JSON');
            }
            }
        ]);
        $this->assertStringContainsString('Context {broken}', file_get_contents($path));
    }

    public function test_log_facade_uses_a_replacement_psr_logger_directly(): void
    {
        $logger = new class extends AbstractLogger {
            public array $entries = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = [$level, (string) $message, $context];
            }
        };
        app()->instance(LoggerInterface::class, $logger);
        Log::warning('Something happened', ['code' => 42]);
        $this->assertSame([['warning', 'Something happened', ['code' => 42]]], $logger->entries);
    }

    public function test_object_events_ignore_returns_and_order_parent_interface_listeners(): void
    {
        $events = new Events();
        $order = [];
        $events->listen(PsrTestEvent::class, function () use (&$order) {
            $order[] = 'class';
            return false;
        });
        $events->listen(PsrEventMarker::class, function () use (&$order) {
            $order[] = 'interface';
        }, 10);
        $events->listen(PsrTestEvent::class, function () use (&$order) {
            $order[] = 'last';
        }, -1);
        $event = new PsrTestEvent();
        $this->assertSame($event, $events->dispatch($event));
        $this->assertSame(['interface', 'class', 'last'], $order);
        app()->instance(Events::class, $events);
        $this->assertSame($event, event($event));
    }

    public function test_object_events_stop_and_propagate_the_original_exception(): void
    {
        $events = new Events();
        $calls = 0;
        $events->once(PsrTestEvent::class, function ($event) use (&$calls) {
            $calls++;
            $event->stopped = true;
        });
        $event = new PsrTestEvent();
        $event->stopped = true;
        $events->dispatch($event);
        $this->assertSame(0, $calls);
        $event->stopped = false;
        $events->dispatch($event);
        $this->assertSame(1, $calls);
        $this->assertFalse($events->hasListeners(PsrTestEvent::class));

        app()->setConfig('app.debug', false);
        $failure = new \RuntimeException('listener failed');
        $events->listen(PsrTestEvent::class, fn() => throw $failure);
        $events->listen(PsrTestEvent::class, fn() => $this->fail('Must stop after failure'));
        $caught = $this->assertThrows(\RuntimeException::class, fn() => $events->dispatch(new PsrTestEvent()));
        $this->assertSame($failure, $caught);
    }

    public function test_psr_cache_preserves_native_fluent_api_and_handles_expiration(): void
    {
        $native = Cache::make('psr');
        $cache = new SimpleCache($native);
        $this->assertSame($native, $native->set('app:key', 1, '+1 hour')->delete('app:key'));
        $this->assertTrue($cache->set('value', false, new DateInterval('PT1H')));
        $this->assertSame(false, $cache->get('value', 'missing'));
        $this->assertTrue($native->ttl('value') > 3590);
        foreach ([0, -1, new DateInterval('PT0S')] as $ttl) {
            $cache->set('value', 'old');
            $cache->set('value', 'expired', $ttl);
            $this->assertFalse($cache->has('value'));
            $this->assertSame('missing', $cache->get('value', 'missing'));
        }
        $cache->set('null', null);
        $this->assertNull($cache->get('null'));
        $cache->setMultiple((function () {
            yield 'a' => 1;
            yield 'b' => 2;
        })());
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $cache->getMultiple(['a', 'b', 'c'], 3));
        $this->assertTrue($cache->deleteMultiple(['a', 'b']));
        $this->assertFalse($cache->has('a'));
        $this->assertTrue($cache->delete('missing'));
        Cache::make('separate')->set('keep', 1);
        $cache->clear();
        $this->assertSame(1, Cache::make('separate')->get('keep'));
    }

    public function test_psr_cache_rejects_invalid_keys(): void
    {
        $cache = new SimpleCache(Cache::make('psr-invalid'));
        foreach (['', 'a:b', 'a/b', 'a\\b', '{a}', '(a)', 'a@b'] as $key) {
            foreach (['get', 'has', 'delete'] as $method) {
                $this->assertThrows(InvalidArgumentException::class, fn() => $cache->$method($key));
            }
            $this->assertThrows(InvalidArgumentException::class, fn() => $cache->set($key, 1));
        }
        $this->assertThrows(InvalidArgumentException::class, fn() => $cache->getMultiple([false]));
        $this->assertThrows(InvalidArgumentException::class, fn() => $cache->deleteMultiple([[]]));
        $this->assertThrows(InvalidArgumentException::class, fn() => $cache->setMultiple(['bad:key' => 1]));
    }
}
