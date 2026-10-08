<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Events;

final class EventDispatcherTest extends FrameworkTestCase
{
    public function test_priority_and_response_order(): void
    {
        $events = new Events();
        $events->addListener('run', fn ($n) => $n + 1, 0);
        $events->addListener('run', fn ($n) => $n + 2, 10);
        $this->assertSame([5, 4], $events->dispatchWithResponse('run', 3));
    }

    public function test_false_response_stops_propagation(): void
    {
        $events = new Events();
        $events->addListener('run', fn () => false);
        $events->addListener('run', fn () => $this->fail('Listener should not run'));
        $this->assertSame([false], $events->dispatchWithResponse('run'));
    }

    public function test_once_listener_is_removed_before_recursive_dispatch(): void
    {
        $events = new Events();
        $calls = 0;
        $events->once('run', function () use ($events, &$calls) {
            $calls++;
            $events->dispatch('run');
        });
        $events->dispatch('run');
        $events->dispatch('run');
        $this->assertSame(1, $calls);
        $this->assertFalse($events->hasListeners('run'));
    }

    public function test_until_returns_first_non_null_including_false(): void
    {
        $events = new Events();
        $events->addListener('run', fn () => null);
        $events->addListener('run', fn () => false);
        $events->addListener('run', fn () => $this->fail('Listener should not run'));
        $this->assertFalse($events->until('run'));
        $this->assertNull($events->until('missing'));
    }

    public function test_removing_listener_preserves_other_events(): void
    {
        $events = new Events();
        $callback = fn () => 'ok';
        $events->subscribe(['a' => $callback, 'b' => $callback]);
        $events->removeListener('a', $callback);
        $this->assertFalse($events->hasEvent('a'));
        $this->assertSame(['ok'], $events->dispatchWithResponse('b'));
        $events->clearListeners('b');
        $this->assertSame(0, $events->countListeners());
    }

    public function test_conditional_dispatch(): void
    {
        $events = new Events();
        $calls = 0;
        $events->addListener('run', function () use (&$calls) { $calls++; });
        $events->dispatchIf('run', false);
        $events->dispatchUnless('run', true);
        $this->assertSame(0, $calls);
        $events->dispatchIf('run', true);
        $events->dispatchUnless('run', false);
        $this->assertSame(2, $calls);
    }

    public function test_invalid_listener_rejected_at_registration(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => (new Events())->addListener('run', 'NoSuchListenerClass'));
    }
}
