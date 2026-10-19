# Views and Frontend (Blade)

Read this reference for Blade templates, component-first architecture, view helpers, Blade directives, and frontend integrations in TinyMVC / TinyCore (Spark). All paths are relative to the application root.

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
```

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
