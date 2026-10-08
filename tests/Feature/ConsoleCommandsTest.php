<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class ConsoleCommandsTest extends FrameworkTestCase
{
    public function test_named_connection_resolution_and_resource_generator(): void
    {
        config([
            'database' => [
                'default' => 'primary',
                'connections' => [
                    'primary' => ['driver' => 'sqlite', 'database' => ':memory:'],
                    'reports' => ['database' => ':memory:'],
                    'pgsql' => [],
                ],
            ]
        ]);
        $primary = app(\Spark\Database\DB::class);
        $this->assertSame($primary, \Spark\Database\DB::connection('primary'));
        $primary->beginTransaction();
        $this->assertTrue(\Spark\Database\DB::connection('primary')->inTransaction());
        $primary->rollBack();
        $reports = \Spark\Database\DB::connection('reports');
        $this->assertSame('sqlite', $reports->getDriver());
        $this->assertSame(1, (int) $reports->query('SELECT 1')->fetchColumn());
        $this->assertSame('pgsql', \Spark\Database\DB::connection('pgsql')->getDriver());

        foreach ([
            ['default' => 'sqlite', 'driver' => 'mysql', 'connections' => ['sqlite' => ['database' => ':memory:']]],
            ['driver' => 'primary', 'connections' => ['primary' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            ['driver' => 'sqlite', 'connections' => ['default' => ['database' => ':memory:']]],
            ['default_connection' => 'primary', 'driver' => 'sqlite', 'connections' => ['primary' => ['database' => ':memory:']]],
        ] as $config) {
            $db = new \Spark\Database\DB($config);
            $this->assertSame('sqlite', $db->getDriver());
            $this->assertSame(1, (int) $db->query('SELECT 1')->fetchColumn());
        }

        config([
            'cache' => ['default' => 'local', 'connections' => ['local' => ['driver' => 'file', 'path' => $this->storagePath . '/alias-cache']]],
            'queue' => ['default' => 'local', 'connections' => ['local' => ['driver' => 'file', 'path' => $this->storagePath . '/alias-queue']]],
            'session' => ['default' => 'local', 'connections' => ['local' => ['driver' => 'file', 'path' => $this->storagePath . '/alias-session']]],
        ]);
        $cache = new \Spark\Cache\Cache('audit');
        $cache->store('key', 'value');
        $this->assertSame('value', $cache->retrieve('key'));
        $lock = new \Spark\Cache\Lock('audit');
        $this->assertTrue($lock->lock('key', 10, 0));
        $this->assertTrue($lock->unlock('key'));
        $queue = new \Spark\Queue\Queue;
        $queue->push(new \Spark\Queue\Job('strlen', ['audit']));
        $this->assertCount(1, $queue->getJobs());
        $resolve = new \ReflectionMethod(\Spark\Http\Session\SessionHandler::class, 'resolveHandler');
        $session = $resolve->invoke(null);
        $this->assertTrue($session instanceof \Spark\Http\Session\Handler\FileHandler);
        $this->assertTrue($session->open('', 'audit'));
        $this->assertTrue($session->write('audit123', 'payload'));
        $session->close();
        $this->assertSame('payload', $session->read('audit123'));
        $session->close();

        foreach (['cache', 'queue', 'session', 'database'] as $name) {
            config([$name => ['default' => 'missing', 'connections' => []]]);
            $caught = null;

            try {
                match ($name) {
                    'cache' => new \Spark\Cache\Cache,
                    'queue' => new \Spark\Queue\Queue,
                    'session' => $resolve->invoke(null),
                    'database' => new \Spark\Database\DB,
                };
            } catch (\Throwable $exception) {
                $caught = $exception;
            }

            $this->assertTrue($caught !== null);
            $this->assertStringContainsString('missing', $caught->getMessage());
        }

        ob_start();

        try {
            (new \Spark\Foundation\Console\MakeStubCommandsHandler)->makeJsonResource(['_args' => ['Api/AuditResource']]);
        } finally {
            ob_end_clean();
        }

        $path = $this->storagePath . '/app/Http/Resources/Api/AuditResource.php';
        $this->assertTrue(is_file($path));
        require $path;
        $resource = new \App\Http\Resources\Api\AuditResource([]);
        $this->assertTrue($resource instanceof \Spark\Http\Resources\JsonResource);
        $this->assertSame([], $resource->toArray());
    }

    public function test_console_output_and_primary_command_lists(): void
    {
        $previous = getenv('NO_COLOR');
        putenv('NO_COLOR=1');
        ob_start();

        try {
            \Spark\Console\Prompt::info("Ready\r\n\033[31mnow\033[0m\033]0;hidden\007");
            \Spark\Console\Prompt::message('Broken', 'error');
            \Spark\Console\Prompt::success('Created');
            \Spark\Console\Prompt::warning('Skipped');
            \Spark\Console\Prompt::status('migration_example.php', 'DONE', 0.012);
            \Spark\Console\Prompt::line("<bold>Help</bold>\n  --option");
            \Spark\Console\Prompt::table(['Value'], [["first\nsecond"], [['nested' => true]]]);

            $handler = new \Spark\Foundation\Console\PrimaryCommandsHandler;
            $handler->routeList(new \Spark\Http\Routing\Router);
            $handler->routeList(new \Spark\Http\Routing\Router([
                'health' => ['path' => '/health', 'method' => ['GET', 'HEAD']],
            ]));

            mkdir($this->storagePath . '/public/uploads', 0700, true);
            $handler->createSymbolicLinkForUploads();
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
            putenv($previous === false ? 'NO_COLOR' : 'NO_COLOR=' . $previous);
        }

        foreach (['INFO  Ready now', 'ERROR  Broken', 'DONE  Created', 'WARN  Skipped', '12.00ms DONE', "Help\n  --option", 'first second', '{"nested":true}', 'No routes are registered.', 'GET|HEAD', '/health', 'Showing 1 routes.', 'path already exists.'] as $expected) {
            $this->assertTrue(str_contains($output, $expected), $expected);
        }

        $this->assertFalse(str_contains($output, "\033"));
        $this->assertFalse(str_contains($output, 'hidden'));
        $this->assertFalse(str_contains($output, 'has been connected'));
    }

    public function test_migration_progress_success_noop_and_failure(): void
    {
        // These assertions exercise plain output; ApplicationTestCase restores the environment.
        putenv('NO_COLOR=1');

        $directory = $this->storagePath . '/migrations';
        mkdir($directory);
        $file = $directory . '/migration_example.php';
        file_put_contents($file, '<?php return new class { public function up(): void {} public function down(): void {} };');
        $migration = new \Spark\Database\Migration($directory);
        ob_start();

        try {
            $migration->up();
            $migration->up();
            $migration->down();
            $migration->down();
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertSame(2, substr_count($output, 'RUNNING'));
        $this->assertSame(2, substr_count($output, 'DONE'));
        $this->assertTrue(str_contains($output, 'Nothing to migrate.'));
        $this->assertTrue(str_contains($output, 'Nothing to roll back.'));
        $this->assertSame(1, preg_match('/migration_example\.php .*\d+\.\d{2}ms DONE/', $output));
        $this->assertFalse(str_contains($output, "\033"));

        file_put_contents($file, '<?php return new class { public function up(): void { throw new \\RuntimeException("Failed deliberately."); } };');
        $caught = null;
        ob_start();

        try {
            $migration->up();
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame('Failed deliberately.', $caught?->getMessage());
        $this->assertTrue(str_contains($output, 'FAIL'));
        $this->assertTrue(str_contains($output, 'ERROR  Migration failed:'));
        $this->assertFalse(str_contains($output, 'DONE'));
        $this->assertSame(0, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }
}
