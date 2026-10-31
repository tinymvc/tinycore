# Routing and Controllers

> **Read when:** defining routes/groups, binding models, or implementing controller actions.
> **Related:** [Requests and validation](requests-validation.md) · [Auth and gate](auth-gate.md) · [Middleware](middleware.md) · [Application patterns](application-patterns.md)
> Routes belong to the application's configured files; use `Spark\Facades\Route` in backend PHP.

Contents:

- [Routes and model binding](#routes-and-model-binding)
- [Groups and resource routes](#groups-and-resource-routes)
- [Controller actions](#controller-actions)
- [Dispatch and verification](#dispatch-and-verification)

## Routes and model binding

```php
use Spark\Facades\Route;
use App\Http\Controllers\Api\PostController;

Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
Route::get('/posts/{post:slug}', [PostController::class, 'show'])->name('posts.show');
Route::put('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
```

A route argument matching a type-hinted Model parameter resolves through `firstOrFail()`. `{post}` uses the primary key; `{post:slug}` uses that column. Missing rows become 404. Binding does not authorize access: retain explicit tenant/ownership filtering or an ability check.

Pass unencoded values to `route('posts.show', ['post' => $post->slug])`; each value becomes one encoded segment. Missing required values throw InvalidNamedRouteException; unresolved optional segments are removed. Routing decodes parameters once after matching encoded path segments.

## Groups and resource routes

```php
Route::group(function () {
    Route::get('/posts', [PostController::class, 'index']);
    Route::post('/posts', [PostController::class, 'store']);
})->prefix('api/v1')->middleware(['cors', 'auth:api']);

Route::prefix('api')->middleware('auth:api')
    ->resource('/posts', PostController::class, name: 'posts')
    ->only(['index', 'show']);
```

`resource()` also supports `except()`. Fluent attributes apply to all generated actions, compose with surrounding groups, and do not leak to later routes.

| Method/path | Action |
| --- | --- |
| GET `/posts` | index |
| GET `/posts/create` | create |
| POST `/posts` | store |
| GET `/posts/{id}` | show |
| GET `/posts/{id}/edit` | edit |
| PUT/PATCH `/posts/{id}` | update |
| DELETE `/posts/{id}` | destroy |

Resource routes use **`{id}`**. For a controller argument `Post $post`, register explicit `{post}` routes so names match.

## Controller actions

Extend the application's `App\Http\Controllers\Controller`. Keep actions focused on request validation, authorization, workflow invocation, and the [response contract](responses.md).

```php
public function index(): \Spark\Http\Resources\ResourceCollection
{
    $posts = Post::with('author')->withCount('comments')->latest()->paginate(20);

    return PostResource::collection($posts);
}

public function update(UpdatePostRequest $request, Post $post): PostResource
{
    // The FormRequest must authorize this post for the current user.
    if (!$post->fill($request->validated())->save()) {
        abort(\Spark\Http\Response::STATUS_INTERNAL_SERVER_ERROR, 'Unable to save the post.');
    }

    return PostResource::make($post);
}
```

Use FormRequests for substantial writes, resource classes for public entities, and model `remove()` for instance deletion. Check failed writes. Browser writes redirect; FireLine/Inertia use their adapters. A service is useful for coordinated workflows, not as a mandatory wrapper around every model call.

## Dispatch and verification

- Missing paths return 404 or a configured fallback; unsupported methods on an existing path return 405 with Allow.
- Explicit `Route::head()` takes precedence; otherwise HEAD uses GET headers without a body.
- Explicit OPTIONS routes take precedence over the preflight fallback to the requested method; CORS still validates policy.
- Global middleware wraps normal routes, fallbacks, 404s, and 405s once per request. Route exclusions still apply.
- Controllers/fallbacks can return native or PSR responses. Changing the URI in route middleware does not reroute the matched request.

Verify route generation, required/optional parameters, encoded values, binding failures, wrong-owner access, method negotiation, middleware order, and returned public fields.
