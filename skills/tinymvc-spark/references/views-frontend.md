# Views and Frontend (Blade)

Read this reference for Blade templates, component-first architecture, view helpers, all native Blade directives, and frontend integrations in TinyMVC / TinyCore (Spark). All paths are relative to the application root. For adapter behavior, read [FireLine](fireline.md) or [Inertia](inertia.md); for asset configuration, read [frontend setup](frontend-setup.md).

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

{{-- 7. Literal Alpine / client bindings using double-colon prefix --}}
<x-button ::disabled="form.processing">Publish</x-button>
```

### Component Attributes (`Spark\View\Attributes`)

Inside component templates, `$attributes` manages HTML attributes:
* `$attributes->merge(['class' => 'default-class'])`: Merges defaults with consumer-provided attributes without clobbering.
* `$attributes->class(['base-btn', 'active' => $isActive, 'opacity-50' => $disabled])`: Conditionally compiles class strings.
* `$attributes->has('id')`: Checks if an attribute exists.
* `$attributes->get('name', 'default')`: Reads attribute value.
* `$attributes->except('class')`: Excludes attributes.
* `$attributes->props(['title' => 'Default'])`: Extracts declared component props.

### Required Escaping and Slot Standards

| Syntax | TinyCore behavior | Use |
| --- | --- | --- |
| `{{ $value }}` | Calls `e($value)`; escapes ordinary values and honors `Spark\Contracts\Support\Htmlable` | Default for output, including user data |
| `{!! $value !!}` | Echoes the value without escaping | Intentionally trusted HTML only |
| `{{ $slot }}` | Renders an HTML-safe `Spark\Support\HtmlString` containing rendered child markup | Standard default-slot output; never wrap in raw `{!! !!}` |
| `title="Hello {{ $name }}"` on `<x-...>` | Builds a string, escaping each `{{ }}` expression | Text interpolation, including URLs such as `href="{{ route('register') }}"` |
| `title="{!! $value !!}"` on `<x-...>` | Builds a string without escaping that expression | Trusted raw interpolation |
| `:value="$expression"` / `:$value` | Passes the PHP value, preserving its type | Arrays, booleans, numbers, objects, or direct expressions |
| `::class="{ active: isActive }"` | Passes a literal `:class` attribute to output | Alpine/client expressions on components |
| `@{{ clientValue }}` / `@{!! clientHtml !!}` | Removes the leading `@` and leaves echo syntax literal | Client templates (Alpine, Vue) |
| `@@directive` | Outputs literal `@directive` | Escaping a Blade directive |

---

## 2. Exhaustive Blade Directives Reference

TinyCore's Blade compiler features a rich, native directive set. Below is the complete catalog of supported directives:

### 2.1 Conditionals

| Directive | Compiled PHP Equivalent | Description |
| --- | --- | --- |
| `@if($condition)` | `if($condition):` | Opens an if block |
| `@elseif($condition)` | `elseif($condition):` | Else-if condition branch |
| `@else` | `else:` | Else fallback branch |
| `@endif` | `endif;` | Closes any open conditional block |
| `@unless($condition)` | `if(!($condition)):` | Executes block if condition is false |
| `@endunless` | `endif;` | Closes an unless block |
| `@isset($var)` | `if(isset($var)):` | Executes block if variable is set and not null |
| `@endisset` | `endif;` | Closes an isset block |
| `@empty($var)` | `if(empty($var)):` | Executes block if variable is empty |
| `@endempty` | `endif;` | Closes an empty block |
| `@can('ability', $model)` | `if(can('ability', $model)):` | Checks authorization Gate |
| `@endcan` | `endif;` | Closes a can block |
| `@cannot('ability', $model)`| `if(cannot('ability', $model)):` | Inverse authorization Gate check |
| `@endcannot` | `endif;` | Closes a cannot block |
| `@hasSection('name')` | `if($this->hasSection('name')):` | Checks if section exists |
| `@endhasSection` | `endif;` | Closes hasSection block |
| `@sectionMissing('name')` | `if(!$this->hasSection('name')):` | Checks if section is missing |
| `@endsectionMissing` | `endif;` | Closes sectionMissing block |
| `@session('key')` | `if(session()->has('key')):` | Checks if session key exists |
| `@endsession` | `endif;` | Closes session block |
| `@switch($expr)` | `switch($expr): ...` | Opens a switch block |
| `@case($val)` | `case $val:` | Switch case branch |
| `@default` | `default:` | Switch default branch |
| `@endswitch` | `endswitch;` | Closes a switch block |

### 2.2 Loops

| Directive | Compiled PHP Equivalent | Description |
| --- | --- | --- |
| `@foreach($items as $item)` | `foreach($items as $item):` | Standard foreach loop |
| `@endforeach` | `endforeach;` | Closes foreach loop |
| `@for($i = 0; $i < 10; $i++)` | `for(...):` | Standard for loop |
| `@endfor` | `endfor;` | Closes for loop |
| `@while($condition)` | `while($condition):` | Standard while loop |
| `@endwhile` | `endwhile;` | Closes while loop |
| `@break` | `break;` | Breaks out of loop |
| `@continue` | `continue;` | Continues to next iteration |

### 2.3 Layouts & Sections

| Directive | Compiled PHP Equivalent | Description |
| --- | --- | --- |
| `@use('App\Models\Post')` | `use App\Models\Post;` | Imports class into compiled template |
| `@use('App\Models\Post', 'Article')` | `use App\Models\Post as Article;` | Imports class with alias |
| `@extends('layouts.app')` | `$this->setExtends('layouts.app');` | Declares parent layout template |
| `@section('name')` | `$this->startSection('name');` | Starts capturing section content |
| `@section('name', 'Value')` | `$this->startSection('name'); echo 'Value'; $this->endSection();` | Short inline section definition |
| `@endsection` | `$this->endSection();` | Closes section capture |
| `@stop` | `$this->endSection();` | Synonym for `@endsection` |
| `@show` | `echo $this->stopAndYieldSection();` | Closes section and yields it immediately |
| `@yield('name', 'Default')`| `echo $this->yieldContent('name', 'Default');` | Yields section content in layout |

### 2.4 Partials & Includes

| Directive | Compiled PHP Equivalent | Description |
| --- | --- | --- |
| `@include('path.view', ['k' => $v])` | `echo $this->include('path.view', ['k' => $v]);` | Includes partial view with local context |
| `@includeIf('path.view', [...])` | Checks if template exists before including | Safe include without throwing missing template error |
| `@includeWhen($bool, 'view', [...])`| Includes template only if `$bool` evaluates to true | Conditional include |

### 2.5 Form & HTML Helpers

| Directive | Compiled PHP Equivalent | Description |
| --- | --- | --- |
| `@csrf` | `<?= csrf(); ?>` | Outputs hidden input with CSRF token |
| `@method('PUT')` | `echo method('PUT');` | Outputs hidden `_method` input |
| `@checked($bool)` | `echo ($bool) ? 'checked' : '';` | Outputs checked attribute |
| `@disabled($bool)` | `echo ($bool) ? 'disabled' : '';` | Outputs disabled attribute |
| `@selected($bool)` | `echo ($bool) ? 'selected' : '';` | Outputs selected attribute |
| `@readonly($bool)` | `echo ($bool) ? 'readonly' : '';` | Outputs readonly attribute |
| `@required($bool)` | `echo ($bool) ? 'required' : '';` | Outputs required attribute |
| `@class(['btn', 'active' => $ok])` | `echo $this->compileAttributes(['class' => ...]);` | Compiles HTML class string conditionally |
| `@style(['color: red' => $err])` | `echo $this->compileAttributes(['style' => ...]);` | Compiles inline style string conditionally |
| `@attributes($array)` | `echo $this->compileAttributes($array);` | Compiles an associative array of HTML attributes |

### 2.6 Auth, Gate & Session Helpers

| Directive | Compiled PHP Equivalent | Description |
| --- | --- | --- |
| `@auth` | `if(is_logged()):` | Conditional block for authenticated user |
| `@auth('admin')` | `if(is_logged('admin')):` | Guard-specific authentication block |
| `@endauth` | `endif;` | Closes auth block |
| `@guest` | `if(is_guest()):` | Conditional block for unauthenticated guest |
| `@guest('admin')` | `if(is_guest('admin')):` | Guard-specific guest block |
| `@endguest` | `endif;` | Closes guest block |
| `@authorize('ability', $model)` | `authorize('ability', $model);` | Runs authorization gate check inline (aborts 403 on failure) |
| `@error('field')` | `if(errors()->any() && errors()->has('field')): $message = errors()->first('field');` | Exposes `$message` for first error on field |
| `@enderror` | `endif;` | Closes error block |
| `@errors('field')` | Loops through all error messages for field as `$message` | Loops over each error message |
| `@enderrors` | `endforeach; endif;` | Closes errors loop |
| `@old('field', 'default')` | `echo e(old('field', 'default'));` | Escapes and echoes old input |

### 2.7 Output, Scripts & Utilities

| Directive | Compiled PHP Equivalent | Description |
| --- | --- | --- |
| `@json($data)` | `echo \Spark\Support\Js::from($data);` | Encodes data safely for JavaScript in script tags |
| `@js($data)` | `echo \Spark\Support\Js::from($data);` | Synonym for `@json` |
| `@vite('app.js')` | `echo vite('app.js');` | Generates script/link tags for Vite asset |
| `@vite` | `<?= vite(); ?>` | Parameterless Vite output |
| `@abort(404, 'Not found')` | `abort(404, 'Not found');` | Aborts execution with HTTP status and message |
| `@dump($var)` | `dump($var);` | Dumps variable without terminating execution |
| `@dd($var)` | `dd($var);` | Dumps variable and dies |
| `@share(['key' => $val])` | `$this->share(['key' => $val]);` | Shares data into the current view context |

### 2.8 Raw Blocks & PHP Execution

| Directive | Description |
| --- | --- |
| `{{-- comment --}}` | Blade comment (completely stripped from compiled output) |
| `@php($expr)` | Inline PHP statement (e.g. `@php($count = $posts->count())`) |
| `@php ... @endphp` | Raw multi-line PHP block |
| `@verbatim ... @endverbatim` | Preserves raw text without evaluating Blade syntax or `{{ }}` |

---

## 3. Strict Guardrails: Banned / Hallucination Traps

> [!CAUTION]
> **Do NOT Hallucinate Unsupported Features!**
> The following directives and variables are common in Laravel but **DO NOT EXIST in TinyCore/Spark Blade**.
> Always use the documented native Spark replacements below.

### ❌ Hallucination 1: `@forelse` and `@empty` loop branch
TinyCore's Blade compiler does **not** compile `@forelse`. Using it will produce broken or invalid PHP.
* **Incorrect ❌**:
  ```blade
  @forelse($posts as $post)
      <p>{{ $post->title }}</p>
  @empty
      <p>No posts available.</p>
  @endforelse
  ```
* **Native Spark Replacement ✅**:
  ```blade
  @if(!empty($posts))
      @foreach($posts as $post)
          <p>{{ $post->title }}</p>
      @endforeach
  @else
      <p>No posts available.</p>
  @endif
  ```

### ❌ Hallucination 2: The `$loop` variable in `@foreach`
Inside `@foreach`, the `$loop` variable (`$loop->first`, `$loop->last`, `$loop->iteration`, `$loop->index`) does **not** exist in Spark.
* **Incorrect ❌**:
  ```blade
  @foreach($items as $item)
      <li class="{{ $loop->first ? 'font-bold' : '' }}">{{ $item }}</li>
  @endforeach
  ```
* **Native Spark Replacement ✅**:
  ```blade
  @foreach($items as $index => $item)
      <li class="{{ $index === 0 ? 'font-bold' : '' }}">{{ $item }}</li>
  @endforeach
  ```

### ❌ Hallucination 3: `@push`, `@stack`, `@prepend`
TinyCore does not have a stack system.
* **Incorrect ❌**:
  ```blade
  @push('scripts')
      <script src="/page.js"></script>
  @endpush
  ```
* **Native Spark Replacement ✅**:
  Use layout sections (`@section('scripts') ... @endsection`) and yield them in the layout (`@yield('scripts')`), or bundle scripts with Vite.

### ❌ Hallucination 4: Named slots (`<x-slot:name>` or `<x-slot name="name">`)
TinyCore's component compiler supports **anonymous components with a single default `$slot`**. Named slots are not implemented.
* **Incorrect ❌**:
  ```blade
  <x-card>
      <x-slot:header>Title</x-slot:header>
      Body content
  </x-card>
  ```
* **Native Spark Replacement ✅**:
  Pass secondary slots or headers as component props or attributes:
  ```blade
  <x-card title="Title">
      Body content
  </x-card>
  ```
  Or use classic layout templates with `@extends` and `@section`.

### ❌ Hallucination 5: Class-based components & `<x-dynamic-component>`
TinyCore does **not** support class-based components (`App\View\Components\*`) or `<x-dynamic-component>`. All components are anonymous template files located in `resources/views/components/`.

### ❌ Hallucination 6: Conditional `@break($condition)` and `@continue($condition)`
In Spark, `@break` and `@continue` take no arguments.
* **Incorrect ❌**: `@break($user->is_blocked)`
* **Native Spark Replacement ✅**:
  ```blade
  @if($user->is_blocked)
      @break
  @endif
  ```

### ❌ Hallucination 7: `@once`, `@inject`, `@each`
These directives do not exist in Spark.
* Use `@use('App\Services\PaymentGateway')` or global helper `app(PaymentGateway::class)` instead of `@inject`.
* Use `@foreach` instead of `@each`.

---

## 4. Helpers vs. Facades in Blade Views

> [!CAUTION]
> **Strict Rule: Never Use Static Facades in Blade Views!**
> Do **NOT** use `\Spark\Facades\*` (e.g. `\Spark\Facades\Session`, `\Spark\Facades\Auth`, `\Spark\Facades\App`, `\Spark\Facades\Route`) inside Blade templates.
> In views, **always use native Spark global helpers and directives**.

| Purpose | Banned in Blade ❌ | Native & Smart in Blade ✅ |
| --- | --- | --- |
| **Session Flash** | `\Spark\Facades\Session::get('status')` | `session('status')` or `@session('status') ... @endsession` |
| **Session Check** | `\Spark\Facades\Session::has('error')` | `session()->has('error')` |
| **Auth User** | `\Spark\Facades\Auth::user()` | `auth()->user()`, `user()`, or `user('email')` |
| **Auth Check** | `\Spark\Facades\Auth::check()` | `@auth ... @endauth` or `@guest ... @endguest` |
| **App / Services** | `\Spark\Facades\App::make('cache')` | `app('cache')` or `app(SomeService::class)` |
| **Config Value** | `\Spark\Facades\Config::get('app.name')` | `config('app.name')` |
| **Current Request** | `\Spark\Facades\Request::input('q')` | `request('q')` |
| **Route URL** | `\Spark\Facades\Route::url(...)` | `route('posts.show', $post->slug)` |
| **Current Time** | `\Spark\Facades\Date::now()` or `date()` | `now()->year` or `today()->toDateString()` |
| **CSRF Token** | `\Spark\Facades\Session::token()` | `@csrf` or `csrf_token()` or `csrf_field()` |
| **Authorization** | `\Spark\Facades\Gate::allows(...)` | `@can('update-post', $post) ... @endcan` or `@cannot` |
| **Validation Error** | Manual `$session->get('errors')` | `@error('field') {{ $message }} @enderror` |

### Idiomatic Blade View Example

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

## 5. Layout Architectures: Components vs. Sections

### Approach A: Component-Based Layouts (Recommended)

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
        @if(!empty($posts))
            @foreach($posts as $post)
                <x-card :title="$post->title">
                    <p class="text-gray-600">{{ $post->excerpt }}</p>
                    <div class="mt-4">
                        <a href="{{ route('posts.show', $post->slug) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">Read more &rarr;</a>
                    </div>
                </x-card>
            @endforeach
        @else
            <p class="text-gray-500">No posts published yet.</p>
        @endif
    </div>
</x-layout>
```

### Approach B: Classic Section Layouts (`@extends`)

```blade
{{-- layouts/app.blade.php --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
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

## 6. Rendering from Controllers & Clearing Cache

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
