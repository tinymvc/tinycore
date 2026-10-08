# TinyCore/Spark
The Backbone/Core Classes and Functionalities for TinyMvc/Spark Framework.

## Introduction

**TinyCore/Spark** is the backbone of the TinyMvc Framework, providing essential core classes, utility functions, and helper methods to streamline for simplified web development.

* [Learn More](https://tinymvc.github.io)

# TinyCore regression suite

Run from the TinyCore repository after `composer install`:

```sh
php tests/run.php
composer test
php tests/run.php --testsuite Unit
php tests/run.php --filter AuthenticationTest
php tests/run.php --filter RelationQueryTest
php tests/database.php
php tests/run.php --list-tests
```

The suite uses Spark's native test runner. Fixtures live in this repository; neither the skeleton nor the documentation repository is required. Each application test gets a unique temporary storage directory, removed during teardown, including when a test fails. Tests do not use your application configuration or database.

## Coverage

| Area | Checks |
| --- | --- |
| Database | SQLite schema creation/alteration, column nullability, indexes, migration ledger/seeds/rollback failures, query bindings, subqueries, qualified columns, pagination, aggregates and transaction behavior |
| Models and ORM | Arrayable writes, model CRUD/casts, soft deletes, belongs-to/has-many/has-one/many-to-many/through relations, eager loading, relationship scopes, counts and exists projections |
| Authentication | Named guards, Basic auth, JWT user binding, persisted/stateless tokens, expiration, revocation, guest/deleted users; HMAC/RSA signatures and algorithm restrictions |
| HTTP | Existing lifecycle and real HTTP transport suite; routing, request negotiation, trusted proxies, response preparation, JSON normalization, resources, CORS preflight and early errors |
| CSRF | Token creation, form/plain/encrypted header tokens, wrong/missing/non-string tokens, excluded paths |
| Cache and locks | File/database values and expiration, nulls, case-sensitive keys, namespace isolation, ownership, contention, stale owners, safe cache clearing |
| Queue and session | File/database job claims, retries, failures, stale reservations, scheduling, transactional dispatch and worker output; session write/read/expiry and named connections |
| Storage | Local upload/write/list/delete/metadata, traversal and symlink rejection; S3 signing, altered upload constraints, failed/partial downloads, paginated deletion, copy-before-delete using a local fixture |
| Blade and core services | Escaping, components, attributes, guards, pipeline, dates, events, throttling, container injection/singletons/circular dependencies, resource generation and test isolation |

Database fixtures explicitly use case-sensitive cache/lock keys. Application migrations must preserve that property too; this suite does not validate another application's migration files.

## Focused database scenarios

`tests/Feature/Database/` contains 283 independently reported scenarios. Each scenario creates fresh fixtures and verifies returned records, persisted state, exceptions, or query counts. They run unchanged against SQLite, MySQL, and PostgreSQL:

| Test class | Focus |
| --- | --- |
| PredicateTest | Comparisons, nulls, OR grouping, empty/sparse IN lists, dates, text predicates, correlated EXISTS, subqueries and binding collisions |
| ReadQueryTest | Hydration, projections, aggregates, joins, sorting, offsets, distinct values and cloned query isolation |
| WriteQueryTest / SetOperationsTest | Arrayable writes, scoped updates/deletes, increments, rollback/commit, unions, upserts and duplicate handling |
| ModelStateTest | Creation helpers, persistence, identity, original values, dirty tracking, refresh, casts and serialization |
| CustomKeyTest | String and zero-valued primary keys, aliases, writes, exclusions and bulk deletion |
| RelationQueryTest | Has-one/has-many/belongs-to, nested existence/absence, eager loading, constrained aggregates and parent scope isolation |
| PivotQueryTest | Attach/detach/sync/toggle, pivot attributes, parent isolation, eager loading and aggregates |
| ThroughRelationTest / MorphLoadingTest | Through relations, deleted intermediate models, mixed morph types, nested loading and bounded query counts |
| SoftDeleteTest | Default/trash scopes, restoration, permanent deletion, OR predicates and relation aggregates |
| SchemaContractTest | Introspection, nullability, defaults, unique constraints, adding/dropping columns and renaming tables |
| QueryRegressionTest | DISTINCT preservation, nested absence, paginated relationship projections, eager-loading query counts and query-free serialization |

The older database and migration tests also remain, including grammar compilation and migration-ledger failure scenarios. Validation now has independently reported boundary cases for optional/nullable fields, numeric/string/array sizes, integer rejection, nested fields and ASCII rules.

`src/Support` is Laravel-derived and deliberately excluded from dedicated tests. Its collections and helpers may still be used as inputs to Spark APIs. `voku/portable-ascii` is a development dependency so the validator's optional ASCII integration can be tested after a normal `composer install`.

## Running the suite

```sh
php tests/run.php
```

`composer test` runs the same command. This runs the framework suite with SQLite, plus Redis integration checks and two external SQL transaction/lock checks. Connection defaults come from `tests/config.php`; start your local DBngin services first. The external SQL checks create and remove their own disposable databases. Filters, unit-only runs and test discovery remain available:

```sh
php tests/run.php --filter AuthenticationTest
php tests/run.php --testsuite Unit
php tests/run.php --list-tests
```

## Database matrix

Start DBngin MySQL (3306) and PostgreSQL (5432), then run:

```sh
php tests/database.php
php tests/database.php --filter RelationQueryTest
php tests/database.php --list-tests
```

This runs the 283 focused database scenarios on SQLite, MySQL and PostgreSQL. It creates uniquely named `spark_test_*` databases automatically and removes them afterward, including after test failures. Redis is not needed for this command. Filters apply to all three engines; listing tests does not connect to services.

Failures produce a nonzero exit code. A failed test phase does not prevent the remaining phases from running. Cleanup errors also fail the command. Unavailable SQL servers or insufficient database permissions produce a setup error. The runner never selects an application's database; administrative credentials must allow creation and deletion of disposable databases. Both commands preserve terminal colors and keep redirected logs plain.

## Service settings and requirements

Both runners load `tests/config.php`. Edit that file to configure local services: MySQL `root` on port 3306, PostgreSQL `postgres` on port 5432 (both empty passwords), and Redis at `127.0.0.1:6379` are enabled by default. Existing environment variables override file values. Set the Redis host and socket, or an SQL admin DSN, to `null` to skip that optional integration in `run.php` when no environment override is present. The three-engine `database.php` command still requires both SQL services.

Available settings:

| Variable | Purpose |
| --- | --- |
| `SPARK_TEST_MYSQL_ADMIN_DSN` | Administrative connection; default `mysql:host=127.0.0.1;port=3306` |
| `SPARK_TEST_PGSQL_ADMIN_DSN` | Administrative connection; default `pgsql:host=127.0.0.1;port=5432;dbname=postgres` |
| `SPARK_TEST_MYSQL_USER` / `SPARK_TEST_MYSQL_PASSWORD` | MySQL credentials |
| `SPARK_TEST_PGSQL_USER` / `SPARK_TEST_PGSQL_PASSWORD` | PostgreSQL credentials |
| `SPARK_TEST_REDIS_HOST` / `SPARK_TEST_REDIS_PORT` | Redis TCP connection |
| `SPARK_TEST_REDIS_SOCKET` | Redis Unix socket instead of TCP |
| `SPARK_TEST_REDIS_PASSWORD` | Optional Redis authentication |

`database.php` supplies its disposable database DSNs itself. The external SQL checks in `run.php` also create disposable databases unless you explicitly supply `SPARK_TEST_MYSQL_DSN` / `SPARK_TEST_PGSQL_DSN` for an existing dedicated test database. Redis must support logical databases 0 and 1. Tests use unique prefixes and remove their own keys during teardown; they never use `FLUSHDB` or `FLUSHALL`.

The suite needs PDO SQLite (plus PDO MySQL/PostgreSQL for the full run), fileinfo, OpenSSL, and permission to create temporary files and local loopback sockets. HTTP/S3 fixtures start temporary PHP servers and stop them after each test. S3 transport requires curl and SimpleXML; no AWS credentials or external S3 account are used. Redis needs the Redis extension. Process contention checks require `pcntl`; unsupported optional checks are reported as skipped.

SQL compilation alone does not validate database-specific runtime behavior. Mail providers, live cloud storage, every optional extension, and every possible configuration are not covered. A passing regression suite is evidence of the tested behavior, not a guarantee of complete correctness.
