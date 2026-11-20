@php
    $message ??= 'Unknown error';

    // How many source lines to render around each frame (third arg of Highlight::file).
    $contextLines = 14;

    $appRoot = function_exists('root_dir') ? root_dir() : (defined('SPARK_START') ? dirname(__DIR__, 4) : '');

    $relativize = function (?string $path) use ($appRoot): string {
        if (!$path) {
            return '[internal function]';
        }
        $normalized = str_replace(['\\', '/'], '/', $path);
        $rootNormalized = str_replace(['\\', '/'], '/', $appRoot);
        if ($rootNormalized && str_starts_with($normalized, $rootNormalized)) {
            return '@' . substr($normalized, strlen($rootNormalized));
        }
        return $path;
    };

    $isVendor = function (?string $path): bool {
        if (!$path) {
            return false;
        }
        return str_contains(str_replace('\\', '/', $path), '/vendor/');
    };

    // Recursively mask sensitive keys in request data
    $sensitiveKeys = ['password', 'secret', 'token', 'auth', 'key', 'api_key', 'access_token', 'private'];
    $mask = function ($data) use (&$mask, $sensitiveKeys) {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains(strtolower((string) $k), $sensitive)) {
                    $data[$k] = '********';
                    continue 2;
                }
            }
            if (is_array($v)) {
                $data[$k] = $mask($v);
            }
        }
        return $data;
    };

    // Headers that must never be printed on screen
    $sensitiveHeaders = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-csrf-token',
        'x-xsrf-token',
        'x-api-key',
    ];

    // Build the unified frame list (origin + trace)
    $rawFrames = [
        [
            'file' => $file,
            'line' => $line,
            'class' => '',
            'type' => '',
            'function' => '',
            'is_origin' => true,
        ],
    ];
    if (isset($trace) && is_array($trace)) {
        foreach ($trace as $frame) {
            $rawFrames[] = $frame;
        }
    }

    $allFrames = [];
    $appFrameCount = 0;
    foreach ($rawFrames as $f) {
        $path = $f['file'] ?? '';
        $vendor = $isVendor($path);
        if (!$vendor) {
            $appFrameCount++;
        }
        $origin = !empty($f['is_origin']);
        $allFrames[] = [
            'raw' => $path,
            'rel' => $relativize($path),
            'line' => $f['line'] ?? '',
            'vendor' => $vendor,
            'origin' => $origin,
            'func' => $origin ? $type : ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? 'unknown') . '()',
        ];
    }
    $frameCount = count($allFrames);
    $vendorCount = $frameCount - $appFrameCount;

    // Collapse vendor frames by default, unless the error itself was thrown from vendor code
    $hideVendor = $vendorCount > 0 && $appFrameCount > 0 && !$allFrames[0]['vendor'];

    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $httpHost = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $fullUrl =
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $httpHost . $requestUri;
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'PHP ' . PHP_VERSION . ' Development Server';
    $peakMemory = round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB';

    $maskedGet = $mask($_GET ?? []);
    $maskedPost = $mask($_POST ?? []);

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (empty($headers)) {
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $headers[$name] = $v;
            }
        }
    }

    // Plain-text payload for the "Copy" button
    $copyLines = ["$type: $message", "at $file:$line", '', 'Stack trace:'];
    foreach ($allFrames as $i => $fr) {
        $copyLines[] = "#$i {$fr['raw']}" . ($fr['line'] !== '' ? ":{$fr['line']}" : '') . " {$fr['func']}";
    }
    $copyPayload = implode("\n", $copyLines);

    // "Open in editor" link for the origin frame (VS Code URL scheme)
    $editorUrl = 'vscode://file/' . str_replace('\\', '/', (string) $file) . ':' . (int) $line;

    // Inline icons
    $svg = fn(string $inner, int $size = 14) => '<svg width="' .
        $size .
        '" height="' .
        $size .
        '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' .
        $inner .
        '</svg>';
    $copyIcon =
        '<rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>';
    $icons = [
        'copy' => $svg($copyIcon),
        'copy-sm' => $svg($copyIcon, 13),
        'search' => $svg('<circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>'),
        'code' => $svg(
            '<polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline>',
            13,
        ),
        'check' => $svg('<polyline points="20 6 9 17 4 12"></polyline>', 14),
    ];
@endphp

@extends('master')

@section('title', htmlspecialchars("$type: $message", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))

@section('head')
    <x-tracer.styles />
@stop

@section('content')
    <div class="tr">
        <x-tracer.bar :$peakMemory :$copyPayload :$type :$message :$icons />

        <x-tracer.error-summary :$type :$message :$file :$line :$requestMethod :$requestUri :$relativize :$icons />

        <div class="stack">
            <x-tracer.source-viewer :$allFrames :$file :$line :$editorUrl :$contextLines :$relativize :$icons />

            <x-tracer.call-stack :$allFrames :$frameCount :$vendorCount :$appFrameCount :$hideVendor />

            <x-tracer.diagnostics :$requestMethod :$fullUrl :$clientIp :$maskedGet :$maskedPost :$headers :$sensitiveHeaders
                :$serverSoftware :$peakMemory />
        </div>
    </div>

    <x-tracer.toast :$icons />

    <x-tracer.scripts :$hideVendor :$frameCount />
@stop
