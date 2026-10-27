<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Cache\Cache;
use Spark\Cache\CacheManager;
use Spark\Facades\Cache as CacheFacade;
use Spark\Foundation\Application;

final class CacheLifecycleTest extends FrameworkTestCase
{
    public function test_factory_helper_facade_and_psr_adapter_share_the_default_cache(): void
    {
        $this->assertSame(cache(), Cache::make());
        $this->assertSame(cache(), CacheFacade::store());
        $this->assertSame(cache(), app('cache')->store());
        $psr = app(Psr\SimpleCache\CacheInterface::class);
        $this->assertTrue($psr->set('shared', 'value'));
        $this->assertSame('value', cache()->get('shared'));
        $psr->set('shared', 'expired', 0);
        $this->assertFalse(cache()->has('shared'));
    }

    public function test_purge_is_scoped_to_its_manager_application(): void
    {
        $original = cache('reports');
        $original->put('value', 'first');
        Application::create($this->storagePath, [
            'app' => ['key' => str_repeat('b', 32)],
            'cache' => ['driver' => 'file', 'connections' => ['file' => ['path' => $this->storagePath . '/second-cache']]],
        ], providers: []);

        try {
            $current = cache('reports');
            $current->put('value', 'second');
            $this->app->get(Spark\Cache\CacheManager::class)->purge();
            $this->assertSame($current, cache('reports'));
            $this->assertSame('second', cache('reports')->get('value'));
            $this->assertFalse($original === $this->app->get(Spark\Cache\CacheManager::class)->make('reports'));
        } finally {
            Application::$app = $this->app;
        }

        $this->assertFalse($original === cache('reports'));
        $this->assertSame('first', cache('reports')->get('value'));
    }

    public function test_retained_repositories_keep_their_backend_when_selecting_namespaces_after_purge(): void
    {
        $this->app->setConfig('cache', [
            'default' => 'primary',
            'stores' => [
                'primary' => ['driver' => 'file', 'path' => $this->storagePath . '/primary'],
                'secondary' => ['driver' => 'file', 'path' => $this->storagePath . '/secondary'],
            ]
        ]);

        foreach (['primary', 'secondary'] as $store) {
            $old = CacheFacade::store($store);
            $old->put('value', 'original');
            $old->withNamespace('reports')->put('value', 'original report');
            $this->app->setConfig("cache.stores.$store.path", $this->storagePath . '/new-' . $store);
            CacheFacade::purge($store);
            $current = CacheFacade::store($store);
            $current->withNamespace('reports')->put('value', 'replacement report');

            $this->assertSame('original report', $old->withNamespace('reports')->get('value'));
            $this->assertSame('original', $old->withNamespace('reports')->withNamespace('default')->get('value'));
            $old->withNamespace('new-namespace')->put('value', 'old backend');
            $this->assertNull($current->withNamespace('new-namespace')->get('value'));
            $this->assertSame('old backend', $old->withNamespace('new-namespace')->get('value'));
            $this->assertSame('replacement report', $current->withNamespace('reports')->get('value'));
            $this->assertSame($current, CacheFacade::store($store));
            $this->assertSame($current->withNamespace('reports'), CacheFacade::store($store)->withNamespace('reports'));
        }
    }

    public function test_retained_repository_can_select_namespaces_after_its_manager_is_released(): void
    {
        $old = cache();
        $old->put('value', 'original');
        $old->withNamespace('reports')->put('value', 'original report');
        $manager = WeakReference::create(app(CacheManager::class));
        $this->app->forgetInstance(CacheManager::class);
        gc_collect_cycles();
        $this->assertNull($manager->get());
        $this->app->setConfig('cache.connections.file.path', $this->storagePath . '/replacement');
        cache('reports')->put('value', 'replacement report');

        $this->assertSame('original', $old->get('value'));
        $this->assertSame('original report', $old->withNamespace('reports')->get('value'));
        $old->withNamespace('new-namespace')->put('value', 'old backend');
        $this->assertSame('old backend', $old->withNamespace('new-namespace')->get('value'));
        $this->assertNull(cache('new-namespace')->get('value'));
        $this->assertSame('replacement report', cache('reports')->get('value'));
    }

    public function test_new_namespaces_keep_the_resolved_store_configuration_until_purge(): void
    {
        $old = cache();
        $old->put('value', 'original');
        $this->app->setConfig('cache.connections.file.path', $this->storagePath . '/replacement');
        $reports = $old->withNamespace('reports');
        $reports->put('value', 'original report');
        $this->assertSame($reports, cache('reports'));
        CacheFacade::purge();

        $this->assertNull(cache()->get('value'));
        $this->assertNull(cache('reports')->get('value'));
        $this->assertSame('original report', $old->withNamespace('reports')->get('value'));
        $this->assertSame('original', $reports->withNamespace('default')->get('value'));
    }
}
