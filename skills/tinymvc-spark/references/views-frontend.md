# Views and Frontend (Blade)

Read this reference for Blade templates, component-first architecture, view helpers, Blade directives, and frontend integrations in TinyMVC / TinyCore (Spark). All paths are relative to the application root. For adapter behavior, read [FireLine](fireline.md) or [Inertia](inertia.md); for asset configuration, read [frontend setup](frontend-setup.md).

---

## 1. Modern Component-First UI (`<x-component>`)

> [!IMPORTANT]
> **Component-First Standard**:
> Always use Blade components (`<x-component ...>`) for reusable UI elements (alerts, buttons, cards, modals, navigation, form inputs).
> **Never use `@include('partials.alert')` for UI components.** Reserve `@include` strictly for non-componentized document sections or large email template pieces.

### Defining Components

Component templates live in `resources/views/components/`. Use `@props` for declared data and `$attributes` for remaining HTML attributes:

```blade
{{-- resources/views/components/alert.blade.php --}}
@props([
    'type' => 'info',
    'message' => null,
])

<div {{ $attributes->merge(['class' => 'alert alert-' . $type . ' p-4 rounded-lg']) }} role="alert">
    {{ $message ?? $slot }}
</div>
```

```blade
{{-- resources/views/components/button.blade.php --}}
@props(['type' => 'button', 'variant' => 'primary'])

<button type="{{ $type }}" {{ $attributes->class(['btn font-semibold px-4 py-2 rounded', 'btn-' . $variant => $variant]) }}>
    {{ $slot }}
</button>
```

```blade
{{-- resources/views/components/forms/input.blade.php --}}
@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
])

<div>
    @if($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</label>
    @endif
    <input
        type="{{ $type }}"
        name="{{ $name }}"
        id="{{ $name }}"
        value="{{ old($name, $value) }}"
        {{ $attributes->merge(['class' => 'form-input w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500']) }}
    >
    @error($name)
        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
    @enderror
</div>
```

### Using Components in Views

TinyCore supports clean, expressive component syntax:

```blade
{{-- 1. Variable prop shorthand (:$message is identical to :message="$message") --}}
<x-alert :$message type="success" />

{{-- 2. Explicit bound expression (:message) for custom variables or expressions --}}
<x-alert :message="$errorMessage" type="warning" class="mb-4" />

{{-- 3. Block component with slot content ($slot) --}}
<x-alert type="danger">
    <strong>Error!</strong> Could not connect to payment gateway.
</x-alert>

{{-- 4. Nested components via dot notation (components/forms/input.blade.php) --}}
<x-forms.input name="email" label="Email Address" type="email" required />

{{-- 5. Action buttons with slot content --}}
<x-button type="submit" variant="primary">Save Changes</x-button>

{{-- 6. String interpolation in component attributes --}}
<x-link href="{{ route('register') }}" variant="primary" x-navigate>Register</x-link>
```

### Required Escaping and Slot Standards

Follow these rules strictly when generating or reviewing Blade templates:

| Syntax | TinyCore behavior | Use |
| --- | --- | --- |
| `{{ $value }}` | Calls `e($value)`; escapes ordinary values and honors `Spark\Contracts\Support\Htmlable` | Default for output, including user data |
| `{!! $value !!}` | Echoes the value without escaping | Intentionally trusted HTML only |
| `{{ $slot }}` | Renders an HTML-safe `Spark\Support\HtmlString` containing the already rendered child template | Standard default-slot output; do not require a raw-echo workaround |
| `title="Hello {{ $name }}"` on `<x-...>` | Builds a string, escaping each `{{ }}` expression | Text interpolation, including URLs such as `href="{{ route('register') }}"` |
| `title="{!! $value !!}"` on `<x-...>` | Builds a string without escaping that expression | Trusted raw interpolation; subsequent output still follows the component template's escaping rules |
| `:value="$expression"` / `:$value` | Passes the PHP value, preserving its type | Arrays, booleans, numbers, objects, or direct expressions; escape ordinary values when outputting them |
| `::class="{ active: isActive }"` | Passes a literal `:class` attribute | Alpine/client expressions |
| `@{{ clientValue }}` / `@{!! clientHtml !!}` | Removes the leading `@` and leaves the echo syntax literal | Client templates |
| `@@if(...)` | Outputs literal `@if(...)` | Escaping a Blade directive |

`{{ $slot }}` retaining HTML is standard Laravel-style behavior: safe HTML implements `Htmlable`; the compiler must still call `e()`. TinyCore uses its existing `HtmlString` rather than Laravel's `ComponentSlot` class. Slots support `toHtml()`, `isEmpty()`, `isNotEmpty()`, and string conversion. Do not assume Laravel-only slot methods or attributes exist.

