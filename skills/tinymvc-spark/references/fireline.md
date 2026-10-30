# FireLine: Blade Navigation, Forms, and Partials

> **Read when:** setting up FireLine, writing page/form actions or partials, or debugging navigation.
> **Related:** [Frontend setup](frontend-setup.md) (Vite) · [Blade views](views-frontend.md) (component syntax) · [Application patterns](application-patterns.md)
> Describes the **FireLine 2.1** client with the `tinymvc/fireline-php` adapter. Verify the target app's Composer/npm locks and the adapter/client source before using version-specific features. FireLine is an Alpine plugin; it does **not** bundle Alpine.

Contents:

- [Registration and client startup](#registration-and-client-startup)
- [Full document vs page fragment](#full-document-versus-page-fragment)
- [Forms and write responses](#forms-and-write-responses)
- [Partials, preloads, polling, state](#partials-preloads-polling-and-state)
- [Headers, deployment, verification](#headers-deployment-and-verification)

## Registration and client startup

For a requested new integration, add compatible `tinymvc/fireline-php`, `fireline`, and `alpinejs` packages (the 2.1 client documents Alpine 3.15+ within 3.x). Preserve the app's existing dependencies and providers.

**Server**

- Register `Spark\Fire\FireServiceProvider::class` in `bootstrap/providers.php`.
- Queue `Spark\Fire\FireMiddleware::class` **before** the downstream handlers whose responses/validation exceptions it must handle. Keep CSRF middleware enabled and preserve the rest of the `withMiddleware(...)` config.
- The provider supplies `Route::fire('/about', 'pages/about', $props)` for GET pages, plus `fire()` and `is_fire_js()`. A controller can return `fire('pages/about', $props)` or `Spark\Fire\Fire::render(...)`.
- **Removed core APIs, do not use:** `fireline()`, `Router::fireline()`, `Request::isFirelineRequest()`.

**Client**: register the plugin before the app's single Alpine startup.

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

## Full document versus page fragment

The adapter renders the template you give it; it does **not** extract a fragment from a full HTML document. With the `targetEl` above, the layout contains `<div id="app">@yield('content')</div>` and loads `@vite('app.js')` outside that target. The page template supplies one inner `<div>`:

```blade
{{-- resources/views/pages/about.blade.php --}}
@if (!is_fire_js())
    @extends('layouts/app')
@endif

@section('title', 'About us')

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

- The layout should render `<title>@yield('title')</title>`. The adapter reads that section or an explicit `title:` argument.
- Navigation replaces the target element itself, so a page fragment must have **exactly one element root** that continues to match `targetEl`. Full documents, empty fragments, and multiple roots are invalid.
- Use `x-navigate` on links. Native, external, download, and modified-click links keep browser behavior; automatic link/form interception is opt-in.
- Keep shared scripts and Alpine initialization outside replaced content.

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

For an existing Blade component, bind with `<x-button ::disabled="form.processing">Publish</x-button>` or `x-bind:disabled`: single-colon component bindings evaluate PHP.

Use a FormRequest for validation/authorization and a model/service for the write:

```php
// import the app's StorePostRequest, Post, and Spark\Http\Response
public function store(StorePostRequest $request): Response
{
    $post = Post::create($request->validated());

    return fire()->success('Post created.', ['id' => $post->id]);
}
```

**Client form behavior**

- `x-form` uses `form` from Alpine scope; `x-form="accountForm"` selects another property.
- `$form()` exposes `processing`, `message`, `errors`, `status`, `hasError()`, `firstError()`, `reset()`, `submit()`.
- Named controls, files, and the clicked submitter are serialized; arbitrary state properties are not request fields.
- A success envelope resets controls to defaults; validation/error results retain input. `form.reset()` only clears feedback state.

**Adapter response API**

| Intent | Call | Result |
| --- | --- | --- |
| Render page/partial | `fire($view, $props)` | `{html, title}` for FireLine; HTML for native visits |
| Success | `fire()->success($message, $data)` | `{status: 'success', message, data}` |
| Application error | `fire()->error($message, 400)` | `{status: 'error', message}` |
| Explicit validation errors | `fire()->handleValidation($message, ['title' => ['Required.']])` | HTTP 422 `{message, errors}` |
| Client navigation | `fire()->navigate((string) route('posts.index'))` | `{navigate: url}` |
| Full browser navigation | `fire()->redirect($url)` | `{redirect: url}` |

**Rules**

- `FireMiddleware` converts downstream Spark validation exceptions into the 422 envelope.
- Keep `@csrf`. For PATCH/PUT/DELETE use POST plus `@method(...)`, or the client's supported request method. FireLine can send a configured server-generated CSRF token; it does not create one.
- Navigation envelopes use HTTP 200 (their status argument applies to native redirects).
- Success/error/validation helpers **always return JSON**, even without JavaScript. If native form fallback is required, branch on `is_fire_js()` and return a normal post/redirect/get response for native requests.
- Never send raw HTML, 204/empty responses, or custom JSON shapes to a client expecting a FireLine envelope.

## Partials, preloads, polling, and state

| Feature | Behavior |
| --- | --- |
| `x-preload` / `x-preload.hover` | Preloads safe GET destinations. `is_fire_preload()` detects them. Return the same page fragment; omit only optional effects (e.g. page-view analytics). Never mutate state on a preloaded GET. |
| `x-poll="10000"` | Refreshes every 10 s. Pauses while hidden/offline or during foreground work. Pick an interval the UI justifies. |
| `$partial(url)` + `x-partial="state"` | Loads into the host's **children**, preserving the host. `state.load()` replaces/reconciles; `state.loadMore(nextUrl)` appends. |
| `x-partial.lazy` | Loads when visible. |

Partial rules:

- Detect with `is_fire_partial()`; return `Fire::render($fragmentView, $props)` with no layout.
- Partial HTML may have multiple roots or be empty, but still needs a JSON `html` envelope.
- Partials do not follow navigation envelopes or update history/title.
- Keep persistent controls outside the host. Avoid competing `x-html`/`x-text` on partial hosts.
- Use unique stable `key` attributes for reordered server-rendered children (Alpine `:key` belongs to `x-for`). Put third-party widgets behind an `x-ignore` boundary.

JS API: `$fire.navigate(url)`, `$fire.reload()`, `$fire.replaceHtml(html)` are asynchronous: await sequencing. `fireEnd` fires for failures/cancellations too and is not proof of success. Partial/preload requests do not emit global request events.

## Headers, deployment, and verification

- Requests send `X-FireLine: 1`, `X-Requested-With: XMLHttpRequest`, and normally `Accept: application/json`. Detection selects representation, **not authorization**.
- Preserve the adapter's `Vary` and cache-control headers so full documents and envelopes are not mixed by caches.
- **Asset versioning:** assign the same build identifier to the client `assetVersion` and server `Fire::version($version)` before rendering. The adapter does not derive a Vite version automatically. Encode client settings safely; never interpolate unescaped data into scripts.

**Verify**

1. Direct HTML load, client navigation, back/forward.
2. Successful write, invalid input (422 field arrays), CSRF rejection, auth failure.
3. For partials/preloads/versioning: cleanup, matching fragment roots, asset-mismatch behavior.
4. Rebuild changed frontend assets.

For exact behavior inspect the installed adapter (`src/FireService.php`, `FireMiddleware.php`, `FireHeaders.php`) and client package. Do not assume v1 APIs still exist.