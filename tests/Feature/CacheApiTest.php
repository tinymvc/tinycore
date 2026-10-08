<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class CacheApiTest extends FrameworkTestCase
{
    public function test_cache(): void
    {
        $cache = cache('docs-smoke');
        $cache->store('message', 'hello', '+10 minutes');
        $this->assertSame('hello', $cache->retrieve('message'));
        $this->assertSame('hello', $cache->remember('message', fn () => 'wrong', '+10 minutes'));
        $cache->erase('message');
        $this->assertFalse($cache->has('message'));
    }
}
