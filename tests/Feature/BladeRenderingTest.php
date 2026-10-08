<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\View\Blade;

final class BladeRenderingTest extends FrameworkTestCase
{
    private function views(array $templates): Blade
    {
        $path = $this->storagePath . '/views';
        mkdir($path, 0700, true);
        foreach ($templates as $name => $source) {
            file_put_contents($path . '/' . $name . '.blade.php', $source);
        }
        return new Blade($path, $this->storagePath . '/compiled');
    }

    public function test_escaped_and_raw_output_are_distinct(): void
    {
        $blade = $this->views(['page' => '{{ $value }}|{!! $value !!}']);
        $this->assertSame('&lt;b&gt;&quot;&amp;&lt;/b&gt;|<b>"&</b>', $blade->render('page', ['value' => '<b>"&</b>']));
    }

    public function test_layout_sections_and_defaults(): void
    {
        $blade = $this->views(['layout' => '<title>@yield("title", "Default")</title>@yield("body")', 'page' => '@extends("layout")@section("title")Hello@endsection@section("body")World@endsection']);
        $this->assertSame('<title>Hello</title>World', $blade->render('page'));
        $this->assertSame('<title>Default</title>', $blade->render('layout'));
    }

    public function test_include_receives_local_context(): void
    {
        $blade = $this->views(['page' => '@include("partial", ["name" => "Ada"])', 'partial' => 'Hi {{ $name }}']);
        $this->assertSame('Hi Ada', $blade->render('page'));
    }

    public function test_foreach_and_empty_handle_empty_and_nonempty_values(): void
    {
        $blade = $this->views(['page' => '@foreach($items as $item){{ $item }}@endforeach@empty($items)Empty@endempty']);
        $this->assertSame('12', trim($blade->render('page', ['items' => [1, 2]])));
        $this->assertSame('Empty', trim($blade->render('page', ['items' => []])));
    }

    public function test_local_context_overrides_shared_values(): void
    {
        $blade = $this->views(['page' => '{{ $name }}']);
        Blade::share('name', 'Shared');
        $this->assertSame('Local', $blade->render('page', ['name' => 'Local']));
        $this->assertSame('Shared', $blade->render('page'));
    }

    public function test_missing_template_fails_and_next_render_recovers(): void
    {
        $blade = $this->views(['page' => 'Ready']);
        $this->assertFalse($blade->templateExists('missing'));
        $this->assertThrows(\Spark\View\Exceptions\ViewException::class, fn() => $blade->render('missing'));
        $this->assertSame('Ready', $blade->render('page'));
    }

    public function test_template_exception_restores_output_buffer(): void
    {
        $blade = $this->views(['broken' => 'partial @php throw new \\RuntimeException("broken"); @endphp', 'page' => 'Ready']);
        $level = ob_get_level();
        $this->assertThrows(Throwable::class, fn() => $blade->render('broken'));
        $this->assertSame($level, ob_get_level());
        $this->assertSame('Ready', $blade->render('page'));
    }

    public function test_context_cannot_replace_compiled_template_path(): void
    {
        $blade = $this->views(['page' => 'Expected']);
        $other = $this->storagePath . '/other.php';
        file_put_contents($other, 'Wrong template');
        $this->assertSame('Expected', $blade->render('page', ['compiledPath' => $other, 'bufferLevel' => 0]));
    }

    public function test_template_failure_restores_enclosing_sections(): void
    {
        $blade = $this->views(['broken' => '@php throw new \\RuntimeException("broken"); @endphp']);
        $previous = $GLOBALS['sections'] ?? null;
        $GLOBALS['sections'] = ['outer' => 'keep'];
        try {
            $this->assertThrows(RuntimeException::class, fn() => $blade->render('broken'));
            $this->assertSame(['outer' => 'keep'], $GLOBALS['sections']);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['sections']);
            } else {
                $GLOBALS['sections'] = $previous;
            }
        }
    }

    public function test_clear_cache_recompiles_changed_template(): void
    {
        $blade = $this->views(['page' => 'Before']);
        $this->assertSame('Before', $blade->render('page'));
        file_put_contents($blade->getPath() . '/page.blade.php', 'After');
        $blade->clearCache();
        $this->assertSame('After', $blade->render('page'));
    }
}
