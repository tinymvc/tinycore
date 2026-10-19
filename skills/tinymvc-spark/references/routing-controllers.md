# Routing and Controllers

Read this reference for routing, route model binding, and controller patterns. All source paths and command working directories refer to the application root.

## Routing

Routes are defined in `routes/web.php`, `routes/api.php`, or `routes/webhook.php`.

```php
use Spark\Facades\Route;
use App\Http\Controllers\Api\PostController;

Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
Route::get('/posts/{post}', [PostController::class, 'show'])->name('posts.show');
Route::put('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
```

### Route Model Binding

TinyMVC features native route model binding. When a route parameter matches the name of a type-hinted `Model` in the controller action or closure, Spark automatically retrieves the model via `firstOrFail()`, returning a 404 response if not found.

#### 1. Default Primary Key Binding (`id`)

```php
// Route parameter {post} matches controller argument Post $post
Route::get('/posts/{post}', [PostController::class, 'show']);
```

In the controller, type-hint the model parameter:

```php
public function show(Post $post): PostResource
{
    return PostResource::make($post);
}
```

#### 2. Custom Column Binding (`{param:column}`)

You can specify a custom column for lookup directly in the route parameter definition using `{param:column}`:

```php
// Binds by 'slug' instead of 'id'
Route::get('/posts/{post:slug}', [PostController::class, 'show']);
```

In the controller, the model is resolved automatically via `Post::where('slug', $value)->firstOrFail()`:

```php
public function show(Post $post): PostResource
{
    return PostResource::make($post);
}
```

---

## Route Groups and Resource Routes

### Route Groups

```php
use App\Http\Controllers\Api\PostController;

Route::group(function () {
    Route::get('/posts', [PostController::class, 'index']);
    Route::post('/posts', [PostController::class, 'store']);
})
    ->prefix('api/v1')
    ->middleware(['cors', 'auth:api']);
```

### Resource Routes

```php
Route::resource('/posts', PostController::class, name: 'posts')
    ->except(['show', 'edit']); // ->only([]) ...
```

Mapped resource actions:
- `GET /posts` -> `index`
- `GET /posts/create` -> `create`
- `POST /posts` -> `store`
- `GET /posts/{post}` -> `show`
- `GET /posts/{post}/edit` -> `edit`
- `PUT/PATCH /posts/{post}` -> `update`
- `DELETE /posts/{post}` -> `destroy`

---

## Idiomatic API Controller Standard

Always write clean, modern controllers combining **Route Model Binding**, **FormRequest**, and **JsonResource**:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Spark\Http\Resources\ResourceCollection;
use Spark\Http\Response;

class PostController extends Controller
{
    /**
     * List posts with eager-loaded relations and pagination.
     */
    public function index(): ResourceCollection
    {
        $posts = Post::with(['author', 'category'])
            ->withCount('comments')
            ->latest()
            ->paginate(20);

        return PostResource::collection($posts);
    }

    /**
     * Store a newly created post.
     * FormRequest handles authorization and validation before this action runs.
     */
    public function store(StorePostRequest $request): Response
    {
        $post = Post::create($request->validated());

        return PostResource::make($post)->response(201);
    }

    /**
     * Display a specific post via Route Model Binding.
     */
    public function show(Post $post): PostResource
    {
        // Eager load relations for the loaded instance if needed
        $post->load(['author', 'comments.author']);

        return PostResource::make($post);
    }

    /**
     * Update an existing post.
     */
    public function update(UpdatePostRequest $request, Post $post): PostResource
    {
        $post->fill($request->validated());

        if (!$post->save()) {
            abort(500, 'Unable to save the post.');
        }

        return PostResource::make($post);
    }

    /**
     * Remove the specified post.
     */
    public function destroy(Post $post): Response
    {
        if (!$post->remove()) {
            abort(500, 'Unable to remove the post.');
        }

        return response()->noContent();
    }
}
```

### Key Controller Rules

1. **Use resources for entity payloads**: Return `PostResource::make($post)` or `PostResource::collection(...)`; reserve `json()` for intentional message/scalar/webhook contracts.
2. **Never put validation logic inline in create/update actions**: Use dedicated `FormRequest` classes.
3. **Use Route Model Binding**: Type-hint `Post $post` instead of manually finding models by ID in every action.
4. **Use `$model->remove()` to delete**: Do not call `$model->delete()` on model instances.
5. **Return `response()->noContent()`** for successful deletions.
