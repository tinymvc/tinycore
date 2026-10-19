# Operations and release verification

Read when diagnosing environment-dependent failures, preparing a release, or upgrading an application. Use [upgrades](upgrades.md) for version-specific changes and [foundation](foundation.md) for configuration and boot order.

## Establish the runtime

Inspect Composer's installed version, PHP version/extensions, configured driver and relevant named connection, and the actual web/worker entry points. CLI and web PHP can load different configuration. Read only relevant environment keys without printing secret values. Long-running workers can retain old code/config even when a web request sees the new release.

For routing/authentication problems behind a proxy, inspect trusted-proxy settings, effective scheme/host, secure-cookie settings and CORS/CSRF behavior. Do not broadly trust forwarded headers to fix a URL symptom. For missing assets, inspect the Vite build/manifest and configured base URL.

## Prepare an application release

1. Run relevant application tests and build changed frontend assets using the project's scripts. SQL changes need the intended database engine, not only SQLite compilation.
2. Review migration SQL, current migration ledger and rollback/recovery needs. Preserve historical migrations and persistent uploads/session/queue paths across releases.
3. Configure the server document root as `public`, keep debug disabled, retain the existing application key, and install locked production dependencies using the project's deployment procedure.
4. Clear/rebuild the appropriate generated configuration/views and restart workers when code/config changes. Inspect command scope before clearing shared caches. Never regenerate an application key as a routine deployment step.
5. Check a public page, JSON endpoint, login/logout, protected write, assets, upload/download access and an actual queued job. Verify expected logs and failure handling.

Commands are examples to adapt to the intended environment, not instructions to mutate a live deployment merely because the skill was loaded.

## Diagnose shared state and slow requests

Select cache, session and queue backends according to whether processes/hosts must share state. Local files on separate machines do not provide shared locks or queues. Inspect reservation/lock timeouts against actual job duration; retry behavior needs idempotent side effects.

Measure query counts, database time, external calls and memory before optimizing. Eager-load required relations, paginate bounded results, remove serialization queries, and cache only with a defined invalidation policy. `remember()` is not automatically a single-flight operation; use ownership-aware locking when duplicate computation would violate a requirement. Do not present collection iteration as database streaming.

## Handoff evidence

Report what changed, commands and outcomes, skipped or unavailable integrations, and any concrete release limitation. A framework suite passing on one PHP/OS combination does not verify the application's schema, providers, configuration or workload. Keep measured test results in project documentation rather than hardcoding a changing test count into the skill.
