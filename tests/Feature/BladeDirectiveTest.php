<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class BladeDirectiveTest extends FrameworkTestCase
{
    private function assertRendered(string $source, array $context, string $expected): void
    {
        $path = $this->storagePath . '/views';
        mkdir($path, 0700, true);
        file_put_contents($path . '/case.blade.php', $source);
        $blade = new \Spark\View\Blade($path, $this->storagePath . '/compiled');
        $level = ob_get_level();
        $this->assertSame($expected, trim($blade->render('case', $context)));
        $this->assertSame($expected, trim($blade->render('case', $context)), 'Cached render must match the first render.');
        $this->assertSame($level, ob_get_level());
    }

    public function test_checked_true(): void
    {
        $this->assertRendered('@checked($value)', ['value' => true], 'checked');
    }

    public function test_checked_false(): void
    {
        $this->assertRendered('@checked($value)', ['value' => false], '');
    }

    public function test_disabled_true(): void
    {
        $this->assertRendered('@disabled($value)', ['value' => true], 'disabled');
    }

    public function test_disabled_false(): void
    {
        $this->assertRendered('@disabled($value)', ['value' => false], '');
    }

    public function test_selected_true(): void
    {
        $this->assertRendered('@selected($value)', ['value' => true], 'selected');
    }

    public function test_selected_false(): void
    {
        $this->assertRendered('@selected($value)', ['value' => false], '');
    }

    public function test_readonly_true(): void
    {
        $this->assertRendered('@readonly($value)', ['value' => true], 'readonly');
    }

    public function test_readonly_false(): void
    {
        $this->assertRendered('@readonly($value)', ['value' => false], '');
    }

    public function test_required_true(): void
    {
        $this->assertRendered('@required($value)', ['value' => true], 'required');
    }

    public function test_required_false(): void
    {
        $this->assertRendered('@required($value)', ['value' => false], '');
    }

    public function test_if_true(): void
    {
        $this->assertRendered('@if($value)yes@else no@endif', ['value' => true], 'yes');
    }

    public function test_if_false(): void
    {
        $this->assertRendered('@if($value)yes@else no@endif', ['value' => false], 'no');
    }

    public function test_unless_true(): void
    {
        $this->assertRendered('@unless($value)yes@endunless', ['value' => true], '');
    }

    public function test_unless_false(): void
    {
        $this->assertRendered('@unless($value)yes@endunless', ['value' => false], 'yes');
    }

    public function test_isset_null(): void
    {
        $this->assertRendered('@isset($value)yes@endisset', ['value' => null], '');
    }

    public function test_isset_zero(): void
    {
        $this->assertRendered('@isset($value)yes@endisset', ['value' => 0], 'yes');
    }

    public function test_empty_zero(): void
    {
        $this->assertRendered('@empty($value)empty@endempty', ['value' => 0], 'empty');
    }

    public function test_empty_array(): void
    {
        $this->assertRendered('@empty($value)empty@endempty', ['value' => []], 'empty');
    }

    public function test_nested_parentheses(): void
    {
        $this->assertRendered('@if(strlen(strtoupper($value)) > 2)yes@endif', ['value' => 'abc'], 'yes');
    }

    public function test_foreach_associative(): void
    {
        $this->assertRendered('@foreach($items as $key => $value){{ $key }}={{ $value }};@endforeach', ['items' => ['a' => 1, 'b' => 2]], 'a=1;b=2;');
    }

    public function test_for_loop(): void
    {
        $this->assertRendered('@for($i = 0; $i < 3; $i++){{ $i }}@endfor', [], '012');
    }

    public function test_while_loop(): void
    {
        $this->assertRendered('@php $i = 0; @endphp@while($i < 3){{ $i++ }}@endwhile', [], '012');
    }

    public function test_comment_removed(): void
    {
        $this->assertRendered('before{{-- secret --}}after', [], 'beforeafter');
    }

    public function test_verbatim_preserves_directives(): void
    {
        $this->assertRendered('@verbatim{{ $value }} @if(true)literal@endif@endverbatim', [], '{{ $value }} @if(true)literal@endif');
    }

    public function test_elseif_branch(): void
    {
        $this->assertRendered('@if($value === 1)one@elseif($value === 2)two@else other@endif', ['value' => 2], 'two');
    }

    public function test_php_assignment(): void
    {
        $this->assertRendered('@php $value = 3; @endphp{{ $value }}', [], '3');
    }

    public function test_raw_and_escaped(): void
    {
        $this->assertRendered('{!! $value !!}|{{ $value }}', ['value' => '<p>x</p>'], '<p>x</p>|&lt;p&gt;x&lt;/p&gt;');
    }

}
