# Application Patterns and Architecture

> **Read when:** structuring routes, controllers, FormRequests, models, services, jobs, or JSON resources.
> **Related:** [FireLine](fireline.md) · [Inertia](inertia.md) · [Advanced workflows](advanced-workflows.md)
> The examples use the installed Spark contracts and are intentionally small; adapt fields and ownership rules to the target app. They assume the app defines the referenced requests, jobs, resources, scopes, and policies.

Contents:

- [Default request path](#default-request-path)
- [Thin API controller](#thin-api-controller)
- [Focused service](#focused-service)
- [Model scopes, casts, relations](#model-scopes-casts-and-relations)
- [Resource as API boundary](#resource-as-an-api-boundary)
- [Non-API response modes](#non-api-response-modes)

## Default request path

For a JSON resource endpoint, keep the path visible and predictable. Each layer has one job:

| Layer | Owns |
| --- | --- |
| Named route | `{post}` or `{post:slug}` binding when ordinary model binding is enough |
| FormRequest | Meaningful input validation and authorization |
| Controller | Coordinates request, bound model, query, service, resource |
| Model | Casts, relations, reusable scopes |
| Service | Multi-step business workflow or transaction |
| JsonResource | Public API field list and nested resource shape |

The same domain service can serve an API controller, a Blade controller, a FireLine action, a job, or a command. The response layer changes with the frontend contract; the domain rules do not.

## Thin API controller

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Jobs\SendPostPublishedMail;
use App\Models\Post;
use App\Services\PostService;
use Spark\Http\Resources\ResourceCollection;
use Spark\Http\Response;

class PostController extends Controller
{
    public function index(): ResourceCollection
    {
        $posts = Post::with(['author', 'tags'])
            ->withCount('comments')
            ->published()
            ->latest()
            ->paginate(20);

        return PostResource::collection($posts);
    }

    public function store(StorePostRequest $request): Response
    {
        $post = Post::create(
            $request->validated()->merge([
                'user_id' => $request->user('id'),
            ]),
        );

        SendPostPublishedMail::dispatch($post->id)->onQueue('emails');

        return PostResource::make($post)->response(Response::STATUS_CREATED);
    }

    public function show(Post $post): PostResource
    {
        $post->load(['author', 'tags']);

        return PostResource::make($post);
    }

    public function update(
        UpdatePostRequest $request,
        Post $post,
        PostService $postService,
    ): PostResource {
        $post = $postService->update($post, $request->validated());

        return PostResource::make($post);
    }

    public function destroy(Post $post): Response
    {
        if (!$post->remove()) {
            abort(Response::STATUS_INTERNAL_SERVER_ERROR, 'Unable to remove the post.');
        }

        return response()->noContent();
    }
}
```

Notes:

- **Model binding does not enforce ownership.** Protect read/delete actions with the appropriate gate or route policy.
- **Do not add a service merely to call `Post::create()` or `PostResource::make()`.** Inject one when the action coordinates a transaction, multiple models, relation sync, cache/storage invalidation, external effects, or a reusable domain rule.
- **Dispatch placement matters.** `store()` dispatches immediately after the write; when a lost job is unacceptable or the write is inside a transaction, follow [Commit, dispatch, and external effects](advanced-workflows.md#commit-dispatch-and-external-effects).

## Focused service

```php
<?php

namespace App\Services;

use App\Models\Post;
use RuntimeException;
use Spark\Facades\DB;
use Spark\Http\Input;

class PostService
{
    public function update(Post $post, Input $data): Post
    {
        return DB::transaction(function () use ($post, $data): Post {
            $post->fill($data->except('tags'));

            if (!$post->save()) {
                throw new RuntimeException('Unable to save the post.');
            }

            if ($data->has('tags')) {
                $post->tags()->sync($data['tags']);
            }

            return $post;
        });
    }

    public function publish(Post $post): Post
    {
        $post->fill([
            'published' => true,
            'published_at' => now(),
        ]);

        if (!$post->save()) {
            throw new RuntimeException('Unable to publish the post.');
        }

        return $post;
    }
}
```

- Pass `Spark\Http\Input` or arrays deliberately. `Input` implements `\Spark\Contracts\Support\Arrayable` and `\ArrayAccess`, so it goes straight into `$post->fill($data)` or `Post::create($data)` with no `->all()` / `->toArray()`.
- Keep request-specific authorization outside the service, or pass an explicit actor when the rule belongs in the domain workflow.

## Model scopes, casts, and relations

```php
<?php

namespace App\Models;

use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\BelongsTo;
use Spark\Database\Relation\BelongsToMany;
use Spark\Database\Relation\HasMany;

class Post extends Model
{
    protected array $fillable = [
        'user_id',
        'title',
        'slug',
        'content',
        'published',
        'published_at',
    ];

    protected array $casts = [
        'published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function scopePublished(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->where('published', true)
            ->whereNotNull('published_at');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, foreignKey: 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, foreignKey: 'post_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            related: Tag::class,
            table: 'post_tags',
            foreignPivotKey: 'post_id',
            relatedPivotKey: 'tag_id',
        );
    }
}
```

- Query with `Post::query()`, `Post::where(...)`, `Post::findOrFail(...)`, `Post::with(...)`, or a scope.
- Use `with()` on list queries and `load()` on a bound model before a resource needs relations.
- Name relations for the domain. Use `whereHas()`, `whereRelation*()`, `withCount()`, `withExists()`, and aggregates instead of fetching unrelated rows and filtering in PHP.

## Resource as an API boundary

```php
<?php

namespace App\Http\Resources;

use App\Models\Post;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

/**
 * @extends JsonResource<Post>
 */
class PostResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'content' => $this->content,
            'published' => $this->published,
            'author' => $this->whenLoaded('author', fn ($author) => UserResource::make($author)),
            'tags' => $this->whenLoaded('tags', fn ($tags) => TagResource::collection($tags)),
            'comments_count' => $this->whenCounted('comments'),
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

**Do not** call `Carbon::parse($this->created_at)` or `$this->created_at->toIsoUtcString()` here. Model timestamps and `datetime`/`date` casts are already `Spark\Carbon`, and `JsonResource::normalize()` recursively converts them to ISO UTC strings. Format manually only when the product contract explicitly wants a display string (e.g. a localized Blade label).

## Non-API response modes

| Mode | Pattern |
| --- | --- |
| Blade | Query via models/services, `return view('posts.show', ['post' => $post]);`. Redirect after successful writes; use normal session validation flow. |
| FireLine | Preserve the installed adapter's `fire()` / `Fire::render()` response, message/error envelope, and redirect behavior. Read [FireLine](fireline.md) first. |
| Inertia | `inertia()` / `Inertia::render()`, adapter redirects after writes. Props are public response data: select fields and authorize explicitly. Read [Inertia](inertia.md). |
| JSON webhooks / scalar endpoints | `json()` when the contract is not an entity resource. Keep documented keys and status codes stable. |

One controller/service can serve all four modes. Keep representation-specific work in the controller/resource/adapter layer; do not leak JSON envelopes into a Blade or Inertia action.