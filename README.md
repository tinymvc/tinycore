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

## Verified test results

Verified on **2026-10-08**, using PHP **8.4.25** on macOS:

| Command | Result | Assertions | Skipped / failed |
| --- | --- | --- | --- |
| `php tests/run.php` | 1,014 passed | 4,386 | 0 / 0 |
| `php tests/database.php` — SQLite | 413 passed | 896 | 0 / 0 |
| `php tests/database.php` — MySQL | 413 passed | 896 | 0 / 0 |
| `php tests/database.php` — PostgreSQL | 413 passed | 896 | 0 / 0 |

The database matrix reuses the same 413 scenarios; these are not 1,239 distinct tests. They are also included in the main suite on SQLite. MySQL and PostgreSQL disposable databases were removed successfully. Redis integration, process contention, and local HTTP/S3 transport fixtures ran successfully in the main suite.

| Test location | Classes | Independently reported tests |
| --- | --- | --- |
| `tests/Unit/` | 6 | 56 |
| `tests/Feature/Database/` | 20 | 413 |
| Other `tests/Feature/` classes | 50 | 545 |
| **Total** | **76** | **1,014** |

The follow-up expansion adds **459 independently reported tests** to the previous 555, with **1,561 additional assertions**. Every test has a descriptive name and fresh test state. The driver contract defines 37 behaviors once and runs each on file, database and Redis storage (111 backend-specific tests); those are included in the 1,014 total, not added again. No changes were made to the test runner to increase the count.

| Added class / contract | Tests | Focus |
| --- | --- | --- |
| `ValidationFormatTest` | 100 | Email/URL/IP/MAC/UUID formats, Unicode/ASCII, digit and numeric boundaries, case/prefix/suffix rules, dates, accepted/declined and distinct values; success and failure state |
| `InputConversionTest` | 58 | Sanitizers, typed conversion, JSON, null/empty values, immutable selection, copying, filtering and array access |
| `HttpClientResponseTest` | 26 | Status boundaries, JSON scalar/list/object decoding, malformed bodies, decoded-cache invalidation, nested nulls and case-insensitive headers |
| `FileStorageContractTest`, `DatabaseStorageContractTest`, `RedisStorageContractTest` | 37 each | Cache replacement/expiry/type preservation, counter errors, ownership, force unlock, queue isolation, reservations, deduplication, retries, failed/repeated cleanup and exception metadata |
| `Database/ScopedMutationTest` | 48 | Update, hard delete, soft delete and restore under IN/NOT IN, null, grouped OR, ranges, raw bindings, subquery, date and column predicates; unaffected-row preservation |
| `Database/QueryStateContractTest` | 24 | Count/exists/read reuse, scoped aggregates and relation-filtered writes |
| `Database/ArithmeticWriteTest` | 31 | Increment/decrement scope preservation, positional/named write parameters, multiple SET values, mixed-binding rejection and unchanged neighboring rows |
| `RequestBoundaryTest` | 34 | Bearer/Basic header parsing, method flags, IPv4/IPv6 proxy ranges, malformed forwarded addresses, ports and trust boundaries |
| `BladeDirectiveTest` | 27 | Conditional attributes, nested expressions, conditionals, loops, comments, verbatim/PHP blocks and escaped/raw output; first/cached render consistency and output-buffer balance |

This is **behavioral regression coverage**, not a measured line or branch percentage. No Xdebug/PCOV coverage instrumentation was enabled. `src/Support/` is excluded from dedicated testing and was not modified.

## Coverage

