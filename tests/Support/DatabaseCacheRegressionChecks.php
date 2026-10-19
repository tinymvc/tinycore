<?php

use Spark\Cache\Storage\DatabaseStorage;
use Spark\Database\DB;

trait DatabaseCacheRegressionChecks
{
    protected function checkCacheKeyIdentity(array $config = []): void
    {
        $items = ['item' => 1, 'item name' => 2, 'item:' => 3, 'item::' => 4, 'other-item' => 5];
        $stores = [];

        foreach (['identity', 'identity name', 'identity:', 'other-identity'] as $name) {
            $store = new DatabaseStorage($name, $config);
            $store->storeMany($items);
            $stores[] = $store;
        }

        foreach ($stores as $store) {
            foreach ($items as $key => $value) {
                $this->assertSame($value, $store->retrieve($key));
            }

            $this->assertEquals($items, $store->retrieve(array_keys($items)));
            $this->assertEquals($items, $store->retrieveAll());
            $this->assertSame(5, $store->stats()['total_entries']);
        }

        $this->assertSame(12, $stores[1]->increment('item name', 10));
        $this->assertSame(1, $stores[1]->retrieve('item'));
        $this->assertSame(2, $stores[0]->retrieve('item name'));
        $this->assertSame(3, $stores[1]->pull('item:'));
        $this->assertSame(4, $stores[1]->retrieve('item::'));
        $this->assertSame(3, $stores[0]->retrieve('item:'));
        $stores[1]->erase(['item name']);
        $this->assertTrue($stores[1]->has('item'));
        $this->assertFalse($stores[1]->has('item name'));
        $this->assertTrue($stores[1]->add('item name', 20));
        $stores[1]->flush();
        $this->assertSame([], $stores[1]->retrieveAll());
        $this->assertEquals($items, $stores[0]->retrieveAll());
        $this->assertEquals($items, $stores[2]->retrieveAll());
        $this->assertEquals($items, $stores[3]->retrieveAll());
    }

    protected function checkLockKeyAndOwnerIdentity(array $config = []): void
    {
        $store = new DatabaseStorage('lock-identity', $config);

        foreach (['item', 'item name', 'item:', 'item::', 'other-item'] as $key) {
            $this->assertTrue($store->lock($key, 'Owner-A', 60, 0));
            $this->assertTrue($store->ownsLock($key, 'Owner-A'));
            $this->assertSame('Owner-A', $store->getLockInfo($key)['owner']);

            foreach (['owner-a', 'Owner-A '] as $wrongOwner) {
                $this->assertFalse($store->ownsLock($key, $wrongOwner));
                $this->assertFalse($store->extendLock($key, $wrongOwner, 10));
                $this->assertFalse($store->unlock($key, $wrongOwner));
            }

            $this->assertTrue($store->extendLock($key, 'Owner-A', 10));
        }

        $this->assertTrue($store->forceUnlock('item:'));
        $this->assertTrue($store->isLocked('item'));
        $this->assertTrue($store->isLocked('item::'));
        $this->assertSame(4, $store->unlockAll('Owner-A'));
    }

    protected function checkKeyCollation(array $config = []): void
    {
        // The default string columns use NOCASE on SQLite, general_ci on MySQL,
        // and PostgreSQL's default case-sensitive comparison.
        $caseInsensitive = app(DB::class)->getDriver() !== 'pgsql';
        $store = new DatabaseStorage('collation', $config);
        $store->store('Item', 1, '+1 minute');

        $this->assertSame($caseInsensitive, $store->has('item'));
        $this->assertSame($caseInsensitive ? 1 : null, $store->retrieve('item'));
        $this->assertSame($caseInsensitive ? ['Item' => 1] : [], $store->retrieve(['item']));
        $this->assertSame($caseInsensitive, $store->ttl('item') !== null);

        $otherGroup = new DatabaseStorage('COLLATION', $config);
        $this->assertSame($caseInsensitive ? 1 : null, $otherGroup->retrieve('Item'));

        if ($caseInsensitive) {
            $this->assertFalse($store->add('item', 'duplicate'));
            $this->assertSame(2, $store->increment('item'));
            $store->store('item', 3);
            $this->assertSame(1, $store->stats()['total_entries']);
            $this->assertSame(3, $store->pull('item'));
            $this->assertFalse($store->has('Item'));
            $store->store('Item', 4);
            $store->erase(['item']);
            $this->assertFalse($store->has('Item'));
        }

        $this->assertTrue($store->lock('Item', 'Owner-A', 60, 0));
        $this->assertSame($caseInsensitive, $store->isLocked('item'));
        $this->assertSame($caseInsensitive, $store->ownsLock('item', 'Owner-A'));
        $this->assertSame($caseInsensitive ? 'Owner-A' : null, $store->getLockInfo('item')['owner'] ?? null);
        $this->assertFalse($store->unlock('Item', 'owner-a'));
        $this->assertFalse($store->extendLock('Item', 'Owner-A ', 5));

        if ($caseInsensitive) {
            $this->assertFalse($store->lock('item', 'other-owner', 60, 0));
            $this->assertTrue($store->extendLock('item', 'Owner-A', 5));
            $this->assertTrue($store->unlock('item', 'Owner-A'));
        } else {
            $this->assertTrue($store->lock('item', 'other-owner', 60, 0));
            $this->assertTrue($store->unlock('item', 'other-owner'));
            $this->assertTrue($store->unlock('Item', 'Owner-A'));
        }
    }

    protected function checkLockTransactionIsolation(array $config = []): void
    {
        $db = app(DB::class);

        foreach ([[], ['lock_connection' => $db->getDriver()]] as $connection) {
            $store = new DatabaseStorage('transaction', [...$config, ...$connection]);
            $observer = new DatabaseStorage('transaction', [...$config, ...$connection]);

            $this->assertThrows(DomainException::class, function () use ($db, $store, $observer): void {
                $db->transaction(function () use ($store, $observer): void {
                    $this->assertTrue($store->lock('running', 'owner', 60, 0));
                    $this->assertTrue($observer->isLocked('running'));
                    $this->assertFalse($observer->lock('running', 'other-owner', 60, 0));

                    throw new DomainException('Roll back the application transaction.');
                });
            });

            $this->assertTrue($observer->isLocked('running'));
            $this->assertTrue($store->unlock('running', 'owner'));
            $this->assertFalse($observer->isLocked('running'));
        }
    }
}
