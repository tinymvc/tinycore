# FireLine: Blade navigation, forms, and partials

Read this for FireLine setup, page/form actions, partials, or navigation bugs. It describes the FireLine 2.1 client with the `tinymvc/fireline-php` adapter. Verify the target app's Composer/npm locks and adapter/client source before using version-specific features. FireLine is an Alpine plugin; it does not bundle Alpine. See [frontend setup](frontend-setup.md) for Vite and [Blade](views-frontend.md) for component syntax.

## Registration and client startup

For a requested new integration, add compatible `tinymvc/fireline-php`, `fireline`, and `alpinejs` packages. The documented 2.1 client uses Alpine 3.15+ within 3.x. Preserve the application's existing dependencies and providers.

- Register `Spark\Fire\FireServiceProvider::class` in `bootstrap/providers.php`.
- Queue `Spark\Fire\FireMiddleware::class` before the downstream handlers whose responses/validation exceptions it must handle. Keep CSRF middleware enabled. Preserve the rest of `withMiddleware(...)` configuration.
- Register the plugin before the application's single Alpine startup:

```javascript
import './app.css';
import Alpine from 'alpinejs';
import FireLine from 'fireline';

Alpine.plugin(FireLine);
Object.assign(window.FireLine.settings, {
    targetEl: '#app > div',
    focusOnError: true,
});
window.Alpine = Alpine;
Alpine.start();
```

The provider supplies `Route::fire('/about', 'pages/about', $props)` for GET pages, `fire()`, and `is_fire_js()`. A controller can return `fire('pages/about', $props)` or `Spark\Fire\Fire::render(...)`. Do not use removed core APIs `fireline()`, `Router::fireline()`, or `Request::isFirelineRequest()`.

## Full document versus page fragment

The adapter renders the given template; it does not extract a fragment from a full HTML document. For the selector above, the layout contains `<div id="app">@yield('content')</div>` and loads `@vite('app.js')` outside that target. The page template supplies one inner `<div>`:

```blade
{{-- resources/views/pages/about.blade.php --}}
@if (!is_fire_js())
    @extends('layouts/app')
@endif

@section('title')About us@endsection

@section('content')
<div>
    <h1>{{ $message }}</h1>
    <a x-navigate href="/">Home</a>
</div>
@endsection

@if (is_fire_js())
    @yield('content')
@endif
```

The layout should also render `<title>@yield('title')</title>`. The adapter reads that section or accepts an explicit `title:` argument. Navigation replaces the target element itself, so page fragments must have **exactly one element root** that continues to match `targetEl`. Full documents, empty page fragments, and multiple page roots are invalid.

Use `x-navigate` on links. Native/external/download/modified-click links retain browser behavior; automatic link/form interception is opt-in. Keep shared scripts and Alpine initialization outside replaced content.

## Forms and write responses

```blade
<form x-data="{ form: $form() }" x-form action="{{ route('posts.store') }}" method="post">
    @csrf
    <input name="title" type="text" required>
    <p x-show="form.hasError('title')" x-text="form.firstError('title')"></p>
    <p x-text="form.message"></p>
    <button type="submit" :disabled="form.processing">Publish</button>
</form>
```

The example uses a native `<button>`. For an existing Blade component, write `<x-button ::disabled="form.processing">Publish</x-button>` or `x-bind:disabled`; single-colon component bindings evaluate PHP.

Use a FormRequest for validation/authorization and a model/service for the write. Controller action example (import the app's `StorePostRequest`, `Post`, and `Spark\Http\Response`):

```php
public function store(StorePostRequest $request): Response
{
    $post = Post::create($request->validated());

    return fire()->success('Post created.', ['id' => $post->id]);
}
```

`x-form` uses `form` from Alpine scope; `x-form="accountForm"` selects another property. `$form()` exposes `processing`, `message`, `errors`, `status`, `hasError()`, `firstError()`, `reset()`, and `submit()`. Named form controls, files, and the clicked submitter are serialized; arbitrary state properties are not request fields. A success envelope resets controls to defaults, while validation/error results retain input. `form.reset()` only clears feedback state.

| Intent | Adapter API / result |
| --- | --- |
| Render a page or partial | `fire($view, $props)` → `{html, title}` for FireLine; HTML for native visits |
| Report success | `fire()->success($message, $data)` → `{status: 'success', message, data}` |
| Report an application error | `fire()->error($message, 400)` → `{status: 'error', message}` |
| Explicit validation errors | `fire()->handleValidation($message, ['title' => ['Required.']])` → HTTP 422 `{message, errors}` |
| Client navigation | `fire()->navigate((string) route('posts.index'))` → `{navigate: url}` |
| Full browser navigation | `fire()->redirect($url)` → `{redirect: url}` |

`FireMiddleware` converts downstream Spark validation exceptions into the 422 envelope for FireLine. Keep `@csrf`; for PATCH/PUT/DELETE use POST plus `@method(...)`, or the existing client's supported request method. FireLine can send a configured server-generated CSRF token; it does not create one.

Navigation envelopes use HTTP 200; their status argument applies to native redirects. Success/error/validation helpers always return JSON, even without JavaScript. If native form fallback is required, branch on `is_fire_js()` and return a normal post/redirect/get response for native requests. Do not send raw HTML, 204/empty responses, or custom JSON shapes to a client expecting a FireLine envelope.

## Partials, preloads, and state

- `x-preload` / `x-preload.hover` preload safe GET destinations. `is_fire_preload()` detects them. Return the same page fragment; omit only optional effects such as page-view analytics. Do not mutate state on a preloaded GET.
- `x-poll="10000"` refreshes at a 10-second interval. Polling pauses while hidden/offline or during foreground work. Choose an interval justified by the UI.
- `$partial(url)` with `x-partial="state"` loads into the host's **children**, preserving the host. `state.load()` replaces/reconciles children; `state.loadMore(nextUrl)` appends. `x-partial.lazy` loads when visible. Keep persistent controls outside the host.
- Detect partial requests with `is_fire_partial()`. Return `Fire::render($fragmentView, $props)` without a layout. Partial HTML may contain multiple roots or be empty, but still requires a JSON `html` envelope. Partials do not follow navigation envelopes or update history/title.
- Use unique stable `key` attributes for reordered server-rendered children. Alpine `:key` belongs to `x-for`. Keep third-party widgets behind an appropriate `x-ignore` boundary; avoid competing `x-html`/`x-text` on partial hosts.
- `$fire.navigate(url)`, `$fire.reload()`, and `$fire.replaceHtml(html)` are asynchronous. Await sequencing. `fireEnd` includes failures/cancellations and is not proof of success. Partial/preload requests do not emit global request events.

## Headers, deployment, and verification

Requests use `X-FireLine: 1`, `X-Requested-With: XMLHttpRequest`, and normally `Accept: application/json`. Detection selects representation, not authorization. Preserve adapter `Vary` and cache-control headers so full documents and envelopes are not mixed by caches.

When enabling asset versioning, assign the same build identifier to client `assetVersion` and server `Fire::version($version)` before rendering. This adapter does not derive a Vite version automatically. Encode client settings safely; do not interpolate unescaped data into scripts.

Test direct HTML loads, client navigation/back/forward, successful writes, invalid input (422 field arrays), CSRF rejection, and auth failures. When touching partials/preloads/versioning, also test cleanup, matching fragment roots, and asset mismatch behavior. Build changed frontend assets. Inspect installed adapter `src/FireService.php`, `FireMiddleware.php`, `FireHeaders.php`, and the client package for exact behavior; do not assume v1 APIs still exist.
