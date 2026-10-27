<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class CacheApiTest extends FrameworkTestCase
{
    public function test_cache(): void
    {
        $cache = cache('docs-smoke');
        $cache->store('message', 'hello', '+10 minutes');
        $this->assertSame('hello', $cache->retrieve('message'));
        $this->assertSame('hello', $cache->remember('message', fn() => 'wrong', '+10 minutes'));
        $cache->erase('message');
        $this->assertFalse($cache->has('message'));
    }

    public function test_cache_helper_array_writes_preserve_values_and_namespace_resolution(): void
    {
        $namespace = cache(name: 'custom');
        $namespace->put('var', 'namespaced');
        $stored = cache(['var' => 'value', 'null' => null, 'false' => false, 'nested' => ['a' => 1]], '10 minutes');
        $this->assertSame(cache(), $stored);
        $this->assertSame('value', cache()->get('var'));
        $this->assertTrue(cache()->has('null'));
        $this->assertNull(cache()->get('null'));
        $this->assertFalse(cache()->get('false'));
        $this->assertSame(['a' => 1], cache()->get('nested'));
        $this->assertTrue(cache()->ttl('var') >= 598 && cache()->ttl('var') <= 600);
        $this->assertSame($namespace, cache('custom'));
        $this->assertSame('namespaced', $namespace->get('var'));
        $this->assertSame(cache(), cache([]));
        $this->assertSame('value', cache()->get('var'));
    }

    public function test_cache_helper_array_writes_use_native_expiration_rules(): void
    {
        cache(['forever' => 'value']);
        $this->assertSame('value', cache()->get('forever'));
        $this->assertNull(cache()->ttl('forever'));
        foreach ([60, new DateInterval('PT1M'), new DateTimeImmutable('+1 minute'), \Spark\Carbon::now()->addMinutes(1)] as $expire) {
            cache(['timed' => 'value'], $expire);
            $this->assertSame('value', cache()->get('timed'));
            $this->assertTrue(cache()->ttl('timed') >= 58 && cache()->ttl('timed') <= 60);
        }
        foreach ([0, -1, new DateTimeImmutable('-1 minute')] as $expire) {
            cache(['timed' => 'gone'], $expire);
            $this->assertFalse(cache()->has('timed'));
        }
        $this->assertThrows(Exception::class, fn() => cache(['invalid' => 1], 'not a valid date'));
        $this->assertFalse(cache()->has('invalid'));
    }
}