Child markup renders in the calling view's scope, including loop variables. Ordinary `{{ $userInput }}` inside that markup is escaped before it becomes the slot. The component preserves that rendered markup without escaping it again. Slot edges are trimmed; internal whitespace and the text `0` are preserved. Never wrap untrusted, unrendered user input in `HtmlString` to bypass escaping.

Mixed attribute values support literal text and multiple escaped/raw expressions. Interpolated attributes are strings; use `:prop` when the PHP type matters. `$attributes` escapes serialized HTML attribute values without double encoding existing entities. A raw expression in an attribute does not disable escaping when the component later renders it through `{{ $prop }}` or `$attributes`.

These conventions match Laravel's [attribute echo compiler](https://github.com/laravel/framework/blob/13.x/src/Illuminate/View/Compilers/ComponentTagCompiler.php) and [HTML-safe slots](https://github.com/laravel/framework/blob/13.x/src/Illuminate/View/ComponentSlot.php). TinyCore's installed source remains the API authority.

### Supported Surface and Laravel Differences

TinyCore supports anonymous view components, default slots, `@props`, the attribute bag, and the directives listed below. Do not invent support for Laravel class components, named `<x-slot>` slots, `<x-dynamic-component>`, `@aware`, `$loop`, `@forelse`, `@once`, or stack/push directives. Register an explicit custom directive or use the documented native syntax when needed.

Use `@class` and `@style` on ordinary HTML tags. On component tags, pass a bound class/style value, such as `<x-button :class="['active' => $active]" />`. Pass include data explicitly (`@include('document', ['name' => $name])`); TinyCore does not automatically inherit all caller locals for includes. Use `@if($condition) @break @endif` for conditional loop exits; conditional `@break(...)` / `@continue(...)` are not implemented.

Quote component attribute values containing spaces or PHP operators. Use the opposite quote type inside their PHP expressions, following ordinary HTML attribute syntax. Native PHP and `@php ... @endphp` blocks remain opaque to Blade compilation, as do `@verbatim` blocks. Malformed component opening/closing tags, unclosed raw blocks, and unbalanced directive parentheses produce compile-time exceptions. Templates are trusted application source; do not compile user-supplied templates.

### Component Attributes (`Spark\View\Attributes`)

Inside component templates, `$attributes` provides powerful fluent helpers:
* `$attributes->merge(['class' => 'default-class'])`: Merges defaults with consumer-provided attributes without clobbering.
* `$attributes->class(['base-btn', 'active' => $isActive, 'opacity-50' => $disabled])`: Conditionally compiles class strings.
* `$attributes->has('id')`: Checks if an attribute exists.
* `$attributes->get('name', 'default')`: Reads attribute value.
* `$attributes->except('class')`: Excludes attributes.

---

## 2. Helpers vs. Facades in Blade Views

> [!CAUTION]
> **Strict Rule: Never Use Static Facades in Blade Views!**
> Do **NOT** use `\Spark\Facades\*` (e.g. `\Spark\Facades\Session`, `\Spark\Facades\Auth`, `\Spark\Facades\App`) inside Blade templates.
> In views, **always use native Spark global helpers and directives**.

| Purpose | Non-Smart / Banned in Blade ❌ | Smart & Idiomatic in Blade ✅ |
| --- | --- | --- |
| **Session Flash** | `\Spark\Facades\Session::get('status')` | `session('status')` or `@session('status') ... @endsession` |
| **Session Check** | `\Spark\Facades\Session::has('error')` | `session()->has('error')` |
| **Auth User** | `\Spark\Facades\Auth::user()` | `auth()->user()` or `user()` or `user('email')` |
| **Auth Check** | `\Spark\Facades\Auth::check()` | `@auth ... @endauth` or `@guest ... @endguest` |
| **App / Services** | `\Spark\Facades\App::make('cache')` | `app('cache')` or `app(SomeService::class)` |
| **Config Value** | `\Spark\Facades\Config::get('app.name')` | `config('app.name')` |
| **Current Request** | `\Spark\Facades\Request::input('q')` | `request('q')` |
| **Route URL** | `\Spark\Facades\Route::url(...)` | `route('posts.show', $post->slug)` |
| **Current Time** | `\Spark\Facades\Date::now()` or `date()` | `now()->year` or `today()->toDateString()` |
| **CSRF Token** | `\Spark\Facades\Session::token()` | `@csrf` or `csrf_token()` or `csrf_field()` |
| **Authorization** | `\Spark\Facades\Gate::allows(...)` | `@can('update-post', $post) ... @endcan` or `@cannot` |
| **Validation Error** | Manual `$session->get('errors')` | `@error('field') {{ $message }} @enderror` |

### Blade Helper & Directives Example

