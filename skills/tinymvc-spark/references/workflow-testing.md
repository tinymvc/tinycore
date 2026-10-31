# Workflow and Testing

> **Read when:** adding regression coverage, checking persisted state, or diagnosing application/core failures.
> **Related:** [Operations](operations.md) · [Application patterns](application-patterns.md) · [Requests and validation](requests-validation.md) · [Responses](responses.md)
> Use the target project's fixtures and isolated services; the native runner is not PHPUnit/Pest.

Contents:

- [Runner and test scope](#runner-and-test-scope)
- [Test a feature's behavior](#test-a-features-behavior)
- [Request and state helpers](#request-and-state-helpers)
- [Assertions](#assertions)
- [Adapter and lifecycle tests](#adapter-and-lifecycle-tests)
- [Verification and handoff](#verification-and-handoff)

## Runner and test scope

```sh
# Skeleton application:
php test
php test --testsuite Feature
php test --filter PostTest
php test --list-tests

# Explicit TinyCore work:
php tests/run.php
php tests/run.php --filter TelemetryTest
```

`--filter` is a **literal class/method substring**, not a regular expression. `--ansi`/`--no-ansi` work in either runner. Use Composer's test script only after inspecting it.

Unit tests extend `Spark\Testing\TestCase`; feature tests extend the app's `Tests\TestCase` backed by `Spark\Testing\ApplicationTestCase`. The feature base allocates temporary storage and calls `createApplication()`; it does **not** automatically migrate/create a database or roll back writes. Inspect test configuration before executing database tests. In core, `tests/config.php` supplies optional driver endpoints; report unavailable integration coverage explicitly.

## Test a feature's behavior

```php
public function test_authenticated_user_can_create_post(): void
{
    $user = $this->createFixtureUser(); // Use this application's test factory/fixture.
    $response = $this->actingAs($user)->postJson('/api/posts', [
        'title' => 'First post',
        'content' => 'Article body',
    ]);

    $response->assertCreated()->assertJsonPath('data.title', 'First post');
    $this->assertDatabaseHas('posts', ['title' => 'First post', 'user_id' => $user->id]);
}
```

Fixture helpers above are application-defined. Create required related records, hash passwords when the model does not do so, and test validation under the same authentication/authorization state as the intended request.

Cover successful persisted state and public fields, invalid input, unauthenticated/forbidden access, wrong-owner IDs, failed writes, and relevant transaction/retry behavior. Assert results rather than mirroring implementation.

## Request and state helpers

| Need | Helpers |
| --- | --- |
| HTTP | `get()`, `post()`, `put()`, `patch()`, `delete()`, `options()`, `head()` |
| JSON request | `getJson()`, `postJson()`, `putJson()`, `patchJson()`, `deleteJson()` |
| Authentication | `actingAs($model)`, `withToken($token, $type = 'Bearer')` |
| Headers | `withHeaders($headers)`, `flushHeaders()` |
| Session/cookies | `withSession($data)`, `withCookie()`, `withCookies()` |

JSON shortcuts set JSON Accept and X-Requested-With. `withSession()` writes values; it does not flash them. HTTP helpers accept per-request headers; inspect signatures for unusual bodies/uploads.

## Assertions

| Concern | Native assertions |
| --- | --- |
| Status | `assertStatus($code)`, `assertOk()`, `assertCreated()`, `assertAccepted()`, `assertNoContent()`, `assertUnauthorized()`, `assertForbidden()`, `assertNotFound()`, `assertUnprocessable()`, `assertTooManyRequests()`, `assertServerError()`, `assertSuccessful()` |
| JSON equality | `assertJson($expected)` / `assertExactJson()` are **strict equality**, not subset assertions |
| JSON content | `assertJsonPath()`, `assertJsonCount()`, `assertJsonFragment()`, `assertJsonStructure()` (supports `*`), `assertJsonValidationErrors()` (also asserts 422) |
| Body/headers | `assertSee()`, `assertDontSee()`, `assertHeader()`, `assertHeaderMissing()`, `assertRedirect($url, $status = 302)` |
| Database | `assertDatabaseHas()`, `assertDatabaseMissing()`, `assertDatabaseCount()` |
| Authentication | `assertAuthenticated()`, `assertGuest()` |

## Adapter and lifecycle tests

Use the client's actual headers. [FireLine](fireline.md) visits need `X-FireLine: 1` and recognized envelopes. [Inertia](inertia.md) forms use `X-Inertia`, HTML Accept, redirects, and session errors; `postJson()` can select a different validation branch. Use native Spark assertions; do not invent Laravel adapter assertions.

Check initial HTML plus protocol navigation, invalid form responses, and asset-version changes. For shared services, test sequential users/jobs in one process. Core transport changes need real SAPI tests for raw bodies, headers/cookies, early responses, and termination; in-process responses alone cannot prove wire behavior.

## Verification and handoff

Lint changed PHP, run focused tests, then required project checks. Build frontend assets when changed. Inspect the diff for invented APIs, unauthorized writes, escaping/public-field regressions, failed-write handling, and unnecessary abstractions. Report actual checks, skips, and concrete limitations; keep changing test counts out of this skill.
