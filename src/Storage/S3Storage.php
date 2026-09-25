<?php

namespace Spark\Storage;

use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use Spark\Http\Client\Contracts\HttpResponseContract;
use Spark\Http\Client\Http;
use Spark\Http\Client\HttpRequest;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function count;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function strlen;

/**
 * Amazon S3 and compatible object storage via Signature V4.
 * Batch writes are not atomic: some objects may succeed before an exception.
 */
class S3Storage
{
    use Macroable, Conditionable;

    private const MAX_UPLOAD_SIZE = 5 * 1024 ** 3;
    private const CONCURRENCY = 5;

    private string $accessKey;
    private string $secretKey;
    private string $baseUrl;
    private string $region;
    private int $timeout = 300;

    private ?string $publicUrl;
    private ?string $sessionToken;
    private string $bucket;

    /** Endpoint must be an origin URL, without a bucket path, query, or credentials. */
    public function __construct(
        string $accessKey,
        string $secretKey,
        ?string $endpoint = null,
        ?string $bucket = null,
        bool $usePathStyleEndpoint = false,
        int $timeout = 300,
        ?string $region = null,
        ?string $sessionToken = null,
        ?string $url = null,
        private int $listVersion = 2,
    ) {
        if (!in_array($listVersion, [1, 2], true)) {
            throw new InvalidArgumentException('S3 list version must be 1 or 2.');
        }

        if (
            !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket ?? '')
            || preg_match('/\.\.|\.-|-\./', $bucket) || filter_var($bucket, FILTER_VALIDATE_IP)
        ) {
            throw new InvalidArgumentException('Invalid S3 bucket name.');
        }

        if (
            $accessKey === '' || $secretKey === '' || preg_match('/[\s\x00-\x1f\x7f,\/]/', $accessKey)
            || $timeout < 1 || ($sessionToken !== null && preg_match('/[\x00-\x20\x7f]/', $sessionToken))
        ) {
            throw new InvalidArgumentException('S3 credentials and a positive timeout are required.');
        }

        if (!$region && $endpoint && preg_match('~^(?:https://)?([a-z0-9-]+)\.digitaloceanspaces\.com/?$~i', $endpoint, $match)) {
            $region = $match[1];
        }

        $region = $region ?: 'us-east-1';
        if (!preg_match('/^[a-z0-9-]+$/D', $region)) {
            throw new InvalidArgumentException('Invalid S3 region.');
        }

        $suffix = str_starts_with($region, 'cn-') ? 'amazonaws.com.cn' : 'amazonaws.com';
        $endpoint = $endpoint ?: "https://s3.$region.$suffix";
        if (!str_contains($endpoint, '://')) {
            $endpoint = "https://$endpoint";
        }

        $parts = parse_url($endpoint);
        if (
            !$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['pass']) || isset($parts['user'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)
            || preg_match('/[\s\x00-\x1f\x7f]/', $endpoint)
        ) {
            throw new InvalidArgumentException('S3 endpoint must be an HTTP(S) origin, without a path or credentials.');
        }

        if (
            !$usePathStyleEndpoint && (filter_var($parts['host'], FILTER_VALIDATE_IP)
                || str_starts_with($parts['host'], '[') || ($parts['scheme'] === 'https' && str_contains($bucket, '.')))
        ) {
            throw new InvalidArgumentException('IP endpoints and dotted HTTPS bucket names require path-style URLs.');
        }

        $origin = $parts['scheme'] . '://' . ($usePathStyleEndpoint ? '' : "$bucket.") . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $this->bucket = $bucket;
        $this->baseUrl = $origin . ($usePathStyleEndpoint ? "/$bucket" : '');

