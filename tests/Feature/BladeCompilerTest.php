<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Facades\Route;
use Spark\Support\HtmlString;
use Spark\View\Blade;

final class BladeCompilerTest extends FrameworkTestCase
{
    private function views(array $templates): Blade
    {
        $path = $this->storagePath . '/views';

        foreach ($templates as $name => $source) {
            $file = $path . '/' . $name . '.blade.php';

            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0700, true);
            }

            file_put_contents($file, $source);
        }

        return new Blade($path, $this->storagePath . '/compiled');
    }

    private function assertRenders(Blade $blade, string $expected, array $context = []): void
    {
        $level = ob_get_level();

        $this->assertSame($expected, $blade->render('page', $context));
        $this->assertSame($expected, $blade->render('page', $context), 'Cached rendering must match.');
        $this->assertSame($level, ob_get_level());
    }

    public function test_route_echo_in_component_attribute(): void
    {
        Route::get('/register', fn() => 'register')->name('register');

        $blade = $this->views([
            'page' => '<x-link href="{{ route(\'register\') }}" variant="primary" x-navigate>Join</x-link>',
            'components/link' => '@props(["variant"])<a {{ $attributes }} data-variant="{{ $variant }}">{{ $slot }}</a>',
        ]);

        $this->assertRenders($blade, '<a href="' . e(route('register')) . '" x-navigate data-variant="primary">Join</a>');
    }

    public function test_component_attribute_echoes_escape_and_raw_echoes_remain_raw(): void
    {
        $blade = $this->views([
            'page' => '<x-label value="{{ $value }}" raw="{!! $value !!}" />',
            'components/label' => '@props(["value", "raw"]){!! $value !!}|{!! $raw !!}',
        ]);

        $this->assertRenders($blade, '&lt;b&gt;&quot;&amp;&lt;/b&gt;|<b>"&</b>', ['value' => '<b>"&</b>']);
    }

    public function test_mixed_attribute_echoes_preserve_literals_quotes_and_backslashes(): void
    {
        $blade = $this->views([
            'page' => <<<'BLADE'
<x-label value="C:\temp\O'Reilly: {{ strtoupper($name) }} / {!! '<b>' . $id . '</b>' !!}" />
BLADE,
            'components/label' => '@props(["value"]){!! $value !!}',
        ]);

        $this->assertRenders($blade, "C:\\temp\\O'Reilly: ADA / <b>7</b>", ['name' => 'Ada', 'id' => 7]);
    }

    public function test_bound_attributes_preserve_php_types_and_variable_shorthand(): void
    {
        $blade = $this->views([
            'page' => '<x-label :$name :items="[1, 2]" :enabled="false" :count="0" />',
            'components/label' => '@props(["name", "items", "enabled", "count"]){{ $name }}:{{ count($items) }}:{{ $enabled ? "yes" : "no" }}:{{ $count }}',
        ]);

        $this->assertRenders($blade, 'Ada:2:no:0', ['name' => 'Ada']);
    }

    public function test_self_closing_tag_handles_greater_than_and_tag_delimiters_in_quotes(): void
    {
        $blade = $this->views([
            'page' => '<x-label :value="$item->name" text="a > b /> c" />',
            'components/label' => '@props(["value", "text"]){{ $value }}|{{ $text }}',
        ]);

        $this->assertRenders($blade, 'Ada|a &gt; b /&gt; c', ['item' => (object) ['name' => 'Ada']]);
    }

    public function test_single_quoted_attributes_and_php_strings_with_parentheses(): void
    {
        $blade = $this->views([
            'page' => <<<'BLADE'
<x-label value='{{ implode(")", ["a", "b"]) }}' text="don't (panic" />
BLADE,
            'components/label' => '@props(["value", "text"]){{ $value }}|{{ $text }}',
        ]);

        $this->assertRenders($blade, 'a)b|don&#039;t (panic');
    }

    public function test_alpine_double_colon_attributes_remain_literal(): void
    {
        $blade = $this->views([
            'page' => '<x-label ::class="{ active: isActive }" x-on:click="open = true" />',
            'components/label' => '<label {{ $attributes }}></label>',
        ]);

        $this->assertRenders($blade, '<label :class="{ active: isActive }" x-on:click="open = true"></label>');
    }

    public function test_default_slot_preserves_html_and_escapes_user_data_once(): void
    {
        $blade = $this->views([
            'page' => '<x-card><strong>{{ $name }}</strong></x-card>',
            'components/card' => '<article>{{ $slot }}</article>',
        ]);

        $this->assertRenders($blade, '<article><strong>&lt;Ada&gt;</strong></article>', ['name' => '<Ada>']);
    }

    public function test_nested_components_and_same_name_components_keep_parent_scope(): void
    {
        $blade = $this->views([
            'page' => '<x-card><x-card>{{ $name }}</x-card><x-card /></x-card>',
            'components/card' => '[{{ $slot }}]',
        ]);

        $this->assertRenders($blade, '[[Ada][]]', ['name' => 'Ada']);
    }

    public function test_slots_can_read_loop_variables_and_render_nested_components(): void
    {
        $blade = $this->views([
            'page' => '@foreach($names as $name)<x-card>@if($name)<x-label :$name />@endif</x-card>@endforeach',
            'components/card' => '[{{ $slot }}]',
            'components/label' => '@props(["name"]){{ $name }}',
        ]);

        $this->assertRenders($blade, '[Ada][Lin]', ['names' => ['Ada', 'Lin']]);
    }

    public function test_zero_and_internal_whitespace_in_slots_are_preserved(): void
    {
        $blade = $this->views([
            'page' => "<x-card>0</x-card><x-card>A  B\nC</x-card>",
            'components/card' => '[{{ $slot }}]',
        ]);

        $this->assertRenders($blade, "[0][A  B\nC]");
    }

    public function test_direct_component_api_provides_an_htmlable_empty_or_populated_slot(): void
    {
        $blade = $this->views(['components/card' => '{{ $slot->isEmpty() ? "empty" : $slot }}']);

        $this->assertSame('empty', $blade->component('card'));
        $this->assertSame('<b>Hi</b>', $blade->component('card', ['slot' => '<b>Hi</b>']));
    }

    public function test_htmlable_values_work_with_standard_escaped_echoes(): void
    {
        $blade = $this->views(['page' => '{{ $safe }}|{{ $unsafe }}']);

        $this->assertRenders($blade, '<b>safe</b>|&lt;b&gt;unsafe&lt;/b&gt;', [
            'safe' => new HtmlString('<b>safe</b>'),
            'unsafe' => '<b>unsafe</b>',
        ]);
    }

    public function test_escaped_echoes_and_directives_are_literal(): void
    {
        $blade = $this->views(['page' => '@{{ name }}|@{!! html !!}|@@if(true)yes@@endif|@@include("missing")']);

        $this->assertRenders($blade, '{{ name }}|{!! html !!}|@if(true)yes@endif|@include("missing")');
    }

    public function test_escaped_component_attribute_echo_is_literal(): void
    {
        $blade = $this->views([
            'page' => '<x-label value="@{{ name }}" />',
            'components/label' => '@props(["value"]){!! $value !!}',
        ]);

        $this->assertRenders($blade, '{{ name }}');
    }

    public function test_verbatim_preserves_comments_and_component_markup(): void
    {
        $blade = $this->views(['page' => '@verbatim{{-- literal --}}<x-card>{{ name }}</x-card>@endverbatim']);

        $this->assertRenders($blade, '{{-- literal --}}<x-card>{{ name }}</x-card>');
    }

    public function test_native_php_and_php_blocks_do_not_compile_string_contents(): void
    {
        $blade = $this->views([
            'page' => <<<'BLADE'
<?php echo '{{ literal }} @if(true) <x-card />'; ?>|@php echo '{{ block }} @endif'; @endphp
BLADE,
        ]);

        $this->assertRenders($blade, '{{ literal }} @if(true) <x-card />|{{ block }} @endif');
    }

    public function test_generated_php_strings_are_not_recompiled_as_blade(): void
    {
        $blade = $this->views(['page' => '{{ "@if(true)" }}|{!! "{{ literal }}" !!}']);

        $this->assertRenders($blade, '@if(true)|{{ literal }}');
    }

    public function test_inline_php_supports_nested_parentheses_and_block_php_after_it(): void
    {
        $blade = $this->views(['page' => '@php($value = strtoupper(trim(" hi "))){{ $value }}|@php $other = "ok"; @endphp{{ $other }}']);

        $this->assertRenders($blade, 'HI|ok');
    }

    public function test_include_variants_and_balanced_directive_arguments(): void
    {
        $blade = $this->views([
            'page' => '@includeIf("missing")@includeWhen(false, "missing")@includeWhen(true, "partial", ["value" => implode(",", ["a", "b"])])',
            'partial' => '{{ $value }}',
        ]);

        $this->assertRenders($blade, 'a,b');
    }

    public function test_section_expression_and_yield_default_support_nested_arguments(): void
    {
        $blade = $this->views(['page' => '@section("title", implode(",", ["a", "b"]))@hasSection("title")@yield("title")@endif|@sectionMissing("missing")@yield("missing", strtoupper("default"))@endif']);

        $this->assertRenders($blade, 'a,b|DEFAULT');
    }

    public function test_switch_cases_and_default(): void
    {
        $blade = $this->views(['page' => '@switch($value)@case(1)one@break@case(2)two@break@default other@endswitch']);

        $this->assertRenders($blade, 'two', ['value' => 2]);
        $this->assertRenders($blade, ' other', ['value' => 3]);
    }

    public function test_custom_directives_with_and_without_balanced_expressions(): void
    {
        $blade = $this->views(['page' => '@upper(strtolower("Hi)"))|@mark']);
        $blade->getCompiler()->directive('upper', fn($expression) => '<?= strtoupper(' . $expression . '); ?>');
        $blade->getCompiler()->directive('mark', fn() => 'ok');

        $this->assertRenders($blade, 'HI)|ok');
    }

    public function test_compiler_recovers_after_an_invalid_directive(): void
    {
        $blade = $this->views(['page' => 'ready']);
        $compiler = $blade->getCompiler();

        $this->assertThrows(InvalidArgumentException::class, fn() => $compiler->compileString('@verbatim SECRET @endverbatim @if('));
        $this->assertSame('__VERBATIM_BLOCK_0__', $compiler->compileString('__VERBATIM_BLOCK_0__'));
        $this->assertRenders($blade, 'ready');
    }

    public function test_slot_failure_restores_output_buffers_and_next_render_recovers(): void
    {
        $blade = $this->views([
            'page' => 'ready',
            'broken' => '<x-card>partial @php throw new RuntimeException("slot failed"); @endphp</x-card>',
            'components/card' => '{{ $slot }}',
        ]);
        $level = ob_get_level();

        $this->assertThrows(RuntimeException::class, fn() => $blade->render('broken'));
        $this->assertSame($level, ob_get_level());
        $this->assertRenders($blade, 'ready');
    }

    public function test_literal_blade_syntax_in_directive_arguments_is_not_recompiled(): void
    {
        $blade = $this->views([
            'page' => '@if($value === "@endif")yes@endif|@section("title", "@stop")@yield("title")',
        ]);

        $this->assertRenders($blade, 'yes|@stop', ['value' => '@endif']);
    }

    public function test_custom_directives_do_not_recompile_generated_php_strings(): void
    {
        $blade = $this->views(['page' => '{{ "@mark" }}|@emit']);
        $blade->getCompiler()->directive('mark', fn() => 'changed');
        $blade->getCompiler()->directive('emit', fn() => '<?php echo "@emit"; ?>');

        $this->assertRenders($blade, '@mark|@emit');
    }

    public function test_native_php_in_a_slot_uses_the_parent_context(): void
    {
        $blade = $this->views([
            'page' => '<x-card><?php echo e($name); ?></x-card>',
            'components/card' => '{{ $slot }}',
        ]);

        $this->assertRenders($blade, '&lt;Ada&gt;', ['name' => '<Ada>']);
    }

    public function test_static_slot_quotes_and_backslashes_round_trip(): void
    {
        $blade = $this->views([
            'page' => '<x-card>O\'Reilly "quoted" C:\\temp</x-card>',
            'components/card' => '{{ $slot }}',
        ]);

        $this->assertRenders($blade, 'O\'Reilly "quoted" C:\\temp');
    }

    public function test_attributes_escape_at_the_html_boundary_without_double_encoding(): void
    {
        $blade = $this->views([
            'page' => '<x-label title="{{ $value }}" :data-value="$value" />',
            'components/label' => '<label {{ $attributes }}></label>',
        ]);

        $this->assertRenders($blade, '<label title="&quot;&lt;&amp;" data-value="&quot;&lt;&amp;"></label>', ['value' => '"<&']);
    }

    public function test_unquoted_attributes_and_multiline_echoes(): void
    {
        $blade = $this->views([
            'page' => "<x-label id=item :count=2 title=\"{{\n strtoupper(\$name)\n}}\" />",
            'components/label' => '@props(["count"])<label {{ $attributes }}>{{ $count }}</label>',
        ]);

        $this->assertRenders($blade, '<label id="item" title="ADA">2</label>', ['name' => 'Ada']);
    }

    public function test_prefix_component_names_and_dot_notation_are_independent(): void
    {
        $blade = $this->views([
            'page' => '<x-card><x-card-title /><x-forms.input /></x-card>',
            'components/card' => '[{{ $slot }}]',
            'components/card-title' => 'Title',
            'components/forms/input' => '<input>',
        ]);

        $this->assertRenders($blade, '[Title<input>]');
    }

    public function test_use_directive_imports_an_alias(): void
    {
        $blade = $this->views(['page' => '@use("Spark\\Support\\HtmlString", "BladeAuditHtml"){{ new BladeAuditHtml("<b>safe</b>") }}']);

        $this->assertRenders($blade, '<b>safe</b>');
    }

    public function test_class_style_and_json_directives(): void
    {
        $blade = $this->views([
            'page' => '<div @class(["base", "active" => true, "hidden" => false]) @style(["color" => "red"])></div>|@json($value)',
        ]);

        $this->assertRenders($blade, '<div class="base active" style="color: red"></div>|' . \Spark\Support\Js::from('</script>'), ['value' => '</script>']);
    }

    public function test_invalid_directives_and_component_tags_fail_at_compile_time(): void
    {
        $compiler = $this->views(['page' => 'ready'])->getCompiler();

        foreach (['@if(', '@include(', '@extends(', '@section(', '@yield(', '@php(', '<x-card>', '<x-card title="broken>'] as $source) {
            $this->assertThrows(InvalidArgumentException::class, fn() => $compiler->compileString($source));
        }
    }

    public function test_raw_regions_preserve_blade_syntax_inside_php_strings(): void
    {
        $blade = $this->views([
            'page' => <<<'BLADE'
<?php echo '@verbatim{{ native }}@endverbatim'; ?>|@php echo '{{-- php comment --}} <?php literal ?>'; @endphp
BLADE,
        ]);

        $this->assertRenders($blade, '@verbatim{{ native }}@endverbatim|{{-- php comment --}} <?php literal ?>');
    }

    public function test_comments_cannot_start_php_or_verbatim_blocks(): void
    {
        $blade = $this->views(['page' => 'before{{-- @verbatim @php <?php --}}after']);

        $this->assertRenders($blade, 'beforeafter');
    }

    public function test_compiled_paths_are_unique_and_cache_expiration_tracks_files(): void
    {
        $blade = $this->views(['page' => '{{ $name }}']);
        $compiler = $blade->getCompiler();
        $source = $blade->getPath() . '/page.blade.php';
        $compiled = $compiler->getCompiledPath('page');

        $this->assertFalse($compiled === $compiler->getCompiledPath('page.php'));
        $this->assertFalse($compiler->getCompiledPath('a.b') === $compiler->getCompiledPath('a/b'));
        $this->assertTrue($compiler->isExpired($source, $compiled));

        $compiler->compile($source, $compiled);
        touch($source, 1000);
        touch($compiled, 2000);
        clearstatcache();

        $this->assertFalse($compiler->isExpired($source, $compiled));

        touch($source, 3000);
        clearstatcache();

        $this->assertTrue($compiler->isExpired($source, $compiled));
        $this->assertTrue($compiler->isExpired($source . '.missing', $compiled));
    }

    public function test_compile_file_errors_are_explicit_and_clear_cache_preserves_other_files(): void
    {
        $blade = $this->views(['page' => 'ready']);
        $compiler = $blade->getCompiler();
        $compiled = $compiler->getCompiledPath('page');
        $source = $blade->getPath() . '/page.blade.php';

        $this->assertThrows(RuntimeException::class, fn() => $compiler->compile($source . '.missing', $compiled));
        $this->assertThrows(RuntimeException::class, fn() => $compiler->compile($source, $blade->getCachePath()));

        $compiler->compile($source, $compiled);
        $keep = $blade->getCachePath() . '/keep.txt';
        file_put_contents($keep, 'keep');
        $compiler->clearCache();

        $this->assertFalse(is_file($compiled));
        $this->assertSame('keep', file_get_contents($keep));
    }

    public function test_echo_delimiters_inside_php_strings_and_comments_are_preserved(): void
    {
        $blade = $this->views([
            'page' => <<<'BLADE'
{{ '}}' }}|{!! '!!}' !!}|{{ /* }} */ 'ok' }}|{{ (function () { return '}'; })() }}
BLADE,
        ]);

        $this->assertRenders($blade, '}}|!!}|ok|}');
    }

    public function test_blade_and_component_syntax_in_php_expressions_stays_literal(): void
    {
        $blade = $this->views([
            'page' => <<<'BLADE'
{{ '<x-missing />' }}|@if($value === '{{ literal }}')yes@endif|@php($text = '@verbatim <?php {{ text }}'){{ $text }}
BLADE,
        ]);

        $this->assertRenders(
            $blade,
            '&lt;x-missing /&gt;|yes|@verbatim &lt;?php {{ text }}',
            ['value' => '{{ literal }}']
        );
    }

    public function test_component_attribute_echoes_handle_literal_closing_delimiters(): void
    {
        $blade = $this->views([
            'page' => <<<'BLADE'
<x-label value="{{ '}}' }}" raw="{!! '!!}' !!}" />
BLADE,
            'components/label' => '@props(["value", "raw"]){{ $value }}|{!! $raw !!}',
        ]);

        $this->assertRenders($blade, '}}|!!}');
    }

    public function test_component_markup_inside_html_attributes_is_literal(): void
    {
        $blade = $this->views([
            'page' => '<div data-example="<x-missing />">Outside</div><x-card><span title="</x-card>">Inside</span></x-card>',
            'components/card' => '[{{ $slot }}]',
        ]);

        $this->assertRenders($blade, '<div data-example="<x-missing />">Outside</div>[<span title="</x-card>">Inside</span>]');
    }

    public function test_escaped_echo_and_verbatim_attribute_literals_preserve_quotes(): void
    {
        $blade = $this->views([
            'page' => <<<'BLADE'
<x-label value="@{{ 'client' }}" raw="@verbatim{{ 'raw' }}@endverbatim" />
BLADE,
            'components/label' => '@props(["value", "raw"]){!! $value !!}|{!! $raw !!}',
        ]);

        $this->assertRenders($blade, "{{ 'client' }}|{{ 'raw' }}");
    }

    public function test_directive_comments_do_not_unbalance_parentheses(): void
    {
        $blade = $this->views(['page' => '@if(/* ) */ true)yes@endif|@section("title", "a,b")@yield("title")']);

        $this->assertRenders($blade, 'yes|a,b');
    }

    public function test_mismatched_component_nesting_is_rejected(): void
    {
        $compiler = $this->views(['page' => 'ready'])->getCompiler();

        foreach (['<x-card><x-label></x-card></x-label>', '</x-card>', '<x-card></x-label>'] as $source) {
            $this->assertThrows(InvalidArgumentException::class, fn() => $compiler->compileString($source));
        }
    }

    public function test_duplicate_attribute_last_value_keeps_its_own_binding_mode(): void
    {
        $blade = $this->views([
            'page' => '<x-label :value="$name" value="literal" />',
            'components/label' => '@props(["value"]){{ $value }}',
        ]);

        $this->assertRenders($blade, 'literal', ['name' => 'Ada']);
    }

    public function test_include_arguments_are_not_compiled_again_by_other_include_variants(): void
    {
        $blade = $this->views([
            'page' => <<<'BLADE'
@includeWhen(true, 'partial', ['value' => '@include("missing")'])
BLADE,
            'partial' => '{!! $value !!}',
        ]);

        $this->assertRenders($blade, '@include("missing")');
    }

    public function test_compact_self_closing_tags_preserve_typed_values_and_boolean_attributes(): void
    {
        $blade = $this->views([
            'page' => "<x-label :value=\"null\" disabled ::cloak/><x-label :value=\"['a' => [1, 2]]\"/>",
            'components/label' => '@props(["value"])<label {{ $attributes }}>{{ $value === null ? "null" : $value["a"][1] }}</label>',
        ]);

        $this->assertRenders($blade, '<label disabled :cloak>null</label><label >2</label>');
    }

    public function test_expression_tokenizer_handles_heredocs_and_interpolated_strings(): void
    {
        $blade = $this->views([
            'page' => <<<'BLADE'
{{ "{$name}}" }}|{{ <<<'TEXT'
}} literal
TEXT
}}
BLADE,
        ]);

        $this->assertRenders($blade, 'Ada}|}} literal', ['name' => 'Ada']);
    }

    public function test_custom_directive_receives_original_expression_text(): void
    {
        $blade = $this->views(['page' => '@literal("{{ original }}")']);
        $blade->getCompiler()->directive('literal', fn($expression) => '<?= ' . var_export($expression, true) . '; ?>');

        $this->assertRenders($blade, '"{{ original }}"');
    }

    public function test_empty_bound_attributes_fail_at_compile_time(): void
    {
        $compiler = $this->views(['page' => 'ready'])->getCompiler();

        $this->assertThrows(InvalidArgumentException::class, fn() => $compiler->compileString('<x-label :value="" />'));
    }

    public function test_verbatim_content_can_start_with_parentheses(): void
    {
        $blade = $this->views(['page' => '@verbatim(foo) {{ client }} <x-missing />@endverbatim']);

        $this->assertRenders($blade, '(foo) {{ client }} <x-missing />');
    }

    public function test_custom_directive_can_compile_another_template_without_losing_outer_state(): void
    {
        $blade = $this->views(['page' => '{{ $name }}|@nested|@verbatim{{ literal }}@endverbatim']);
        $compiler = $blade->getCompiler();
        $compiler->directive('nested', fn() => $compiler->compileString('{{ strtoupper($name) }}'));

        $this->assertRenders($blade, 'Ada|ADA|{{ literal }}', ['name' => 'Ada']);
    }

    public function test_subcompilers_are_loaded_on_demand(): void
    {
        $blade = $this->views(['page' => 'Hello {{ $name }}']);
        $compiler = $blade->getCompiler();

        $ref = new \ReflectionClass($compiler);
        $componentProp = $ref->getProperty('componentCompiler');
        $includeProp = $ref->getProperty('includeCompiler');
        $layoutProp = $ref->getProperty('layoutCompiler');

        $compiler->compileString('Hello {{ $name }}');
        $this->assertNull($componentProp->getValue($compiler));
        $this->assertNull($includeProp->getValue($compiler));
        $this->assertNull($layoutProp->getValue($compiler));

        $compiler->compileString('<x-alert />');
        $this->assertInstanceOf(\Spark\View\Compilers\ComponentCompiler::class, $componentProp->getValue($compiler));
        $this->assertNull($includeProp->getValue($compiler));
        $this->assertNull($layoutProp->getValue($compiler));

        $compiler->compileString('@include("partials.header")');
        $this->assertInstanceOf(\Spark\View\Compilers\IncludeCompiler::class, $includeProp->getValue($compiler));
        $this->assertNull($layoutProp->getValue($compiler));

        $compiler->compileString('@extends("layouts.app")');
        $this->assertInstanceOf(\Spark\View\Compilers\LayoutCompiler::class, $layoutProp->getValue($compiler));
    }

    public function test_css_at_rules_and_text_after_closing_directives_remain_literal(): void
    {
        $css = '@supports (background: url(https://example.test/a.png)) { @media (min-width: 1px) { .a { color: red; } } }';
        $blade = $this->views(['page' => $css . '@if(true)yes@endif (open text']);

        $this->assertRenders($blade, $css . 'yes (open text');
    }
}
