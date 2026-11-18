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

    public function test_loop_variable_properties_in_foreach(): void
    {
        $template = '@foreach($items as $item)'
            . '{{ $loop->index }}:{{ $loop->iteration }}:{{ $loop->remaining }}:{{ $loop->count }}:'
            . '{{ $loop->first ? "1" : "0" }}:{{ $loop->last ? "1" : "0" }}:'
            . '{{ $loop->even ? "1" : "0" }}:{{ $loop->odd ? "1" : "0" }}:{{ $loop->depth }};'
            . '@endforeach';

        // 3 items:
        // Iter 1: index 0, iter 1, rem 2, count 3, first 1, last 0, even 0, odd 1, depth 1
        // Iter 2: index 1, iter 2, rem 1, count 3, first 0, last 0, even 1, odd 0, depth 1
        // Iter 3: index 2, iter 3, rem 0, count 3, first 0, last 1, even 0, odd 1, depth 1
        $expected = '0:1:2:3:1:0:0:1:1;1:2:1:3:0:0:1:0:1;2:3:0:3:0:1:0:1:1;';
        $this->assertRendered($template, ['items' => ['a', 'b', 'c']], $expected);
    }

    public function test_loop_variable_properties_in_forelse(): void
    {
        $template = '@forelse($items as $item)'
            . '{{ $loop->iteration }}/{{ $loop->count }}'
            . '@empty '
            . 'none:{{ isset($loop) && $loop !== null ? "set" : "null" }}'
            . '@endforelse';

        $this->assertRendered($template, ['items' => ['x', 'y']], '1/22/2');
        $this->assertRendered($template, ['items' => []], 'none:null');
    }

    public function test_loop_variable_nested_and_parent(): void
    {
        $template = '@foreach($groups as $group)'
            . 'G{{ $loop->index }}(d{{ $loop->depth }}):'
            . '@foreach($group as $item)'
            . 'I{{ $loop->index }}(d{{ $loop->depth }},P{{ $loop->parent->index }})'
            . '@endforeach;'
            . 'afterG{{ $loop->index }};'
            . '@endforeach';

        $data = [
            'groups' => [
                ['a', 'b'],
                ['c'],
            ],
        ];

        $expected = 'G0(d1):I0(d2,P0)I1(d2,P0);afterG0;G1(d1):I0(d2,P1);afterG1;';
        $this->assertRendered($template, $data, $expected);
    }

    public function test_loop_variable_with_continue_and_break(): void
    {
        $template = '@foreach($items as $item)'
            . '@continue($loop->first)'
            . '{{ $loop->iteration }}:{{ $item }};'
            . '@break($loop->iteration === 3)'
            . '@endforeach';

        $this->assertRendered($template, ['items' => ['a', 'b', 'c', 'd']], '2:b;3:c;');
    }

    public function test_loop_variable_with_generator(): void
    {
        $generatorFn = function () {
            yield 'alpha';
            yield 'beta';
        };

        $template = '@foreach($gen() as $val)'
            . '{{ $loop->index }}:{{ $loop->iteration }}:{{ is_null($loop->count) ? "null" : $loop->count }}:'
            . '{{ is_null($loop->remaining) ? "null" : $loop->remaining }}:{{ is_null($loop->last) ? "null" : "bool" }};'
            . '@endforeach';

        $expected = '0:1:null:null:null;1:2:null:null:null;';
        $this->assertRendered($template, ['gen' => $generatorFn], $expected);
    }

    public function test_loop_variable_in_include(): void
    {
        $path = $this->storagePath . '/views';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        file_put_contents($path . '/loop_item.blade.php', '{{ $loop->iteration }}={{ $item }};');

        $template = '@foreach($items as $item)@include("loop_item", ["item" => $item])@endforeach';
        $this->assertRendered($template, ['items' => ['first', 'second']], '1=first;2=second;');
    }

    public function test_loop_variable_in_component_slot(): void
    {
        $path = $this->storagePath . '/views';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        if (!is_dir($path . '/components')) {
            mkdir($path . '/components', 0700, true);
        }
        file_put_contents($path . '/components/card.blade.php', '<div>{{ $slot }}</div>');

        $template = '@foreach($items as $item)<x-card>{{ $loop->iteration }}:{{ $item }}</x-card>@endforeach';
        $this->assertRendered($template, ['items' => ['one', 'two']], '<div>1:one</div><div>2:two</div>');
    }

    public function test_malformed_foreach_and_forelse_throw_exception(): void
    {
        $path = $this->storagePath . '/views';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        $blade = new \Spark\View\Blade($path, $this->storagePath . '/compiled');

        file_put_contents($path . '/bad_foreach.blade.php', '@foreach($items) {{ $item }} @endforeach');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $blade->render('bad_foreach', ['items' => []]));

        file_put_contents($path . '/bad_forelse.blade.php', '@forelse($items) {{ $item }} @empty none @endforelse');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $blade->render('bad_forelse', ['items' => []]));
    }

    public function test_canany_and_elsecanany(): void
    {
        \Spark\Facades\Gate::define('edit', fn($user, $role) => $role === 'editor' || $role === 'admin');
        \Spark\Facades\Gate::define('delete', fn($user, $role) => $role === 'admin');
        \Spark\Facades\Gate::define('view', fn($user, $role) => $role === 'viewer');

        $template = '@canany(["delete", "edit"], $role)can-manage@elsecanany(["view"], $role)can-view@else cannot@endcanany';

        $this->assertRendered($template, ['role' => 'admin'], 'can-manage');
        $this->assertRendered($template, ['role' => 'editor'], 'can-manage');
        $this->assertRendered($template, ['role' => 'viewer'], 'can-view');
        $this->assertRendered($template, ['role' => 'guest'], 'cannot');

        // Test case-insensitivity: @canAny and @endcanAny
        $templateCase = '@canAny(["view"], $role)view-ok@elsecanAny(["edit"], $role)edit-ok@endcanAny';
        $this->assertRendered($templateCase, ['role' => 'viewer'], 'view-ok');
        $this->assertRendered($templateCase, ['role' => 'editor'], 'edit-ok');
    }

    public function test_prependif_directive(): void
    {
        $template = '@prependIf(true, "scripts")A;@endprependIf'
            . '@prependIf(false, "scripts")NEVER;@endprependIf'
            . '@prepend("scripts")B;@endprepend'
            . '@stack("scripts")';

        // B prepended after A, so B comes before A in output
        $this->assertRendered($template, [], 'B;A;');
    }

    public function test_inject_directive(): void
    {
        $service = new class {
            public function greet(string $name): string
            {
                return "Greetings, {$name}!";
            }
        };
        \Spark\Foundation\Application::$app->bind('greeter_service', fn() => $service);

        $this->assertRendered('@inject("greeter", "greeter_service"){{ $greeter->greet("Ada") }}', [], 'Greetings, Ada!');
        $this->assertRendered('@inject("greeter", "greeter_service",){{ $greeter->greet("Ada") }}', [], 'Greetings, Ada!');
        $this->assertRendered('@inject("$greeter", "greeter_service"){{ $greeter->greet("Grace") }}', [], 'Greetings, Grace!');
        $this->assertRendered('@inject("hasher", \Spark\Hash::class){{ is_object($hasher) ? "hasher-ok" : "fail" }}', [], 'hasher-ok');
    }

    public function test_each_directive(): void
    {
        $path = $this->storagePath . '/views';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        file_put_contents($path . '/user_row.blade.php', '{{ $user }}:{{ $key }};');
        file_put_contents($path . '/empty_users.blade.php', 'no users');

        // Non-empty array
        $this->assertRendered('@each("user_row", $users, "user")', ['users' => ['Ada', 'Grace']], 'Ada:0;Grace:1;');

        // Empty array with empty view fallback
        $this->assertRendered('@each("user_row", $users, "user", "empty_users")', ['users' => []], 'no users');

        // Empty array without empty view
        $this->assertRendered('@each("user_row", $users, "user")', ['users' => []], '');

        // With collection
        $collection = new \Spark\Support\Collection(['First', 'Second']);
        $this->assertRendered('@each("user_row", $users, "user")', ['users' => $collection], 'First:0;Second:1;');
    }

    public function test_malformed_inject_and_each_throw_exception(): void
    {
        $path = $this->storagePath . '/views';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        $blade = new \Spark\View\Blade($path, $this->storagePath . '/compiled');

        file_put_contents($path . '/bad_inject.blade.php', '@inject("onlyOne")');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $blade->render('bad_inject'));

        file_put_contents($path . '/bad_inject_var.blade.php', '@inject("123bad", "Service")');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $blade->render('bad_inject_var'));

        file_put_contents($path . '/bad_each.blade.php', '@each("view", $items)');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $blade->render('bad_each', ['items' => []]));
    }

    public function test_loop_parsing_preserves_literals_destructuring_and_comments(): void
    {
        foreach (['foreach', 'forelse'] as $directive) {
            $this->assertRendered(
                '@' . $directive . '($items as [" as " => $value]){{ $value }}@end' . $directive,
                ['items' => [[' as ' => '@endforeach @empty @endforelse']]],
                '@endforeach @empty @endforelse'
            );
            $this->assertRendered(
                '@' . $directive . "(\$items // as ignored\n as \$key => \$value // ) ignored\n)"
                . '{{ $key }}:{{ $value }}@end' . $directive,
                ['items' => ['key' => 'value']],
                'key:value'
            );
        }
        $this->assertRendered(
            '@foreach(["@endforeach", " as "] as $value){{ $value }}@endforeach',
            [],
            '@endforeach as'
        );
    }

    public function test_new_directives_preserve_escaped_and_php_literals(): void
    {
        $literal = '@foreach([] as $value)@endforeach @forelse([] as $value)@empty@endforelse '
            . '@each("row", [], "item") @inject("service", "service") '
            . '@canany(["edit"])@elsecanany(["view"])@endcanany '
            . '@prependIf(true, "scripts")@endprependIf';
        $this->assertRendered('@verbatim' . $literal . '@endverbatim', [], $literal);
        $this->assertRendered(str_replace('@', '@@', $literal), [], $literal);
        $this->assertRendered('<?php echo ' . var_export($literal, true) . '; ?>', [], $literal);
    }

    public function test_loop_keys_and_counts_for_arrays_collections_paginators_and_iterators(): void
    {
        $items = ['first' => 'a', 'second' => 'b'];
        $paginator = new \Spark\Paginator\Paginator(100, 10);
        $paginator->setCollection(new \Spark\Support\Collection($items));
        foreach ([$items, new \Spark\Support\Collection($items), $paginator, new \ArrayIterator($items)] as $data) {
            $this->assertRendered(
                '@foreach($items as $key => $value){{ $key }}:{{ $value }}:{{ $loop->index }}:{{ $loop->count }};@endforeach',
                ['items' => $data],
                'first:a:0:2;second:b:1:2;'
            );
        }
    }

    public function test_non_countable_iterator_is_traversed_once_per_render(): void
    {
        $items = new class implements \IteratorAggregate {
            public int $traversals = 0;

            public function getIterator(): \Traversable
            {
                $this->traversals++;
                yield 'key' => 'value';
            }
        };
        $this->assertRendered(
            '@forelse($items as $key => $value){{ $key }}:{{ $value }}:{{ $loop->iteration }}:'
            . '{{ $loop->count === null && $loop->last === null && $loop->remaining === null ? "unknown" : "wrong" }}'
            . '@empty none @endforelse',
            ['items' => $items],
            'key:value:1:unknown'
        );
        $this->assertSame(2, $items->traversals);
    }

    public function test_nested_reference_loops_do_not_overwrite_source_arrays(): void
    {
        $this->assertRendered(
            '@foreach($items as &$item)@php($item++)'
            . '@forelse([1] as $inner){{ $loop->parent->iteration }}@empty none @endforelse'
            . '@endforeach|{{ implode(",", $items) }}',
            ['items' => [1, 2]],
            '12|2,3'
        );
    }

    public function test_early_return_from_include_restores_parent_loop(): void
    {
        $path = $this->storagePath . '/views';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        file_put_contents($path . '/early.blade.php', '@foreach([1] as $item)@php return; @endphp @endforeach');
        $this->assertRendered(
            '@foreach([1, 2] as $item)@include("early"){{ $loop->iteration }}:{{ $loop->depth }};@endforeach',
            [],
            '1:1;2:1;'
        );
    }

    public function test_each_keeps_nested_arguments_and_directive_literals(): void
    {
        $path = $this->storagePath . '/views';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        file_put_contents($path . '/each_row.blade.php', '{{ $key }}:{{ $item }};');
        $this->assertRendered(
            '@EACH("each_row", array_map(fn($value) => $value, ["@each(a, b, c)", "@inject(a, b)"]), "item",)',
            [],
            '0:@each(a, b, c);1:@inject(a, b);'
        );
    }

    public function test_inject_and_each_reject_extra_or_empty_arguments(): void
    {
        $compiler = new \Spark\View\BladeCompiler($this->storagePath . '/compiled');
        foreach ([
            '@inject("service", "service", "extra")',
            '@inject("service", )',
            '@inject("this", "service")',
            '@each("view", [], "item", "empty", "extra")',
            '@each("view", [], )',
        ] as $source) {
            $this->assertThrows(\InvalidArgumentException::class, fn() => $compiler->compileString($source));
        }
    }
}
