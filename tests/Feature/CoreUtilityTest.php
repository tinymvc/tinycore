<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\{DotEnv, Pipeline, Translator, Url};

final class CoreUtilityTest extends FrameworkTestCase
{
    public function test_config_discovery_preserves_nested_structure(): void
    {
        mkdir($this->storagePath . '/config/services', 0700, true);
        file_put_contents($this->storagePath . '/config/app.php', '<?php return ["name" => "Test"];');
        file_put_contents($this->storagePath . '/config/services/mail.php', '<?php return ["enabled" => false];');
        $config = DotEnv::discoverConfig($this->storagePath . '/config', $this->storagePath . '/compiled.php', $this->storagePath . '/.env', false);
        $this->assertSame(['app' => ['name' => 'Test'], 'services' => ['mail' => ['enabled' => false]]], $config);
        $this->assertFalse(is_file($this->storagePath . '/compiled.php'));
    }

    public function test_config_cache_detects_removed_files(): void
    {
        mkdir($this->storagePath . '/config');
        $source = $this->storagePath . '/config/app.php';
        file_put_contents($source, '<?php return ["name" => "Test"];');
        $load = fn () => DotEnv::discoverConfig($this->storagePath . '/config', $this->storagePath . '/compiled.php', $this->storagePath . '/.env');
        $this->assertSame(['app' => ['name' => 'Test']], $load());
        unlink($source);
        $this->assertSame([], $load());
    }

    public function test_pipeline_order_and_middleware_wrap_destination(): void
    {
        $log = [];
        $result = Pipeline::make(2)->through(fn ($n, $next) => $next($n + 1), fn ($n, $next) => $next($n * 3))
            ->middleware(function ($value, $next) use (&$log) {
                $log[] = 'before';
                $result = $next($value);
                $log[] = 'after';
                return $result;
            })->thenReturn();
        $this->assertSame(9, $result);
        $this->assertSame(['before', 'after'], $log);
    }

    public function test_pipeline_short_circuit_skips_downstream(): void
    {
        $result = Pipeline::make(2)->through(fn ($n, $next) => 'stopped', fn () => $this->fail('Downstream executed'))->thenReturn();
        $this->assertSame('stopped', $result);
    }

    public function test_pipeline_propagates_unhandled_errors(): void
    {
        $this->assertThrows(RuntimeException::class, fn () => Pipeline::make(1)->through(fn () => throw new RuntimeException('pipe failed'))->thenReturn());
    }

    public function test_translator_fallback_replacements_and_pluralization(): void
    {
        $translator = new Translator($this->storagePath . '/missing.php');
        $translator->setTranslatedTexts(['hello' => 'Hello :name', 'apples' => ['%s apple', '%s apples']]);
        $this->assertSame('unknown', $translator->translate('unknown'));
        $this->assertSame('Hello Ada', $translator->translate('hello', ['name' => 'Ada']));
        $this->assertSame('1 apple', $translator->translate('apples', 1));
        $this->assertSame('3 apples', $translator->translate('apples', 3));
    }

    public function test_url_query_mutations_are_immutable_and_preserve_fragment(): void
    {
        $url = new Url('https://example.test/items?a=1#top');
        $changed = $url->withQuery(['a' => null, 'b' => 0]);
        $this->assertSame('https://example.test/items?a=1#top', (string) $url);
        $this->assertSame('https://example.test/items?b=0#top', (string) $changed);
    }

    public function test_url_preserves_nondefault_port_for_scheme(): void
    {
        $this->assertSame('https://example.test:80/new', (string) (new Url('https://example.test:80/old'))->withPath('/new'));
        $this->assertSame('http://example.test:443/new', (string) (new Url('http://example.test:443/old'))->withPath('/new'));
        $this->assertSame('https://example.test/new', (string) (new Url('https://example.test:443/old'))->withPath('/new'));
    }
}
