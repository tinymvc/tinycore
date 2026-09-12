<?php

namespace Spark\Testing;

use Spark\Foundation\Application;
use Spark\Http\Request;
use Spark\Tracer;
use function in_array;

/** A fresh application and temporary storage for each feature test. */
abstract class ApplicationTestCase extends TestCase
{
    /** @var Application The application instance for this test. */
    protected Application $app;

    /** @var string The path to a temporary storage directory for this test. */
    protected string $storagePath;

    /** @var array The global variables before the test. */
    private array $globals;

    /** @var string The path to a temporary directory for this test. */
    private string $temporaryDirectory;

    /** @var array Default headers for subsequent requests in this test. */
    private array $defaultHeaders = [];

    /** @var string The timezone before the test. */
    private string $timezone;

    /** @var array The environment variables before the test. */
    private array $environment;

    /** @var Application|null The previous application instance before the test. */
    private ?Application $previousApp;

    /** @var Tracer|null The previous tracer instance before the test. */
    private ?Tracer $previousTracer;

    /** Create a new application instance for this test. */
    abstract protected function createApplication(): Application;

    /** Set up the test environment before each test. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->globals = [];

        foreach (['_ENV', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST'] as $key) {
            $this->globals[$key] = $GLOBALS[$key] ?? null;
        }

        $this->timezone = date_default_timezone_get();
        $this->environment = getenv();
        $this->previousApp = Application::$app;
        $this->previousTracer = Tracer::$instance;
        $this->temporaryDirectory = $this->storagePath = sys_get_temp_dir() . '/tinycore-test-' . bin2hex(random_bytes(10));

        mkdir($this->storagePath, 0700, true);

        \Spark\View\Blade::flushState();
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['TEST_STORAGE_PATH'] = $this->storagePath;
        $_GET = $_POST = $_FILES = $_COOKIE = $_SESSION = $_REQUEST = [];
        $_SERVER = $this->server('GET', '/');

        try {
            $this->app = $this->createApplication();
            if (!$this->app->isTesting()) {
                throw new \LogicException('Feature tests require a CLI testing application.');
            }
        } catch (\Throwable $e) {
            $this->cleanUp();
            throw $e;
        }
    }

    /** Clean up the test environment after each test. */
    protected function tearDown(): void
    {
        try {
            $this->cleanUp();
        } finally {
            parent::tearDown();
        }
    }

