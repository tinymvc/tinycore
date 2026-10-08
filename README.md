# TinyCore/Spark
The Backbone/Core Classes and Functionalities for TinyMvc/Spark Framework.

## Introduction

**TinyCore/Spark** is the backbone of the TinyMvc Framework, providing essential core classes, utility functions, and helper methods to streamline for simplified web development.

* [Learn More](https://tinymvc.github.io)

# TinyCore regression suite

Run from the TinyCore repository after `composer install`:

```sh
composer test
php tests/run.php --testsuite Unit
php tests/run.php --filter AuthenticationTest
php tests/database.php
php tests/database.php --filter RelationQueryTest
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

## Requirements and optional services

The default integration suite needs PDO SQLite, fileinfo, OpenSSL, and permission to create temporary files and local loopback sockets. HTTP/S3 fixtures start temporary PHP servers and stop them after each test. S3 transport requires curl and SimpleXML; no AWS credentials or external S3 account are used. Process contention checks require `pcntl`; unsupported optional checks are reported as skipped.

Redis is opt-in and supports TCP or a Unix socket. The server must support logical databases 0 and 1. For DBngin on its default port:

```sh
SPARK_TEST_REDIS_HOST=127.0.0.1 SPARK_TEST_REDIS_PORT=6379 composer test

# Alternatively:
SPARK_TEST_REDIS_SOCKET=/tmp/spark-test-redis.sock composer test
```

Set `SPARK_TEST_REDIS_PASSWORD` if authentication is required. Tests use unique Redis prefixes and remove their own keys during teardown, including after failures. They never use `FLUSHDB` or `FLUSHALL`. A skipped Redis test does not establish Redis compatibility.

Optional MySQL/PostgreSQL transaction and row-lock checks create and drop uniquely named tables in an explicitly configured **test database**:

```sh
SPARK_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=spark_test' \
SPARK_TEST_MYSQL_USER=test SPARK_TEST_MYSQL_PASSWORD=test \
php tests/run.php --filter test_mysql

SPARK_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;dbname=spark_test' \
SPARK_TEST_PGSQL_USER=test SPARK_TEST_PGSQL_PASSWORD=test \
php tests/run.php --filter test_postgresql
```

SQL compilation tests run without those servers, but compilation alone does not validate database-specific runtime behavior. Mail providers, live cloud storage, every optional extension, and every possible configuration are not covered. A passing regression suite is evidence of the tested behavior, not a guarantee of complete correctness.

To exercise every configured service in one run, set the Redis variables and both database DSN/user/password groups together, then run `composer test`. DBngin defaults commonly use MySQL user `root` and PostgreSQL user `postgres` with empty passwords; create dedicated test databases first. The SQL tests remove their uniquely named tables but do not create or drop the configured database.

## Full local service matrix

With DBngin MySQL (3306), PostgreSQL (5432) and Redis (6379) running:

```sh
php tests/services.php
```

This opt-in command creates uniquely named `spark_test_*` databases, runs the complete suite with SQLite plus the service-driver checks, then runs all focused database scenarios against MySQL and PostgreSQL. It propagates failures and drops only the databases it created in a `finally` block. Each scenario also removes its fixture tables. Redis tests retain their unique per-test prefixes. Administrative database credentials must allow creation and deletion of the disposable databases.

Defaults are MySQL `root` and PostgreSQL `postgres` with empty passwords. Override `SPARK_TEST_MYSQL_ADMIN_DSN`, `SPARK_TEST_PGSQL_ADMIN_DSN`, and the corresponding `_USER`/`_PASSWORD` variables for other local setups. Redis uses the variables described above. No application database is selected by this runner.

To run the focused scenarios in an existing disposable database, provide its connection variables and select the driver explicitly:

```sh
SPARK_TEST_DATABASE_DRIVER=mysql \
SPARK_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=spark_test_manual' \
SPARK_TEST_MYSQL_USER=root php tests/database.php
```

The focused runner refuses external DSNs unless the database name starts with `spark_test_`. It creates and removes its fixed `scenario_*` fixture tables, so use a database reserved for these tests and do not run concurrent suites in that same database. PostgreSQL uses `SPARK_TEST_DATABASE_DRIVER=pgsql` and the `SPARK_TEST_PGSQL_*` variables.