        if (
            $url !== null && $url !== '' && (!filter_var($url, FILTER_VALIDATE_URL)
                || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
                || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null
                || parse_url($url, PHP_URL_USER) !== null)
        ) {
            throw new InvalidArgumentException('Public URL must be an HTTP(S) URL without credentials, query, or fragment.');
        }

        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->timeout = $timeout;
        $this->region = $region;
        $this->sessionToken = $sessionToken ?: null;
        $this->publicUrl = $url ? rtrim($url, '/') : null;
    }

    /**
     * Upload one path + key, or an array of [object key => local path].
     * Returns {success, key, url, size}, or those results indexed by object key.
     * Streams single PUTs up to 5 GiB; larger files require multipart upload.
     * Metadata applies to every file. ACLs are omitted by default for policy-controlled buckets.
     *
     * @param string|array<string, string> $filePath
     * @param array<string, string> $metadata
     */
    public function uploadFile(string|array $filePath, ?string $key = null, array $metadata = [], ?string $acl = null): array
    {
        if ($acl !== null && !in_array($acl, ['private', 'public-read'], true)) {
            throw new InvalidArgumentException('This client supports null, private, or public-read ACLs.');
        }

        if (is_array($filePath) && $key !== null) {
            throw new InvalidArgumentException('For multiple uploads, pass [object key => local path] without a separate key.');
        }

        $headers = $acl === null ? [] : ['x-amz-acl' => $acl];
        $metadataSize = 0;

        foreach ($metadata as $name => $value) {
            if (
                !is_string($name) || !preg_match('/^[a-zA-Z0-9_.-]+$/D', $name)
                || !is_string($value) || preg_match('/[^\x20-\x7e\t]/', $value)
            ) {
                throw new InvalidArgumentException('Metadata requires valid header names and printable ASCII string values.');
            }

            $name = 'x-amz-meta-' . strtolower($name);
            if (isset($headers[$name])) {
                throw new InvalidArgumentException('Metadata names must be unique ignoring case.');
            }

            $headers[$name] = preg_replace('/[ \t]+/', ' ', trim($value));
            $metadataSize += strlen($name) + strlen($headers[$name]);
        }

        if ($metadataSize > 2048) {
            throw new InvalidArgumentException('User metadata exceeds the 2 KiB limit.');
        }

        $files = is_array($filePath) ? $filePath : [($key ?? '') => $filePath];
        foreach ($files as $objectKey => $path) {
            $this->objectUrl((string) $objectKey);

            if (!is_string($path) || str_contains($path, '://') || !is_file($path) || !is_readable($path)) {
                throw new InvalidArgumentException("Upload source must be a readable local file for key: {$objectKey}");
            }
        }

        $results = $this->transfer('PUT', $files, $headers);

        return is_array($filePath) ? $results : $results[$key ?? ''];
    }

    /**
     * Delete one exact key or a list of keys; returns true or [key => true].
     * Missing objects also succeed. Versioned buckets retain older versions.
     * Uses bounded parallel DELETEs, never a prefix or whole-bucket deletion.
     *
     * @param string|string[] $key
     */
    public function deleteFile(string|array $key): bool|array
    {
        $keys = is_array($key) ? $key : [$key];
        foreach ($keys as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('Object keys must be strings.');
            }

            $this->objectUrl($item);
        }

        $results = $this->transfer('DELETE', array_fill_keys($keys, null));

        return is_array($key) ? $results : $results[$key];
    }

    /** Return one page. Pass next_marker back unchanged; V2 uses opaque continuation tokens. */
    public function listFiles(int $limit = 100, string $prefix = '', string $marker = ''): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('The listing limit must be between 1 and 1000.');
        }

        $query = ['encoding-type' => 'url', 'max-keys' => $limit, 'prefix' => $prefix];
        if ($this->listVersion === 2) {
            $query['list-type'] = 2;
            if ($marker !== '') {
                $query['continuation-token'] = $marker;
            }
        } elseif ($marker !== '') {
            $query['marker'] = $marker;
        }
        $request = $this->request('GET', '', query: $query);

        $response = $request->execute();

        $this->checkResponse($response, 200, 'List files');

        $xml = $this->parseXml($response->body());
        if ($xml->getName() !== 'ListBucketResult') {
            throw new RuntimeException('Unexpected S3 listing response.');
        }

        $decode = fn(string $value): string => (string) $xml->EncodingType === 'url' ? rawurldecode($value) : $value;

        $files = [];
        foreach ($xml->Contents as $object) {
            $key = $decode((string) $object->Key);
            $files[] = [
                'key' => $key,
                'size' => (int) $object->Size,
                'last_modified' => (string) $object->LastModified,
                'etag' => trim((string) $object->ETag, '"'),
                'url' => $this->getPublicUrl($key),
            ];
        }

        if (!in_array((string) $xml->IsTruncated, ['true', 'false'], true)) {
            throw new RuntimeException('S3 listing is missing a valid IsTruncated flag.');
        }
        $truncated = (string) $xml->IsTruncated === 'true';
        // Continuation tokens are opaque, even when EncodingType is url.
        $next = $this->listVersion === 2 ? (string) $xml->NextContinuationToken : $decode((string) $xml->NextMarker);
        if ($next === '' && $this->listVersion === 1 && $files) {
            $next = $files[array_key_last($files)]['key'];
        }
        if ($truncated && ($next === '' || $next === $marker)) {
            throw new RuntimeException('Truncated S3 listing did not provide a usable next cursor.');
        }

        return [
            'files' => $files,
            'is_truncated' => $truncated,
            'next_marker' => $truncated ? $next : null,
            'count' => count($files)
        ];
    }

    /** Build encoded public/CDN URLs; private objects still require authentication. */
    public function getPublicUrl(string|array $key): string|array
    {
        if (is_array($key)) {
            return array_map(function ($item): string {
                if (!is_string($item)) {
                    throw new InvalidArgumentException('Object keys must be strings.');
                }
                return $this->getPublicUrl($item);
            }, $key);
        }

        if ($key !== '') {
            $this->objectUrl($key);
        }

        return ($this->publicUrl ?? $this->baseUrl) . '/' . StoragePath::encode($key);
    }

    private function objectUrl(string $key): string
    {
        if (
            $key === '' || strlen($key) > 1024 || !preg_match('//u', $key)
            || preg_match('/[\x00-\x1f\x7f]/', $key)
        ) {
            throw new InvalidArgumentException('Object keys must contain 1–1024 bytes of valid UTF-8 without control characters.');
        }

        return $this->baseUrl . '/' . StoragePath::encode($key);
    }

    private function host(string $url): string
    {
        $port = parse_url($url, PHP_URL_PORT);
        return parse_url($url, PHP_URL_HOST) . ($port === null ? '' : ":$port");
    }

    /** Read the object into memory. */
    public function getFile(string $key): string
    {
        $this->objectUrl($key);
        $response = $this->request('GET', $key)->execute();
        $this->checkResponse($response, 200, 'Read file');

        return $response->body();
    }

    /** False only for HTTP 404; authentication/transport failures are errors. */
    public function exists(string $key): bool
    {
        $this->objectUrl($key);
        $response = $this->request('HEAD', $key)->execute();
        if ($response->status() === 404) {
            return false;
        }

        $this->checkResponse($response, 200, 'Inspect file');
        return true;
    }

    public function metadata(string $key): array
    {
        $this->objectUrl($key);
        $response = $this->request('HEAD', $key)->execute();
        $this->checkResponse($response, 200, 'Inspect file');
        $size = $response->header('content-length');
        $modified = strtotime((string) $response->header('last-modified', ''));

        if (!is_scalar($size) || !ctype_digit((string) $size) || $modified === false) {
            throw new RuntimeException('S3 metadata is missing size or modification time.');
        }

        return [
            'size' => (int) $size,
            'last_modified' => $modified,
            'mime_type' => $response->header('content-type', 'application/octet-stream')
        ];
    }

    /** Copy within the bucket without downloading; metadata is preserved. */
    public function copyFile(string $from, string $to, ?string $acl = null): bool
    {
        $this->objectUrl($from);
        $this->objectUrl($to);
        if ($acl !== null && !in_array($acl, ['private', 'public-read'], true)) {
            throw new InvalidArgumentException('This client supports null, private, or public-read ACLs.');
        }
        if ($from === $to)
            return $this->exists($from);
        if ($this->metadata($from)['size'] > self::MAX_UPLOAD_SIZE) {
            throw new RuntimeException('Single S3 copies are limited to 5 GiB; multipart copy is not implemented.');
        }
        $headers = [
            'x-amz-copy-source' => '/' . $this->bucket . '/' . StoragePath::encode($from),
            'x-amz-metadata-directive' => 'COPY'
        ];
        if ($acl !== null)
            $headers['x-amz-acl'] = $acl;
        $response = $this->request('PUT', $to, $headers)->withOptions([CURLOPT_POSTFIELDS => ''])->execute();
        $this->checkResponse($response, 200, 'Copy file');
        // S3 may send HTTP 200 with an Error XML body after starting a copy.
        $xml = $this->parseXml($response->body());
        if ($xml->getName() !== 'CopyObjectResult' || (string) $xml->ETag === '') {
            throw new RuntimeException('S3 copy did not complete successfully.');
        }
        return true;
    }

    /** Short-lived access to a private object; sign the origin, never a CDN URL. */
    public function temporaryUrl(string $key, int $expires = 300): string
    {
        if ($expires < 1 || $expires > 604800) {
            throw new InvalidArgumentException('Download expiry must be between 1 and 604800 seconds.');
        }

        $dateTime = gmdate('Ymd\THis\Z');
        $date = substr($dateTime, 0, 8);
        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$this->accessKey}/{$scope}",
            'X-Amz-Date' => $dateTime,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
            'response-content-disposition' => 'attachment; filename="' . basename($key) . '"',
        ];

        if ($this->sessionToken !== null) {
            $query['X-Amz-Security-Token'] = $this->sessionToken;
        }
        ksort($query);

        $url = $this->objectUrl($key);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $canonical = implode("\n", [
            'GET',
            parse_url($url, PHP_URL_PATH),
            $queryString,
            'host:' . $this->host($url) . "\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $signingKey = 'AWS4' . $this->secretKey;
        foreach ([$date, $this->region, 's3', 'aws4_request'] as $part) {
            $signingKey = hash_hmac('sha256', $part, $signingKey, true);
        }

        $signature = hash_hmac('sha256', "AWS4-HMAC-SHA256\n{$dateTime}\n{$scope}\n" . hash('sha256', $canonical), $signingKey);

        return "$url?$queryString&X-Amz-Signature=$signature";
    }

    /** One transfer path for single/batch uploads and deletions. */
    private function transfer(string $method, array $files, array $headers = []): array
    {
        $this->requireCurl();
        $results = [];
        foreach (array_chunk($files, self::CONCURRENCY, true) as $batch) {
            $streams = $requests = $sizes = [];

            try {
                foreach ($batch as $key => $path) {
                    $options = [];
                    $payloadHash = hash('sha256', '');
                    $fileHeaders = $headers;

                    if ($method === 'PUT') {
                        $stream = @fopen($path, 'rb');
                        if ($stream === false) {
                            throw new RuntimeException("Cannot open upload source for key: {$key}");
                        }

                        $streams[] = $stream;
                        $stat = fstat($stream);
                        if ($stat === false || $stat['size'] > self::MAX_UPLOAD_SIZE) {
                            throw new RuntimeException("Cannot upload {$key}: single PUTs are limited to 5 GiB.");
                        }

                        $sizes[$key] = $stat['size'];
                        $hash = hash_init('sha256');
                        if (hash_update_stream($hash, $stream) !== $stat['size'] || !rewind($stream)) {
                            throw new RuntimeException("Cannot read complete upload source for key: {$key}");
                        }

                        $payloadHash = hash_final($hash);
                        $fileHeaders['content-type'] = function_exists('mime_content_type')
                            ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
                        $fileHeaders['content-length'] = (string) $stat['size'];

                        // Spark exposes streaming through transport options; do not buffer the body.
                        $options = [
                            CURLOPT_UPLOAD => true,
                            CURLOPT_INFILE => $stream,
                            CURLOPT_INFILESIZE => $stat['size']
                        ];
                    }

                    $requests[] = $this->request($method, (string) $key, $fileHeaders, $payloadHash)
                        ->withOptions($options);
                }

                $responses = Http::pool(fn() => $requests);
                foreach ($responses as $key => $response) {
                    if ($method !== 'DELETE' || $response->status() !== 404) {
                        $this->checkResponse($response, $method === 'PUT' ? 200 : 204, "{$method} {$key}");
                    }

                    $results[$key] = $method === 'DELETE' ? true : [
                        'success' => true,
                        'key' => (string) $key,
                        'url' => $this->getPublicUrl((string) $key),
                        'size' => $sizes[$key],
                    ];
                }
            } finally {
                foreach ($streams as $stream) {
                    fclose($stream);
                }
            }
        }

        return $results;
    }

    /** Sign exactly the path, query, headers and payload sent by Spark. */
    private function request(string $method, string $key, array $headers = [], ?string $payloadHash = null, array $query = []): HttpRequest
    {
        $this->requireCurl();
        $url = $key === '' ? $this->baseUrl . '/' : $this->objectUrl($key);
        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $headers['host'] = $this->host($url);
        if ($this->sessionToken !== null) {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }

        $headers['x-amz-content-sha256'] = $payloadHash ?? hash('sha256', '');
        $headers['x-amz-date'] = gmdate('Ymd\THis\Z');

        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }

        $signedHeaders = implode(';', array_keys($headers));
        $canonical = implode("\n", [
            $method,
            parse_url($url, PHP_URL_PATH),
            $queryString,
            $canonicalHeaders,
            $signedHeaders,
            $headers['x-amz-content-sha256'],
        ]);

        $date = substr($headers['x-amz-date'], 0, 8);
        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $signingKey = 'AWS4' . $this->secretKey;

        foreach ([$date, $this->region, 's3', 'aws4_request'] as $part) {
            $signingKey = hash_hmac('sha256', $part, $signingKey, true);
        }

        $signature = hash_hmac('sha256', "AWS4-HMAC-SHA256\n{$headers['x-amz-date']}\n{$scope}\n" . hash('sha256', $canonical), $signingKey);
        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return HttpRequest::make($method, $url . ($queryString === '' ? '' : "?$queryString"), key: $key)
            ->withHeaders($headers)
            ->withTimeout($this->timeout)
            ->withRetry(0)
            ->withOptions([
                CURLOPT_ENCODING => 'identity',
                CURLOPT_HTTP_CONTENT_DECODING => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PATH_AS_IS => true,
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);
    }

    private function requireCurl(): void
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('S3 operations require the PHP cURL extension.');
        }
    }

    private function checkResponse(HttpResponseContract $response, int $expected, string $operation): void
    {
        if ($response->status() !== $expected) {
            $detail = $response->status() === 0 ? 'Transport failure.' : substr($response->body(), 0, 2048);
            throw new RuntimeException("S3 {$operation} failed (HTTP {$response->status()}): {$detail}");
        }
    }

    private function parseXml(string $body): SimpleXMLElement
    {
        if (!extension_loaded('simplexml')) {
            throw new RuntimeException('S3 listing and copy responses require the PHP SimpleXML extension.');
        }
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = stripos($body, '<!DOCTYPE') === false ? simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET) : false;
            if ($xml === false) {
                throw new RuntimeException('Invalid XML returned by S3.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
