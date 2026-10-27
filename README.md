# TinyCore

**TinyCore** (`tinymvc/tinycore`) is the core engine of **TinyMVC / Spark** (`tinymvc/spark`), a custom PHP web framework. It implements the framework's runtime classes, services, utilities, and helpers under the `Spark\` PHP namespace.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-1280%20passed-brightgreen.svg)](tests/)

Applications built with TinyMVC / Spark start from [Skeleton](https://github.com/tinymvc/skeleton) (`tinymvc/skeleton`), which provides the application structure and installs TinyCore as a Composer dependency under `vendor/tinymvc/tinycore`. This repository contains the core engine's source and regression suite.

Create a new application with:

```sh
composer create-project tinymvc/skeleton myapp
```

[Framework documentation](https://tinymvc.github.io)

## Versioned AI development skill

The [TinyMVC Spark skill](skills/tinymvc-spark/SKILL.md), its focused references, and its dependency-free API lookup tool ship with this Composer package under `skills/tinymvc-spark/`. Maintain them alongside framework API and behavior changes so the instructions match each installed release.

Skeleton keeps a small `.agents/skills/tinymvc-spark/SKILL.md` entry point that loads `vendor/tinymvc/tinycore/skills/tinymvc-spark/SKILL.md`. Normal Composer installs and updates deliver the guidance together with the framework; no copying, symlinks, or install hooks are required. Application-specific conventions stay in the application's `AGENTS.md`.

For core development, [AGENTS.md](AGENTS.md) points directly to the bundled skill. Run API lookup from this repository with an explicit source:

```sh
php skills/tinymvc-spark/scripts/api-lookup.php upsert --source=src
```

Application developers run the same tool from their application root:

```sh
php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php upsert
```

## Tests

Clone this repository and run `composer install`; Composer release archives omit `tests/`. The runtime `src/Testing/` APIs and bundled skill remain available to applications.

```sh
php tests/run.php                      # Full suite; composer test is equivalent
php tests/run.php --filter RelationQueryTest
php tests/run.php --testsuite Unit
php tests/run.php --list-tests
php tests/database.php                 # SQLite, MySQL, and PostgreSQL
SPARK_TEST_TABLE_PREFIX=app_ php tests/database.php
```

Verified on **2026-10-27**, with PHP **8.4.27** on macOS:

| Suite | Passed | Skipped | Assertions |
| --- | ---: | ---: | ---: |
| Full framework suite | 1,280 | 44 | 6,588 |
| Database scenarios, per engine and prefix configuration (previous verification) | 472 | 0 | 1,323 |

The full suite contains 1,324 tests. Redis and PostgreSQL were unavailable for this run; their integration tests were skipped with `SPARK_TEST_REDIS_HOST='' SPARK_TEST_REDIS_SOCKET='' SPARK_TEST_PGSQL_ADMIN_DSN='' php tests/run.php`. All remaining tests passed. The database row records the earlier SQLite, MySQL, and PostgreSQL runs with both empty and `app_` prefixes; that matrix was not rerun for this change. These shared scenarios are also included in the full suite on SQLite, not additional distinct tests. No line or branch coverage percentage was measured.

### Coverage

| Area | Main checks |
| --- | --- |
| Database and ORM | Prefixes, schema/constraints, transactions/savepoints, bindings, scoped writes, model identity and connection retention, casts, soft deletes, relations/pivots, aggregates, and pagination |
| Cache and locks | File/database/Redis contracts, helper array writes, expiration and nulls, atomic counters, namespaces, manager lifecycle, independent owners, contention/timeouts, ownership transfer, lease extension/inspection, and safe clearing |
| Queues and sessions | Named connections, claims, deduplication, retries/failures, stale reservations, worker restarts, persistence/expiry, flash data, and invalidation |
| HTTP and validation | Routing, request lifecycle, PSR integration, proxies, middleware, responses/resources, validation boundaries, uploads, and real HTTP client/session transport |
| Authentication | Guards, gates, password/encryption helpers, JWT tokens, revocation, and CSRF |
| Storage | Local filesystem boundaries, upload handling, S3 signing, pagination, and failed/partial transfers through local fixtures |
| Core services | Blade, container/providers, events, configuration, dates/localization, URLs, subprocesses, and concurrency |

`src/Support/` has no dedicated suite; its collections and helpers are exercised where Spark APIs use them. Live mail/cloud providers and every optional extension or deployment configuration are not covered.
