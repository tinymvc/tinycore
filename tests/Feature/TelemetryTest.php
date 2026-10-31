<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Events;
use Spark\Foundation\Application;
use Spark\Telemetry;

final class TelemetryTest extends FrameworkTestCase
{
    public function test_production_request_model_translation_and_boot_activity(): void
    {
        config(['app.debug' => false]);
        $seen = [];
        Telemetry::listen('*', function ($event, ...$args) use (&$seen) {
            $seen[$event][] = $args;
        });
        $calls = 0;
        \Spark\Facades\Route::get('/metric', function () use (&$calls) {
            $calls++;
            return 'done';
        });
        $this->get('/metric')->assertContent('done');
        $this->get('/metric')->assertOk();
        $this->assertSame(2, $calls, 'Telemetry must not execute callback-valued route metadata');
        $this->assertCount(1, $seen['app:booting']);
        $this->assertCount(1, $seen['app:booted']);
        foreach (['routeMatched', 'routeDispatched', 'middlewaresHandled', 'request.handled', 'terminated'] as $event) {
            $this->assertCount(2, $seen['app:' . $event]);
        }
        $this->assertSame(200, $seen['app:request.handled'][0][0]['response']->getStatusCode());
        $this->tokenUser();
        $this->assertTrue(isset($seen['app:model.action']));
        file_put_contents($this->storagePath . '/en.php', "<?php return ['hello' => 'Hello'];");
        $translator = new \Spark\Translator\Translator($this->storagePath);
        $this->assertSame('Hello', $translator->get('hello'));
        $this->assertSame($this->storagePath . '/en.php', $seen['app:translator.loadedLanguageFile'][0][0]);
    }

    public function test_command_boot_timezone_success_and_failure_are_observed(): void
    {
        config(['app.debug' => false, 'app.timezone' => 'Asia/Dhaka']);
        $seen = [];
        Telemetry::listen('*', function ($event, ...$args) use (&$seen) {
            $seen[$event][] = $args;
        });
        $commands = app(\Spark\Console\Commands::class);
        $commands->addCommand('metric', fn() => 7);
        $commands->addCommand('metric-fail', fn() => throw new RuntimeException('command failed'));
        $original = $GLOBALS['argv'];
        $GLOBALS['argv'] = ['spark', 'metric'];
        ob_start();
        try {
            $this->app->handleCommand();
            $this->assertSame('Asia/Dhaka', date_default_timezone_get());
            $this->assertTrue($this->app->isBooted());
            $this->assertThrows(RuntimeException::class, fn() => app(\Spark\Console\Console::class)->executeCommand('metric-fail', []));
        } finally {
            ob_end_clean();
            $GLOBALS['argv'] = $original;
        }
        $this->assertCount(1, $seen['app:booted']);
        $this->assertSame(7, $seen['app:command.finished'][0][0]['exit_code']);
        $this->assertSame(1, $seen['app:command.finished'][1][0]['exit_code']);
    }

    public function test_queue_observers_cannot_turn_success_into_retry_and_failures_are_reported(): void
    {
        config(['app.debug' => true, 'queue.default' => 'file', 'queue.connections.file.path' => $this->storagePath . '/queue']);
        $queue = new \Spark\Queue\Queue();
        $seen = [];
        Telemetry::listen('job.*', function ($event) use (&$seen) {
            $seen[] = $event;
        });
        Telemetry::listen('job.processed', fn() => throw new RuntimeException('observer failed'), -10);
        $queue->push(new \Spark\Queue\Job('strlen', ['success']));
        ob_start();
        try {
            $queue->work(once: true, sleep: 0);
            $this->assertCount(0, $queue->getJobs());
            $queue->push(new \Spark\Queue\Job(TelemetryFailingJob::class));
            $queue->work(once: true, sleep: 0, tries: 1);
            $this->assertCount(1, $queue->getFailedJobs());
        } finally {
            ob_end_clean();
        }
        $this->assertSame(['app:job.pushed', 'app:job.processing', 'app:job.processed', 'app:job.pushed', 'app:job.processing', 'app:job.failed'], $seen);
    }

