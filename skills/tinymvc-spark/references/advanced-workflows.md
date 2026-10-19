# Advanced implementation workflows

Contents:

- [Atomic business transitions](#atomic-business-transitions)
- [Authorization scopes and query composition](#authorization-scopes-and-query-composition)
- [Commit, dispatch, and external effects](#commit-dispatch-and-external-effects)
- [Lock ownership and cache consistency](#lock-ownership-and-cache-consistency)
- [Request lifecycle and extension boundaries](#request-lifecycle-and-extension-boundaries)
- [Extend and release safely](#extend-and-release-safely)

Read the applicable workflow when a feature crosses consistency, concurrency, lifecycle, or performance boundaries. Pair it with the API-specific references; these are design decisions and verification criteria, not additional framework APIs.

## Atomic business transitions

Write down the invariant first: for example, only the owning user can move a pending order to paid, once. Decide which database connection owns every participating row, whether model callbacks/casts are required, and which external effects cannot roll back.

A conditional write is often simpler than read-then-write. This example assumes the app's default connection, an `orders` table, trusted `$orderId`/`$userId`, and an allowed pending-to-paid transition:

```php
use Spark\Facades\DB;
use App\Models\Order;

$changed = DB::transaction(function () use ($orderId, $userId): int {
    return Order::whereKey($orderId)
        ->where('user_id', $userId)
        ->where('status', 'pending')
        ->update(['status' => 'paid']);
});

if ($changed !== 1) {
    // Map this to the application's not-found/conflict/idempotent-success policy.
    throw new DomainException('The order is not available for this transition.');
}
```

This is a model query write: it preserves the model table and query scopes but does not hydrate an instance or run instance save callbacks. Use a model instance when casts/events or the updated representation matter. Do not infer affected-row counts from `increment()` / `decrement()`; their current return contract is boolean. Use an explicit conditional update when the number of transitioned rows is the decision input.

For read/compute/write invariants, load the row under `lockForUpdate()` inside the same connection's transaction, then mutate before commit. Acquire multiple locks in a stable order. SQLite omits SELECT row-lock clauses; use a strategy verified on that driver rather than assuming MySQL/PostgreSQL semantics. Nested transaction callbacks use savepoints, not independent durable commits. Transactions have no automatic deadlock retry; retry only the whole retry-safe unit with a bounded policy. Do not put an irreversible HTTP/payment/email action inside that retry loop.

Unique constraints remain the final protection for concurrent creation. `firstOrCreate()` is lookup-then-write; it does not replace uniqueness. Define conflict keys explicitly for upserts and verify how the selected engine uses them. Test two contenders, rollback, conflicting ownership, and unchanged neighboring rows.

## Authorization scopes and query composition

Keep authorization constraints outside any OR group. A tenant/owner filter followed by an ungrouped OR can admit another owner's row. Reapply the same scope to mutation paths, restore/purge actions, relation subqueries, exports and bulk endpoints. UI visibility and route binding do not authorize a write.

For each query, distinguish physical table names, read aliases, model primary keys, and soft-delete predicates. Joined reads do not imply portable joined updates. Start independent operations with fresh builders or deliberate copies; inspect execution/reset behavior rather than reusing a consumed builder accidentally. Keep named and positional binding styles consistent in raw fragments; allowlist identifiers and sort expressions since placeholders bind values, not column names.

For eager loading, include the keys required to match parents, related records and pivots when narrowing projections. Prefer `whereHas()` and constrained counts/existence projections when returning entire relations is unnecessary. Visibility on the parent does not automatically filter related models. Keep serialization query-free with preloaded relations and resource `whenLoaded()`/`whenCounted()` fields. Test empty relations, null foreign keys, custom keys, archived related records, and bounded query counts for multiple parents.

For large datasets, distinguish SQL pagination from Collection operations that hydrate the complete result set. Use a stable ordering with a unique tie-breaker. Repeated `orderBy()` replaces ordering in this implementation; consult [queries](queries.md) before composing sorts. For batch mutation, use a stable key boundary or a captured key set so changing rows does not shift offset pages and skip work. Do not invent cursor/chunk APIs from Laravel familiarity.

## Commit, dispatch, and external effects

Read [queues](queues.md) before choosing dispatch placement. Default database queue writes can participate in the business transaction. A named database connection, file queue or Redis queue is a separate boundary. Pending dispatch may send when its object is destroyed; do not use object lifetime as an after-commit mechanism.

The current `PendingDispatch` has no declared `afterCommit()` method. Dispatch explicitly after the outer transaction succeeds when that is the desired contract. This still leaves a crash window between commit and dispatch. When losing a committed task is unacceptable, use an application outbox row written in the same transaction, a separate dispatcher, and an idempotent consumer. An outbox is an application pattern, not a built-in Spark service. MySQL `dispatchOnce()`/`pushOnce()` rejects an active transaction; follow the driver's documented boundary.

Jobs execute at least once. Use a business operation identifier and persistent completion state or the external provider's idempotency key. Repeated delivery, a lost response after a successful external call, and a worker dying before acknowledgement must not duplicate the effect. Queue deduplication alone is not business idempotency. Resolve current records from small job payloads and define deleted/cancelled-target behavior.

Test pre-commit invisibility where supported, rollback, commit followed by dispatch failure, retry after partial completion, and stale-claim recovery. Set execution/reservation timeouts against real job duration and restart long-running workers after code/config updates.

## Lock ownership and cache consistency

A cache miss and `remember()` do not guarantee one computation. When duplicates violate an invariant, acquire a named lock, recheck the cache after acquisition, compute/store the result, and release in `finally` using the same owner instance. Distinguish “another process holds the lock” from a storage error. Use a bounded wait and an explicit timeout/fallback policy.

Lock expiry can let a second worker enter while the first is still running. An ownership-safe unlock prevents deleting a successor's lock, but does not stop the old worker from writing stale data. For important writes, also enforce a database version/status condition or another persistent invariant. Do not use `forceUnlock()` as normal cleanup. A lock backend shared across processes/hosts is necessary when the protected resource is shared.

Invalidate caches after successful business commit, not before rollback is still possible. Define key dimensions (tenant, user/permission context, locale, query version) and distinguish cached null from a missing key. When returning stale data is acceptable, document the freshness bound and refresh policy. Test owner mismatch, expiry/reacquisition, callback failure, concurrent misses, and namespace isolation.

## Request lifecycle and extension boundaries

Trace bootstrap → providers → route/middleware → action → response preparation/termination for behavior affecting redirects, headers, sessions or deferred work. Test early responses as well as successful controller returns. Do not write output before cookie/session/header preparation. In-process feature tests do not prove native HTTP/session transport; use a local server integration check for those contracts.

When adding container bindings, identify whether a value is request-specific or process-long. Avoid storing a current request/user/tenant inside a singleton consumed by workers or repeated requests. Inspect actual container binding semantics, provider registration order, facade accessors and any app overrides. Add a two-request/two-job isolation regression when state can leak.

Custom exception mappings should preserve useful HTTP status and the application's JSON/browser contract. Keep unexpected errors observable; do not catch every throwable and report success. External clients need explicit failure/timeout handling and bounded retries appropriate to idempotency. Record operation IDs without secrets so a retry can be reconciled with its original attempt.

## Extend and release safely

For a driver/contract change, inspect the interface, every implementation, facades/helpers, test fixtures, and public references. Test shared behavior across affected backends and driver-specific SQL against a real engine. Compatibility is about results, error behavior and lifecycle, not just matching signatures.

For an additive schema rollout, consider old and new application/worker versions coexisting. Add compatible schema, backfill in bounded batches, deploy readers/writers that handle the transition, and remove obsolete structure only after consumers have moved. DDL rollback/locking differs across engines; do not assume wrapping a migration in a transaction makes it reversible everywhere.

Use [workflow testing](workflow-testing.md) for exact commands. Record the tested PHP/driver configuration, assertions about persisted state and side effects, and any unverified integration. Advance from a minimal reproduction to the affected contract suite; avoid broad rewrites or repeated full runs without a new reason.
