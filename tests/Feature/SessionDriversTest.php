<?php

require_once dirname(__DIR__) . '/Support/DriverTestCase.php';

final class SessionDriversTest extends DriverTestCase
{
    public function test_session_handlers_and_id_changes(): void
    {
        $this->schema();
        $handlers = [
            new \Spark\Http\Session\Handler\DatabaseHandler(['lifetime' => 1]),
            new \Spark\Http\Session\Handler\FileHandler(['path' => $this->storagePath . '/sessions', 'lifetime' => 1]),
        ];

        if ($this->redisEnabled()) {
            $handlers[] = new \Spark\Http\Session\Handler\RedisHandler($this->redisConfig() + ['lifetime' => 1]);
        }

        foreach ($handlers as $handler) {
            $this->assertTrue($handler->open('', 'release'));
            $this->assertFalse($handler->validateId('unknown'));
            $this->assertSame('', $handler->read('old-id'));
            $this->assertTrue($handler->write('old-id', 'old-data'));
            $handler->close();
            $handler->open('', 'release');
            $this->assertSame('old-data', $handler->read('old-id'));
            $this->assertTrue($handler->write('new-id', 'new-data'));
            $handler->close();
            $handler->open('', 'release');
            $this->assertSame('new-data', $handler->read('new-id'));
            $handler->close();
            $handler->open('', 'release');
            $this->assertSame('old-data', $handler->read('old-id'));
            $handler->destroy('old-id');
            $this->assertFalse($handler->validateId('old-id'));
            $handler->close();
        }

        $file = $this->storagePath . '/sessions/sess_new-id';
        touch($file, time() - 120);
        clearstatcache(true, $file);
        $handler = $handlers[1];
        $this->assertSame('', $handler->read('new-id'));
        $handler->close();
        $this->assertSame(1, $handler->gc(60));
    }

    public function test_named_database_cache_locks_and_sessions_are_isolated(): void
    {
        $this->schema();
        $named = $this->storagePath . '/state.db';
        copy($this->storagePath . '/database.db', $named);
        $pdo = new \PDO('sqlite:' . $named);
        $this->app->mergeConfig(['database' => ['connections' => [
            'state' => ['driver' => 'sqlite', 'file' => $named],
        ]]]);
        $cache = new \Spark\Cache\Storage\DatabaseStorage('state', [
            'connection' => 'state',
            'lock_connection' => 'state',
            'table' => 'main.caches',
            'lock_table' => 'main.locks',
        ]);
        $cache->store('counter', 1);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM caches')->fetchColumn());
        $this->assertSame(0, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM caches')->fetchColumn());
        $this->assertSame(3, $cache->increment('counter', 2));
        $this->assertTrue($cache->lock('shared', 'owner', 30, 0));
        $other = new \Spark\Cache\Storage\DatabaseStorage('other', ['lock_connection' => 'state']);
        $this->assertTrue($other->lock('shared', 'owner', 30, 0));
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM locks')->fetchColumn());
        $this->assertSame(0, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM locks')->fetchColumn());
        $this->assertSame(1, $cache->unlockAll('owner'));
        $this->assertTrue($other->ownsLock('shared', 'owner'));
        $this->assertTrue($other->unlock('shared', 'owner'));
        $this->assertSame(3, $cache->pull('counter'));
        $this->assertFalse($cache->has('counter'));

        $session = new \Spark\Http\Session\Handler\DatabaseHandler([
            'connection' => 'state',
            'table' => 'main.sessions',
            'lifetime' => 1,
        ]);
        $this->assertTrue($session->open('', 'state'));
        $this->assertTrue($session->write('session-id', "binary\0\xff"));
        $session->close();
        $session->open('', 'state');
        $this->assertSame("binary\0\xff", $session->read('session-id'));
        $this->assertTrue($session->updateTimestamp('session-id', 'updated'));
        $this->assertSame('updated', $session->read('session-id'));
        $this->assertTrue($session->validateId('session-id'));
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        $this->assertSame(0, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        $pdo->exec('UPDATE sessions SET last_activity = ' . (time() - 61));
        $this->assertSame('', $session->read('session-id'));
        $this->assertFalse($session->validateId('session-id'));
        $this->assertSame(1, $session->gc(60));
        $session->close();

        foreach (['caches', 'locks', 'sessions'] as $table) {
            $this->assertSame(0, (int) app(\Spark\Database\DB::class)->query("SELECT COUNT(*) FROM $table")->fetchColumn());
        }
    }
}
