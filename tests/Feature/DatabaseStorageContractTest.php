<?php

require_once dirname(__DIR__) . '/Support/StorageDriverContract.php';
require_once dirname(__DIR__) . '/Support/DatabaseCacheRegressionChecks.php';

use Spark\Cache\Exceptions\CacheException;
use Spark\Cache\Storage\DatabaseStorage;
use Spark\Database\DB;

final class DatabaseStorageContractTest extends StorageDriverContract
{
    use DatabaseCacheRegressionChecks;

    protected string $driver = 'database';

    public function test_cache_keys_and_groups_preserve_spaces_colons_and_case(): void
    {
        $this->checkCacheKeyIdentity();
    }

    public function test_lock_keys_and_owners_preserve_spaces_colons_and_case(): void
    {
        $this->checkLockKeyAndOwnerIdentity();
    }

    public function test_locks_are_visible_and_survive_application_rollback(): void
    {
        $this->checkLockTransactionIsolation();
    }

    public function test_lock_owner_checks_override_case_insensitive_sqlite_columns(): void
    {
        app(DB::class)->exec('CREATE TABLE loose_locks ("key" TEXT COLLATE BINARY PRIMARY KEY, owner TEXT COLLATE NOCASE NOT NULL, expiration INTEGER NOT NULL)');

        $this->checkLockKeyAndOwnerIdentity(['lock_table' => 'loose_locks']);
    }

    public function test_lock_key_has_no_appended_colon(): void
    {
        $store = new DatabaseStorage();
        $this->assertTrue($store->lock('item:', 'owner', 60, 0));
        $this->assertSame(
            md5('default') . ':item:',
            app(DB::class)->query('SELECT "key" FROM locks')->fetchColumn(),
        );
        $this->assertTrue($store->unlock('item:', 'owner'));

        $key = str_repeat('x', 222);
        $this->assertTrue($store->lock($key, 'owner', 60, 0));
        $this->assertThrows(CacheException::class, fn() => $store->lock($key . 'x', 'owner', 60, 0));
        $this->assertTrue($store->unlock($key, 'owner'));
    }

    public function test_cache_stores_plain_keys_and_default_group(): void
    {
        $cache = new DatabaseStorage();
        $cache->store('user:1', 'cached');
        $pdo = app(DB::class)->getPdo();

        $this->assertSame(
            ['key' => 'user:1', 'group' => 'default'],
            $pdo->query('SELECT "key", "group" FROM caches')->fetch(PDO::FETCH_ASSOC),
        );

        $key = str_repeat('x', 255);
        $cache->store($key, 'long key');
        $this->assertSame('long key', $cache->retrieve($key));
        $this->assertThrows(CacheException::class, fn() => $cache->store($key . 'x', 'too long'));
        $this->assertThrows(PDOException::class, fn() => $pdo->exec(
            'INSERT INTO caches ("key", "group", data) VALUES (\'invalid\', NULL, \'value\')'
        ));
    }

    public function test_cache_crud_targets_group_and_key(): void
    {
        $cache = new DatabaseStorage();
        $other = new DatabaseStorage('other');
        $differentCase = new DatabaseStorage('Default');

        foreach ([$cache, $other, $differentCase] as $store) {
            $store->store('shared', 10);
        }

        $cache->store('shared', 1, '+1 minute');
        $this->assertSame(3, $cache->increment('shared', 2));
        $this->assertTrue($cache->has('shared'));
        $this->assertTrue($cache->ttl('shared') > 0);
        $this->assertNull($other->ttl('shared'));
        $this->assertSame(['shared' => 3], $cache->retrieve(['shared', 'missing']));
        $this->assertSame(['shared' => 3], $cache->retrieveAll());
        $this->assertSame(3, $cache->pull('shared'));
        $this->assertFalse($cache->has('shared'));
        $this->assertNull($cache->retrieve('shared'));
        $this->assertSame('missing', $cache->pull('shared', 'missing'));
        $this->assertFalse($cache->increment('shared'));
        $this->assertSame(10, $other->retrieve('shared'));

        $this->assertTrue($cache->add('shared', 4));
        $this->assertFalse($cache->add('shared', 5));
        $cache->erase(['shared']);
        $this->assertFalse($cache->has('shared'));
        $this->assertSame(10, $other->retrieve('shared'));

        $cache->storeMany(['shared' => 6, 'second' => 7]);
        $cache->storeManyWithExpiry(['shared' => ['value' => 8, 'expire' => '+1 minute']]);
        $this->assertSame(8, $cache->retrieve('shared'));
        $this->assertSame(2, $cache->stats()['total_entries']);
        $this->assertSame(1, $other->stats()['total_entries']);
        $cache->flush();
        $this->assertSame([], $cache->retrieveAll());
        $this->assertSame(['shared' => 10], $other->retrieveAll());
        $this->assertSame(['shared' => 10], $differentCase->retrieveAll());
    }

    public function test_cache_expiration_cleanup_stays_in_group(): void
    {
        $cache = new DatabaseStorage();
        $other = new DatabaseStorage('other');
        $expired = ['has' => 'old', 'many' => 'old', 'shared' => 'old', 'single' => 'old'];

        foreach ([$cache, $other] as $store) {
            $store->storeMany($expired, '-1 minute');
        }

        $this->assertSame(4, $cache->stats()['expired_entries']);
        $this->assertSame($expired, $cache->getExpired());
        $this->assertTrue($cache->add('shared', 'fresh'));
        $this->assertFalse($cache->has('has', true));
        $this->assertNull($cache->retrieve('single', true));
        $this->assertSame([], $cache->retrieve(['many'], true));
        $this->assertSame([], $cache->getExpired());
        $this->assertSame($expired, $other->getExpired());

        $cache->store('shared', 'expired again', '-1 minute');
        $this->assertSame(1, $cache->eraseExpired());
        $cache->store('shared', 'expired again', '-1 minute');
        $this->assertSame([], $cache->retrieveAll(true));
        $cache->store('shared', 'expired again', '-1 minute');
        $cache->optimizeCache();
        $cache->flush();
        $this->assertSame(0, $cache->stats()['total_entries']);
        $this->assertSame($expired, $other->getExpired());
    }

    public function test_bulk_cache_operations_keep_numeric_and_empty_keys_in_group(): void
    {
        $cache = new DatabaseStorage();
        $other = new DatabaseStorage('other');
        $items = ['' => 'empty', '01' => 'leading zero', '1x' => 'suffix'];

        for ($key = 0; $key < 501; $key++) {
            $items[$key] = $key;
        }

        $cache->storeMany($items);
        $other->storeMany($items);
        $this->assertSame(0, $cache->retrieve('0'));
        $this->assertSame('empty', $cache->retrieve(''));
        $this->assertSame(['01' => 'leading zero', 1 => 1], $cache->retrieve(['01', '1']));
        $results = $cache->retrieve(array_keys($items));
        ksort($items, SORT_STRING);
        ksort($results, SORT_STRING);
        $this->assertSame($items, $results);
        $cache->erase(array_keys($items));
        $this->assertSame([], $cache->retrieveAll());
        $this->assertCount(count($items), $other->retrieveAll());
    }
}
