<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Cache\Cache;
use Spark\Cache\CacheManager;
use Spark\Facades\Cache as CacheFacade;
use Spark\Facades\Lock;

final class CacheManagerTest extends FrameworkTestCase
{
    private function configureStores(): void
    {
        $this->app->setConfig('cache', [
            'default' => 'primary',
            'stores' => [
                'primary' => ['driver' => 'file', 'path' => $this->storagePath . '/primary'],
                'secondary' => ['driver' => 'file', 'path' => $this->storagePath . '/secondary'],
            ]
        ]);
    }

    public function test_stores_and_namespaces_are_distinct_and_all_factories_share_repositories(): void
    {
        $this->configureStores();
        $primary = CacheFacade::store();
        $secondary = CacheFacade::store('secondary');
        $this->assertSame($primary, cache());
        $this->assertSame($primary, app(Cache::class));
        $this->assertSame($primary, CacheFacade::driver('primary'));
        $this->assertSame($secondary, CacheFacade::store('secondary'));
        $primary->put('key', 'primary');
        $secondary->put('key', 'secondary');
        cache('custom')->put('key', 'namespace');
        $this->assertSame('primary', $primary->get('key'));
        $this->assertSame('secondary', $secondary->get('key'));
        $this->assertSame('namespace', Cache::make('custom')->get('key'));
        $this->assertSame(cache('custom'), $primary->withNamespace('custom'));
        $this->assertSame(cache('custom'), Lock::make('custom'));
        $this->assertSame(cache('custom'), lock(name: 'custom'));
        $this->assertSame($secondary->withNamespace('custom'), lock(name: 'custom', store: 'secondary'));
        $secondary->flush();
        $this->assertSame('primary', $primary->get('key'));
        $this->assertSame('namespace', cache('custom')->get('key'));
        $this->assertInstanceOf(CacheManager::class, app('cache'));
    }

    public function test_purge_reloads_only_one_store_and_preserves_inflight_references_and_data(): void
    {
        $this->configureStores();
        $old = cache();
        $namespace = cache('custom');
        $other = CacheFacade::store('secondary');
        $held = $old->lock('in-flight');
        $this->assertTrue($held->get());
        $old->put('kept', 'value');
        CacheFacade::purge();
        $replacement = cache();
        $this->assertFalse($old === $replacement);
        $this->assertFalse($namespace === cache('custom'));
        $this->assertSame($other, CacheFacade::store('secondary'));
        $this->assertSame('value', $replacement->get('kept'));
        $this->assertFalse($replacement->lock('in-flight')->get());
        $this->assertTrue($held->release());
        $this->app->setConfig('cache.stores.primary.path', $this->storagePath . '/new-primary');
        $this->assertSame($replacement, cache());
        CacheFacade::forgetDriver(['primary']);
        $this->assertNull(cache()->get('kept'));
        $this->assertSame('value', $old->get('kept'));
    }

    public function test_default_can_change_without_replacing_existing_repositories(): void
    {
        $this->configureStores();
        $primary = cache();
        $secondary = CacheFacade::store('secondary');
        $this->app->setConfig('cache.default', 'secondary');
        $this->assertSame($secondary, cache());
        $this->assertSame($secondary, app(Cache::class));
        $this->assertSame($primary, CacheFacade::store('primary'));
    }

    public function test_explicit_stores_are_lazy_and_unknown_or_malformed_stores_fail(): void
    {
        $this->configureStores();
        $this->app->setConfig('cache.default', 'missing');
        $this->assertInstanceOf(Cache::class, CacheFacade::store('secondary'));
        $this->assertThrows(InvalidArgumentException::class, fn() => cache());
        $this->assertThrows(InvalidArgumentException::class, fn() => CacheFacade::store('missing'));
        $this->assertThrows(InvalidArgumentException::class, fn() => CacheFacade::store('secondary', 'old-write-value'));
        $this->app->setConfig('cache.stores.broken', false);
        $this->assertThrows(InvalidArgumentException::class, fn() => CacheFacade::store('broken'));
        $this->app->setConfig('cache.stores', false);
        $this->assertThrows(InvalidArgumentException::class, fn() => CacheFacade::store('unknown'));
    }

    public function test_failed_store_resolution_can_be_retried_after_correcting_configuration(): void
    {
        $this->configureStores();
        $this->app->setConfig('cache.stores.primary.driver', 'unsupported');
        $this->assertThrows(InvalidArgumentException::class, fn() => cache());
        $this->app->setConfig('cache.stores.primary.driver', 'file');
        $this->app->setConfig('cache.stores.primary.path', $this->storagePath . '/corrected');
        cache()->put('value', 'corrected');
        $this->assertSame('corrected', cache()->get('value'));
        $this->assertSame(cache('reports'), cache()->withNamespace('reports'));
    }
}
