<?php

namespace Spark\Testing;

use Spark\Http\Response;
use function array_key_exists;
use function is_array;
use function is_int;
use function sprintf;

/** A fluent wrapper around a Response for testing. */
final class TestResponse
{
    /** @param Response $response The response to test. */
    public function __construct(public readonly Response $response)
    {
    }

    /** Get the response's content as a string. */
    public function content(): string
    {
        return $this->response->getContent();
    }

    /** Get the response's content as a decoded JSON value. */
    public function json(?string $path = null): mixed
    {
        $data = json_decode($this->content(), true, 512, JSON_THROW_ON_ERROR);
        return $path === null ? $data : data_get($data, $path);

    }

    /** Get the response's content as a decoded JSON value, throwing if the path is missing. */
    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->response->getStatusCode(), sprintf(
            'Expected HTTP %d, got %d. %s',
            $status,
            $this->response->getStatusCode(),
            $this->content()
        ));

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->content());
        return $this;
    }

    public function assertHeader(string $name, string $value): self
    {
        $headers = array_change_key_case($this->response->getHeaders(), CASE_LOWER);

        Assert::assertArrayHasKey(strtolower($name), $headers);
        Assert::assertSame($value, $headers[strtolower($name)]);

        return $this;
    }

    public function assertRedirect(string $url, int $status = 302): self
    {
        return $this->assertStatus($status)->assertHeader('Location', $url);
    }

    /** Assert the complete decoded JSON value, including types. */
    public function assertJson(mixed $expected): self
    {
        Assert::assertSame($expected, $this->json());
        return $this;
    }
    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertAccepted(): self
    {
        return $this->assertStatus(202);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnprocessable(): self
    {
        return $this->assertStatus(422);
    }

    public function assertTooManyRequests(): self
    {
        return $this->assertStatus(429);
    }

    public function assertServerError(): self
    {
        return $this->assertStatus(500);
    }

    public function assertSuccessful(): self
    {
        $status = $this->response->getStatusCode();

        Assert::assertTrue($status >= 200 && $status < 300, "Expected a successful response, got HTTP $status.");
        return $this;
    }

    public function assertNoContent(int $status = 204): self
    {
        return $this->assertStatus($status)->assertContent('');
    }

    public function assertContent(string $expected): self
    {
        Assert::assertSame($expected, $this->content());
        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->content());
        return $this;
    }

    public function assertHeaderMissing(string $name): self
    {
        Assert::assertArrayNotHasKey(strtolower($name), array_change_key_case($this->response->getHeaders(), CASE_LOWER));
        return $this;
    }

    public function assertExactJson(mixed $expected): self
    {
        return $this->assertJson($expected);
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        Assert::assertSame($expected, $this->requiredJsonPath($path));
        return $this;
    }

    public function assertJsonCount(int $count, ?string $path = null): self
    {
        $data = $path === null ? $this->json() : $this->requiredJsonPath($path);

        Assert::assertIsArray($data, 'Expected an array or object at JSON path ' . ($path ?? '(root)') . '.');
        Assert::assertCount($count, $data);

        return $this;
    }

    /** Assert TinyCore's 422 response contains errors for each supplied field. */
    public function assertJsonValidationErrors(string|array $fields): self
    {
        $this->assertStatus(422);

        $errors = $this->requiredJsonPath('errors');
        Assert::assertIsArray($errors);

        foreach ((array) $fields as $field) {
            Assert::assertArrayHasKey($field, $errors);
            Assert::assertNotEmpty($errors[$field], "No validation errors for $field.");
        }

        return $this;
    }

    /** Find a strict key/value fragment at any level in the decoded JSON. */
    public function assertJsonFragment(array $fragment): self
    {
        Assert::assertTrue($this->containsFragment($this->json(), $fragment), 'JSON fragment was not found.');
        return $this;
    }

    /** Keys, nested structures, and '*' for every item in an array. */
    public function assertJsonStructure(array $structure, ?string $path = null): self
    {
        $this->checkStructure($structure, $path === null ? $this->json() : $this->requiredJsonPath($path));
        return $this;
    }

    private function containsFragment(mixed $data, array $fragment): bool
    {
        if (!is_array($data)) {
            return false;
        }

        $matches = true;
        foreach ($fragment as $key => $value) {
            if (!array_key_exists($key, $data) || $data[$key] !== $value) {
                $matches = false;
                break;
            }
        }

        if ($matches) {
            return true;
        }

        foreach ($data as $value) {
            if ($this->containsFragment($value, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function checkStructure(array $structure, mixed $data): void
    {
        Assert::assertIsArray($data, 'Expected a JSON object or array.');

        foreach ($structure as $key => $value) {
            if (is_int($key)) {
                Assert::assertArrayHasKey($value, $data);
            } elseif ($key === '*') {
                foreach ($data as $item) {
                    $this->checkStructure($value, $item);
                }
            } else {
                Assert::assertArrayHasKey($key, $data);
                $this->checkStructure($value, $data[$key]);
            }
        }
    }

    private function requiredJsonPath(string $path): mixed
    {
        $missing = new \stdClass();
        $value = data_get($this->json(), $path, $missing);

        Assert::assertNotSame($missing, $value, "Missing JSON path: $path");

        return $value;
    }
}
