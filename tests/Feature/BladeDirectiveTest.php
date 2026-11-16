<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class BladeDirectiveTest extends FrameworkTestCase
{
    private function assertRendered(string $source, array $context, string $expected): void
    {
        $path = $this->storagePath . '/views';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        $name = 'case_' . md5($source);
        file_put_contents($path . '/' . $name . '.blade.php', $source);
        $blade = new \Spark\View\Blade($path, $this->storagePath . '/compiled');
        $level = ob_get_level();
        $this->assertSame($expected, trim($blade->render($name, $context)));
        $this->assertSame($expected, trim($blade->render($name, $context)), 'Cached render must match the first render.');
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

    public function test_forelse_with_empty_array(): void
    {
        $this->assertRendered('@forelse($items as $item){{ $item }}@empty No items @endforelse', ['items' => []], 'No items');
    }

    public function test_forelse_with_nonempty_array(): void
    {
        $this->assertRendered('@forelse($items as $item){{ $item }}@empty No items @endforelse', ['items' => ['a', 'b']], 'ab');
    }

    public function test_forelse_associative_array(): void
    {
        $this->assertRendered('@forelse($items as $key => $val){{ $key }}:{{ $val }};@empty None @endforelse', ['items' => ['x' => 1, 'y' => 2]], 'x:1;y:2;');
    }

    public function test_forelse_with_collection(): void
    {
        $collection = new \Spark\Support\Collection(['one', 'two']);
        $emptyCollection = new \Spark\Support\Collection([]);

        $this->assertRendered('@forelse($items as $item){{ $item }}@empty Empty @endforelse', ['items' => $collection], 'onetwo');
        $this->assertRendered('@forelse($items as $item){{ $item }}@empty Empty @endforelse', ['items' => $emptyCollection], 'Empty');
    }

    public function test_forelse_nested(): void
    {
        $data = [
            'groups' => [
                ['name' => 'A', 'items' => [1, 2]],
                ['name' => 'B', 'items' => []],
            ]
        ];

        $source = '@forelse($groups as $group){{ $group["name"] }}:@forelse($group["items"] as $item){{ $item }}@empty none @endforelse;@empty no groups @endforelse';
        $this->assertRendered($source, $data, 'A:12;B: none ;');

        $this->assertRendered($source, ['groups' => []], 'no groups');
    }

    public function test_forelse_nested_empty_conditional(): void
    {
        $source = '@forelse($users as $user){{ $user["name"] }}@empty($user["bio"])(no bio)@endempty;@empty No users @endforelse';
        $this->assertRendered($source, ['users' => [['name' => 'Ada', 'bio' => null]]], 'Ada(no bio);');
        $this->assertRendered($source, ['users' => []], 'No users');
    }

    public function test_forelse_without_empty_block(): void
    {
        $this->assertRendered('@forelse($items as $item){{ $item }}@endforelse', ['items' => ['a', 'b']], 'ab');
        $this->assertRendered('@forelse($items as $item){{ $item }}@endforelse', ['items' => []], '');
    }

    public function test_forelse_escaped_directives(): void
    {
        $source = '@@forelse($items as $item) @@empty @@endforelse';
        $this->assertRendered($source, [], '@forelse($items as $item) @empty @endforelse');
    }

    public function test_forelse_unbalanced_parentheses_throws_exception(): void
    {
        $path = $this->storagePath . '/views';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        file_put_contents($path . '/unbalanced.blade.php', '@forelse($items as $item');
        $blade = new \Spark\View\Blade($path, $this->storagePath . '/compiled');

        $this->assertThrows(\InvalidArgumentException::class, fn() => $blade->render('unbalanced', ['items' => []]));
    }

    public function test_continue_without_condition(): void
    {
        $this->assertRendered('@foreach($items as $item)@continue{{ $item }}@endforeach', ['items' => [1, 2]], '');
    }

    public function test_continue_with_condition(): void
    {
        $this->assertRendered('@foreach($items as $item)@continue($item === 2){{ $item }}@endforeach', ['items' => [1, 2, 3]], '13');
    }

    public function test_break_without_condition(): void
    {
        $this->assertRendered('@foreach($items as $item){{ $item }}@break @endforeach', ['items' => [1, 2, 3]], '1');
    }

    public function test_break_with_condition(): void
    {
        $this->assertRendered('@foreach($items as $item){{ $item }}@break($item === 2)@endforeach', ['items' => [1, 2, 3]], '12');
    }

    public function test_session_binds_value(): void
    {
        session()->set('status', 'TaskCompleted');
        $this->assertRendered('@session("status")Status: {{ $value }}@endsession', [], 'Status: TaskCompleted');
        $this->assertRendered('@session("missing")Status: {{ $value }}@endsession', [], '');
    }

    public function test_elsecan_and_elsecannot(): void
    {
        \Spark\Facades\Gate::define('edit', fn($user, $role) => $role === 'editor');
        \Spark\Facades\Gate::define('view', fn($user, $role) => $role === 'viewer');

        $template = '@can("edit", $role)can-edit@elsecan("view", $role)can-view@else cannot-do-anything@endcan';
        $this->assertRendered($template, ['role' => 'editor'], 'can-edit');
        $this->assertRendered($template, ['role' => 'viewer'], 'can-view');
        $this->assertRendered($template, ['role' => 'guest'], 'cannot-do-anything');
    }

    public function test_elseauth_and_elseguest(): void
    {
        $template = '@auth welcome-user @elseguest welcome-guest @endauth';
        // In test environment, not logged in by default
        $this->assertRendered($template, [], 'welcome-guest');
    }

    public function test_production_and_env(): void
    {
        // Default test environment is 'testing'
        $this->assertSame('testing', app_environment());
        $this->assertTrue(app_environment('testing'));
        $this->assertFalse(app_environment('production'));
        $this->assertTrue(app_environment(['local', 'testing']));
        $this->assertFalse(app_environment(['production', 'staging']));
        $this->assertTrue(app()->environment('testing'));
        $this->assertFalse(app()->environment('production'));

        $this->assertRendered('@production is-prod @endproduction@env("testing") is-test @endenv', [], 'is-test');
        $this->assertRendered('@env(["local", "testing"]) is-env @endenv', [], 'is-env');
        $this->assertRendered('@env(["production", "staging"]) is-env @endenv', [], '');
    }

    public function test_include_unless(): void
    {
        $path = $this->storagePath . '/views';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        file_put_contents($path . '/greeting.blade.php', 'Hello {{ $name }}');

        $this->assertRendered('@includeUnless(false, "greeting", ["name" => "Ada"])|@includeUnless(true, "greeting", ["name" => "Bob"])', [], 'Hello Ada|');
    }

    public function test_include_first(): void
    {
        $path = $this->storagePath . '/views';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        file_put_contents($path . '/existing.blade.php', 'Found: {{ $item }}');

        $this->assertRendered('@includeFirst(["nonexistent1", "existing", "nonexistent2"], ["item" => "Widget"])', [], 'Found: Widget');
    }
}
