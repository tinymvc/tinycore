<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Events;
use Spark\Foundation\Application;
use Spark\Telemetry;

final class TelemetryTest extends FrameworkTestCase
{
    public function test_telemetry_emit_calls_listener_with_lazy_closure_resolution(): void
    {
        $app = Application::$app;
        /** @var Events $events */
        $events = $app->get(Events::class);

        $captured = [];
        $events->addListener('app:test.metric', function (array $payload) use (&$captured) {
            $captured[] = $payload;
        });

        $closureExecuted = false;
        Telemetry::emit('test.metric', function () use (&$closureExecuted) {
            $closureExecuted = true;
            return ['key' => 'value', 'count' => 10];
        });

        $this->assertTrue($closureExecuted);
        $this->assertCount(1, $captured);
        $this->assertSame('value', $captured[0]['key']);
        $this->assertSame(10, $captured[0]['count']);
    }

    public function test_telemetry_skips_closure_when_nobody_is_listening(): void
    {
        $closureExecuted = false;

        // When debug mode is false and no listener exists for the event
        $originalDebug = config('app.debug');
        config(['app.debug' => false]);

        try {
            Telemetry::emit('unregistered.event', function () use (&$closureExecuted) {
                $closureExecuted = true;
                return ['heavy' => 'calculation'];
            });

            $this->assertFalse($closureExecuted, 'Closure should not be evaluated when no listener exists');
        } finally {
            config(['app.debug' => $originalDebug]);
        }
    }

    public function test_telemetry_disable_and_enable(): void
    {
        $captured = [];
        Telemetry::listen('toggle.test', function ($data) use (&$captured) {
            $captured[] = $data;
        });

        Telemetry::disable();
        $this->assertFalse(Telemetry::isEnabled());

        Telemetry::emit('toggle.test', ['step' => 1]);
        $this->assertEmpty($captured);

        Telemetry::enable();
        $this->assertTrue(Telemetry::isEnabled());

        Telemetry::emit('toggle.test', ['step' => 2]);
        $this->assertCount(1, $captured);
        $this->assertSame(2, $captured[0]['step']);
    }
}
