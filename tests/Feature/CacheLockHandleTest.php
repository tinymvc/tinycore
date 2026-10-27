<?php

require_once dirname(__DIR__) . '/Support/DriverTestCase.php';

use Spark\Cache\Exceptions\LockTimeoutException;
use Spark\Database\DB;
use Spark\Facades\Cache;
use Spark\Foundation\Application;

final class CacheLockHandleTest extends DriverTestCase
{
    public function test_file_lock_get_and_block_observe_their_deadlines_when_the_shard_guard_is_busy(): void
    {
        $path = $this->storagePath . '/guard-contention';
        $cache = new \Spark\Cache\Cache('guard-test', ['driver' => 'file', 'path' => $path]);
        $lock = $cache->lock('resource', 10);
        $this->assertTrue($lock->get());
        $this->assertTrue($lock->release());
        $guard = glob($path . '/' . md5('guard-test') . '.lock.d/*/.guard')[0];
        $stream = fopen($guard, 'c+');
        flock($stream, LOCK_EX);
        try {
            $start = hrtime(true);
            $this->assertFalse($lock->get());
            $this->assertTrue((hrtime(true) - $start) / 1e9 < 0.5);
            $start = hrtime(true);
            $this->assertThrows(LockTimeoutException::class, fn() => $lock->block(1));
            $elapsed = (hrtime(true) - $start) / 1e9;
            $this->assertTrue($elapsed >= 0.9 && $elapsed < 2.0);
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
        $this->assertTrue($lock->get());
        $this->assertTrue($lock->release());
    }

    private function stores(): array
    {
        $this->schema();
        $stores = [
            'sql' => ['driver' => 'database'],
            'disk' => ['driver' => 'file', 'path' => $this->storagePath . '/locks'],
        ];
        if ($this->redisEnabled()) {
            $stores['remote'] = ['driver' => 'redis', ...$this->redisConfig()];
        }
        $this->app->setConfig('cache', ['default' => 'disk', 'stores' => $stores]);
        return array_keys($stores);
    }

    private function databaseStore(): DB
    {
        $this->app->setConfig('database.connections.cache_db', [
            'driver' => 'sqlite',
            'file' => $this->storagePath . '/shared-cache.db',
            'prefix' => 'shared_',
        ]);
        $db = DB::connection('cache_db');
        $db->exec('CREATE TABLE shared_caches ("group" TEXT NOT NULL, "key" TEXT NOT NULL, data TEXT NOT NULL, expiration INTEGER NOT NULL DEFAULT 0, PRIMARY KEY ("group", "key"))');
        $db->exec('CREATE TABLE shared_locks ("key" TEXT PRIMARY KEY, owner TEXT NOT NULL, expiration INTEGER NOT NULL)');
        $this->app->setConfig('cache', [
            'default' => 'shared',
            'stores' => [
                'shared' => ['driver' => 'database', 'connection' => 'cache_db'],
            ]
        ]);
        return $db;
    }

    public function test_database_store_locks_fall_back_to_its_connection_without_default_database_tables(): void
    {
        $db = $this->databaseStore();
        foreach ([[], ['lock_connection' => null]] as $options) {
            $this->app->setConfig('cache.stores.shared', [
                'driver' => 'database',
                'connection' => 'cache_db',
                ...$options,
            ]);
            Cache::purge();
            $cache = Cache::store('shared');
            $cache->put('value', 'shared cache');
            $this->assertSame('shared cache', $cache->get('value'));
            $lock = $cache->lock('resource', 30);
            try {
                $this->assertTrue($lock->get());
                $this->assertSame($lock->owner(), $db->query('SELECT owner FROM shared_locks')->fetchColumn());
                $this->assertFalse($cache->lock('resource')->get());
            } finally {
                $lock->release();
            }
            $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM shared_locks')->fetchColumn());
        }
    }

    public function test_applications_with_different_default_databases_contend_on_the_shared_cache_lock(): void
    {
        $this->schema();
        $shared = $this->databaseStore();
        cache()->put('value', 'shared cache');
        $first = cache()->lock('resource', 30);
        $second = null;
        try {
            $this->assertTrue($first->get());
            Application::create($this->storagePath, [
                'app' => ['storage_dir' => $this->storagePath],
                'database' => [
                    'driver' => 'sqlite',
                    'file' => $this->storagePath . '/second-app.db',
                    'connections' => $this->app->getConfig('database.connections'),
                ],
                'cache' => $this->app->getConfig('cache'),
            ], providers: []);
            $this->schema();
            $this->assertSame('shared cache', cache()->get('value'));
            $second = cache()->lock('resource', 30);
            $this->assertFalse($second->get());
            $this->assertSame(0, (int) app(DB::class)->query('SELECT COUNT(*) FROM locks')->fetchColumn());
            $this->assertSame($first->owner(), $shared->query('SELECT owner FROM shared_locks')->fetchColumn());
            $this->assertTrue($first->release());
            $this->assertTrue($second->get());
        } finally {
            $second?->release();
            $first->release();
            Application::$app = $this->app;
        }
        $this->assertSame(0, (int) app(DB::class)->query('SELECT COUNT(*) FROM locks')->fetchColumn());
    }

    public function test_explicit_lock_connection_overrides_cache_connection_and_commits_outside_app_transactions(): void
    {
        $shared = $this->databaseStore();
        $this->app->setConfig('cache.stores.shared.lock_connection', 'sqlite');
        $this->app->setConfig('cache.stores.shared.lock_table', 'custom_locks');
        $pdo = app(DB::class)->getPdo();
        $pdo->exec('CREATE TABLE custom_locks ("key" TEXT PRIMARY KEY, owner TEXT NOT NULL, expiration INTEGER NOT NULL)');
        $observer = new PDO('sqlite:' . $this->storagePath . '/database.db');
        $lock = cache()->lock('resource', 30);
        $pdo->beginTransaction();
        try {
            $this->assertTrue($lock->get());
            $this->assertSame($lock->owner(), $observer->query('SELECT owner FROM custom_locks')->fetchColumn());
            $this->assertSame(0, (int) $shared->query('SELECT COUNT(*) FROM shared_locks')->fetchColumn());
            $pdo->rollBack();
            $this->assertTrue($lock->isOwnedByCurrentProcess());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $lock->release();
        }
        $this->assertSame(0, (int) $observer->query('SELECT COUNT(*) FROM custom_locks')->fetchColumn());
    }

    public function test_handles_contend_release_by_owner_and_restore_without_destructor_release(): void
    {
        foreach ($this->stores() as $store) {
            $repository = Cache::store($store)->withNamespace('custom');
            $first = $repository->lock('resource', 30);
            $second = $repository->lock('resource', 30);
            $this->assertFalse($first->owner() === $second->owner());
            $this->assertTrue($first->get());
            $this->assertFalse($second->get());
            $this->assertFalse($second->release());
            $this->assertTrue($first->isOwnedByCurrentProcess());
            $owner = $first->owner();
            unset($first);
            $this->assertFalse($second->get());
            $this->assertTrue($repository->restoreLock('resource', $owner)->release());
            $this->assertTrue($second->get());
            $repository->flush();
            $this->assertTrue($second->isOwnedByCurrentProcess());
            $this->assertTrue($repository->lock('resource')->forceRelease());
            $this->assertFalse($second->isOwnedByCurrentProcess());
            $this->assertTrue($repository->lock('resource')->get(fn() => true));
        }
    }

    public function test_callbacks_release_on_success_or_exception_and_wait_timeout_is_explicit(): void
    {
        foreach ($this->stores() as $store) {
            $cache = Cache::store($store);
            $first = $cache->lock('callback');
            $this->assertSame('result', $first->block(0, fn() => $first->isOwnedByCurrentProcess() ? 'result' : 'wrong'));
            $this->assertFalse($first->isOwnedByCurrentProcess());
            $this->assertThrows(RuntimeException::class, fn() => $first->get(function () {
                throw new RuntimeException('callback failure');
            }));
            $this->assertFalse($first->isOwnedByCurrentProcess());
            $this->assertTrue($first->get());
            $second = $cache->lock('callback');
            $called = false;
            $this->assertFalse($second->get(function () use (&$called) {
                $called = true;
            }));
            $this->assertFalse($called);
            $this->assertThrows(LockTimeoutException::class, fn() => $second->block(0));
            $this->assertTrue($first->release());
        }
    }

    public function test_expired_owner_cannot_release_the_next_owner_on_any_store(): void
    {
        foreach ($this->stores() as $store) {
            $cache = Cache::store($store);
            $expired = $cache->lock('lease', 1);
            $this->assertTrue($expired->get());
            usleep(1100000);
            $this->assertFalse($expired->isLocked());
            $this->assertNull($expired->info());
            $this->assertFalse($expired->extend(30));
            $replacement = $cache->lock('lease', 30);
            $this->assertTrue($replacement->get());
            $replacementInfo = $replacement->info();
            $this->assertFalse($expired->extend(30));
            $this->assertSame($replacementInfo, $replacement->info());
            $this->assertFalse($expired->release());
            $this->assertTrue($replacement->isOwnedByCurrentProcess());
            $this->assertTrue($replacement->release());
        }
    }

    public function test_lock_inspection_and_extension_preserve_ownership_on_any_store(): void
    {
        foreach ($this->stores() as $store) {
            $cache = Cache::store($store);
            $owner = $cache->lock('inspect', 30);
            $other = $cache->lock('inspect', 30);
            $this->assertFalse($owner->isLocked());
            $this->assertNull($owner->info());
            $this->assertFalse($owner->extend(10));
            $this->assertTrue($owner->get());
            try {
                $before = $owner->info();
                $this->assertSame($owner->owner(), $before['owner']);
                $this->assertTrue($other->isLocked());
                $this->assertSame($before, $other->info());
                $this->assertFalse($other->extend(10));
                $this->assertSame($before, $owner->info());
                $this->assertThrows(InvalidArgumentException::class, fn() => $owner->extend(0));
                $this->assertThrows(InvalidArgumentException::class, fn() => $owner->extend(-1));
                $this->assertTrue($owner->extend(10));
                $this->assertSame($before['expire_at'] + 10, $owner->info()['expire_at']);
                $this->assertFalse($other->get());
            } finally {
                $owner->release();
            }
            $this->assertNull($other->info());
            $this->assertFalse($other->isLocked());
        }
    }

    public function test_helper_facades_namespaces_and_selected_stores_use_the_same_lock_domain(): void
    {
        $this->stores();
        $first = lock('0', 30, name: 'custom');
        $second = Cache::make('custom')->lock('0', 30);
        $otherNamespace = Cache::lock('0', 30);
        $otherStore = lock('0', 30, name: 'custom', store: 'sql');
        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
        $this->assertTrue($otherNamespace->get());
        $this->assertTrue($otherStore->get());
        $first->release();
        $otherNamespace->release();
        $otherStore->release();
    }

    public function test_another_process_can_release_a_transferred_owner_while_a_waiter_blocks(): void
    {
        $this->stores();
        $cache = Cache::store('disk')->withNamespace('transfer');
        $original = $cache->lock('resource', 30);
        $this->assertTrue($original->get());
        $script = $this->storagePath . '/release-owner.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';' . <<<'PHP'
            Spark\Foundation\Application::create(path: $argv[1], config: [
                'app' => ['storage_dir' => $argv[1]],
                'cache' => ['default' => 'disk', 'stores' => ['disk' => ['driver' => 'file', 'path' => $argv[1] . '/locks']]],
            ], providers: []);
            echo "ready\n";
            usleep(200000);
            exit(cache('transfer')->restoreLock('resource', $argv[2])->release() ? 0 : 1);
            PHP);
        $process = proc_open(
            [PHP_BINARY, $script, $this->storagePath, $original->owner()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes
        );
        $this->assertTrue(is_resource($process));
        fclose($pipes[0]);
        $replacement = $cache->lock('resource', 30);
        try {
            $this->assertSame("ready\n", fgets($pipes[1]));
            $this->assertTrue($replacement->block(2));
            $this->assertFalse($original->release());
            $this->assertTrue($replacement->isOwnedByCurrentProcess());
        } finally {
            $replacement->release();
            fclose($pipes[1]);
            $status = proc_close($process);
        }
        $this->assertSame(0, $status);
    }

    public function test_invalid_leases_names_owners_and_waits_are_rejected(): void
    {
        $this->stores();
        foreach ([0, -1] as $seconds) {
            $this->assertThrows(InvalidArgumentException::class, fn() => Cache::lock('key', $seconds));
        }
        $this->assertThrows(InvalidArgumentException::class, fn() => Cache::lock(''));
        $this->assertThrows(InvalidArgumentException::class, fn() => Cache::restoreLock('key', ''));
        $this->assertThrows(InvalidArgumentException::class, fn() => Cache::lock('key')->block(-1));
    }
}
