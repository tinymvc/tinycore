# Inertia: Server Routes and React/Vue Pages

> **Read when:** setting up Inertia, writing controllers, props, forms, redirects, or debugging protocol issues.
> **Related:** [Frontend setup](frontend-setup.md) (Vite) · [Requests and validation](requests-validation.md) (FormRequests) · [Application patterns](application-patterns.md)
> TinyMVC uses the optional **`tinymvc/inertia-php`** adapter and the **`Inertia\Facades\Inertia`** facade. It is **not** the Laravel adapter.

Contents:

- [Check the installed protocol first](#check-the-installed-protocol-first)
- [Register and mount](#register-and-mount)
- [Page and write actions](#page-and-write-actions)
- [Redirects, validation, flash](#redirects-validation-and-flash)
- [Choose props deliberately](#choose-props-deliberately)
- [Assets and verification](#assets-and-verification)

## Check the installed protocol first

Read Composer installed metadata, the adapter's `composer.json` and `src/`, and the JS lockfile. The documented adapter supports **Inertia v3** with client rendering and no SSR; older releases/starters differ.

- Do not infer the client major from the PHP package major.
- Do not install an unpinned latest client into an existing app.
- Do not copy Laravel middleware/testing APIs.
- Installed constraints and source beat stale README compatibility text.

When upgrading, inspect:

| File | Concern |
| --- | --- |
| `src/InertiaServiceProvider.php` | Service, Blade directive, route macro registration |
| `src/Inertia.php` | Root rendering, redirects, flash, versioning, validation errors |
| `src/PropsResolver.php`, `src/Props/` | Partial reloads, supported prop wrappers |
| Core validation exception handling | Whether Inertia requests redirect with session errors |

## Register and mount

Add `Inertia\InertiaServiceProvider::class` to the existing `bootstrap/providers.php` array (preserve other providers). It registers the service, the `@inertia` directive, and `Route::inertia('/about', 'About')`. The package supplies `inertia()` via Composer autoload. **Do not** invent a Laravel `HandleInertiaRequests` middleware requirement.

Root Blade view for a React entry `app.tsx`:

```blade
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Vite automatically includes @viteReactRefresh for .jsx/.tsx entries -->
    @vite('app.tsx')
</head>
<body>
    @inertia
</body>
</html>
```

- Keep `@inertia` in `<body>`. For Vue use the matching entry/plugin and omit React refresh. Import CSS in the entry or configure it as an extra build entry.
- The v3 adapter emits a JSON page script plus a separate mount element; let the adapter encode the payload.
- `Inertia::setRootView()` picks the Blade view; `setRootElementId()` picks the DOM ID. The client's `createInertiaApp()` ID must match.
- Preserve the existing React/Vue bootstrap and page resolver. `Posts/Index` must resolve to the correct **case-sensitive** component path; an unresolved page must fail visibly, never mount an undefined component.

## Page and write actions

Use server routes and controllers: an Inertia page needs no duplicate REST layer.

```php
// import the app's Post, StorePostRequest, Inertia\Facades\Inertia, Spark\Http\Response
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

- Adapt scopes, fields, and FormRequest authorization to the viewer. Explicit field selection prevents accidental disclosure; the closure keeps query work inside the prop's evaluation boundary. Preserve the client page's expected paginator shape.
- `Inertia::render()` returns **`Spark\Http\Response`**, not `Inertia\Response`. `inertia($component, $props)` is the helper equivalent. Do not build custom Inertia JSON envelopes.
- Call `withEncryptedHistory()`, `withClearedHistory()`, and `preserveFragment()` on the **adapter** before rendering or redirecting, not on the returned response.
- Resolve user-specific shared props lazily from the current request.

## Redirects, validation, and flash

- After writes use `Inertia::redirect($url)` or `Inertia::back()`. The adapter converts default redirects after PUT/PATCH/DELETE to 303 and handles protocol-specific navigation; a generic redirect does not guarantee this.
- For an external/full browser visit use `Inertia::location($url)` with a trusted destination.
- Keep server validation, authorization, authentication, sessions, and CSRF enabled. **Inertia validation follows a redirect with session errors, not an API-style JSON 422.**
- Preserve `X-Inertia` and the client's normal HTML `Accept` header. In TinyCore, requesting ordinary JSON can select JSON validation handling before the Inertia redirect branch, so **do not use `postJson()` blindly for an Inertia form test**.
- Field errors appear in `props.errors` (first message per field), with optional `X-Inertia-Error-Bag` nesting. Keep client field names aligned.
- `Inertia::flash()` delivers transient data at **`page.flash`**, not automatically `page.props.flash`. Consume it via the installed client API. Generic session flash becomes a prop only if the app explicitly shares it.

## Choose props deliberately

All resolved props are browser-visible. Select fields, authorize queries, and keep tenant/ownership filtering inside lazy callbacks. `Inertia::share()` is for common props; page props override shared keys. Resolve user-specific shared data in the active request, never as a boot-time user captured in a long-running worker.

| Need | Supported v3 approach |
| --- | --- |
| Cheap required data | Plain value |
| Avoid work for unselected props | Closure containing the query |
| Fetch only when explicitly requested | `Inertia::optional(fn () => ...)` (replaces old `lazy()`) |
| Load after initial render | `Inertia::defer(fn () => ...)` + client loading state |
| Include despite partial selection | `Inertia::always($value)` |
| Append/merge on partial reloads | `merge()` / `deepMerge()` with deliberate matching keys |
| Reuse client-held data | `once()` / `shareOnce()` with expiry/invalidation suited to the viewer |
| Infinite-scroll paginator metadata | `scroll($paginator)`; verify wrapper and client expectations |

- Start with plain values/closures; use advanced wrappers only when the installed adapter **and** client support them and the UI benefits.
- Wrappers preserve lazy behavior inside nested arrays, collections, and closures. Partial selection requires a matching Inertia component; custom scroll pagination requires a metadata provider.
- **Partial-prop selection is not authorization.** Do not precompute expensive queries outside callbacks meant to defer them.

## Assets and verification

- The adapter derives its asset version from `public/build/.vite/manifest.json`; `setBuildDirectory()` and `version()` customize it.
- Preserve `Vary: X-Inertia`, `X-Inertia`, and protocol redirect/version headers through middleware and proxies. An asset mismatch can yield a 409 with a location header: do not rewrite it into a generic application error.

**Verify**

1. Initial full HTML and an `X-Inertia` page visit.
2. Form success followed by a GET; invalid-input redirect with returned errors; forbidden access.
3. Partial reloads and changed asset versions.
4. Assert the component name and permitted props, not just HTTP 200. Use native Spark response assertions; Laravel's `assertInertia()` does not exist.
5. After setup changes, build frontend assets and check page resolution/mounting in the browser.