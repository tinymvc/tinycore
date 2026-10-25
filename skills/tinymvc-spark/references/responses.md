# Responses, API Resources, and Frontend Envelopes

Read this reference for HTTP responses, JSON resources, redirects, and API contracts in TinyMVC / TinyCore (Spark). All source paths and commands are relative to the application root.

---

## REST API Responses and JSON Resources

For REST APIs, use **`Spark\Http\Resources\JsonResource`** for all entity and collection presentations. Do **not** manually construct an entity envelope such as `json(['data' => $post])` or `return ['data' => $post]` in a resource controller. Use `json()` for a message, scalar, webhook, or other intentionally non-resource contract.

### Standard Controller Response Patterns

```php
use Spark\Http\Response;

// 1. Single Entity (HTTP 200 OK with 'data' envelope)
return PostResource::make($post);

// 2. Created Entity (HTTP 201 Created)
return PostResource::make($post)->response(Response::STATUS_CREATED);
// Or: return response(PostResource::make($post), Response::STATUS_CREATED);

// 3. Collection of Entities (HTTP 200 OK with 'data' array)
return PostResource::collection($posts);

// 4. Paginated Collection (HTTP 200 OK automatically including 'data', 'links', and 'meta')
return PostResource::collection(Post::published()->latest()->paginate(20));

// 5. Successful Deletion / Empty State (HTTP 204 No Content)
return response()->noContent();

// 6. Simple Message, Status, or Webhook (NOT for models or entity data)
return json(['message' => 'Password reset email sent.'], Response::STATUS_OK);

// 7. Explicit Operation Error Message
return json(['message' => 'The provided invitation code has expired.'], Response::STATUS_BAD_REQUEST);
```

---

## Defining JSON Resources

Extend `Spark\Http\Resources\JsonResource` and implement `toArray(?Request $request = null): array`. Read model attributes and loaded relations via `$this->attribute`.

```php
<?php

namespace App\Http\Resources;

use App\Models\Post;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

/**
 * @mixin Post
 */
class PostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'content' => $this->content,

            // Relations: use whenLoaded() to prevent unexpected N+1 queries
            'author' => $this->whenLoaded('author', fn ($author) => UserResource::make($author)),
            'comments' => $this->whenLoaded('comments', fn ($comments) => CommentResource::collection($comments)),
            'tags' => $this->whenLoaded('tags', fn ($tags) => TagResource::collection($tags)),
            'comments_count' => $this->whenCounted('comments'),

            // Dates: do NOT manually call Carbon::parse() or ->toIsoUtcString()!
            // JsonResource::normalize() automatically serializes Spark\Carbon into ISO-8601 UTC strings.
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // URLs: Spark\Url objects are automatically normalized to URL strings
            'url' => route('posts.show', ['post' => $this->slug]),
        ];
    }
}
```

### Date and Carbon Handling in Resources

> [!IMPORTANT]
> **Automatic Date Serialization**:
> `JsonResource::normalize()` automatically inspects values recursively:
> - Instances of `Spark\Carbon` are automatically converted via `->toIsoUtcString()`.
> - Instances of `DateTimeInterface` are automatically formatted as `DateTimeInterface::ATOM`.
>
> Because model timestamps (`created_at`, `updated_at`) and attributes cast with `'datetime'` or `'date'` are already `Spark\Carbon` objects, pass them directly without manual parsing or formatting.

```php
// BAD / UN-SMART:
$data = [
    'created_at' => Carbon::parse($this->created_at)->toIsoUtcString(),
    'created_at' => $this->created_at?->toIsoUtcString(),
    'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($this->created_at)),
];

// GOOD / SMART & ELEGANT:
$data = [
    'created_at' => $this->created_at,
    'published_at' => $this->published_at,
];
```

---

## Conditional Attributes in Resources

Use built-in conditional helpers on `JsonResource` to keep resource output clean and omission-aware:

- **`when($condition, $value, $default = MissingValue)`**: Includes `$value` only if `$condition` is truthy.
- **`unless($condition, $value, $default = MissingValue)`**: Includes `$value` only if `$condition` is falsy.
- **`whenNotNull($value, $default = MissingValue)`**: Includes `$value` only when not null.
- **`whenHas(string $attribute, $value = MissingValue)`**: Includes value only if the attribute exists on the underlying resource.
- **`whenLoaded(string $relation, $value = MissingValue)`**: Includes the relation only if it was eager loaded on the model (`$model->relationLoaded($relation)`). Never triggers an unexpected lazy query.
- **`whenCounted(string $relation)`**: Shorthand for `$this->whenHas($relation . '_count')`.

```php
return [
    'id' => $this->id,
    'secret_code' => $this->when($request?->user()?->isAdmin(), $this->secret_code),
    'author' => $this->whenLoaded('author', fn ($author) => UserResource::make($author)),
    'comments_count' => $this->whenCounted('comments'),
    ...$this->when($this->is_featured, fn () => [
        'featured_at' => $this->featured_at,
        'badge' => 'Featured',
    ], []),
];
```

---

## Collections and Pagination Envelopes

`PostResource::collection($items)` accepts iterable collections and every `Spark\Paginator\AbstractPaginator` implementation: `Paginator`, `SimplePaginator`, and `CursorPaginator`.

