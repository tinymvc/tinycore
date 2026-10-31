# TinyCore

TinyCore (`tinymvc/tinycore`) is the PHP runtime for **TinyMVC / Spark**, providing framework services under the `Spark\` namespace.

[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-1384%20passed-brightgreen.svg)](tests/)

Start an application with [Skeleton](https://github.com/tinymvc/skeleton):

```sh
composer create-project tinymvc/skeleton myapp
```

[Framework documentation](https://tinymvc.github.io) · [Core development instructions](AGENTS.md)

## Development

The bundled [TinyMVC Spark skill](skills/tinymvc-spark/SKILL.md) ships with Composer releases. Maintain its affected references alongside public API changes; keep application conventions in the application's `AGENTS.md`.

```sh
php skills/tinymvc-spark/scripts/api-lookup.php upsert --source=src
```

Application developers run the same tool from their application root:

```sh
php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php upsert
```

## Tests

Clone this repository and run `composer install`. Release archives omit `tests/` but retain the `Spark\Testing` APIs.

```sh
php tests/run.php                        # Full suite; also composer test
php tests/run.php --filter TranslatorTest
php tests/database.php                   # Requires MySQL and PostgreSQL
SPARK_TEST_TABLE_PREFIX=app_ php tests/database.php
```

Verified **2026-10-31**, PHP **8.4.27**:

| Checks | Passed | Skipped | Assertions |
| --- | ---: | ---: | ---: |
| Full suite | 1,384 | 0 | 7,378 |
| Database scenarios, per engine/prefix | 474 | 0 | 1,335 |

All **1,384 tests** passed, including Redis, PostgreSQL, MySQL, and real HTTP integration checks. The shared database scenarios also passed on SQLite, MySQL, and PostgreSQL with both empty and `app_` prefixes; these are repeated scenarios, not additional distinct tests.

### Coverage

| Area | Main checks |
| --- | --- |
| Database and ORM | Prefixes, schema/constraints, transactions/savepoints, bindings, scoped writes, model identity and connection retention, casts, soft deletes, relations/pivots, aggregates, and pagination |
| Cache and locks | File/database/Redis contracts, helper array writes, expiration and nulls, atomic counters, namespaces, manager lifecycle, independent owners, contention/timeouts, ownership transfer, lease extension/inspection, and safe clearing |
| Queues and sessions | Named connections, claims, deduplication, retries/failures, stale reservations, worker restarts, persistence/expiry, flash data, and invalidation |
| HTTP and validation | Routing, request lifecycle, PSR integration, proxies, middleware, responses/resources, validation boundaries, uploads, and real HTTP client/session transport |
| Authentication | Guards, gates, password/encryption helpers, JWT tokens, revocation, and CSRF |
| Storage | Local filesystem boundaries, upload handling, S3 signing, pagination, and failed/partial transfers through local fixtures |
| Events and telemetry | Wildcard payloads, priorities, one-time listeners, nested dispatch, lazy payloads, observer failure/recursion isolation, HTTP sends/downloads/prepared requests/pools, and service/lifecycle hooks |
| Core services | Blade, container/providers, CLI boot, configuration/environment caching, Tracer logging/error handling, Tinker, dates/localization, URLs, subprocesses, and concurrency |