    protected function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, headers: $headers);
    }

    protected function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    protected function getJson(string $uri, array $headers = []): TestResponse
    {
        return $this->get($uri, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', ...$headers]);
    }

    protected function postJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, $headers, json: true);
    }

    protected function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $data, $headers);
    }

    protected function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $data, $headers);
    }

    protected function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, $data, $headers);
    }

    protected function options(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('OPTIONS', $uri, $data, $headers);
    }

    protected function head(string $uri, array $headers = []): TestResponse
    {
        return $this->request('HEAD', $uri, headers: $headers);
    }

    protected function putJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $data, $headers, json: true);
    }

    protected function patchJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $data, $headers, json: true);
    }

    protected function deleteJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, $data, $headers, json: true);
    }

    /** Default headers for subsequent requests in this test. */
    protected function withHeaders(array $headers): static
    {
        $this->defaultHeaders = array_replace($this->defaultHeaders, array_change_key_case($headers, CASE_LOWER));
        return $this;
    }

    protected function flushHeaders(): static
    {
        $this->defaultHeaders = [];
        return $this;
    }

    protected function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withHeaders(['Authorization' => $type . ' ' . $token]);
    }

    protected function withSession(array $data): static
    {
        \Spark\Http\Session::put($data);
        return $this;
    }

    protected function withCookie(string $name, string $value): static
    {
        return $this->withCookies([$name => $value]);
    }

    protected function withCookies(array $cookies): static
    {
        $_COOKIE = array_replace($_COOKIE, $cookies);
        return $this;
    }

    /** Log in a persisted user through the application's configured auth service. */
    protected function actingAs(\Spark\Database\Model $user): static
    {
        $this->app->get(\Spark\Http\Auth::class)->login($user);
        return $this;
    }

    protected function assertAuthenticated(): void
    {
        self::assertTrue($this->app->get(\Spark\Http\Auth::class)->check(), 'Expected an authenticated user.');
    }

    protected function assertGuest(): void
    {
        self::assertFalse($this->app->get(\Spark\Http\Auth::class)->check(), 'Expected a guest.');
    }

    protected function assertDatabaseHas(string $table, array $data): void
    {
        self::assertTrue($this->databaseQuery($table, $data)->exists(), "No matching row in $table.");
    }

    protected function assertDatabaseMissing(string $table, array $data): void
    {
        self::assertFalse($this->databaseQuery($table, $data)->exists(), "Unexpected matching row in $table.");
    }

    protected function assertDatabaseCount(string $table, int $count): void
    {
        self::assertSame($count, $this->databaseQuery($table, [])->count(), "Unexpected row count in $table.");
    }

    private function databaseQuery(string $table, array $data): \Spark\Database\QueryBuilder
    {
        $query = $this->app->get(\Spark\Database\DB::class)->table($table);
        foreach ($data as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }
        return $query;
    }

    /** Data is supplied as parsed input, including for JSON requests. */
    protected function request(string $method, string $uri, array $data = [], array $headers = [], bool $json = false): TestResponse
    {
        $saved = [$_SERVER, $_GET, $_POST, $_FILES, $_REQUEST];

        try {
            $method = strtoupper($method);
            $headers = array_replace($this->defaultHeaders, array_change_key_case($headers, CASE_LOWER));

            if ($json) {
                $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', ...$headers];
                $data = json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            }

            $_SERVER = $this->server($method, $uri, $headers);
            parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $_GET);
            $_POST = $_FILES = [];

            if (in_array($method, ['GET', 'HEAD'], true)) {
                $_GET = array_replace($_GET, $data);
                $_SERVER['QUERY_STRING'] = http_build_query($_GET);
                $_SERVER['REQUEST_URI'] = (parse_url($uri, PHP_URL_PATH) ?? '/')
                    . ($_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
            } else {
                $_POST = $data;
            }

            $_REQUEST = [...$_GET, ...$_POST];

            $response = $this->app->handle(new Request());

            $this->app->terminate();

            if ($method === 'HEAD') {
                $response->getContent(); // Prepare headers before suppressing the body.
                $response->setContent('');
            }

            return new TestResponse($response);
        } finally {
            [$_SERVER, $_GET, $_POST, $_FILES, $_REQUEST] = $saved;
        }
    }

    private function server(string $method, string $uri, array $headers = []): array
    {
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new \InvalidArgumentException('Invalid request URI.');
        }

        $https = ($parts['scheme'] ?? 'http') === 'https';
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''),
            'QUERY_STRING' => $parts['query'] ?? '',
            'HTTP_HOST' => ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : ''),
            'SERVER_PORT' => $parts['port'] ?? ($https ? 443 : 80),
            'HTTPS' => $https ? 'on' : 'off',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server["HTTP_$key"] = $value;

            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $server[$key] = $value;
            }
        }

        return $server;
    }

    private function cleanUp(): void
    {
        if (!isset($this->globals, $this->timezone, $this->storagePath, $this->environment)) {
            return;
        }

        if (isset($this->app)) {
            $this->app->flush();
            unset($this->app);
        }

        \Spark\View\Blade::flushState();
        Application::$app = $this->previousApp;
        Tracer::$instance = $this->previousTracer;

        foreach ($this->globals as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }

        foreach (array_diff_key(getenv(), $this->environment) as $key => $value) {
            putenv($key);
        }

        foreach ($this->environment as $key => $value) {
            putenv($key . '=' . $value);
        }

        date_default_timezone_set($this->timezone);

        // Only remove the directory allocated by this test, never a replacement symlink.
        $directory = $this->temporaryDirectory;
        clearstatcache(true, $directory);

        if (is_link($directory) || is_file($directory)) {
            unlink($directory);
        } elseif (is_dir($directory)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($directory);
        }
    }
}
