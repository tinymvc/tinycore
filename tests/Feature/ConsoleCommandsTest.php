<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class ConsoleCommandsTest extends FrameworkTestCase
{
    private array $arguments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arguments = $GLOBALS['argv'];
        $GLOBALS['argv'] = ['spark', '--no-ansi'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['argv'] = $this->arguments;
        parent::tearDown();
    }

    public function test_vendor_publish_lists_and_selects_resources_and_preserves_local_edits(): void
    {
        $source = $this->storagePath . '/package';
        mkdir("$source/views/parts", 0755, true);
        file_put_contents("$source/views/parts/header.php", 'package header');
        file_put_contents("$source/settings.php", 'package settings');
        $this->app->addServiceProvider(new ConsoleAssetFixtureProvider());
        $this->app->addServiceProvider(new \Spark\Foundation\Providers\ConsoleServiceProvider());
        $this->app->boot();

        $run = function (string ...$args): string {
            $GLOBALS['argv'] = ['spark', 'vendor:publish', ...$args];
            ob_start();
            try {
                $this->app->get(\Spark\Console\Console::class)->run();
                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        $header = root_dir('views/parts/header.php');
        $settings = root_dir('config/fixture.php');
        $this->assertStringContainsString('fixture-views', $run());
        $this->assertFalse(is_file($header));
        $run('--tag=fixture-views');
        $this->assertSame('package header', file_get_contents($header));
        $this->assertFalse(is_file($settings));

        file_put_contents($header, 'my header');
        $run('--provider', ConsoleAssetFixtureProvider::class);
        $this->assertSame('package settings', file_get_contents($settings));
        $this->assertSame('my header', file_get_contents($header));

        // Test single quotes, double quotes, leading slash, and short class name
        unlink($settings);
        $this->assertFalse(is_file($settings));
        $run("--provider='" . ConsoleAssetFixtureProvider::class . "'");
        $this->assertSame('package settings', file_get_contents($settings));

        unlink($settings);
        $this->assertFalse(is_file($settings));
        $run('--provider="' . ConsoleAssetFixtureProvider::class . '"');
        $this->assertSame('package settings', file_get_contents($settings));

        unlink($settings);
        $this->assertFalse(is_file($settings));
        $run('--provider=\\' . ConsoleAssetFixtureProvider::class);
        $this->assertSame('package settings', file_get_contents($settings));

        unlink($settings);
        $this->assertFalse(is_file($settings));
        $run('--provider=ConsoleAssetFixtureProvider');
        $this->assertSame('package settings', file_get_contents($settings));

        // Test unbooted provider discovered and booted on-demand via --provider='Class'
        $unbooted = root_dir('config/unbooted.php');
        $this->assertFalse(is_file($unbooted));
        $run("--provider='" . UnbootedFixtureProvider::class . "'");
        $this->assertSame('package settings', file_get_contents($unbooted));

        // Test Laravel-standard array of tags and tagless publishing
        $multi = root_dir('config/multi.php');
        $untagged = root_dir('config/untagged.php');
        is_file($multi) && unlink($multi);
        is_file($untagged) && unlink($untagged);
        $this->assertFalse(is_file($multi));
        $this->assertFalse(is_file($untagged));

        $run('--tag=multi-one');
        $this->assertSame('package settings', file_get_contents($multi));
        $this->assertFalse(is_file($untagged));

        unlink($multi);
        $run('--tag=multi-two');
        $this->assertSame('package settings', file_get_contents($multi));
        $this->assertFalse(is_file($untagged));

        // Publishing by provider includes untagged resources
        $run('--provider=' . ConsoleAssetFixtureProvider::class);
        $this->assertSame('package settings', file_get_contents($untagged));

        $this->assertThrows(InvalidArgumentException::class, fn() => $run('--tag', '--force'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $run('--provider=""'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $run("--provider='   '"));
        $this->assertSame('my header', file_get_contents($header));
        $run('--all', '--force');
        $this->assertSame('package header', file_get_contents($header));
    }

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
        $lock = $cache->lock('key', 10);
        $this->assertTrue($lock->get());
        $this->assertTrue($lock->release());
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

    public function test_console_help_version_exit_codes_and_primary_commands(): void
    {
        $commands = new \Spark\Console\Commands();
        $ran = false;
        $commands->addCommand('probe', function () use (&$ran): int {
            $ran = true;
            return 3;
        })->description('Probe command')->help('Probe detailed help.');
        $console = new \Spark\Console\Console($commands);

        // --version prints TinyCore version and exits 0.
        $GLOBALS['argv'] = ['spark', '--version'];
        ob_start();
        try {
            $exit = $console->run();
            $versionOutput = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertSame(0, $exit);
        $this->assertStringContainsString(\Spark\Foundation\Application::VERSION, $versionOutput);

        // --help shows command help without executing the command callback.
        $GLOBALS['argv'] = ['spark', 'probe', '--help'];
        ob_start();
        try {
            $exit = $console->run();
            $helpOutput = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertSame(0, $exit);
        $this->assertFalse($ran);
        $this->assertStringContainsString('Probe detailed help.', $helpOutput);

        // Returning an integer exit code propagates cleanly; unknown command exits 1.
        $GLOBALS['argv'] = ['spark', 'probe'];
        ob_start();
        try {
            $exit = $console->run();
        } finally {
            ob_end_clean();
        }
        $this->assertTrue($ran);
        $this->assertSame(3, $exit);

        $GLOBALS['argv'] = ['spark', 'unknown:cmd'];
        ob_start();
        try {
            $exit = $console->run();
        } finally {
            ob_end_clean();
        }
        $this->assertSame(1, $exit);

        // make:migration --table=users and make:rule generate expected files.
        $maker = new \Spark\Foundation\Console\MakeStubCommandsHandler();
        ob_start();
        try {
            $maker->makeMigration(['_args' => ['add_bio_to_users'], 'table' => 'users']);
            $maker->makeRule(['_args' => ['Uppercase']]);
        } finally {
            ob_end_clean();
        }
        $migrations = glob($this->storagePath . '/database/migrations/migration_*_add_bio_to_users.php') ?: [];
        $this->assertCount(1, $migrations);
        $this->assertStringContainsString("Schema::table('users'", file_get_contents($migrations[0]));
        $ruleFile = $this->storagePath . '/app/Http/Rules/UppercaseRule.php';
        $this->assertTrue(is_file($ruleFile));
        $this->assertStringContainsString('implements RuleContract', file_get_contents($ruleFile));

        // config:cache, view:cache, about, cache:prune, cache:clear --namespace, queue:forget, queue:clear --queue, route:list filters, key:generate --force/--show, optimize:clear.
        mkdir($this->storagePath . '/config', 0755, true);
        file_put_contents($this->storagePath . '/config/app.php', "<?php return ['name' => 'TestApp', 'debug' => true];\n");
        file_put_contents($this->storagePath . '/.env', "APP_NAME=TestApp\nAPP_KEY=0123456789abcdef0123456789abcdef\n");
        mkdir($this->storagePath . '/resources/views/pages', 0755, true);
        file_put_contents($this->storagePath . '/resources/views/pages/home.blade.php', '<h1>{{ $title }}</h1>');
        config([
            'app.views_dir' => $this->storagePath . '/resources/views',
            'app.storage_dir' => $this->storagePath . '/storage',
            'cache' => ['default' => 'file', 'connections' => ['file' => ['driver' => 'file', 'path' => $this->storagePath . '/cache-prune']]],
            'queue' => ['default' => 'file', 'connections' => ['file' => ['driver' => 'file', 'path' => $this->storagePath . '/queue-forget']]],
        ]);
        app()->forgetInstance(\Spark\View\Blade::class);

        $cache = app(\Spark\Cache\CacheManager::class)->store();
        $cache->store('stale', 'v', '-1 minute');
        $throttled = app(\Spark\Cache\CacheManager::class)->repository(null, 'throttle');
        $throttled->store('ip', 5);
        $queue = app(\Spark\Queue\QueueManager::class)->connection();
        $job = new \Spark\Queue\Job('strlen', ['abc']);
        $queue->push($job);
        $jobId = (string) $job->getId();
        $queue->push(new \Spark\Queue\Job('strlen', ['mail']), 'emails');

        $handler = new \Spark\Foundation\Console\PrimaryCommandsHandler();
        $router = new \Spark\Http\Routing\Router([
            'users.index' => ['path' => '/users', 'method' => ['GET'], 'callback' => ['UserController', 'index'], 'middleware' => ['auth']],
            'posts.store' => ['path' => '/posts', 'method' => ['POST'], 'callback' => fn() => 'ok', 'middleware' => []],
        ]);

        ob_start();
        try {
            $handler->cacheConfig();
            $handler->cacheViews();
            $handler->about();
            $handler->pruneCache();
            $handler->clearCache(['namespace' => 'throttle']);
            $handler->routeList($router, ['method' => 'get']);
            $forgetFound = $handler->forgetQueueJob(app(\Spark\Queue\QueueManager::class), ['_args' => [$jobId]]);
            $forgetMissing = $handler->forgetQueueJob(app(\Spark\Queue\QueueManager::class), ['_args' => [$jobId]]);
            $handler->clearQueueJobs(app(\Spark\Queue\QueueManager::class), ['queue' => 'emails']);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertTrue(is_file($this->storagePath . '/bootstrap/cache/config.php'));
        $this->assertTrue(is_file($this->storagePath . '/bootstrap/cache/env.php'));
        $this->assertCount(1, glob($this->storagePath . '/storage/framework/views/*.php') ?: []);
        $this->assertFalse($throttled->has('ip'));
        $this->assertCount(0, $queue->getJobs(queue: 'emails'));
        $this->assertStringContainsString('Configuration cached successfully.', $output);
        $this->assertStringContainsString('Compiled 1 view template(s).', $output);
        $this->assertStringContainsString('TinyCore Version', $output);
        $this->assertStringContainsString('Pruned 1 expired cache entries', $output);
        $this->assertStringContainsString('UserController@index', $output);
        $this->assertStringNotContainsString('/posts', $output);
        $this->assertSame(0, $forgetFound);
        $this->assertSame(1, $forgetMissing);

        ob_start();
        try {
            $handler->clearOptimization();
            $handler->generateAppKey(['force' => true]);
            $handler->generateAppKey(['show' => true]);
        } finally {
            ob_end_clean();
        }
        $this->assertFalse(is_file($this->storagePath . '/bootstrap/cache/config.php'));
        $this->assertFalse(is_file($this->storagePath . '/bootstrap/cache/env.php'));
        $this->assertSame([], glob($this->storagePath . '/storage/framework/views/*.php') ?: []);
        $this->assertNotSame("APP_NAME=TestApp\nAPP_KEY=0123456789abcdef0123456789abcdef\n", file_get_contents($this->storagePath . '/.env'));

        // Application::handleCommand() returns the command exit code and `test` / `db:wipe` are not registered.
        $this->app->withConsoleServices()->boot();
        $this->assertFalse(app(\Spark\Console\Commands::class)->hasCommand('test'));
        $this->assertFalse(app(\Spark\Console\Commands::class)->hasCommand('db:wipe'));
        $GLOBALS['argv'] = ['spark', 'about'];
        ob_start();
        try {
            $aboutExit = $this->app->handleCommand();
        } finally {
            ob_end_clean();
        }
        $this->assertSame(0, $aboutExit);
    }
}

final class UnbootedFixtureProvider extends \Spark\Foundation\Providers\ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->publishes([root_dir('package/settings.php') => root_dir('config/unbooted.php')], 'unbooted-config');
    }
}

final class ConsoleAssetFixtureProvider extends \Spark\Foundation\Providers\ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->publishes([root_dir('package/views') => root_dir('views')], 'fixture-views');
        $this->publishes([root_dir('package/settings.php') => root_dir('config/fixture.php')], 'fixture-config');
        $this->publishes([root_dir('package/settings.php') => root_dir('config/multi.php')], ['multi-one', 'multi-two']);
        $this->publishes([root_dir('package/settings.php') => root_dir('config/untagged.php')]);
    }
}
