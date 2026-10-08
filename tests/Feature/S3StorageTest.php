<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class S3StorageTest extends FrameworkTestCase
{
    public function test_presigned_upload_constraints_and_pagination_cycles(): void
    {
        $assert = function (bool $condition, string $message): void {
            $this->assertTrue($condition, $message);
        };
        $throws = fn (callable $action, string $class = RuntimeException::class) => $this->assertThrows($class, $action);
        // Reconstruct the SigV4 canonical request from the URL and headers a client sends.
        $verify = function (string $url, array $headers) use ($assert) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $signature = $query['X-Amz-Signature'];
            unset($query['X-Amz-Signature']);
            $headers['host'] = parse_url($url, PHP_URL_HOST) . (parse_url($url, PHP_URL_PORT) ? ':' . parse_url($url, PHP_URL_PORT) : '');
            $lines = '';
            foreach (explode(';', $query['X-Amz-SignedHeaders']) as $name) {
                $assert(isset($headers[$name]), 'Missing signed header ' . $name);
                $lines .= $name . ':' . preg_replace('/\s+/', ' ', trim($headers[$name])) . "\n";
            }
            ksort($query);
            $canonical = "PUT\n" . parse_url($url, PHP_URL_PATH) . "\n" . http_build_query($query, '', '&', PHP_QUERY_RFC3986) . "\n" . $lines . "\n" . $query['X-Amz-SignedHeaders'] . "\nUNSIGNED-PAYLOAD";
            $scope = substr($query['X-Amz-Credential'], strpos($query['X-Amz-Credential'], '/') + 1);
            $key = 'AWS4test-secret';
            foreach (explode('/', $scope) as $part) {
                $key = hash_hmac('sha256', $part, $key, true);
            }
            $expected = hash_hmac('sha256', "AWS4-HMAC-SHA256\n{$query['X-Amz-Date']}\n$scope\n" . hash('sha256', $canonical), $key);
            return hash_equals($expected, $signature);
        };
        $client = new \Spark\Storage\S3Storage('test-key', 'test-secret', bucket: 'docs-bucket', region: 'us-east-1', sessionToken: 'temporary+token/==');
        $url = $client->temporaryUploadUrl('reports/a +%.pdf', 60, 'application/pdf', 'private');
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        $assert($parameters['X-Amz-SignedHeaders'] === 'content-type;host;x-amz-acl', 'Upload constraints must be signed.');
        $assert($parameters['X-Amz-Security-Token'] === 'temporary+token/==', 'Session token must survive encoding.');
        $assert(str_contains($url, '/reports/a%20%2B%25.pdf?'), 'Object key must be encoded once.');
        $assert($verify($url, ['content-type' => 'application/pdf', 'x-amz-acl' => 'private']), 'Incorrect PUT signature.');
        $assert(!$verify($url, ['content-type' => 'text/plain', 'x-amz-acl' => 'private']), 'Changing content type must invalidate signature.');
        $assert(!$verify($url, ['content-type' => 'application/pdf', 'x-amz-acl' => 'public-read']), 'Changing ACL must invalidate signature.');
        $assert($verify($client->temporaryUploadUrl('plain', 604800), []), 'Host-only signature failed.');
        $throws(fn () => $client->temporaryUploadUrl('key', 0), InvalidArgumentException::class);
        $throws(fn () => $client->temporaryUploadUrl('key', 604801), InvalidArgumentException::class);
        $throws(fn () => $client->temporaryUploadUrl('key', contentType: "text/plain\r\nX-Test: bad"), InvalidArgumentException::class);
        $throws(fn () => $client->temporaryUploadUrl('key', acl: 'invalid'), InvalidArgumentException::class);
        $throws(fn () => $client->deleteDirectory(''), InvalidArgumentException::class);

        // A defective provider must not keep prefix deletion looping through old pages.
        $cyclic = new class('test-key', 'test-secret', bucket: 'docs-bucket') extends \Spark\Storage\S3Storage {
            private int $page = 0;
            public function listFiles(int $limit = 100, string $prefix = '', string $marker = ''): array {
                return ['files' => [], 'is_truncated' => true, 'next_marker' => (++$this->page % 2 ? 'first' : 'second'), 'count' => 0];
            }
        };
        $throws(fn () => $cyclic->deleteDirectory('reports/'));
    }

    public function test_download_integrity_and_copy_before_delete(): void
    {
        if (!extension_loaded('curl') || !extension_loaded('simplexml')) {
            $this->markTestSkipped('S3 transport tests require curl and simplexml.');
        }

        $assert = function (bool $condition, string $message): void {
            $this->assertTrue($condition, $message);
        };
        $throws = fn (callable $action, string $class = RuntimeException::class) => $this->assertThrows($class, $action);
        $dir = $this->storagePath . '/s3';
        mkdir($dir, 0700);
        $server = null;
        try {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if (!$socket) {
                throw new RuntimeException('Cannot bind loopback fixture: ' . $error);
            }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $server = proc_open(
                [PHP_BINARY, '-S', $address, dirname(__DIR__) . '/Fixtures/s3-server.php'],
                [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'a'], 2 => ['file', $dir . '/server.log', 'a']],
                $pipes,
                null,
                ['SPARK_S3_LOG' => $dir . '/deleted.log'],
            );
            if (!is_resource($server)) {
                throw new RuntimeException('Cannot launch fixture.');
            }
            fclose($pipes[0]);
            $ready = false;
            for ($i = 0; $i < 50; $i++) {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $error, .05);
                if ($connection) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            $assert($ready, 'Fixture did not start.');
            $client = new \Spark\Storage\S3Storage('test-key', 'test-secret', 'http://' . $address, 'docs-bucket', true, timeout: 3);
            $destination = $dir . '/download';
            file_put_contents($destination, 'original');
            $throws(fn () => $client->downloadFile('missing', $destination));
            $assert(file_get_contents($destination) === 'original', '404 overwrote destination.');
            $throws(fn () => $client->downloadFile('truncated', $destination));
            $assert(file_get_contents($destination) === 'original', 'Partial transfer overwrote destination.');
            $assert($client->downloadFile('success', $destination), 'Successful download failed.');
            $assert(file_get_contents($destination) === "a\0b\n123", 'Download must preserve binary bytes.');
            $assert(glob($dir . '/.spark_s3_*') === [], 'Temporary files leaked.');
            $assert($client->deleteDirectory('reports/') === 2, 'Prefix deletion must consume all pages.');
            $deleted = file($dir . '/deleted.log', FILE_IGNORE_NEW_LINES);
            $assert($deleted === ['/docs-bucket/reports/a.txt', '/docs-bucket/reports/b.txt'], 'Wrong deletion keys.');
            $assert($client->moveFile('same', 'same'), 'Same-key move must preserve object.');
            $assert(file($dir . '/deleted.log', FILE_IGNORE_NEW_LINES) === $deleted, 'Same-key move deleted object.');
            $throws(fn () => $client->moveFile('source', 'copy-error'));
            $assert(file($dir . '/deleted.log', FILE_IGNORE_NEW_LINES) === $deleted, 'Failed copy deleted source.');
            $assert($client->moveFile('source', 'destination'), 'Move failed.');
            $assert(str_ends_with(file_get_contents($dir . '/deleted.log'), "/docs-bucket/source\n"), 'Move did not delete source after copy.');
        } finally {
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
        }
    }
}
