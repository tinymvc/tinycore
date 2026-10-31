---
name: tinymvc-spark
description: Implement, debug, review, and test TinyMVC applications built on TinyCore/Spark (namespace `Spark\`, `php spark` CLI), or make explicitly requested TinyCore framework changes. Use whenever a task touches Spark routes, controllers, models, FormRequest validation, API resources, Blade views, FireLine, Inertia, Relay, queues, cache, or migrations, even if the user never says "TinyMVC", and whenever a Laravel habit might be mistaken for a Spark API.
---

# TinyMVC Spark development

Use Spark's native APIs and the application's conventions. Laravel is a style reference, not API compatibility.

## 1. Establish the target

- `tinymvc/spark` is the root framework (`Spark\` namespace, facades, `php spark` CLI). `tinymvc/tinycore` is its runtime engine; `tinymvc/skeleton` is the application boilerplate.
- **Application work:** use `vendor/tinymvc/tinycore/skills/tinymvc-spark` and `vendor/tinymvc/tinycore/src`. Vendor files are reference only; a sibling checkout may contain unreleased APIs.
- **Explicit core work:** use the target checkout's skill and `src/`, run `php tests/run.php`, and update this skill when public API or behavior changes.
- This skill ships with TinyCore. An application's `.agents/skills/tinymvc-spark/SKILL.md` is only a discovery entrypoint: framework guidance lives here, application conventions live in the app.

## 2. Work from evidence

1. Inspect the nearest implementation and tests, `composer.json`, and the lockfile. For frontend work, also inspect the package lock, asset entrypoint, and adapter versions. Read only the bootstrap/provider files the task needs.
2. Load one primary reference (section 6). Check its supported casts, rules, methods, or directives before choosing an API; [feature map](references/native-feature-map.md) links these lists. Trace route → request → action → model/service → response. Preserve the user's frontend and public response contract.
3. Verify uncertain APIs against installed source:

   ```bash
   # Application root:
   php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php whenLoaded
   # Explicit TinyCore checkout:
   php skills/tinymvc-spark/scripts/api-lookup.php whenLoaded --source=src
   ```

   `--file=Database/` narrows results; `--include-support` adds `Spark\Support`. Read the matching body and follow parents, traits, and facade forwarding. No match does not prove an API is absent; see [API discovery](references/api-discovery.md).
4. Implement only what the feature needs. Reuse native utilities and app patterns. FireLine/Inertia/Relay APIs belong to their installed packages; do not add a package, frontend, or abstraction just to fit an example.

## 3. Contracts that differ from Laravel

| Intent | Spark contract | Laravel habit to avoid |
| --- | --- | --- |
| Validated input | Inject `Spark\Foundation\Http\FormRequest` for substantial writes/authorization. `validated()` returns `Spark\Http\Input` (`Arrayable`, `ArrayAccess`, property access); compatible APIs accept it directly. | Assuming a plain array |
| Validation factory | `Validator::make($rules, $data)`, rules first. Parameter-array rules: [validation](references/requests-validation.md). | `make($data, $rules)` |
| Model writes | `Post::create($input)` returns a model; `$post->fill($input)->save()` returns `bool`. Handle failed writes where success matters. | Expecting `save()` to return the model |
| Deletion | `$post->remove()` returns `bool`. `Post::destroy($id)` and query `delete()` have different lifecycle contracts. | `$post->delete()` (not the instance API) |
| Query results | `get()` returns a collection; `all()` returns an array. Query `update()`/`delete()` return affected-row counts. | Expecting `all()` to return a collection |
| Pagination | Classes live in `Spark\Paginator`. `paginate()` includes totals; `simplePaginate()` omits counts; `cursorPaginate()` needs explicit, unique, non-null ordering. | `Illuminate\Pagination\*` |
| Passwords | `Hash::password($pw)` / `Hash::verify($pw, $hash)`. `make()` / `check()` are keyed HMAC helpers. | `Hash::make()` / `Hash::check()` |
| Cache | `Cache::remember($key, fn () => ..., '+1 hour')`: callback before expiration. `cache('name')` selects a namespace; `cache(['key' => $v], '10 minutes')` writes. | `remember($key, $ttl, $cb)`; cache tags |
| Jobs | Implement `Spark\Queue\Contracts\JobInterface`, use `Spark\Queue\Dispatchable`: `SendEmail::dispatch($id)->onQueue('emails')`. Reserve `job()` for recurring registration or function/callable-array jobs. | Closure jobs (unsupported) |
| Dates | `now()`, `carbon()`, `Spark\Carbon` follow the app timezone. Resources normalize Carbon to UTC; pass model dates through unless the contract requires another format. | Reformatting dates by hand |

**Data safety**
- Model binding does not authorize access. Preserve ownership/tenant conditions and assign server-owned identity explicitly.
- Prefer model scopes, relations, and eager loading. Use `DB` for transactions and deliberate low-level work, accounting for casts, events, and soft deletes.
- A service coordinates a workflow or reusable rule; a single model call needs no wrapper. Keep controllers thin.
- Keep request/user/tenant state out of singletons and explicit in job payloads.
- For transactions, external effects, retries, and idempotency, read [advanced workflows](references/advanced-workflows.md). Do not invent after-commit APIs.

## 4. Match the response to the consumer

| Consumer | Result |
| --- | --- |
| API entity/list | Dedicated `ThingResource::make(...)` / `ThingResource::collection(...)` with explicit public fields; wrap paginators directly. Never return raw model columns. |
| Message/scalar/webhook | `json($payload, $status)` with `Response::STATUS_*`, preserving the existing contract. |
| Browser/Blade | `view(...)`; redirect after writes. |
| FireLine | `fire($view, $props)` for visits, `Fire::navigate()` for navigation; follow the adapter's action/validation/navigation envelopes ([FireLine](references/fireline.md)). |
| Inertia | `Inertia::render(...)` for pages; adapter redirects after writes ([Inertia](references/inertia.md)). Props are public browser data. |

Read the selected response/adapter reference before implementing. Keep domain services independent of response envelopes.

**Blade** uses native helpers and anonymous components only. Never assume Laravel-only features:
- No `\Spark\Facades\*` in views; use helpers (`user()`, `auth()`, `session()`, `route()`, `old()`, `errors()`, `config()`, `now()`).
- No `$loop` (use `$index => $item`), no `@forelse` (use `@if` + `@foreach` + `@else`). Check arrays with `!empty($items)` and Collections with `$items->isNotEmpty()`.
- No `@push`, `@stack`, `@once`, `@inject`, `<x-slot:name>`, or class components. Components are anonymous templates in `resources/views/components/` that render `{{ $slot }}`.
- On `<x-...>` tags, use `::disabled` or `x-bind:disabled` for Alpine bindings.
- Full directive list, escaping, and component syntax: [views and frontend](references/views-frontend.md).

## 5. Code conventions

Four-space indentation, next-line class/method braces, explicit visibility and types, readable multiline chains. Match the PHP version in `composer.json` and nearby conventions. Base controller is `App\Http\Controllers\Controller`. Prefer `Spark\Facades\*` in backend PHP, or inject explicit dependencies. Use `Request::METHOD_*`, `Response::STATUS_*`, `Header::*`, `DB::DRIVER_*` with correct imports ([responses](references/responses.md), [queries](references/queries.md)). Never import `Illuminate\*` unless the application deliberately uses that package.

## 6. Reference for the task

Load one primary topic first; use the feature map to find related ones. Paths are relative to the application root.

| Task | Reference |
| --- | --- |
| Feature architecture (controllers, services, resources) | [Application patterns](references/application-patterns.md) |
| Exact signatures, forwarded facade APIs | [API discovery](references/api-discovery.md) |
| Which native subsystem to use | [Native feature map](references/native-feature-map.md) |
| Routes, controllers, model binding | [Routing and controllers](references/routing-controllers.md) |
| Requests, validation, FormRequest, Input | [Requests and validation](references/requests-validation.md) |
| Middleware, CSRF, CORS, rate limiting | [Middleware](references/middleware.md) |
| Auth, guards, gates, JWT | [Auth and gate](references/auth-gate.md) |
| Session, flash, cookies | [Sessions](references/sessions.md) |
| Models, casts, scopes, relations, pivots, events | [Models and relations](references/models-relations.md) |
| Soft deletion and restore | [Soft deletes](references/soft-deletes.md) |
| SQL, joins, transactions | [Queries](references/queries.md) |
| Multi-step writes, locks, side-effect ordering | [Advanced workflows](references/advanced-workflows.md) |
| API resources, pagination, redirects | [Responses](references/responses.md) |
| Blade templates, components, escaping | [Views and frontend](references/views-frontend.md) |
| Vite, asset entries, frontend choice/config | [Frontend setup](references/frontend-setup.md) |
| FireLine navigation, forms, partials, Alpine | [FireLine](references/fireline.md) |
| Inertia pages, props, redirects, React/Vue | [Inertia](references/inertia.md) |
| Cache and distributed locks | [Cache and locks](references/cache-locks.md) |
| Jobs, retry, recurring queues | [Queues](references/queues.md) |
| Concurrent tasks and subprocesses | [Concurrency](references/concurrency.md) |
| Uploads, storage, files, images | [Files and storage](references/files-storage.md) |
| Mail and outbound HTTP | [Mail and HTTP](references/mail-http.md) |
| Relay broadcasting, Pusher clients, channel auth | [Realtime and Relay](references/realtime-relay.md) |
| Helpers, strings, collections, numbers, hashing, URLs | [Helpers](references/helpers.md) |
| Dates, timezones, translations | [Localization and dates](references/localization-dates.md) |
| Providers, DI, events, telemetry, pipelines | [Container and pipelines](references/container-pipelines.md) |
| Extensions and exception handling | [Extensions and errors](references/extensions-errors.md) |
| Bootstrap and configuration | [Foundation](references/foundation.md) |
| Schema and migrations | [Migrations](references/migrations.md) |
| CLI, deployment, operational tooling | [Operations](references/operations.md) |
| Tests and diagnosis | [Workflow and testing](references/workflow-testing.md) |
| Version migration | [Upgrades](references/upgrades.md) |

## 7. Verify and hand off

Run checks proportional to the change: `php -l` on changed PHP, focused tests (`php test --filter=Name` in Skeleton; `php tests/run.php` in core), and the existing frontend build script for asset changes. For documentation-only changes, verify links, examples, and source contracts.

Test persisted state, public fields, validation/authorization failures, and transaction/retry behavior as relevant. Adapter work needs initial-HTML and client-protocol visits; JSON test shortcuts can change validation handling.

Inspect the diff for invented APIs, unhandled failed writes, accidental public fields, missing authorization, escaping regressions, wrong Blade directives, and unnecessary layers. Report what changed, which checks actually ran, and any concrete limitations.
