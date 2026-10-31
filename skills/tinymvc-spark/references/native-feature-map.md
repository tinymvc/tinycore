# Native Feature Map

> **Read when:** choosing a subsystem or finding its focused reference.
> **Related:** [API discovery](api-discovery.md) · [Application patterns](application-patterns.md) · [Foundation](foundation.md)
> This index covers TinyCore and marked optional adapters. Verify exact APIs in the installed source.

Contents:

- [Framework feature directory](#framework-feature-directory)
- [Select the next reference](#select-the-next-reference)

## Framework feature directory

Use the linked API lists for supported names; inspect installed source for uncertain signatures. Keep each list in its topic reference.

| Feature | Main classes | Reference |
| --- | --- | --- |
| REST APIs | `Spark\Http\Resources\JsonResource`, `ResourceCollection` | [responses.md](responses.md) |
| Blade Templates | `Spark\View\Blade`, `Spark\View\BladeCompiler`, `Spark\View\Attributes` | [Native directives](views-frontend.md#native-directives) |
| FireLine v2 | `Spark\Fire\FireService`, `Spark\Fire\FireMiddleware` (optional `tinymvc/fireline-php`) | [fireline.md](fireline.md) |
| Inertia + Vite | `Inertia\Inertia`, `Spark\Http\Response` (optional `tinymvc/inertia-php`) | [inertia.md](inertia.md) |
| Cache | `Spark\Cache\CacheManager`, `Cache`, `SimpleCache`, `Storage\*` | [cache-locks.md](cache-locks.md) |
| Distributed Lock | `Spark\Cache\LockHandle`, `Spark\Cache\Storage\*` | [cache-locks.md](cache-locks.md) |
| Database: DB | `Spark\Database\DB`, PDO wrapper | [queries.md](queries.md) |
| Database: Schema | `Spark\Database\Schema\Schema`, `Blueprint`, `Column` | [Columns and constraints](migrations.md#columns-constraints-and-alterations) |
| Database: Model | `Spark\Database\Model`, `Spark\Database\Concerns\InteractsWithTracking`, `Spark\Database\Casts\Attribute` | [Casts](models-relations.md#casts-accessors-and-mutators) · [Write APIs](models-relations.md#writes-and-state) |
| Database: ORM | `Spark\Database\Relation\*` (`HasOne`, `HasMany`, `BelongsTo`, `BelongsToMany`, `HasManyThrough`) | [models-relations.md](models-relations.md) |
| Database: SoftDeletes | `Spark\Database\Concerns\InteractsWithSoftDeletes` | [soft-deletes.md](soft-deletes.md) |
| Facades | `Spark\Facades\*` | [helpers.md](helpers.md) |
| Global Helpers | `vendor/tinymvc/tinycore/src/Foundation/helpers.php` | [Helper lists](helpers.md#global-helpers) |
| Http: Client | `Spark\Http\Client\Http`, `HttpResponseContract` | [Request options](mail-http.md#fluent-http-requests) · [Response methods](mail-http.md#responses-and-concurrent-requests) |
| Http: Routing | `Spark\Http\Routing\Router`, `Route`, `RouteGroup` | [routing-controllers.md](routing-controllers.md) |
| Http: Session | `Spark\Http\Session`, session drivers | [sessions.md](sessions.md) |
| Http: Auth | `Spark\Http\Auth`, auth guards | [auth-gate.md](auth-gate.md) |
| Http: Gate | `Spark\Http\Gate` | [auth-gate.md](auth-gate.md) |
| Http: Input | `Spark\Http\Input` | [Typed accessors](requests-validation.md#input-shaping-and-extraction) |
| Http: InputErrors | `Spark\Http\InputErrors` | [requests-validation.md](requests-validation.md) |
| Http: Middleware | `Spark\Http\Middleware`, `MiddlewareInterface` | [middleware.md](middleware.md) |
| Http: Request | `Spark\Http\Request`, `Spark\Foundation\Http\FormRequest` | [requests-validation.md](requests-validation.md) |
| Http: Response | `Spark\Http\Response` | [responses.md](responses.md) |
| Http: Validator | `Spark\Http\Validation\Validator`, `Rule` | [Rules and builders](requests-validation.md#rules-and-constrained-validation) |
| Queue | `Spark\Queue\QueueManager`, `Queue`, `JobInterface`, `Dispatchable` | [queues.md](queues.md) |
| Storage | `Spark\Storage\Storage`, `LocalStorage`, `S3Storage` | [files-storage.md](files-storage.md) |
| Supports: Collection | `Spark\Support\Collection` | [helpers.md](helpers.md) |
| Supports: LazyCollection | `Spark\Support\LazyCollection` | [helpers.md](helpers.md) |
| Supports: Arr | `Spark\Support\Arr` | [helpers.md](helpers.md) |
| Supports: Str | `Spark\Support\Str`, `Spark\Support\Stringable` | [helpers.md](helpers.md) |
| Supports: Number | `Spark\Support\Number` | [helpers.md](helpers.md) |
| Supports: Js | `Spark\Support\Js` | [helpers.md](helpers.md) |
| Testing | `Spark\Testing\ApplicationTestCase`, `TestResponse`, `Assert` | [workflow-testing.md](workflow-testing.md) |
| Utils: File | `Spark\Utils\File` | [files-storage.md](files-storage.md) |
| Utils: Image | `Spark\Utils\Image` | [files-storage.md](files-storage.md) |
| Utils: JWT | `Spark\Utils\JWT` | [auth-gate.md](auth-gate.md) |
| Utils: Mail | `Spark\Utils\Mail` | [mail-http.md](mail-http.md) |
| Paginator | `Spark\Paginator\Paginator` | [responses.md](responses.md) |
| SimplePaginator | `Spark\Paginator\SimplePaginator` | [queries.md](queries.md#simple-pagination) |
| CursorPaginator | `Spark\Paginator\CursorPaginator`, `Cursor` | [queries.md](queries.md#cursor-pagination) |
| Utils: Vite | `Spark\Utils\Vite` | [frontend-setup.md](frontend-setup.md) |
| Carbon | `Spark\Carbon` | [localization-dates.md](localization-dates.md) |
| Events (App) | `Spark\Events` | [container-pipelines.md](container-pipelines.md) |
| Events (Model) | `Spark\Database\Events` | [models-relations.md](models-relations.md) |
| Hash | `Spark\Hash` | [helpers.md](helpers.md) |
| Translator | `Spark\Translator\Translator` | [localization-dates.md](localization-dates.md) |
| Url | `Spark\Url` | [helpers.md](helpers.md) |
| Container & Lifecycle | `Spark\Foundation\Application`, `Spark\Container` | [container-pipelines.md](container-pipelines.md) |
| Pipelines | `Spark\Pipeline` | [container-pipelines.md](container-pipelines.md) |
| Process & CLI | `Spark\Console\Process`, `Spark\Console\Prompt` | [concurrency.md](concurrency.md) |
| Telemetry | `Spark\Telemetry`, `Spark\Facades\Telemetry` | [Container and pipelines](container-pipelines.md#runtime-telemetry) |

## Select the next reference

Start with one primary topic, then follow its Related links. Keep existing application contracts and optional package boundaries. [SKILL.md](../SKILL.md) summarizes the core differences from Laravel; use [API discovery](api-discovery.md) for signatures/forwarding and [testing](workflow-testing.md) for verification.
