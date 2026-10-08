<?php

namespace Tests\Feature;

require_once dirname(__DIR__) . '/Support/LifecycleTestCase.php';

use Tests\Support\LifecycleTestCase;

/** Exercise the real SAPI, php://input, run(), send(), and shutdown termination. */
final class HttpTransportLifecycleTest extends LifecycleTestCase
{
    private mixed $process = null;
    private string $address;

    protected function setUp(): void
    {
        parent::setUp();
        // Let the OS choose a free loopback port. Fail clearly if sockets/processes are unavailable.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException("Cannot allocate HTTP test port: $error");
        }
        $this->address = stream_socket_get_name($socket, false);
        fclose($socket);
        $environment = getenv();
        unset($environment['PHP_CLI_SERVER_WORKERS']);
        $environment['TINYCORE_HTTP_TEST_STORAGE'] = $this->storagePath;
        $this->process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'expose_php=0', '-S', $this->address, dirname(__DIR__) . '/Fixtures/http.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->storagePath . '/server.log', 'a'], 2 => ['file', $this->storagePath . '/server.log', 'a']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
        );
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Cannot launch PHP HTTP test server.');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $this->address, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            if (!proc_get_status($this->process)['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('HTTP test server failed to start: ' . file_get_contents($this->storagePath . '/server.log'));
    }

    protected function tearDown(): void
    {
        try {
            if (is_resource($this->process)) {
                proc_terminate($this->process);
                proc_close($this->process);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** Read raw wire bytes so response normalization cannot hide a transport defect. */
    private function exchange(string $method, string $path, string $body = '', array $headers = []): array
    {
        $socket = stream_socket_client('tcp://' . $this->address, $errno, $error, 3);
        if ($socket === false) {
            throw new \RuntimeException("HTTP connection failed: $error");
        }
        try {
            stream_set_timeout($socket, 3);
            $headers = ['Host' => $this->address, 'Connection' => 'close', 'Content-Length' => (string) strlen($body), ...$headers];
            $wire = "$method $path HTTP/1.0\r\n";
            foreach ($headers as $name => $value) {
                $wire .= "$name: $value\r\n";
            }
            $wire .= "\r\n" . $body;
            while ($wire !== '') {
                $written = fwrite($socket, $wire);
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('HTTP request write failed.');
                }
                $wire = substr($wire, $written);
            }
            $raw = stream_get_contents($socket);
            if (stream_get_meta_data($socket)['timed_out']) {
                throw new \RuntimeException('HTTP response timed out.');
            }
        } finally {
            fclose($socket);
        }
        $this->assertStringContainsString("\r\n\r\n", $raw);
        [$head, $content] = explode("\r\n\r\n", $raw, 2);
        $lines = explode("\r\n", $head);
        $this->assertSame(1, preg_match('/^HTTP\/1\.[01] (\d{3})/', array_shift($lines), $match));
        $responseHeaders = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower($name)][] = trim($value);
        }
        return ['status' => (int) $match[1], 'headers' => $responseHeaders, 'body' => $content];
    }

    public function testRealJsonBodiesReachCustomInjectedRequestAcrossWriteMethods(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $this->exchange($method, '/input/42?q=a%20b', '{"name":"✓","nested":{"ok":true}}', ['Content-Type' => 'application/json']);
            $this->assertSame(200, $response['status']);
            $this->assertSame(['1'], $response['headers']['x-prepared']);
            $this->assertSame([
                'method' => $method,
                'query' => ['q' => 'a b'],
                'body' => ['name' => '✓', 'nested' => ['ok' => true]],
                'id' => '42',
                'same' => true,
                'custom' => true,
                'middleware' => true,
                'type' => 'application/json',
            ], json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testRealFormInputAndMethodOverrideReachRouting(): void
    {
        $response = $this->exchange('POST', '/input/form', 'name=Ada&_method=PATCH', ['Content-Type' => 'application/x-www-form-urlencoded']);
        $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(200, $response['status']);
        $this->assertSame('PATCH', $data['method']);
        $this->assertSame(['name' => 'Ada', '_method' => 'PATCH'], $data['body']);
    }

    public function testScalarMalformedAndEmptyJsonBodiesDoNotCrashRequestConstruction(): void
    {
        foreach (['null', 'true', '42', '"text"', '{broken', '', '[]', '{}'] as $body) {
            $response = $this->exchange('POST', '/input/json', $body, ['Content-Type' => 'application/json']);
            $this->assertSame(200, $response['status'], 'Body: ' . $body);
            $this->assertSame([], json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['body']);
        }
    }

    public function testContentTypesAndHeadAreCorrectOnTheWire(): void
    {
        foreach ([
            ['/html', 'text/html; charset=utf-8', '<h1>Hello ✓</h1>'],
            ['/text', 'text/plain; charset=utf-8', 'literal <b>text</b>'],
            ['/json', 'application/json; charset=utf-8', '{"ok":true}'],
            ['/vendor-json', 'application/vnd.test+json', '{"ok":true}'],
        ] as [$path, $type, $body]) {
            foreach (['GET', 'HEAD'] as $method) {
                $response = $this->exchange($method, $path);
                $this->assertSame(200, $response['status']);
                $this->assertSame([$type], $response['headers']['content-type']);
                $this->assertSame(['1'], $response['headers']['x-prepared']);
                $this->assertSame($method === 'HEAD' ? '' : $body, $response['body']);
            }
        }
    }

    public function testBodylessStatusesDoNotEmitContent(): void
    {
        foreach ([204, 205, 304] as $status) {
            $response = $this->exchange('GET', '/status/' . $status);
            $this->assertSame($status, $response['status']);
            $this->assertSame('', $response['body']);
        }
    }

    public function testRedirectEarlyExitAndHandledErrorArePreparedAndTerminatedExactlyOnce(): void
    {
        $cases = [['/json', 200, '{"ok":true}'], ['/redirect', 303, ''], ['/route-redirect', 301, ''], ['/early', 202, 'early'], ['/forbidden', 403, '{"message":"Forbidden","code":403}']];
        foreach ($cases as [$path, $status, $body]) {
            $response = $this->exchange('GET', $path, headers: ['Accept' => 'application/json']);
            $this->assertSame($status, $response['status']);
            $this->assertSame($body, $response['body']);
            $this->assertSame(['1'], $response['headers']['x-prepared']);
            if (in_array($path, ['/redirect', '/route-redirect'], true)) {
                $this->assertSame(['/html'], $response['headers']['location']);
            }
        }
        // The socket closes after PHP completes its shutdown handlers.
        $entries = array_map(fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($this->storagePath . '/terminated.jsonl', FILE_IGNORE_NEW_LINES));
        $this->assertSame([
            ['path' => '/json', 'same' => true],
            ['path' => '/redirect', 'same' => true],
            ['path' => '/route-redirect', 'same' => true],
            ['path' => '/early', 'same' => true],
            ['path' => '/forbidden', 'same' => true],
        ], $entries);
    }

    public function testRunAndShutdownDoNotEmitDuplicateTerminationEvents(): void
    {
        $response = $this->exchange('GET', '/events');
        $this->assertSame(200, $response['status']);
        $this->assertSame('events', $response['body']);
        $this->assertSame("terminated\n", file_get_contents($this->storagePath . '/events.log'));
    }
}
