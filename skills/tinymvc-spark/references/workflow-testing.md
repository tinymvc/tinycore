# Workflow Testing

Read this reference for unit and feature testing, test assertions, database verification, and regression workflows in TinyMVC / TinyCore (Spark). All paths refer to the application root.

---

## The Dependency-Free Test Runner

TinyMVC includes a native, high-performance, plain PHP test runner:

```bash
# Run the entire test suite
php test

# Or via composer
composer test

# Run a specific test suite (Unit or Feature)
php test --testsuite Feature

# Filter tests by class or method name
php test --filter PostTest
php test --filter test_can_create_post

# List all discovered tests
php test --list-tests
```

---

## Writing Tests: Unit vs. Feature

### 1. Unit Tests (`tests/Unit/*Test.php`)

Unit tests extend **`Spark\Testing\TestCase`** and verify isolated business logic, models, support classes, or services without booting the HTTP kernel:

```php
<?php

namespace Tests\Unit;

use Spark\Support\Str;
use Spark\Testing\TestCase;

final class SlugTest extends TestCase
{
    public function test_it_generates_url_friendly_slugs(): void
    {
        $slug = Str::slug('Building Modern APIs with TinyMVC');

        $this->assertSame('building-modern-apis-with-tinymvc', $slug);
    }
}
```

### 2. Feature Tests (`tests/Feature/*Test.php`)

Feature tests extend **`Tests\TestCase`** (which extends `Spark\Testing\ApplicationTestCase`). They boot a fresh application instance in an isolated temporary storage and testing database environment:

```php
<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Tests\TestCase;

final class PostApiTest extends TestCase
{
    public function test_can_list_paginated_posts(): void
    {
        $response = $this->getJson('/api/posts');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'slug'],
                ],
                'links',
                'meta' => ['current_page', 'total'],
            ]);
    }

    public function test_authenticated_user_can_create_post(): void
    {
        $user = User::create([
            'name' => 'Alex Developer',
            'email' => 'alex@example.com',
            'password' => 'secret123',
        ]);

        $payload = [
            'title' => 'First TinyMVC Post',
            'content' => 'Full article body content.',
            'category_id' => 1,
        ];

        $response = $this->actingAs($user)
            ->postJson('/api/posts', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'First TinyMVC Post');

        $this->assertDatabaseHas('posts', [
            'title' => 'First TinyMVC Post',
            'user_id' => $user->id,
        ]);
    }

    public function test_validation_errors_return_422(): void
    {
        $response = $this->postJson('/api/posts', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'content']);
    }
}
```

---

## Request and State Helpers on `$this`

In feature tests, `$this` provides modern HTTP verbs and state configurators:

### 1. HTTP Verbs
- `$this->get(string $uri, array $headers = [])`
- `$this->post(string $uri, array $data = [], array $headers = [])`
- `$this->put(string $uri, array $data = [], array $headers = [])`
- `$this->patch(string $uri, array $data = [], array $headers = [])`
- `$this->delete(string $uri, array $data = [], array $headers = [])`
- `$this->options(string $uri, array $data = [], array $headers = [])`
- `$this->head(string $uri, array $headers = [])`

### 2. JSON Shortcuts (sets `Accept: application/json` & `X-Requested-With`)
- `$this->getJson(string $uri, array $headers = [])`
- `$this->postJson(string $uri, array $data = [], array $headers = [])`
- `$this->putJson(string $uri, array $data = [], array $headers = [])`
- `$this->patchJson(string $uri, array $data = [], array $headers = [])`
- `$this->deleteJson(string $uri, array $data = [], array $headers = [])`

### 3. Request State, Authentication & Headers
- `$this->actingAs(Model $user)`: Logs in a user for subsequent requests in the test.
- `$this->withToken(string $token, string $type = 'Bearer')`: Sets the Authorization header.
- `$this->withHeaders(array $headers)`: Adds custom HTTP headers.
- `$this->flushHeaders()`: Clears default headers.
- `$this->withSession(array $data)`: Flashes session variables.
- `$this->withCookie(string $name, string $value)` / `$this->withCookies(array $cookies)`.

---

## Response Assertions (`Spark\Testing\TestResponse`)

The `$response` returned by `$this->get()`, `$this->postJson()`, etc. provides fluent assertions:

### Status Code Assertions
- `assertOk()`: Asserts HTTP 200.
- `assertCreated()`: Asserts HTTP 201.
- `assertAccepted()`: Asserts HTTP 202.
- `assertNoContent(int $status = 204)`: Asserts HTTP 204 with empty body.
- `assertUnauthorized()`: Asserts HTTP 401.
- `assertForbidden()`: Asserts HTTP 403.
- `assertNotFound()`: Asserts HTTP 404.
- `assertUnprocessable()`: Asserts HTTP 422.
- `assertTooManyRequests()`: Asserts HTTP 429.
- `assertServerError()`: Asserts HTTP 500.
- `assertSuccessful()`: Asserts HTTP 2xx.
- `assertStatus(int $code)`: Asserts exact status code.

### JSON Payload Assertions
- `assertJson(mixed $expected)`: Strict JSON equality check.
- `assertExactJson(mixed $expected)`: Alias for `assertJson()`.
- `assertJsonPath(string $path, mixed $expected)`: Asserts nested JSON value using dot notation (`assertJsonPath('data.user.role', 'admin')`).
- `assertJsonCount(int $count, ?string $path = null)`: Asserts number of items in array at root or path.
- `assertJsonValidationErrors(string|array $fields)`: Asserts HTTP 422 and verifies errors exist for given fields.
- `assertJsonFragment(array $fragment)`: Asserts that key/value fragment exists at any depth in the JSON.
- `assertJsonStructure(array $structure, ?string $path = null)`: Verifies JSON keys and nested array structure (supports `'*'` for list items).

### Content and Header Assertions
- `assertSee(string $text)`: Asserts response body contains string.
- `assertDontSee(string $text)`: Asserts response body does not contain string.
- `assertHeader(string $name, string $value)`: Asserts specific HTTP header value.
- `assertHeaderMissing(string $name)`: Asserts HTTP header is absent.
- `assertRedirect(string $url, int $status = 302)`: Asserts redirect status and Location header.

---

## Database and Authentication Assertions

Feature test cases provide built-in database inspection against the configured testing database:

```php
// Check that a database record exists matching criteria
$this->assertDatabaseHas('users', [
    'email' => 'alex@example.com',
    'is_active' => true,
]);

// Check that a database record does not exist
$this->assertDatabaseMissing('posts', [
    'slug' => 'deleted-post',
]);

// Check exact row count in a table
$this->assertDatabaseCount('orders', 3);

// Authentication state assertions
$this->assertAuthenticated();
$this->assertGuest();
```

---

## AI Agent Verification Checklist

Before finishing code changes:
1. Run `php -l` on every created or edited PHP file.
2. Run focused tests: `php test --filter=YourTestName`.
3. Verify that all HTTP responses return exact expected contracts (`JsonResource` for entities, `json()` for messages, `noContent()` for deletions).
4. Run frontend build if assets changed: `npm run build`.
5. Ensure zero warnings or unhandled exceptions.
