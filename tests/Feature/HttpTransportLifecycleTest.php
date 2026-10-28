<?php

namespace Tests\Feature;

require_once dirname(__DIR__) . '/Support/LifecycleTestCase.php';

use Tests\Support\LifecycleTestCase;
use Spark\Http\{Header, Request, Response};

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

    public function testPsrConversionPreservesRawInputAndRepeatedResponseHeaders(): void
    {
        foreach (['{"hello":"world"}', '0'] as $body) {
            $response = $this->exchange('POST', '/psr-request', $body, ['Content-Type' => 'application/json']);
            $this->assertSame(200, $response['status']);
            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($body, $data['raw']);
            $this->assertSame($body === '0' ? [] : ['hello' => 'world'], $data['parsed']);
        }

        $response = $this->exchange('GET', '/psr-response');
        $this->assertSame(200, $response['status']);
        $this->assertSame(['first=1; Path=/', 'second=2; Path=/'], $response['headers']['set-cookie']);
        $this->assertSame('cookies', $response['body']);
    }

    public function test_native_and_psr_upload_validation_works_over_http(): void
    {
        $boundary = 'spark-validation';
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"sample.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\nupload content\r\n--{$boundary}--\r\n";
        foreach (['native', 'psr'] as $kind) {
            $response = $this->exchange('POST', '/validate-upload/' . $kind, $body, [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ]);
            $this->assertSame(200, $response['status']);
            $this->assertSame('valid upload', $response['body']);
        }
    }

    public function test_forced_download_atomically_replaces_the_target_and_resets_its_state(): void
    {
        $path = $this->storagePath . '/download.txt';
        file_put_contents($path, 'original');
        $client = new \Spark\Http\Client\Http();
        $this->assertThrows(\RuntimeException::class, fn() => $client->download($path));
        $response = $client->download($path, true)->get('http://' . $this->address . '/psr-response');
        $this->assertSame(200, $response->status());
        $this->assertSame('cookies', file_get_contents($path));
        $this->assertThrows(\RuntimeException::class, fn() => $client->download($path));
        $second = $this->storagePath . '/new-download.txt';
        $client->download($second)->get('http://' . $this->address . '/psr-response');
        $this->assertSame('cookies', file_get_contents($second));
        $client->get('http://' . $this->address . '/csrf/other');
        $this->assertSame('cookies', file_get_contents($second));
        $this->assertSame([], glob($this->storagePath . '/.spark_download_*'));
    }

    public function test_failed_forced_download_preserves_the_existing_file(): void
    {
        $path = $this->storagePath . '/download.txt';
        file_put_contents($path, 'original');
        $client = new \Spark\Http\Client\Http();
        $response = $client->withRetry(0)->download($path, true)->get('http://127.0.0.1:1');
        $this->assertSame(0, $response->status());
        $this->assertSame('original', file_get_contents($path));
        $this->assertSame([], glob($this->storagePath . '/.spark_download_*'));
    }

    public function testNativeAndPsrUploadsShareTheSameHttpUploadPipeline(): void
    {
        $boundary = 'tinycore-upload-boundary';
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"sample.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\nupload content\r\n--{$boundary}--\r\n";
        foreach (['native', 'psr', 'columns', 'field'] as $kind) {
            $response = $this->exchange('POST', '/upload/' . $kind, $body, [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ]);
            $this->assertSame(200, $response['status']);
            $this->assertSame(['contents' => 'upload content'], json_decode($response['body'], true));
        }
    }

    public function test_native_and_psr_upload_batches_support_sparse_and_nested_inputs(): void
    {
        $boundary = 'tinycore-upload-boundary';
        foreach ([false, true] as $nested) {
            $body = '';
            foreach ([7 => 'first', 2 => '', 9 => 'last'] as $key => $contents) {
                $field = $nested ? "file[documents][$key]" : "file[$key]";
                $name = $contents === '' ? '' : $contents . '.txt';
                $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$name}\"\r\n"
                    . "Content-Type: text/plain\r\n\r\n{$contents}\r\n";
            }
            $body .= "--{$boundary}--\r\n";
            $kinds = $nested ? ['psr', 'columns', 'tree'] : ['native', 'psr', 'columns', 'tree', 'field'];
            foreach ($kinds as $kind) {
                $response = $this->exchange('POST', '/upload/' . $kind, $body, [
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                ]);
                $this->assertSame(200, $response['status'], $kind . ': ' . $response['body']);
                $this->assertSame(['contents' => ['first', 'last']], json_decode($response['body'], true));
            }
        }
    }

    public function test_csrf_cookie_uses_the_trusted_request_scheme(): void
    {
        foreach (['trusted' => true, 'untrusted' => false] as $kind => $secure) {
            $response = $this->exchange('GET', '/csrf/' . $kind, headers: ['X-Forwarded-Proto' => 'https']);
            $this->assertSame(200, $response['status']);
            $cookies = array_values(array_filter($response['headers']['set-cookie'] ?? [], fn($cookie) => str_starts_with($cookie, 'XSRF-TOKEN=')));
            $this->assertCount(1, $cookies);
            $this->assertSame($secure, str_contains(strtolower($cookies[0]), '; secure'));
            $sessionCookies = array_values(array_filter($response['headers']['set-cookie'] ?? [], fn($cookie) => str_starts_with($cookie, 'PHPSESSID=')));
            $this->assertCount(1, $sessionCookies);
            $this->assertSame($secure, str_contains(strtolower($sessionCookies[0]), '; secure'));
        }
    }

    public function test_remember_cookie_matches_trusted_scheme_and_is_http_only_and_lax(): void
    {
        foreach (['trusted' => true, 'untrusted' => false] as $kind => $secure) {
            $response = $this->exchange('GET', '/remember/' . $kind, headers: ['X-Forwarded-Proto' => 'https']);
            $this->assertSame(200, $response['status']);
            $cookies = array_values(array_filter($response['headers']['set-cookie'] ?? [], fn($cookie) => str_starts_with($cookie, 'auth=')));
            $this->assertCount(1, $cookies);
            $cookie = strtolower($cookies[0]);
            $this->assertSame($secure, str_contains($cookie, '; secure'));
            $this->assertStringContainsString('; httponly', $cookie);
            $this->assertStringContainsString('; samesite=lax', $cookie);
        }
    }

    public function test_session_cookie_persists_data_and_invalidation_rejects_the_old_id(): void
    {
        $created = $this->exchange('GET', '/session/write');
        $this->assertSame(200, $created['status']);
        $original = json_decode($created['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $original['value']);
        $headers = ['Cookie' => 'PHPSESSID=' . $original['id']];

        $read = $this->exchange('GET', '/session/read', headers: $headers);
        $this->assertSame($original, json_decode($read['body'], true, flags: JSON_THROW_ON_ERROR));
        $invalidated = $this->exchange('GET', '/session/invalidate', headers: $headers);
        $fresh = json_decode($invalidated['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame($original['id'], $fresh['id']);
        $this->assertNull($fresh['value']);

        $replayed = $this->exchange('GET', '/session/read', headers: $headers);
        $rejected = json_decode($replayed['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame($original['id'], $rejected['id']);
        $this->assertNull($rejected['value']);
    }

    public function test_downloads_and_nonseekable_streams_use_normal_response_preparation(): void
    {
        foreach (['file', 'content'] as $kind) {
            foreach (['GET', 'HEAD'] as $method) {
                $response = $this->exchange($method, '/download/' . $kind);
                $this->assertSame(200, $response['status']);
                $this->assertSame(['1'], $response['headers']['x-prepared']);
                $this->assertSame(["attachment; filename*=UTF-8''download.txt"], $response['headers']['content-disposition']);
                $this->assertSame(['131072'], $response['headers']['content-length']);
                $this->assertSame($method === 'HEAD' ? '' : str_repeat('download-content', 8192), $response['body']);
            }
        }
        $response = $this->exchange('GET', '/pipe');
        $this->assertSame(200, $response['status']);
        $this->assertSame('streamed-pipe', $response['body']);
        $this->assertSame(['1'], $response['headers']['x-prepared']);
    }

    public function test_body_parsing_uses_media_type_and_preserves_raw_bytes(): void
    {
        foreach ([
            ['PUT', 'application/x-www-form-urlencoded', 'name=Ada+Lovelace&zero=0', ['name' => 'Ada Lovelace', 'zero' => '0']],
            ['PATCH', 'application/problem+json', '{"name":"Ada"}', ['name' => 'Ada']],
            ['POST', 'text/plain', '{"name":"not parsed"}', []],
        ] as [$method, $type, $body, $parsed]) {
            $response = $this->exchange($method, '/input/body', $body, ['Content-Type' => $type]);
            $this->assertSame(200, $response['status']);
            $this->assertSame($parsed, json_decode($response['body'], true)['body']);
        }
    }

    public function test_psr_entry_point_captures_early_sends_without_emitting_or_exiting(): void
    {
        $response = $this->exchange('GET', '/psr-entry');
        $this->assertSame(200, $response['status']);
        $this->assertSame(['status' => 202, 'body' => 'early', 'prepared' => '1'], json_decode($response['body'], true));
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

    public function testJsonParsingPreservesLargeIntegersAndRawBodyBytes(): void
    {
        $body = '{"id":9223372036854775808,"negative":-9223372036854775809,"zero":0,"enabled":false,"items":[1,2]}';
        $response = $this->exchange(Request::METHOD_POST, '/psr-request', $body, [Header::CONTENT_TYPE => 'Application/Vnd.Example+Json; charset=UTF-8']);
        $this->assertSame(Response::STATUS_OK, $response['status']);
        $data = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($body, $data['raw']);
        $this->assertSame([
            'id' => '9223372036854775808',
            'negative' => '-9223372036854775809',
            'zero' => 0,
            'enabled' => false,
            'items' => [1, 2],
        ], $data['parsed']);
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
