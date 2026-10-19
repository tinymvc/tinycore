# Frontend Setup: Blade, Vite, FireLine, and Inertia

Read this reference when configuring or working with frontend stacks in TinyMVC / TinyCore (Spark): Blade with Tailwind + Alpine, FireLine v2, and Inertia with Vite + React or Vue. Preserve the application's current stack unless explicitly requested to change it.

---

## Supported Frontend Stacks Overview

| Stack | Initial Response | Interaction & Navigation Model | Installed Packages |
| --- | --- | --- | --- |
| **Blade + Tailwind + Alpine** | Full Blade HTML document | Traditional MPA navigation with Alpine.js reactivity | Core framework + Vite |
| **Blade + FireLine v2 + Alpine** | Full layout or fragment JSON | Seamless SPA-like partial visits without full page reloads | `tinymvc/fireline-php`, `fireline@^2.1`, `alpinejs@^3.15` |
| **Inertia + React / Vue** | Adapter root Blade view (`@inertia`) | Client-side SPA navigation powered by React/Vue components | `tinymvc/inertia-php`, `@inertiajs/react` or `@inertiajs/vue3` |

---

## 1. Blade and Vite Baseline (Tailwind + Alpine)

The standard TinyMVC skeleton entry is `resources/app/app.js`, which imports `app.css` and starts Alpine.js.

### Configuration (`vite.config.js`)

```javascript
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss()],
    root: 'resources/app',
    base: '/build/',
    build: {
        outDir: '../../public/build',
        emptyOutDir: true,
        manifest: '.vite/manifest.json',
        rollupOptions: {
            input: 'resources/app/app.js',
        },
    },
    server: {
        strictPort: true,
        port: 5173,
    },
});
```

### Root Layout (`resources/views/layouts/app.blade.php`)

```blade
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite('app.js')
</head>
<body class="bg-gray-50 text-gray-900 font-sans antialiased">
    <main class="container mx-auto px-4 py-8">
        {{ $slot ?? '' }}
        @yield('content')
    </main>
</body>
</html>
```

### Development vs Production Commands

```bash
# Start Vite development server
npm run dev

# Run TinyMVC PHP server
php spark serve

# Build production assets into public/build/
npm run build
```

---

## 2. FireLine v2 Setup (Blade + Tailwind + Alpine + FireLine)

FireLine v2 provides lightweight SPA-like page transitions and AJAX form handling directly inside Blade templates with Alpine.js.

### Installation

```bash
composer require tinymvc/fireline-php
npm install fireline@^2.1 alpinejs@^3.15
```

### Provider & Middleware Registration

1. Add `Spark\Fire\FireServiceProvider::class` in `bootstrap/providers.php`.
2. Add `Spark\Fire\FireMiddleware::class` in `bootstrap/app.php`:

```php
return Application::create(path: dirname(__DIR__))
    ->withMiddleware(
        load: __DIR__ . '/middlewares.php',
        queue: [
            \Spark\Fire\FireMiddleware::class,
            'csrf',
        ]
    );
```

### Client Entry (`resources/app/app.js`)

```javascript
import Alpine from 'alpinejs';
import FireLine from 'fireline';
import './app.css';

window.Alpine = Alpine;
Alpine.plugin(FireLine);

Alpine.start();
```

### Controller Actions

Use the `fire()` helper or `Spark\Fire\Fire::render()` in controllers:

```php
use App\Models\Post;
use Spark\Http\Response;

// Render page (HTML for first load, JSON envelope for FireLine visits)
public function index(): Response
{
    return fire('posts.index', [
        'posts' => Post::published()->latest()->paginate(20),
    ]);
}

// Form write response: returns JSON success envelope and resets form
public function store(StorePostRequest $request): Response
{
    $post = Post::create($request->validated());

    return fire()->success('Post created successfully!', ['id' => $post->id]);
}

// Form error response
public function update(UpdatePostRequest $request, Post $post): Response
{
    $post->fill($request->validated());

    if (!$post->save()) {
        return fire()->error('Could not save post.', 500);
    }

    return fire()->success('Post updated.');
}

// FireLine client navigation
return fire()->navigate(route('posts.show', $post->slug));
```

