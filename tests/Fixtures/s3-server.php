<?php
// Local-only fixture: synthetic credentials and responses, never a provider bucket.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'DELETE') {
    file_put_contents(getenv('SPARK_S3_LOG'), $path . "\n", FILE_APPEND);
    http_response_code(204);
    return;
}
if ($method === 'HEAD') {
    header('Content-Length: 7');
    header('Last-Modified: Mon, 28 Sep 2026 00:00:00 GMT');
    return;
}
if ($method === 'PUT') {
    header('Content-Type: application/xml');
    echo str_ends_with($path, '/copy-error') ? '<Error><Code>InternalError</Code></Error>' : '<CopyObjectResult><ETag>abc</ETag></CopyObjectResult>';
    return;
}
if (isset($_GET['list-type'])) {
    $second = isset($_GET['continuation-token']);
    $key = $second ? 'reports/b.txt' : 'reports/a.txt';
    header('Content-Type: application/xml');
    echo '<ListBucketResult><EncodingType>url</EncodingType><IsTruncated>' . ($second ? 'false' : 'true') . '</IsTruncated>';
    echo '<Contents><Key>' . rawurlencode($key) . '</Key><Size>7</Size><LastModified>2026-09-28</LastModified><ETag>abc</ETag></Contents>';
    if (!$second) echo '<NextContinuationToken>opaque+/%=</NextContinuationToken>';
    echo '</ListBucketResult>';
    return;
}
if (str_ends_with($path, '/missing')) {
    http_response_code(404);
    echo '<Error>Missing</Error>';
} elseif (str_ends_with($path, '/truncated')) {
    header('Content-Length: 1000');
    echo 'partial';
} else {
    header('Content-Length: 7');
    echo "a\0b\n123";
}