    public function test_tracer_can_report_an_exception_before_an_application_exists(): void
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $log = var_export($this->storagePath . '/standalone.log', true);
        $process = \Spark\Console\Process::run([
            PHP_BINARY,
            '-r',
            "require $autoload; (new \\Spark\\Utils\\Tracer($log, false))->handleException(new \\RuntimeException('original failure'));",
        ]);
        $this->assertFalse($process->successful());
        $this->assertStringContainsString('original failure', $process->output());
        $this->assertStringContainsString('original failure', file_get_contents($this->storagePath . '/standalone.log'));
        $this->assertFalse(str_contains($process->errorOutput(), 'not found'));
    }

    public function test_patterns_work_without_debug_and_observers_do_not_instrument_themselves(): void
    {
        config(['app.debug' => false]);
        $seen = [];
        Telemetry::listen('*', function ($event, ...$args) use (&$seen) {
            $seen[] = [$event, $args];
            Telemetry::emit('observer.query', fn() => $this->fail('Recursive payload evaluated'));
        });
        Telemetry::emit('custom.metric', ['value' => 3]);
        $this->assertSame([['app:custom.metric', [['value' => 3]]]], $seen);
        event()->clearListeners('app:*');
        Telemetry::listen('http.*', fn() => null);
        $this->assertTrue(Telemetry::isListening('http.request'));
        $this->assertFalse(Telemetry::isListening('db.queryExecuted'));
    }

    public function test_absent_application_disabled_conditions_and_failed_observers_are_safe(): void
    {
        $app = Application::$app;
        try {
            Application::$app = null;
            $this->assertFalse(Telemetry::isListening('metric'));
            Telemetry::emit('metric', fn() => $this->fail('No application'));
        } finally {
            Application::$app = $app;
        }

        Telemetry::disable();
        try {
            Telemetry::emitIf(fn() => $this->fail('Disabled condition evaluated'), 'metric');
        } finally {
            Telemetry::enable();
        }

        Telemetry::listen('metric', fn() => throw new RuntimeException('observer failed'));
        Telemetry::emit('metric');
        $this->assertTrue(Telemetry::isListening('metric'), 'Emission guard must reset after failures');
        Telemetry::emit('lazy.failure', fn() => throw new RuntimeException('payload failed'));
        $this->assertTrue(Telemetry::isListening('metric'));
    }

    public function test_cache_null_false_batch_and_remember_misses_are_classified(): void
    {
        config(['app.debug' => false]);
        $cache = new \Spark\Cache\Cache('telemetry');
        $cache->store('null', null)->store('false', false);
        $seen = [];
        Telemetry::listen('cache.*', function ($event, $data) use (&$seen) {
            $seen[] = [$event, $data['key']];
        });
        $cache->retrieve('null');
        $cache->retrieve(['null', 'false', 'missing']);
        $cache->remember('new', fn() => 42);
        $this->assertSame([
            ['app:cache.hit', 'null'],
            ['app:cache.hit', 'null'],
            ['app:cache.hit', 'false'],
            ['app:cache.miss', 'missing'],
            ['app:cache.miss', 'new'],
            ['app:cache.written', 'new'],
        ], $seen);
    }

    public function test_legacy_query_and_blade_arguments_and_named_connections(): void
    {
        config(['app.debug' => false, 'database.connections.reporting' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $queries = [];
        Telemetry::listen('db.queryExecuted', function (...$args) use (&$queries) {
            $queries[] = $args;
        });
        $database = \Spark\Database\DB::connection('reporting');
        $database->query('SELECT 1');
        $database->table('sqlite_master')->get();
        $this->assertSame('SELECT 1', $queries[0][0]);
        $this->assertSame('reporting', $queries[0][3]);
        $this->assertSame('reporting', $queries[1][4]);
        $this->assertTrue(is_float($queries[0][1]));

        $views = [];
        Telemetry::listen('bladeTemplateRendered', function (...$args) use (&$views) {
            $views[] = $args;
        });
        file_put_contents($this->storagePath . '/metric.blade.php', '{{ $value }}');
        $blade = app(\Spark\View\Blade::class);
        $blade->setPath($this->storagePath);
        $this->assertSame('test', $blade->render('metric', ['value' => 'test']));
        $this->assertTrue(is_string($views[0][0]));
        $this->assertTrue(in_array('value', $views[0][3], true));
    }

    public function test_logger_and_mail_failures_are_observed_without_changing_results(): void
    {
        $seen = [];
        Telemetry::listen('*', function ($event) use (&$seen) {
            $seen[] = $event;
        });
        (new \Spark\Logger())->info('message');
        $mail = new \Spark\Utils\Mail(['transport' => 'log']);
        $this->assertFalse($mail->send());
        $this->assertTrue(in_array('app:log.logged', $seen, true));
        $this->assertTrue(in_array('app:mail.failed', $seen, true));
        $this->assertFalse(in_array('app:mail.sent', $seen, true));
    }

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

final class TelemetryFailingJob
{
    public function handle(): void
    {
        throw new RuntimeException('job failed');
    }
}
