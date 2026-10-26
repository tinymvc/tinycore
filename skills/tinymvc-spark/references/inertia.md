# Inertia: server routes and React/Vue pages

Read this for Inertia setup, controllers, props, forms, redirects, or protocol bugs. TinyMVC uses the optional **`tinymvc/inertia-php`** adapter with the **`Inertia\Facades\Inertia`** facade. It is not the Laravel adapter. See [frontend setup](frontend-setup.md) for Vite and [requests and validation](requests-validation.md) for FormRequests.

## Check the installed protocol first

Read Composer installed metadata, the adapter's `composer.json` and `src/`, and the JavaScript lockfile. The documented adapter supports Inertia v3 and client rendering without SSR; older releases/starters can differ. Do not infer the client major from the PHP package major, install an unpinned latest client into an existing app, or copy Laravel middleware/testing APIs.

Installed constraints and source take precedence over stale README compatibility text. Inspect these boundaries when upgrading:

- `src/InertiaServiceProvider.php`: service, Blade directive, route macro registration.
- `src/Inertia.php`: root rendering, redirects, flash, versioning, validation errors.
- `src/PropsResolver.php` and `src/Props/`: partial reloads and supported prop wrappers.
- Core validation exception handling: whether Inertia requests redirect with session errors.

## Register and mount

Add `Inertia\InertiaServiceProvider::class` to the existing `bootstrap/providers.php` array. It registers the service, `@inertia`, and `Route::inertia('/about', 'About')`; the package supplies `inertia()` through Composer autoload. Preserve existing app providers. Do not invent a Laravel `HandleInertiaRequests` middleware requirement.

For a React entry configured as `app.tsx`, the root Blade view can contain:

```blade
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Vite will automitically include @viteReactRefresh for .jsx, .tsx entry  -->
    @vite('app.tsx')
</head>
<body>
    @inertia
</body>
</html>
```

Keep `@inertia` in the body. Use the matching Vue entry/plugin and omit React refresh for Vue. Import CSS in the entry or configure it as an additional build entry.

The v3 adapter emits a JSON page script plus a separate mount element. Let the adapter encode that payload. `Inertia::setRootView()` chooses the Blade view; `setRootElementId()` chooses the DOM ID. The client's `createInertiaApp()` ID must match. Preserve the existing React/Vue bootstrap and page resolver; `Posts/Index` must resolve to the correct case-sensitive component path. An unresolved page must fail visibly, not silently mount an undefined component.

## Page and write actions

Use server routes and controllers; an Inertia page does not need a duplicate REST layer. Example controller actions (import the app's `Post`, `StorePostRequest`, `Inertia\Facades\Inertia`, and `Spark\Http\Response`):

```php
public function index(): Response
{
    return Inertia::render('Posts/Index', [
        'posts' => fn () => Post::select(['id', 'title', 'slug'])
            ->latest()
            ->paginate(20),
    ]);
}

public function store(StorePostRequest $request): Response
{
    Post::create($request->validated());

    Inertia::flash('status', 'Post created.');

    return Inertia::redirect((string) route('posts.index'));
}
```

Adapt scopes, fields, and FormRequest authorization to the viewer. The explicit field selection prevents accidental disclosure. The closure keeps query work inside the prop's evaluation boundary. Preserve the client page's expected paginator shape.

`Inertia::render()` returns **`Spark\Http\Response`**, not `Inertia\Response`. `inertia($component, $props)` is the helper equivalent. Do not construct custom Inertia JSON envelopes.

Call `withEncryptedHistory()`, `withClearedHistory()`, and `preserveFragment()` on the adapter before rendering or redirecting, not on its returned response. Resolve user-specific shared props lazily from the current request.

## Redirects, validation, and flash

- Use `Inertia::redirect($url)` or `Inertia::back()` after writes. The adapter converts default redirects after PUT/PATCH/DELETE to 303 and handles protocol-specific navigation. A generic redirect is not guaranteed to preserve those behaviors.
- Use `Inertia::location($url)` for an external/full browser visit; the adapter chooses the protocol response. Supply a trusted destination.
- Keep server validation, authorization, authentication, sessions, and CSRF enabled. Inertia form validation follows a redirect with session errors, not an API-style JSON 422 success path.
- Preserve `X-Inertia` and the client's normal HTML Accept header. In TinyCore, requesting ordinary JSON can select JSON validation handling before the Inertia redirect branch. Do not use `postJson()` blindly for an Inertia form test.
- The adapter exposes field errors in `props.errors`, with the first message per field and optional `X-Inertia-Error-Bag` nesting. Keep client form field names aligned.
- In the v3 adapter, `Inertia::flash()` delivers transient data at **`page.flash`**, not automatically `page.props.flash`. Use the installed client API to consume it. Generic session flash only becomes a prop if the application explicitly shares it.

## Choose props deliberately

All resolved props are browser-visible. Select fields, authorize queries, and keep tenant/ownership filtering inside lazy callbacks. `Inertia::share()` is for common props; page props override shared keys. Resolve user-specific shared data in the active request, not as a captured boot-time user in a long-running worker.

| Need | Supported v3 approach |
| --- | --- |
| Cheap required data | Plain value |
| Avoid work for unselected props | Closure containing the query |
| Only fetch when explicitly requested | `Inertia::optional(fn () => ...)` |
| Load after initial render | `Inertia::defer(fn () => ...)`, with client loading state |
| Include despite partial selection | `Inertia::always($value)` |
| Append/merge during partial reloads | `merge()` / `deepMerge()` with deliberate matching keys |
| Reuse client-held data | `once()` / `shareOnce()` with expiry/invalidation appropriate to the viewer |
| Infinite-scroll paginator metadata | `scroll($paginator)`; verify wrapper and client expectations |

Start with plain values/closures. Use advanced wrappers only when the installed adapter and client support them and the UI benefits. `optional()` replaces the old `lazy()` API in v3. Partial-prop selection is not an authorization check; do not precompute expensive queries outside callbacks intended to defer them.

Prop wrappers preserve lazy behavior in nested arrays, collections, and closures. Partial selection requires a matching Inertia component; custom scroll pagination requires a metadata provider.

## Assets and verification

The documented adapter derives its asset version from `public/build/.vite/manifest.json`; `setBuildDirectory()` and `version()` customize it. Preserve `Vary: X-Inertia`, `X-Inertia`, and protocol redirect/version headers through middleware and proxies. Asset mismatch can produce a 409 with a location header; do not rewrite it into a generic application error.

Test initial full HTML, an `X-Inertia` page visit, form success followed by a GET, invalid-input redirect and returned errors, forbidden access, partial reloads, and changed asset versions. Assert the component name and permitted props, not just HTTP 200. Use native Spark response assertions; do not assume Laravel `assertInertia()` exists. Build changed frontend assets and verify page resolution/mounting in the browser when setup changes.
