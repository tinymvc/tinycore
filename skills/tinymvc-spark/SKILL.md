---
name: tinymvc-spark
description: Implement, debug, review, and test TinyMVC applications powered by TinyCore/Spark. Use for Spark-native PHP, routes, controllers, validation, ORM, JSON resources, Blade, FireLine, Inertia, storage, queues, cache, HTTP, auth, and other framework features, or for explicitly requested TinyCore changes.
---

# TinyMVC Spark development

Write clean, spacious Laravel-style PHP using Spark's native APIs. Laravel is a style reference; it does not establish API compatibility. Prefer a direct, readable feature implementation over clever syntax or extra layers.

## Framework and source authority

- `tinymvc` is the vendor; `tinymvc/spark` (Spark) is the root framework, using the `Spark\` namespace alias, facades, and `php spark` CLI.
- `tinymvc/tinycore` (TinyCore) is the runtime engine; `tinymvc/skeleton` (Skeleton) is the application boilerplate.
- This skill ships with TinyCore. Application `.agents/skills/tinymvc-spark/SKILL.md` files are discovery entrypoints; maintain framework guidance here.
- **Application work:** use the installed `vendor/tinymvc/tinycore/skills/tinymvc-spark` and `vendor/tinymvc/tinycore/src`. Vendor files are reference only. A sibling checkout may contain unreleased APIs.
- **Explicit core work:** use the target checkout's `skills/tinymvc-spark`, `src/`, and `php tests/run.php`. Do not patch application vendor copies.
- Resolve links relative to this skill. Read only the reference needed for the task, then related topics as the implementation crosses them.

## Start with the existing application

1. Read `composer.json`, installed Composer metadata/lockfile, and the nearest implementation and tests. For routing or integration work, inspect the relevant bootstrap/provider files. For frontend work, also inspect package metadata/lockfiles and the existing asset entrypoint.
2. Trace one relevant route → request → action → model/service → response/view. Preserve intentional local conventions and the user's chosen frontend.
3. Verify uncertain signatures, return values, and lifecycle behavior against the installed source. Use [API discovery](references/api-discovery.md) for bounded lookups:

   ```bash
   # From an application root:
   php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php whenLoaded
   # From an explicitly targeted TinyCore checkout:
   php skills/tinymvc-spark/scripts/api-lookup.php whenLoaded --source=src
   ```

4. Optional packages have their own source: inspect `vendor/tinymvc/fireline-php` or `vendor/tinymvc/inertia-php` as appropriate. Core lookup does not enumerate their APIs. Installed source and package constraints take precedence over current-branch documentation.
5. Implement only the pieces the feature needs. Do not install a frontend, add an abstraction, upgrade a dependency, or change core just to fit an example.

## Elegant native code defaults

These are defaults for new and edited application code. Honor explicit product contracts and compatible local conventions. Read [application patterns](references/application-patterns.md) when designing a feature across multiple layers.

- Controllers coordinate requests, domain work, and responses. Keep reusable queries in model scopes and relations. Create a focused service for a multi-step workflow, transaction, external effect, or reusable business rule; a one-line model operation does not need a repository or service wrapper.
- Use injected `Spark\Foundation\Http\FormRequest` classes for substantial writes, uploads, and policy-bearing input. Authorization and validation happen before the action. Small read-only filters can use inline validation. Binding a model does not authorize access; preserve ownership and tenant conditions.
- `$request->validated()` returns `Spark\Http\Input` (`Arrayable`, `ArrayAccess`). Pass it directly to compatible APIs. Use `only()`, `except()`, and `merge()` to shape fields; assign server-owned identity explicitly rather than trusting submitted owner IDs.
- Query existing models through their scopes/relations. Eager-load data a list or resource needs. Use `get()` for collections and `paginate()` for bounded lists. Use `DB` for transactions and deliberate low-level operations, not as a substitute for model casts, events, or soft deletes.
- Prefer `Spark\Facades\*` in backend PHP and dependency injection where it clarifies a service dependency. In Blade, use helpers/directives such as `session()`, `user()`, `route()`, `old()`, `@auth`, and `@error`; do not place static facade calls in templates.
- Use `now()`, `carbon()`, and `Spark\Carbon` for application dates, parsing, and arithmetic instead of procedural date functions. Defaults follow `config('app.timezone', 'UTC')`; UTC is not guaranteed when another timezone is configured. Resources normalize Carbon to ISO-8601 UTC; pass model date attributes through unchanged unless a different output format is explicitly required.
- Jobs implement `Spark\Queue\Contracts\JobInterface` and use `Spark\Queue\Dispatchable`. Dispatch domain jobs statically; reserve `job()` for recurring bootstrap registration or raw callbacks. Keep request/user/tenant state explicit in job payloads and out of singletons.
- Use native collections, `Arr`, `Str`, `Number`, `Js`, HTTP, storage, cache, locks, and hashing before writing replacements. Higher-order collection syntax is useful for simple property/method access; use a clear callback for more complex logic.

| Intent | Native pattern / important contract |
| --- | --- |
| Create from validated fields | `Post::create($request->validated())`; no redundant `->all()` |
| Update a loaded model | `$post->fill($input)->save()`; `fill()` returns the model, `save()` returns `bool` |
| Delete a loaded model | `$post->remove()` returns `bool`; `$post->delete()` is not the instance API |
| Delete by key / query | `Post::destroy($id)` / query `delete()`; inspect lifecycle needs before bulk writes |
| Query writes | `update()` and `delete()` return affected-row counts; do not treat them as models |
| Resource pagination | `PostResource::collection($query->paginate(20))`; supplies `data`, `links`, `meta` |
| Reuse related data | `with()`, `load()`, `whenLoaded()`, `whenCounted()`; avoid N+1 queries |
| Atomic work / locking | `DB::transaction(fn () => ...)`; `Lock::withLock($key, fn () => ..., timeout: 10, waitTimeout: 5)` |
| Cached computation | `Cache::remember($key, fn () => ..., '+1 hour')`; include viewer/tenant context when needed |
| Queued work | `SendAccountEmail::dispatch($user->id)->onQueue('emails')` |
| Recurring work | `SyncReports::dispatchOnce()->repeatEveryMinutes(5)`; verify retry/idempotency semantics |
| Dates and output | `now()->addDays(7)`, `now()->toDateTimeString()`, `Number::currency($amount, 'USD')` |
| Strings and passwords | `str($title)->slug()`, `Hash::make($password)`, `Hash::check($password, $hash)` |
| Outbound HTTP | `Http::withToken($token)->timeout(10)->post($url, $payload)`; handle failed responses |
| Foreign keys | `$table->foreignId('user_id')->constrained()->cascadeOnDelete()`; choose deletion policy deliberately |

Handle failed `save()`/`remove()` results where success matters. Do not swallow validation, authorization, transaction, storage, or external-service failures and return a success response. Keep external effects consistent with transaction commits and retries; do not invent Laravel's queue or transaction APIs.

## Choose the response contract first

| Consumer | Controller result |
| --- | --- |
| API entity/list | Dedicated `ThingResource::make($thing)` / `ThingResource::collection($items)` with explicit public fields |
| API paginator | Resource collection wrapping the paginator; no hand-built pagination envelope |
| Message, scalar, webhook | `json($payload, $status)` preserving the intentional contract |
| Browser / Blade | `view(...)` for pages; `redirect(...)` / `to_route(...)` after writes |
| FireLine | `fire($view, $props)` for visits; adapter success/validation/navigation envelopes for actions |
| Inertia | `Inertia::render($component, $props)` for pages; adapter redirects after writes |

Do not expose all model attributes by accident. API resources own public field lists; Inertia props are also delivered to the browser. Keep domain services independent of page/JSON envelopes. See [responses](references/responses.md), [FireLine](references/fireline.md), or [Inertia](references/inertia.md) for the selected contract.

## Blade standards

Use anonymous components for reusable UI in `resources/views/components/`: `<x-alert :$message />`, `<x-forms.input :value="old('email')" />`, and `<x-card>...</x-card>`. Reserve includes for document sections or email pieces. Keep component defaults in `@props` and forward HTML attributes through `$attributes`.

- `{{ $value }}` escapes ordinary data through `e()`. Raw `{!! !!}` is for intentionally trusted HTML.
- **Render slots with `{{ $slot }}`.** TinyCore supplies rendered child markup as `Spark\Support\HtmlString`; `e()` honors `Htmlable`. User values inside the child template remain escaped. Do not prescribe raw slot echoes as a workaround.
- Component `href="{{ route('register') }}"` supports escaped string interpolation; `{!! !!}` interpolates raw expressions. `:href="route('register')"` passes a PHP value. Use bound syntax for typed arrays, booleans, and objects.
- On `<x-...>` tags, `:disabled="form.processing"` would evaluate PHP. Write `::disabled="form.processing"` or `x-bind:disabled="form.processing"` for Alpine. Ordinary HTML tags use Alpine's normal `:disabled` syntax.
- Read [Blade](references/views-frontend.md) for supported directives and component limitations. Do not assume Laravel named slots, class components, `$loop`, or other unimplemented APIs exist.

## Required coding style

- Four-space indentation, one statement per line, deliberate blank lines, and braces for control flow. Class/method braces go on the next line.
- Use descriptive names, explicit visibility, accurate parameter/return types, and minimal correct imports. The app base controller is `App\Http\Controllers\Controller`; input is `Spark\Http\Input`.
- Put long fluent chains and arrays/argument lists on multiple lines with trailing commas where supported. Prefer guard clauses and focused methods; avoid nested ternaries and side effects hidden in expressions.
- Use `fn (...) => ...` for a single expression. Comments explain domain constraints or surprising behavior rather than restating code.
- Preserve nearby conventions when compatible. Laravel-style formatting does not authorize `Illuminate\*` imports or assumed Laravel behavior.

## Read the reference for the task

Load one primary topic first. The feature map is a discovery aid, not a requirement to load every reference.

| Task | Reference |
| --- | --- |
| Feature architecture, controllers/services/resources together | [Application patterns](references/application-patterns.md) |
| Exact signatures or forwarded facade APIs | [API discovery](references/api-discovery.md) |
| Discover which native subsystem to use | [Native feature map](references/native-feature-map.md) |
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
| Vite, asset entries, choosing/configuring a frontend | [Frontend setup](references/frontend-setup.md) |
| FireLine navigation, forms, partials, Alpine state | [FireLine](references/fireline.md) |
| Inertia pages, props, redirects, React/Vue | [Inertia](references/inertia.md) |
| Cache and distributed locks | [Cache and locks](references/cache-locks.md) |
| Jobs, retry, recurring queues | [Queues](references/queues.md) |
| Concurrent tasks and subprocesses | [Concurrency](references/concurrency.md) |
| Uploads, storage, files, images | [Files and storage](references/files-storage.md) |
| Mail and outbound HTTP | [Mail and HTTP](references/mail-http.md) |
| Helpers, strings, collections, numbers, hashing, URLs | [Helpers](references/helpers.md) |
| Dates, timezones, translations | [Localization and dates](references/localization-dates.md) |
| Providers, dependency injection, events, pipelines | [Container and pipelines](references/container-pipelines.md) |
| Extensions and exception handling | [Extensions and errors](references/extensions-errors.md) |
| Bootstrap and configuration | [Foundation](references/foundation.md) |
| Schema and migrations | [Migrations](references/migrations.md) |
| CLI, deployment, operational tooling | [Operations](references/operations.md) |
| Tests and diagnosis | [Workflow and testing](references/workflow-testing.md) |
| Version migration | [Upgrades](references/upgrades.md) |

## Verify and hand off

Run checks proportional to the change: `php -l` for changed PHP, focused native tests (`php test --filter=Name` or the project's Composer script), and `npm run build` for frontend changes. In the core checkout use its own `php tests/run.php` runner. For documentation-only work, verify links, examples, and source contracts rather than running unrelated suites.

Test observable behavior: persisted state, public response fields, validation/authorization failures, ownership boundaries, and retry/transaction behavior where relevant. Adapter work needs initial HTML and client-protocol visits; JSON shortcuts may send headers that change validation handling. Read the adapter reference before writing those tests.

Inspect the final diff for invented methods/imports, redundant conversions, unhandled failed writes, accidental public fields, escaping regressions, and unnecessary new layers. If a needed API is absent, use a supported application-level design or explain the precise gap; keep dependency/core changes within the requested scope. Report the behavior changed, checks actually run, and concrete remaining limitations.
