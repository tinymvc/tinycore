# Blade Views and Components

> **Read when:** writing Blade templates, layouts, components, directives, or escaped output.
> **Related:** [Frontend setup](frontend-setup.md) (Vite) · [FireLine](fireline.md) · [Inertia](inertia.md) · [Requests and validation](requests-validation.md)
> Spark Blade is native; Laravel directive/component compatibility must not be assumed.

Contents:

- [Anonymous components](#anonymous-components)
- [Escaping and client expressions](#escaping-and-client-expressions)
- [Native directives](#native-directives)
- [Unsupported features and native replacements](#unsupported-features-and-native-replacements)
- [Helpers and forms](#helpers-and-forms)
- [Layouts, rendering, and verification](#layouts-rendering-and-verification)

## Anonymous components

Use `<x-...>` for reusable UI. Components are templates in `resources/views/components/`; nested paths use dots. Reserve `@include` for document sections or large email pieces.

```blade
{{-- components/alert.blade.php --}}
@props(['type' => 'info', 'message' => null])
<div {{ $attributes->merge(['class' => 'alert alert-' . $type]) }} role="alert">
    {{ $message ?? $slot }}
</div>
```

```blade
<x-alert :$message type="success" />
<x-alert :message="$errorMessage" class="mb-4" />
<x-alert type="danger"><strong>Error!</strong> Please try again.</x-alert>
<x-forms.input name="email" type="email" required />
<x-link href="{{ route('register') }}" x-navigate>Register</x-link>
<x-button ::disabled="form.processing">Publish</x-button>
```

`@props()` declares data/defaults; `$attributes` contains remaining HTML attributes. `merge()` combines defaults/classes without replacing explicit consumer values; `class()` builds conditional classes. `has()`, `get()`, `except()`, and `props()` inspect/filter/extract attributes.

## Escaping and client expressions

| Syntax | Contract |
| --- | --- |
| `{{ $value }}` | Escapes ordinary data via `e()`; honors Htmlable |
| `{!! $value !!}` | Trusted HTML only; no escaping |
| `{{ $slot }}` | Standard default slot; HtmlString contains rendered child markup |
| `title="Hello {{ $name }}"` on a component | String interpolation, escaping expressions |
| `title="{!! $value !!}"` | Trusted raw interpolation |
| `:value="$expression"` / `:$value` | Typed PHP value/expression |
| `::class="{ active: isActive }"` | Literal Alpine/client `:class`; `x-bind:class` also works |
| `@{{ value }}` / `@{!! value !!}` | Literal client echo syntax |
| `@@directive` | Literal directive text |

Use `@json($data)` / `@js($data)` for script-context serialization (`Spark\Support\Js::from`). Do not insert arbitrary JSON or user strings raw into HTML/scripts.

## Native directives

Blocks use their corresponding closing directive unless listed otherwise. This is the core set; optional adapters/providers can register more.

| Purpose | Syntax |
| --- | --- |
| Conditions | `@if`, `@elseif`, `@else`, `@endif`; `@unless`, `@isset`, `@empty` with expressions and matching `@end...`; `@env('local')` / `@endenv`, `@production` / `@endproduction` |
| Switch | `@switch($value)`, `@case($value)`, `@break`, `@default`, `@endswitch` |
| Loops | `@foreach`, `@forelse` (`@empty`, `@endforelse`), `@for`, `@while` and matching `@end...`; `@break`, `@continue`, and conditional `@break($condition)`, `@continue($condition)` |
| Gate | `@can('ability', $model)`, `@cannot(...)`, `@elsecan(...)`, `@elsecannot(...)`, matching closing directives; `@authorize(...)` runs authorization |
| Auth | `@auth`, `@guest`, `@elseauth`, `@elseguest`, optional guard (`@auth('admin')`), `@endauth`, `@endguest` |
| Session | `@session('key') ... @endsession` checks `session()->has()`, exposing `$value = session()->get('key')` |
| Errors | `@error('field')` first `$message`, `@errors('field')` each `$message`, `@enderror`, `@enderrors`; `@old('field', $default)` escapes old input |
| Components | `@props(['name', 'type' => 'text'])` |
| Stacks | `@stack('scripts', 'default')`, `@push('scripts') ... @endpush`, `@prepend('scripts') ... @endprepend`, `@pushIf($condition, 'scripts') ... @endpushIf`, `@pushOnce('scripts') ... @endpushOnce`, `@prependOnce('scripts') ... @endprependOnce`, `@once ... @endonce` |
| Layout | `@extends('layouts.app')`, `@section('name') ... @endsection`, inline `@section('name', 'Value')`, `@yield('name', 'Default')` |
| Section helpers | `@stop` ends capture; `@show` ends/yields; `@hasSection('name')` / `@sectionMissing('name')` with matching `@end...` |
| Includes | `@include('view', $data)`, `@includeIf('view', $data)`, `@includeWhen($condition, 'view', $data)`, `@includeUnless($condition, 'view', $data)`, `@includeFirst(['view1', 'view2'], $data)` |
| Forms | `@csrf`, `@method('PUT')`, `@checked($bool)`, `@disabled($bool)`, `@selected($bool)`, `@readonly($bool)`, `@required($bool)` |
| Attributes | `@class(['btn', 'active' => $active])`, `@style(['color: red' => $error])`, `@attributes($array)` |
| Assets/data | `@vite('app.js')` or `@vite`, `@json($data)`, `@js($data)`, `@share(['key' => $value])` |
| Imports | `@use('App\Models\Post')`, optional alias as second argument |
| Debug/control | `@abort(404, 'Not found')`, `@dump($value)`, `@dd($value)` |
| Raw/PHP | `@php($statement)`, `@php ... @endphp`, `@verbatim ... @endverbatim` |
| Comments | `{{-- removed from output --}}` |

## Named component slots and stacks

### Named slots
Components support named slots via `<x-slot:name>` or `<x-slot name="name">`. Each named slot is an instance of `Spark\View\ComponentSlot` (which implements `Htmlable`), exposing its own `$attributes`, `isEmpty()`, and `isNotEmpty()`. Content outside `<x-slot>` tags belongs to the default `$slot`.

Names are static and camel-cased (`header-title` becomes `$headerTitle`); `slot` and `attributes` are reserved. The shorthand also accepts `</x-slot>`. Slots follow surrounding conditions and loops, capture variables at their source location, and remain scoped to their own component.

```blade
{{-- components/card.blade.php --}}
<div {{ $attributes->merge(['class' => 'card']) }}>
    @if(isset($header) && $header->isNotEmpty())
        <div {{ $header->attributes->merge(['class' => 'card-header']) }}>
            {{ $header }}
        </div>
    @endif
    <div class="card-body">
        {{ $slot }}
    </div>
</div>

{{-- usage --}}
<x-card class="shadow-sm">
    <x-slot:header class="font-bold">
        <span>Featured Post</span>
    </x-slot:header>
    <p>Card body content</p>
</x-card>
```

### Stacks and once
Push scripts or styles onto named stacks from anywhere in templates, included views, or components:

```blade
{{-- layout --}}
<head>
    @stack('styles')
</head>
<body>
    @yield('content')
    @stack('scripts')
</body>

{{-- child template or component --}}
@push('scripts')
    <script src="/js/chart.js"></script>
@endpush

{{-- Ensure a script or style block renders only once per page render --}}
@pushOnce('scripts')
    <script src="/js/vendor-modal.js"></script>
@endpushOnce
```

Stack entries and once markers are scoped to one render, shared by its includes, components, nested renders, and inherited layouts. Repeating a top-level render starts fresh. Once directives deduplicate each compiled block; separate blocks have separate identities.

## Unsupported features and native replacements

| Unsupported | Use instead |
| --- | --- |
| `$loop` | Explicit index/key or controller-prepared state (`$index => $item`) |
| Class components / `<x-dynamic-component>` | Anonymous templates, explicit component choice |
| `@inject`, `@each` | Controller-provided data/helpers; `@foreach` or `@forelse` |

Native `@forelse` works with arrays, collections, and any iterable:

```blade
@forelse($posts as $index => $post)
    <p>{{ $index + 1 }}. {{ $post->title }}</p>
@empty
    <p>No posts available.</p>
@endforelse
```

## Helpers and forms

Never call `Spark\Facades\*` in Blade. Use `user('name')`, `auth()`, `session()`, `config()`, `request()`, `route()`, `now()`, `old()`, `errors()`, and native directives. Keep queries and workflow logic in controllers/services.

```blade
@auth
    <p>Welcome, {{ user('name') }}.</p>
@endauth
<form method="POST" action="{{ route('posts.store') }}">
    @csrf
    <input name="title" value="{{ old('title') }}">
    @error('title')<p>{{ $message }}</p>@enderror
    <x-button type="submit">Publish</x-button>
</form>
```

## Layouts, rendering, and verification

A component layout accepts a title prop and renders `{{ $slot }}` in its body; use `<x-layout title="Posts">...</x-layout>`. Classic layouts remain supported:

```blade
{{-- layouts/app.blade.php --}}
<!doctype html>
<html lang="en">
<head>
    <title>@yield('title', config('app.name'))</title>
    @vite('app.js')
</head>
<body><main>@yield('content')</main></body>
</html>

{{-- posts/index.blade.php --}}
@extends('layouts.app')
@section('title', 'Posts')
@section('content')
    <h1>Posts</h1>
@endsection
```

`view('posts.show', $data)` returns a response; `blade()->render('emails.welcome', $data)` returns HTML. `php spark view:clear` clears compiled templates.

Verify escaping with quotes/markup, component props/default slots/attributes, empty collections, active authorization, and the actual client bindings. Build assets after frontend changes. For adapter navigation and validation, test the adapter protocol as well as full HTML.
