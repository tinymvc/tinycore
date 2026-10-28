<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Facades\Queue;
use Spark\Queue\Job;
use Spark\Queue\PendingDispatch;
use Spark\Queue\Queue as Connection;
use Spark\Queue\QueueManager;
use Spark\Foundation\Console\PrimaryCommandsHandler;

final class QueueManagerTest extends FrameworkTestCase
{
    private function configureConnections(): QueueManager
    {
        $this->app->setConfig('queue', [
            'default' => 'primary',
            'connections' => [
                'primary' => ['driver' => 'file', 'path' => $this->storagePath . '/primary'],
                'secondary' => ['driver' => 'file', 'path' => $this->storagePath . '/secondary'],
            ]
        ]);
        return app(QueueManager::class);
    }

    public function test_connections_are_shared_isolated_and_purge_keeps_persisted_jobs(): void
    {
        $manager = $this->configureConnections();
        $primary = Queue::connection();
        $secondary = Queue::connection('secondary');
        $this->assertSame($manager, app('queue'));
        $this->assertSame($primary, app(Connection::class));
        $this->assertSame($primary, app(Spark\Queue\Contracts\QueueContract::class));
        $this->assertSame('secondary', $secondary->getConnectionName());
        Queue::push(new Job('strlen', ['primary']));
        $secondary->push(new Job('strlen', ['secondary']));
        Queue::purge();
        $this->assertFalse($primary === Queue::connection());
        $this->assertSame($secondary, Queue::connection('secondary'));
        $this->assertCount(1, Queue::connection()->getJobs());
        $primary->clearAllJobs();
        $this->assertCount(0, Queue::connection()->getJobs());
        $this->assertCount(1, $secondary->getJobs());
        $this->app->setConfig('queue.default', 'secondary');
        $this->assertSame($secondary, app(Connection::class));
    }

    public function test_job_instance_dispatch_and_deduplication_use_the_selected_connection(): void
    {
        $this->configureConnections();
        (new Job('strlen', ['default']))->dispatch();
        (new Job('strlen', ['named']))->onConnection('secondary')->dispatch('emails');
        $once = (new Job('strlen', ['once']))->onConnection('secondary');
        $once->dispatchOnce('emails');
        $once->dispatchOnce('emails');
        $this->assertCount(1, Queue::getJobs());
        $this->assertCount(2, Queue::connection('secondary')->getJobs('emails'));
    }

    public function test_named_dispatch_and_worker_use_selected_connection_and_queue(): void
    {
        $manager = $this->configureConnections();
        $output = $this->storagePath . '/named-job-result';
        NamedConnectionJob::dispatch($output)->onConnection('secondary')->onQueue('emails');
        $this->assertCount(0, Queue::getJobs());
        $this->assertCount(1, Queue::connection('secondary')->getJobs('emails'));
        Queue::connection('secondary')->push(new Job('strlen', ['other']), 'other');
        ob_start();
        try {
            (new PrimaryCommandsHandler())->runQueueJobs($manager, [
                'connection' => 'secondary',
                'queue' => 'emails',
                'once' => true,
                'sleep' => 0,
                'timeout' => 0,
            ]);
        } finally {
            ob_end_clean();
        }
        $this->assertSame('done', file_get_contents($output));
        $this->assertCount(0, Queue::connection('secondary')->getJobs('emails'));
        $this->assertCount(1, Queue::connection('secondary')->getJobs('other'));
    }

    public function test_once_dispatch_is_scoped_to_connection_and_explicit_send_is_not_repeated(): void
    {
        $this->configureConnections();
        $job = new Job('strlen', ['same']);
        foreach (['primary', 'secondary', 'secondary'] as $connection) {
            $pending = (new PendingDispatch($job, true))->onConnection($connection)->onQueue('emails');
            $pending->send();
            $pending->send();
            unset($pending);
        }
        $this->assertCount(1, Queue::connection('primary')->getJobs('emails'));
        $this->assertCount(1, Queue::connection('secondary')->getJobs('emails'));
    }

    public function test_explicit_connection_does_not_initialize_a_broken_default_and_errors_are_not_retried(): void
    {
        $this->configureConnections();
        $this->app->setConfig('queue.default', 'missing');
        $this->assertInstanceOf(Connection::class, Queue::connection('secondary'));
        $pending = (new PendingDispatch(new Job('strlen', ['value'])))->onConnection('unknown');
        $this->assertThrows(InvalidArgumentException::class, fn() => $pending->send());
        unset($pending);
        $this->assertThrows(InvalidArgumentException::class, fn() => Queue::connection());
        // A restart is global to the app's workers and only needs the cache transport.
        Queue::restart();
        $this->assertTrue(is_string(cache('queue:workers')->get('restart')));
        $this->app->setConfig('queue.connections.broken', false);
        $this->assertThrows(InvalidArgumentException::class, fn() => Queue::connection('broken'));
    }

    public function test_with_queue_registration_is_lazy_reentrant_and_survives_purge_without_duplicates(): void
    {
        $this->configureConnections();
        $calls = 0;
        $this->app->withQueue([new Job('strlen', ['registered'])], function ($queue) use (&$calls) {
            $calls++;
            $this->assertSame($queue, Queue::connection());
        });
        Queue::connection('secondary');
        $this->assertSame(0, $calls);
        $this->assertCount(1, Queue::getJobs());
        Queue::connection();
        $this->assertSame(1, $calls);
        Queue::purge();
        $this->assertCount(1, Queue::getJobs());
        $this->assertSame(2, $calls);
    }

    public function test_failed_initializer_is_not_retained_as_a_completed_connection(): void
    {
        $this->configureConnections();
        $calls = 0;
        $this->app->withQueue(then: function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('Initialization failed');
            }
        });
        $this->assertThrows(RuntimeException::class, fn() => Queue::connection());
        $this->assertInstanceOf(Connection::class, Queue::connection());
        $this->assertSame(2, $calls);
    }

    public function test_initializer_can_purge_its_connection_without_corrupting_resolution(): void
    {
        $this->configureConnections();
        $this->app->withQueue(then: fn() => Queue::purge());
        $first = Queue::connection();
        $this->assertInstanceOf(Connection::class, $first);
        $this->assertFalse($first === Queue::connection());
    }

    public function test_management_commands_target_only_the_selected_connection(): void
    {
        $manager = $this->configureConnections();
        Queue::push(new Job('strlen', ['primary']));
        Queue::connection('secondary')->push(new Job('strlen', ['secondary']));
        ob_start();
        try {
            $handler = new PrimaryCommandsHandler();
            $handler->listQueueJobs($manager, ['connection' => 'secondary']);
            $handler->listFailedQueueJobs($manager, ['connection' => 'secondary']);
            $handler->retryFailedQueueJobs($manager, ['connection' => 'secondary']);
            $handler->clearFailedQueueJobs($manager, ['connection' => 'secondary']);
            $handler->clearQueueJobs($manager, ['connection' => 'secondary']);
        } finally {
            ob_end_clean();
        }
        $this->assertCount(1, Queue::getJobs());
        $this->assertCount(0, Queue::connection('secondary')->getJobs());
    }
}

final class NamedConnectionJob implements Spark\Queue\Contracts\JobInterface
{
    use Spark\Queue\Dispatchable;

    public function __construct(private string $path)
    {
    }

    public function handle(): void
    {
        file_put_contents($this->path, 'done');
    }
}
