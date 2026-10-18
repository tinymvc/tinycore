<?php

use Spark\Cache\Storage\DatabaseStorage;
use Spark\Database\DB;

trait DatabaseCacheRegressionChecks
{
    protected function checkCacheKeyIdentity(array $config = []): void
    {
        $items = ['item' => 1, 'item ' => 2, 'item:' => 3, 'item::' => 4, 'Item' => 5];
        $stores = [];

        foreach (['identity', 'identity ', 'identity:', 'Identity'] as $name) {
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

        $this->assertSame(12, $stores[1]->increment('item ', 10));
        $this->assertSame(1, $stores[1]->retrieve('item'));
        $this->assertSame(2, $stores[0]->retrieve('item '));
        $this->assertSame(3, $stores[1]->pull('item:'));
        $this->assertSame(4, $stores[1]->retrieve('item::'));
        $this->assertSame(3, $stores[0]->retrieve('item:'));
        $stores[1]->erase(['item ']);
        $this->assertTrue($stores[1]->has('item'));
        $this->assertFalse($stores[1]->has('item '));
        $this->assertTrue($stores[1]->add('item ', 20));
        $stores[1]->flush();
        $this->assertSame([], $stores[1]->retrieveAll());
        $this->assertEquals($items, $stores[0]->retrieveAll());
        $this->assertEquals($items, $stores[2]->retrieveAll());
        $this->assertEquals($items, $stores[3]->retrieveAll());
    }

    protected function checkLockKeyAndOwnerIdentity(array $config = []): void
    {
        $store = new DatabaseStorage('lock-identity', $config);

        foreach (['item', 'item ', 'item:', 'item::', 'Item'] as $key) {
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
