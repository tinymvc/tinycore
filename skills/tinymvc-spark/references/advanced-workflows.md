# Advanced implementation workflows

> **Read when:** a feature crosses consistency, concurrency, lifecycle, or performance boundaries.
> **Related:** [Application patterns](application-patterns.md) · [Queries](queries.md) · [Queues](queues.md)
> These are design and verification boundaries, not additional framework APIs.

Contents:

- [Atomic business transitions](#atomic-business-transitions)
- [Authorization scopes and query composition](#authorization-scopes-and-query-composition)
- [Commit, dispatch, and external effects](#commit-dispatch-and-external-effects)
- [Lock ownership and cache consistency](#lock-ownership-and-cache-consistency)
- [Request lifecycle and extension boundaries](#request-lifecycle-and-extension-boundaries)
- [Extend and release safely](#extend-and-release-safely)

| Situation | Section | API reference |
| --- | --- | --- |
| Multi-row or multi-step write, "only once" rules | [Atomic business transitions](#atomic-business-transitions) | [Queries](queries.md) |
| Tenant/owner scoping, eager loading, large datasets | [Authorization scopes and query composition](#authorization-scopes-and-query-composition) | [Queries](queries.md), [Auth and gate](auth-gate.md) |
| Dispatching jobs, email, payments, webhooks around a commit | [Commit, dispatch, and external effects](#commit-dispatch-and-external-effects) | [Queues](queues.md) |
| Duplicate computation, stale cache, lock expiry | [Lock ownership and cache consistency](#lock-ownership-and-cache-consistency) | [Cache and locks](cache-locks.md) |
| Redirects, headers, sessions, bindings, error mapping | [Request lifecycle and extension boundaries](#request-lifecycle-and-extension-boundaries) | [Container](container-pipelines.md), [Errors](extensions-errors.md) |
| Driver changes, schema rollout, release | [Extend and release safely](#extend-and-release-safely) | [Migrations](migrations.md), [Testing](workflow-testing.md) |

## Atomic business transitions

Write down the invariant first (for example, only the owning user can move a pending order to paid, once). Then decide which database connection owns every participating row, whether model callbacks/casts are required, and which external effects cannot roll back.

A conditional write is often simpler than read-then-write. This example assumes the app's default connection, an `orders` table, trusted `$orderId`/`$userId`, and an allowed pending-to-paid transition:

```php
use App\Models\Order;
use Spark\Facades\DB;

$changed = DB::transaction(function () use ($orderId, $userId): int {
    return Order::whereKey($orderId)
        ->where('user_id', $userId)
        ->where('status', 'pending')
        ->update(['status' => 'paid']);
});

if ($changed !== 1) {
    // Map this to the application's not-found/conflict/idempotent-success policy.
    throw new \DomainException('The order is not available for this transition.');
}
```

- This is a model **query** write: it keeps the model table and query scopes but does not hydrate an instance or run instance save callbacks. Use a model instance when casts/events or the updated representation matter.
- Do not infer affected-row counts from `increment()` / `decrement()`; their current return contract is boolean. Use an explicit conditional update when the number of transitioned rows drives the decision.
- For read/compute/write invariants, load the row under `lockForUpdate()` inside the same connection's transaction and mutate before commit. Acquire multiple locks in a stable order.
- SQLite omits SELECT row-lock clauses; use a strategy verified on that driver rather than assuming MySQL/PostgreSQL semantics.
- Nested transaction callbacks use savepoints, not independent durable commits.
- Transactions have no automatic deadlock retry. Retry only the whole retry-safe unit, with a bounded policy, and never put an irreversible HTTP/payment/email action inside the retry loop.
- Unique constraints remain the final protection for concurrent creation. `firstOrCreate()` is lookup-then-write and does not replace uniqueness. Define conflict keys explicitly for upserts and verify how the selected engine uses them.

Test: two contenders, rollback, conflicting ownership, and unchanged neighboring rows.

## Authorization scopes and query composition

**Scoping**
- Keep authorization constraints outside any OR group. A tenant/owner filter followed by an ungrouped OR can admit another owner's row.
- Reapply the same scope to mutation paths, restore/purge actions, relation subqueries, exports, and bulk endpoints.
- UI visibility and route binding do not authorize a write.

**Query composition**
- Distinguish physical table names, read aliases, model primary keys, and soft-delete predicates. Joined reads do not imply portable joined updates.
- Start independent operations with fresh builders or deliberate copies; inspect execution/reset behavior rather than reusing a consumed builder by accident.
- Keep named and positional binding styles consistent in raw fragments. Allowlist identifiers and sort expressions, since placeholders bind values, not column names.

**Eager loading**
- When narrowing projections, include the keys needed to match parents, related records, and pivots.
- Prefer `whereHas()` and constrained counts/existence projections when returning whole relations is unnecessary. Visibility on the parent does not filter related models.
- Keep serialization query-free with preloaded relations and resource `whenLoaded()` / `whenCounted()` fields.

**Large datasets**
- Distinguish SQL pagination from Collection operations that hydrate the full result set.
- Use a stable ordering with a unique tie-breaker. Repeated `orderBy()` replaces ordering in this implementation; consult [queries](queries.md) before composing sorts.
- For batch mutation, use a stable key boundary or a captured key set so changing rows do not shift offset pages and skip work.
- Do not invent cursor/chunk APIs from Laravel familiarity.

Test: empty relations, null foreign keys, custom keys, archived related records, and bounded query counts for multiple parents.

## Commit, dispatch, and external effects

Read [queues](queues.md) before choosing dispatch placement.

- Default database queue writes can participate in the business transaction. A named database connection, file queue, or Redis queue is a separate boundary.
- Pending dispatch may send when its object is destroyed; never use object lifetime as an after-commit mechanism.
- `PendingDispatch` has no declared `afterCommit()` method. Dispatch explicitly after the outer transaction succeeds when that is the contract you want. This still leaves a crash window between commit and dispatch.
- When losing a committed task is unacceptable, use an application outbox row written in the same transaction, a separate dispatcher, and an idempotent consumer. An outbox is an application pattern, not a built-in Spark service.
- MySQL `dispatchOnce()` / `pushOnce()` rejects an active transaction; follow the driver's documented boundary.
- Jobs execute **at least once**. Use a business operation identifier with persistent completion state, or the external provider's idempotency key. Repeated delivery, a lost response after a successful external call, and a worker dying before acknowledgement must not duplicate the effect. Queue deduplication alone is not business idempotency.
- Resolve current records from small job payloads and define behavior for deleted or cancelled targets.

Test: pre-commit invisibility where supported, rollback, commit followed by dispatch failure, retry after partial completion, and stale-claim recovery. Set execution/reservation timeouts against real job duration, and restart long-running workers after code/config updates.

## Lock ownership and cache consistency

See [cache and locks](cache-locks.md) for the API.

- A cache miss and `remember()` do not guarantee one computation. When duplicates violate an invariant, acquire a named lock, recheck the cache after acquisition, compute and store the result, and release in `finally` using the same owner instance.
- Distinguish "another process holds the lock" from a storage error. Use a bounded wait with an explicit timeout/fallback policy.
- Lock expiry can let a second worker enter while the first is still running. Ownership-safe unlock prevents deleting a successor's lock but does not stop the old worker from writing stale data. For important writes, also enforce a database version/status condition or another persistent invariant.
- Never use `forceRelease()` as normal cleanup. A lock backend shared across processes/hosts is necessary when the protected resource is shared.
- Invalidate caches after the business commit succeeds, not while a rollback is still possible.
- Define key dimensions (tenant, user/permission context, locale, query version) and distinguish a cached null from a missing key. When stale data is acceptable, document the freshness bound and refresh policy.

Test: owner mismatch, expiry/reacquisition, callback failure, concurrent misses, and namespace isolation.

## Request lifecycle and extension boundaries

- Trace bootstrap → providers → route/middleware → action → response preparation/termination for behavior affecting redirects, headers, sessions, or deferred work. Test early responses as well as successful controller returns.
- Do not write output before cookie/session/header preparation. In-process feature tests do not prove native HTTP/session transport; use a local server integration check for those contracts.
- When adding container bindings, decide whether a value is request-specific or process-long. Avoid storing the current request/user/tenant in a singleton consumed by workers or repeated requests. Inspect container binding semantics, provider registration order, facade accessors, and app overrides. Add a two-request/two-job isolation regression when state can leak.
- Custom exception mappings should preserve a useful HTTP status and the application's JSON/browser contract. Keep unexpected errors observable; never catch every throwable and report success.
- External clients need explicit failure/timeout handling and bounded retries suited to idempotency. Record operation IDs without secrets so a retry can be reconciled with its original attempt.

## Extend and release safely

- **Driver/contract change:** inspect the interface, every implementation, facades/helpers, test fixtures, and public references. Test shared behavior across affected backends and driver-specific SQL against a real engine. Compatibility means results, error behavior, and lifecycle, not just matching signatures.
- **Additive schema rollout:** assume old and new application/worker versions coexist. Add compatible schema, backfill in bounded batches, deploy readers/writers that handle the transition, and remove obsolete structure only after consumers have moved. DDL rollback and locking differ across engines; wrapping a migration in a transaction does not make it reversible everywhere.
- **Reporting:** use [workflow testing](workflow-testing.md) for exact commands. Record the tested PHP/driver configuration, assertions about persisted state and side effects, and any unverified integration. Advance from a minimal reproduction to the affected contract suite; avoid broad rewrites or repeated full runs without a new reason.