```blade
@auth
    <span>Welcome back, {{ user('name') }}!</span>
@endauth

@session('status')
    <x-alert :message="session('status')" type="success" />
@endsession

<form method="POST" action="{{ route('posts.store') }}">
    @csrf
    <x-forms.input name="title" :value="old('title')" placeholder="Post title" />

    @error('title')
        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
    @enderror

    <x-button type="submit">Publish</x-button>
</form>
```

---

## 3. Layouts: Components vs. Sections

### Component-Based Layouts (Recommended)

Create `resources/views/components/layout.blade.php`:

```blade
@props(['title' => config('app.name')])
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite('app.js')
</head>
<body class="bg-gray-50 text-gray-900 font-sans antialiased">
    <x-navigation />
    <main class="container mx-auto px-4 py-8">
        {{ $slot }}
    </main>
</body>
</html>
```

Use in a page view:

```blade
<x-layout title="All Posts">
    <h1 class="text-3xl font-bold mb-6">Blog Posts</h1>
    <div class="grid gap-6 md:grid-cols-2">
        @foreach($posts as $post)
            <x-card :title="$post->title">
                <p class="text-gray-600">{{ $post->excerpt }}</p>
                <div class="mt-4">
                    <a href="{{ route('posts.show', $post->slug) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">Read more &rarr;</a>
                </div>
            </x-card>
        @endforeach
    </div>
</x-layout>
```

### Classic Section Layouts (`@extends`)

```blade
{{-- layouts/app.blade.php --}}
<!doctype html>
<html>
<head>
    <title>@yield('title', config('app.name'))</title>
    @vite('app.js')
</head>
<body>
    <x-navigation />
    <main>@yield('content')</main>
</body>
</html>

{{-- posts/index.blade.php --}}
@extends('layouts.app')
@section('title', 'Posts')

@section('content')
    <h1>Posts</h1>
@endsection
```

---

## 4. Blade Directives Reference

| Directive | Purpose | Example |
| --- | --- | --- |
| `@props(['name', 'type' => 'text'])` | Declares component props in component files | `@props(['variant' => 'primary'])` |
| `@use('Namespace\Class', 'Alias')` | Imports a PHP class into the compiled template | `@use('App\Models\Post')` |
| `@if`, `@elseif`, `@else`, `@endif` | Conditional blocks | `@if($user->isAdmin())` |
| `@unless`, `@endunless` | Inverse conditional | `@unless($post->is_published)` |
| `@isset`, `@empty` | Variable state checks | `@isset($user->avatar)` |
| `@switch`, `@case`, `@default`, `@break`, `@endswitch` | Switch statement | `@switch($status) @case('active') ... @break @endswitch` |
| `@foreach`, `@for`, `@while` | Loops | `@foreach($posts as $post) ... @endforeach` |
| `@class([...])` | Conditional CSS classes | `<div @class(['p-4', 'bg-red-50' => $hasError])>` |
| `@style([...])` | Conditional inline styles | `<div @style(['background-color: red' => $hasError])>` |
| `@checked($bool)`, `@selected($bool)` | Form state attributes | `<input type="checkbox" @checked($user->active)>` |
| `@disabled($bool)`, `@readonly($bool)`, `@required($bool)` | Form control attributes | `<input @disabled($isLocked)>` |
| `@csrf`, `@method('PUT')` | CSRF token & HTTP method spoofing | `@csrf @method('PATCH')` |
| `@error('field') ... @enderror` | Renders validation error exposing `$message` | `@error('email') <p>{{ $message }}</p> @enderror` |
| `@errors('field') ... @enderrors` | Loops over all errors for a field | `@errors('tags') <p>{{ $message }}</p> @enderrors` |
| `@session('key') ... @endsession` | Session presence block | `@session('status') <p>{{ session('status') }}</p> @endsession` |
| `@auth($guard)`, `@guest($guard)` | Authentication state blocks | `@auth <span>{{ user('name') }}</span> @endauth` |
| `@can('ability', $model)`, `@cannot` | Gate authorization checks | `@can('update-post', $post) <a href="...">Edit</a> @endcan` |
| `@json($data)` | Encodes data safely for JS scripts (`Js::from`) | `const user = @json($user);` |
| `@vite('app.js')` | Renders Vite script and style tags | `@vite('app.js')` |
| `@verbatim ... @endverbatim` | Preserves raw Alpine/Vue template brackets | `@verbatim <span>{{ alpineVar }}</span> @endverbatim` |

---

## 5. Rendering from Controllers & Clearing View Cache

```php
// Return View Response from Controller
return view('posts.show', ['post' => $post]);

// Render HTML String directly (e.g. for notifications or emails)
$html = blade()->render('emails.welcome', ['user' => $user]);
```

Clear compiled template cache:

```bash
php spark view:clear
```
