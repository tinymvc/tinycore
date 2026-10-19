# Application patterns and architecture

Read this reference when structuring routes, controllers, FormRequests, models, services, jobs, or JSON resources. The examples use the installed Spark contracts and are intentionally small; adapt fields and ownership rules to the target app.

## The default request path

For a JSON resource endpoint, keep the path visible and predictable:

1. A named route uses `{post}` or `{post:slug}` when ordinary model binding is sufficient.
2. A FormRequest owns meaningful input validation and authorization.
3. A controller coordinates the request, bound model, query, service, and resource.
4. A model owns casts, relations, and reusable scopes.
5. A service owns a multi-step business workflow or transaction.
6. A JsonResource owns the public API field list and nested resource shape.

The same domain service can serve an API controller, a Blade controller, a FireLine action, a job, or a command. The response layer changes with the frontend contract; the domain rules do not.

## A thin API controller

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
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
            ])
        );

        // Dispatch background notification job
        SendPostPublishedMail::dispatch($post->id)->onQueue('emails');

        return PostResource::make($post)->response(201);
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
    ): PostResource
    {
        $post = $postService->update($post, $request->validated());

        return PostResource::make($post);
    }

    public function destroy(Post $post): Response
    {
        if (!$post->remove()) {
            abort(500, 'Unable to remove the post.');
        }

        return response()->noContent();
    }
}
```

Do not add a service merely to call `Post::create()` or `PostResource::make()`. Inject a service when the action coordinates a transaction, multiple models, relation synchronization, cache/storage invalidation, external effects, or a reusable domain rule.

## A focused service

```php
<?php

namespace App\Services;

use App\Models\Post;
use Spark\Http\Input;
use RuntimeException;
use Spark\Facades\DB;

class PostService
{
    public function update(Post $post, Input $data): Post
    {
        return DB::transaction(function () use ($post, $data): Post {
            $post->fill($data);

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

Pass `Spark\Http\Input` or arrays deliberately. Since `Spark\Http\Input` implements `\Spark\Contracts\Support\Arrayable` and `\ArrayAccess`, it passes seamlessly into `$post->fill($data)` or `Post::create($data)` without requiring explicit `->all()` or `->toArray()` conversion. Keep authorization outside the service when it is request-specific, or pass an explicit actor when the rule belongs in the domain workflow.

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

Use `Post::query()`, `Post::where(...)`, `Post::findOrFail(...)`, `Post::with(...)`, or a scope for a model-backed table. Use `with()` on list queries and `load()` on a bound model before a resource needs relations. Define relation names that describe the domain and use `whereHas()`, `whereRelation*()`, `withCount()`, `withExists()`, and aggregates rather than fetching unrelated rows and filtering in PHP.

## A resource is an API boundary

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

Do not call `Carbon::parse($this->created_at)` or `$this->created_at->toIsoUtcString()` here. Model timestamps and `datetime`/`date` casts are already `Spark\Carbon` values, and `JsonResource::normalize()` recursively converts them to ISO UTC strings. Format a date manually only when the product contract explicitly asks for a display string, such as a localized Blade label.

## Non-API response modes

- Blade: query through models/services, then `return view('posts.show', ['post' => $post]);`; use redirects for successful writes and normal session validation flow.
- FireLine: preserve the installed adapter's `fire()`/`Fire::render()` response, message/error envelope, and redirect behavior. Read [frontend setup](frontend-setup.md) before changing a FireLine form.
- Inertia: use the installed adapter's `inertia()`/`Inertia::render()` response and redirect after writes. Props are public response data, so select fields and authorize them explicitly.
- JSON webhooks or scalar endpoints: use `json()` when the external contract is not an entity resource. Keep the documented keys and status codes stable.

The controller may share a service with all four modes. Keep representation-specific work in the controller/resource/adapter layer rather than leaking JSON envelopes into a Blade or Inertia action.
