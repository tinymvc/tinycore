---
name: tinymvc-spark
description: Implement, debug, review, and test TinyMVC applications using TinyCore/Spark, or make explicitly requested TinyCore framework changes. Use for Spark-native PHP and its installed frontend integrations.
---

# TinyMVC Spark development

Use Spark's native APIs and the application's conventions. Laravel is a style reference, not API compatibility.

## Establish the target

- `tinymvc/spark` is the root framework with the `Spark\` namespace, facades, and `php spark` CLI. `tinymvc/tinycore` is its runtime engine; `tinymvc/skeleton` is the application boilerplate.
- **Application work:** use `vendor/tinymvc/tinycore/skills/tinymvc-spark` and `vendor/tinymvc/tinycore/src`. Vendor files are reference only; a sibling checkout may contain unreleased APIs.
- **Explicit core work:** use the target checkout's skill and `src/`, and run `php tests/run.php`. Maintain affected skill guidance alongside public API or behavior changes.
- This skill ships with TinyCore. Application `.agents/skills/tinymvc-spark/SKILL.md` files are discovery entrypoints; keep framework guidance here and application conventions in the app.

## Work from evidence

1. Inspect the nearest implementation/tests and `composer.json` plus installed Composer metadata/lockfile. For frontend work, inspect the actual package lock, asset entrypoint, and adapter versions. Read only the bootstrap/provider files relevant to the task.
2. Load one primary reference below. Trace the relevant route → request → action → model/service → response. Preserve the user's frontend and public response contract.
3. Verify uncertain APIs against the installed source with a bounded lookup:

   ```bash
   # Application root:
   php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php whenLoaded
   # Explicit TinyCore checkout:
   php skills/tinymvc-spark/scripts/api-lookup.php whenLoaded --source=src
   ```

   Use `--file=Database/` to narrow results; `--include-support` includes `Spark\Support` APIs. Read the matching body for behavior and follow parents, traits, and facade forwarding. See [API discovery](references/api-discovery.md) for documentation search, CLI discovery, and optional dependencies. No match does not prove an API is absent.
4. Implement only what the feature needs. Reuse native utilities and existing app patterns. Optional FireLine/Inertia/Relay APIs belong to their installed packages; do not add a package, frontend, or abstraction just to fit an example.

## Contracts that differ from Laravel

| Intent | Spark contract |
| --- | --- |
| Validated input | Inject `Spark\Foundation\Http\FormRequest` for substantial writes/authorization. `validated()` returns `Spark\Http\Input` (`Arrayable`, `ArrayAccess`); compatible APIs accept it directly. |
| Model writes | `Post::create($input)` returns a model; `$post->fill($input)->save()` returns `bool`. Handle failed writes where success matters. |
| Deletion | `$post->remove()` returns `bool`; `$post->delete()` is not the instance API. `Post::destroy($id)` and query `delete()` have different lifecycle contracts. |
| Query results | `get()` returns a collection; `all()` returns an array. Query `update()`/`delete()` return affected-row counts. |
| Pagination | Classes live in `Spark\Paginator`. `paginate()` includes totals; `simplePaginate()` omits counts; `cursorPaginate()` requires explicit, unique, non-null ordering. |
| Validation factory | `Validator::make($rules, $data)` takes rules first. See [validation](references/requests-validation.md) for parameter-array rules. |
| Passwords | `Hash::hashPassword($password)` / `Hash::verify($password, $hash)`. `make()` / `check()` are keyed HMAC helpers. |
| Cache | `Cache::remember($key, fn () => ..., '+1 hour')` takes the callback before expiration. |
| Jobs | Implement `Spark\Queue\Contracts\JobInterface` and use `Spark\Queue\Dispatchable`; `SendEmail::dispatch($id)->onQueue('emails')`. Reserve `job()` for recurring registration or raw callbacks. |
| Dates | `now()`, `carbon()`, `Spark\Carbon` follow the configured app timezone. Resources normalize Carbon to UTC; pass model dates through unless the contract requires another format. |

Model binding does not authorize access. Preserve ownership/tenant conditions and assign server-owned identity explicitly. Prefer model scopes/relations and eager loading for domain data. Use `DB` for transactions and deliberate low-level work, accounting for model casts, events, and soft deletes. A service should coordinate a workflow or reusable business rule; one model call needs no wrapper. Keep request/user/tenant state out of singletons and explicit in job payloads. Consult [advanced workflows](references/advanced-workflows.md) for transactions, external effects, retries, and idempotency; do not invent after-commit APIs.

## Match the response to the consumer

| Consumer | Result |
| --- | --- |
| API entity/list | Dedicated `ThingResource::make(...)` / `ThingResource::collection(...)` with explicit public fields; wrap paginators directly. |
| Message/scalar/webhook | `json($payload, $status)` preserving its contract. |
| Browser/Blade | `view(...)`; redirects after writes. |
| FireLine | `fire($view, $props)` for visits, `Fire::navigate()` for navigation; adapter action/validation/navigation envelopes. |
| Inertia | `Inertia::render(...)` for pages; adapter redirects after writes. |

Read the selected response/adapter reference before implementation. Inertia props are public browser data too. Keep domain services independent of response envelopes.

For Blade, use anonymous components for reusable UI and helpers/directives in templates. Render slots with `{{ $slot }}`; use `::disabled` or `x-bind:disabled` for Alpine bindings on `<x-...>` tags. Read [Blade](references/views-frontend.md) for escaping and supported component syntax; do not assume Laravel named slots, class components, or `$loop` exist.

## Code conventions

Use four-space indentation, next-line class/method braces, explicit visibility/types, and readable multiline chains. Follow compatible nearby conventions. The app base controller is `App\Http\Controllers\Controller`; prefer `Spark\Facades\*` in backend PHP or injection for explicit dependencies. Use `Request::METHOD_*`, `Response::STATUS_*`, `Header::*`, and `DB::DRIVER_*` constants with correct imports; see [responses](references/responses.md) and [queries](references/queries.md). Avoid `Illuminate\*` imports unless the application deliberately uses that package.

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
| Relay broadcasting, Pusher clients, channel authorization | [Realtime and Relay](references/realtime-relay.md) |
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

Run checks proportional to the change: `php -l` for changed PHP, the project's focused tests (`php test --filter=Name` in Skeleton; `php tests/run.php` in core), and the existing frontend build script for asset changes. For documentation-only changes, verify links, examples, and source contracts.

Test persisted state, public fields, validation/authorization failures, and transaction/retry behavior as relevant. Adapter work needs initial HTML and client-protocol visits; JSON test shortcuts can change validation handling. Inspect the diff for invented APIs, unhandled failed writes, accidental public fields, escaping regressions, and unnecessary layers. Report changes, checks actually run, and concrete limitations.