| Area | Checks |
| --- | --- |
| Database | SQLite/MySQL/PostgreSQL runtime scenarios, foreign-key restrict/cascade/set-null behavior, composite uniqueness, nested savepoint recovery, connection reset/configuration, SQLite schema creation/alteration, column nullability, indexes, migration ledger/seeds/rollback failures, query bindings, subqueries, qualified columns, pagination, aggregates and transaction behavior |
| Models and ORM | Arrayable writes, model CRUD/casts, soft deletes, belongs-to/has-many/has-one/many-to-many/through relations, eager loading, relationship scopes, counts and exists projections |
| Authentication | Named guards, Basic auth, JWT user binding, persisted/stateless tokens, expiration, revocation, guest/deleted users; HMAC/RSA signatures and algorithm restrictions |
| HTTP | Named/optional/resource/group routes, route redirects through response preparation and termination; existing lifecycle and real HTTP transport suite; routing, request negotiation, trusted proxies, response preparation, JSON normalization, resources, CORS preflight and early errors |
| CSRF | Token creation, form/plain/encrypted header tokens, wrong/missing/non-string tokens, excluded paths |
| Cache and locks | File/database/Redis values and expiration, nulls, case-sensitive keys, namespace isolation, ownership, contention, stale owners, safe cache clearing, callback failures, cached nulls, ArrayAccess, lock callback cleanup after errors |
| Queue and session | File/database/Redis job claims, retries, failures, stale reservations, scheduling, transactional dispatch and worker output; session write/read/expiry and named connections; flash consumption, invalidation, job retry/backoff defaults and failure hooks |
| Storage | Local upload/write/list/delete/metadata, traversal and symlink rejection; S3 signing, altered upload constraints, failed/partial downloads, paginated deletion, copy-before-delete using a local fixture |
| Blade and core services | Layouts/includes, cache recompilation, output-buffer and section recovery after template errors, reserved render context, escaping, components, attributes, guards, pipeline, dates, events, throttling, container injection/singletons/circular dependencies, resource generation and test isolation; config cache invalidation, translations, URL query immutability and ports |

| Additional area | Test classes and checks |
| --- | --- |
| Gate | `GateTest`: deny-by-default, authorization exceptions, argument forwarding, before/after overrides, any/none, short circuiting |
| Hash | `HashTest`: binary/empty round trips, randomized IVs, tampered envelopes, wrong keys, malformed fields, array encoding errors, password rehash and keyed digests |
| Validation | `ValidationRulesTest`, `ValidationTest`, `ValidatorStateTest`, `Database/ValidationDatabaseTest`: presence/type/size boundaries, nested wildcards, state reset, confirmation, unique/exists/exclusions, bound values and avoiding database queries after invalid input |
| Events | `EventDispatcherTest`: listener priority, response order, false short circuiting, recursive once listeners, removal, conditional dispatch and invalid callbacks |
| View attributes | `ViewAttributesTest`: escaped values, boolean/null attributes, immutable filtering and default/class merging |

Database fixtures explicitly use case-sensitive cache/lock keys. Application migrations must preserve that property too; this suite does not validate another application's migration files.

## Focused database scenarios

`tests/Feature/Database/` contains 413 independently reported scenarios. Each scenario creates fresh fixtures and verifies returned records, persisted state, exceptions, or query counts. They run unchanged against SQLite, MySQL, and PostgreSQL:

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
| TransactionRecoveryTest | Nested rollback/commit, three-level savepoints, caught constraint failures, caller-owned transactions, original errors and connection reuse |
| ConstraintBehaviorTest | Foreign-key rejection, nullable keys, restrict/cascade/set-null deletes, cascade updates and composite uniqueness |
| PaginationBoundaryTest | Second/custom pages, invalid and out-of-range pages, zero/negative limits, empty results and grouped totals |
| ValidationDatabaseTest | Unique values and exclusions, exists/not-exists, bound hostile strings and rejected-field query suppression |
| ScopedMutationTest / ArithmeticWriteTest | Scoped writes and arithmetic, raw positional bindings, multiple SET values, IN/NOT IN expansion, soft deletion/restoration and unaffected rows |
| QueryStateContractTest | Reusable read predicates, aggregate scopes and relation-filtered updates |
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

This runs the 413 focused database scenarios on SQLite, MySQL and PostgreSQL. It creates uniquely named `spark_test_*` databases automatically and removes them afterward, including after test failures. Redis is not needed for this command. Filters apply to all three engines; listing tests does not connect to services.

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