1. **When given an array or Collection**:
   Wraps items under the top-level `'data'` key:
   ```json
   {
       "data": [
           { "id": 1, "title": "First Post" },
           { "id": 2, "title": "Second Post" }
       ]
   }
   ```

2. **When given a `Paginator`** (e.g. `PostResource::collection(Post::latest()->paginate(20))`):
   Automatically produces the standardized paginated response containing `data`, `links`, and `meta`:
   ```json
   {
       "data": [...],
       "links": {
           "first": "https://api.example.com/posts?page=1",
           "last": "https://api.example.com/posts?page=5",
           "prev": null,
           "next": "https://api.example.com/posts?page=2"
       },
       "meta": {
           "current_page": 1,
           "per_page": 20,
           "last_page": 5,
           "total": 95,
           "from": 1,
           "to": 20
       }
   }
   ```
   Do not manually format pagination envelopes; let `ResourceCollection` handle it.

---

### Cursor pagination envelopes

`PostResource::collection(Post::orderBy('id')->cursorPaginate(20))` keeps transformed items in `data`, navigation URLs in `links.prev` / `links.next`, and `path`, `per_page`, `next_cursor`, and `prev_cursor` in `meta`. Boundary cursors and URLs are null when unavailable. It does not invent totals, page numbers, or first/last links. Additional metadata is preserved, with pagination fields controlled by the paginator.

See [queries](queries.md#cursor-pagination) for ordering requirements and the supported API.

### Simple pagination envelopes

`PostResource::collection(Post::orderBy('id')->simplePaginate(20))` includes transformed `data`, `links.first`, `links.prev`, `links.next`, and `meta.current_page`, `meta.per_page`, `meta.from`, `meta.to`. Empty pages have null `from`/`to`. It omits totals and last-page metadata. All paginator resources share the same metadata integration and preserve additional application metadata.

## Response Wrapping and Custom Metadata

- **Wrapping**: Top-level resources default to a `'data'` envelope.
  - `UserResource::make($user)->wrap('user')` changes the top-level key to `'user'`.
  - `UserResource::make($user)->withoutWrapping()` returns flat attributes without the outer wrapper.
- **Additional Metadata**:
  - `UserResource::make($user)->additional(['meta' => ['version' => '1.0']])` attaches custom root-level metadata.
  - Or define static metadata by overriding `with(?Request $request = null): array` inside the resource class:

```php
// Example controller response with custom wrapper and metadata:
return UserResource::make($user)
    ->additional([
        'meta' => [
            'api_version' => 'v1',
            'server_time' => now()->toIsoUtcString(),
        ],
    ]);

// Example returning flat JSON without outer 'data' wrapper:
return UserResource::make($user)->withoutWrapping();
```

---

## Web Responses, Redirects & Error Bags

For traditional server-rendered web routes:

```php
// Render a Blade template with data
return view('posts.show', ['post' => $post]);

// Redirect to a specific URL
return redirect('/dashboard');

// Redirect to a named route with parameters
return to_route('posts.show', ['post' => $post->slug]);

// Redirect back with validation errors and old input flashed to session
return back()->withErrors(['email' => 'Invalid credentials.'])->withInput();

// Empty 204 No Content response
return response()->noContent();

// Download a generated file
response()->download($fileContents, 'report.pdf', [
    'Content-Type' => 'application/pdf',
]);
```

## PSR-7 responses and PSR-17 factories

Controllers, middleware, fallbacks, and custom exception handlers may return `Psr\Http\Message\ResponseInterface` directly. `Response::from($value)` is the common result normalizer; strings, arrays, resources, and integer status codes retain their native behavior.

`$response->toPsrResponse()` exports a prepared PSR-7 message. `Response::fromPsrResponse($message)` imports it without reading its body. Stream bodies remain the same `StreamInterface`; imported messages without a content type do not gain an HTML default. Repeated headers, including `Set-Cookie`, remain separate. Nyholm owns header/status validation and case-insensitive header operations; native setters remain mutable.

The six `Psr\Http\Message\*FactoryInterface` bindings default to the shared `Nyholm\Psr7\Factory\Psr17Factory`. Each can be replaced independently with `app()->bind(...)`.

```php
$factory = app(Psr\Http\Message\ResponseFactoryInterface::class);
$streams = app(Psr\Http\Message\StreamFactoryInterface::class);
return $factory->createResponse(200)
    ->withHeader('Content-Type', 'application/pdf')
    ->withBody($streams->createStreamFromFile(storage_dir('report.pdf')));
```

Emission reads streams in chunks. `getContent()` explicitly reads a string: it preserves the cursor for seekable streams and consumes the remaining bytes of non-seekable streams. HEAD responses retain representation headers but emit no body; bodyless status codes are handled in the same response path.

`file()` and `download()` retain their early-response behavior and now use normal preparation, emission, and termination. During application handling, `send()` returns control to the application using an internal response exception, so it does not emit twice or exit a PSR host. Prefer returning a response from new controller and middleware code.

Use `Nyholm\Psr7\Uri` for standard URI semantics; `Spark\Url` retains its application URL API. The native outbound HTTP client does not implement PSR-18.