### Conditional Blade View

```blade
{{-- resources/views/posts/index.blade.php --}}
@if (!is_fire_js())
    @extends('layouts.app')
@endif

@section('title', 'All Posts')

@section('content')
<div id="posts-container" class="space-y-6">
    <h1 class="text-3xl font-bold">Posts</h1>

    {{-- Interactive FireLine form --}}
    <form x-data="{ form: $form() }" x-form action="{{ route('posts.store') }}" method="POST" class="space-y-4">
        @csrf
        <x-forms.input name="title" label="Title" required />
        <x-forms.input name="content" label="Content" required />

        <p x-show="form.hasError('title')" x-text="form.firstError('title')" class="text-red-500 text-sm"></p>
        <p x-show="form.message" x-text="form.message" class="text-green-600 text-sm"></p>

        <x-button type="submit" :disabled="form.processing">Create Post</x-button>
    </form>

    {{-- Page navigation with x-navigate --}}
    <div class="grid gap-4">
        @foreach($posts as $post)
            <div class="p-4 bg-white shadow rounded-lg">
                <h2 class="text-xl font-semibold">{{ $post->title }}</h2>
                <a x-navigate href="{{ route('posts.show', $post->slug) }}" class="text-indigo-600 hover:underline">
                    View &rarr;
                </a>
            </div>
        @endforeach
    </div>
</div>
@endsection

@if (is_fire_js())
    @yield('content')
@endif
```

---

## 3. Inertia Setup (Vite + React / Vue)

For full SPA applications powered by modern JavaScript frameworks like React or Vue, TinyMVC integrates via `tinymvc/inertia-php`.

### Installation

```bash
composer require tinymvc/inertia-php
npm install @inertiajs/react react react-dom
# Or for Vue: npm install @inertiajs/vue3 vue
```

### Provider Registration

Add `Inertia\InertiaServiceProvider::class` in `bootstrap/providers.php`.

### Root View (`resources/views/app.blade.php`)

```blade
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>

    {{-- React Fast Refresh in development --}}
    {!! vite()->reactRefreshTag() !!}
    @vite('app.tsx')
    @inertia
</head>
<body class="font-sans antialiased bg-gray-50 text-gray-900">
    {{-- Inertia mounts root element --}}
</body>
</html>
```

### React Client Entry (`resources/app/app.tsx`)

```tsx
import './app.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });
        return pages[`./pages/${name}.tsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
```

### Controller Actions with Inertia

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Models\Post;
use Inertia\Facades\Inertia;
use Spark\Http\Response;

class PostController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Posts/Index', [
            'posts' => Post::with('author')->latest()->paginate(20),
        ]);
    }

    public function store(StorePostRequest $request): Response
    {
        $post = Post::create($request->validated());

        return to_route('posts.index')
            ->with('status', 'Post created successfully!');
    }
}
```

### Key Inertia Rules:
1. **Always redirect on form writes**: Never return a raw JSON response to an Inertia form submission; return `redirect()` or `to_route()`. Inertia automatically follows redirects and re-renders props seamlessly.
2. **Select explicit props**: Props are client-visible. Filter out sensitive attributes.
3. **Keep shared data in providers**: Share global user state or flash messages via `Inertia::share()`.

---

## Verification Checklist

1. Run `npm run build` and ensure output files exist in `public/build/` with a valid `.vite/manifest.json`.
2. For Blade + Alpine: verify initial load and reactive Alpine components.
3. For FireLine: verify full page load without errors, then check navigation using `x-navigate` and form submission with `x-form` and `$form()`.
4. For Inertia: verify that the page component mounts, client-side links navigate without reloading, and form writes properly redirect with flash state.
