# Responses and API Resources

> **Read when:** returning JSON entities, pagination, redirects, downloads, or PSR responses.
> **Related:** [Queries](queries.md) (pagination) · [Requests and validation](requests-validation.md) · [FireLine](fireline.md) · [Inertia](inertia.md)
> Use `Spark\Http\Response::STATUS_*` and `Spark\Http\Header::*` for known statuses and headers.

Contents:

- [Choose the response contract](#choose-the-response-contract)
- [Define a resource](#define-a-resource)
- [Collections and pagination envelopes](#collections-and-pagination-envelopes)
- [Redirects, flash, and files](#redirects-flash-and-files)
- [PSR responses and emission](#psr-responses-and-emission)

## Choose the response contract

| Consumer/result | Native return |
| --- | --- |
| API entity | `PostResource::make($post)` |
| Created entity | `PostResource::make($post)->response(Response::STATUS_CREATED)` |
| List/paginator | `PostResource::collection($items)` |
| Empty API success | `response()->noContent()` |
| Message/scalar/webhook | `json($payload, $status)` |
| Browser page | `view('posts.show', ['post' => $post])` |
| Browser write | `to_route('posts.show', ['post' => $post->slug])` |
| Adapter page/write | Follow the FireLine/Inertia reference |

Resources define public entity fields. Avoid raw model columns and hand-built resource/pagination envelopes. Preserve existing non-resource contracts.

## Define a resource

```php
namespace App\Http\Resources;

use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->whenLoaded('author', fn($author) => UserResource::make($author)),
            'comments_count' => $this->whenCounted('comments'),
            'published_at' => $this->published_at,
            'url' => route('posts.show', ['post' => $this->slug]),
        ];
    }
}
```

Load needed relations in the query. `whenLoaded()` does not initiate lazy queries. Carbon values normalize recursively to UTC ISO-8601, other `DateTimeInterface` values to ATOM, and `Spark\Url` to strings. Pass model dates through; format only for an explicit different contract.

| Conditional helper | Inclusion rule |
| --- | --- |
| `when($condition, $value, $default)` / `unless(...)` | Truthy / falsy condition |
| `whenNotNull($value, $default)` | Non-null value |
| `whenHas($attribute, $value)` | Underlying attribute exists |
| `whenLoaded($relation, $value)` | Relation already loaded |
| `whenCounted($relation)` | Loaded `{relation}_count` attribute |

Defaults use MissingValue to omit fields. Use closures for values that must be evaluated only when included; use an explicit array default when spreading conditional fields.

## Collections and pagination envelopes

`collection()` accepts iterables and `Spark\Paginator\AbstractPaginator` implementations. Arrays/collections produce `data`; paginator resources add `links` and `meta` automatically.

| Paginator | Links | Metadata |
| --- | --- | --- |
| `paginate()` | first, last, prev, next | current_page, per_page, last_page, total, from, to |
| `simplePaginate()` | first, prev, next | current_page, per_page, from, to |
| `cursorPaginate()` | prev, next | path, per_page, next_cursor, prev_cursor |

Unavailable URLs/cursors are null. Empty numbered/simple pages have null from/to; partial ranges reflect actual items. Simple/cursor results do not invent totals. See [simple](queries.md#simple-pagination) and [cursor](queries.md#cursor-pagination) requirements.

Resources default to a `data` wrapper. Per-instance `wrap('user')` changes it; `withoutWrapping()` removes it. `additional(['meta' => [...]])` or `with(?Request $request = null): array` adds root metadata. Pagination controls its own fields while retaining additional metadata.

## Redirects, flash, and files

```php
return redirect('/dashboard');
return back()->withErrors(['email' => 'Invalid credentials.'])->withInput();
```

Use trusted redirect destinations. Flash/error behavior is described in [sessions](sessions.md). Downloads use `response()->download($contents, 'report.pdf', [Header::CONTENT_TYPE => 'application/pdf'])`; file/download helpers can send early. Prefer returning normal responses in new controller/middleware code.

## PSR responses and emission

Controllers, middleware, fallbacks, and exception handlers may return `Psr\Http\Message\ResponseInterface`. `Response::from()` normalizes these plus native responses, strings, arrays, resources, and integer statuses.

- `toPsrResponse()` exports a prepared message; `fromPsrResponse()` imports without reading its body. The StreamInterface identity is retained; absent imported Content-Type stays absent. Repeated headers, including Set-Cookie, remain separate. Nyholm validates headers/statuses; native setters stay mutable.
- All six PSR-17 factory interfaces default to one `Nyholm\Psr7\Factory\Psr17Factory`; replace each independently with a container binding.
- Emission reads streams in chunks. `getContent()` preserves seekable cursors, consumes remaining bytes of non-seekable streams. HEAD retains representation headers and emits no body; bodyless statuses share the same preparation path.
- `write($text)` appends to the prepared representation and removes stale Content-Length. An empty string changes nothing. Appending arbitrary text does not preserve JSON validity; use `json()` to replace structured content.
- During application handling, `send()` uses an internal response exception to return to the application. File/download helpers use normal preparation/emission/termination; they neither emit twice nor exit a PSR host.

```php
$factory = app(Psr\Http\Message\ResponseFactoryInterface::class);
$streams = app(Psr\Http\Message\StreamFactoryInterface::class);
return $factory->createResponse(Response::STATUS_OK)
    ->withHeader(Header::CONTENT_TYPE, 'application/pdf')
    ->withBody($streams->createStreamFromFile(storage_dir('report.pdf')));
```

Use Nyholm Uri for standard URI semantics; Spark Url keeps its application API. The outbound client is not PSR-18. Verify status, public fields, pagination boundaries, repeated headers, HEAD/empty bodies, and stream/early-response behavior relevant to the change.
