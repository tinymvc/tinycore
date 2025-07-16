@extends('master')

@section('title', "$type: $message")

@section('content')
    @php
        if (!function_exists('clear_error_trace_file')) {
            function clear_error_trace_file($path): string
            {
                return '@' . ltrim(str_replace(dirname(root_dir()), '', $path), DIRECTORY_SEPARATOR);
            }
        }
    @endphp

    <div class="error-container">
        <div class="error-header">
            <div class="error-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path fill-rule="evenodd"
                        d="M8.478 1.6a.75.75 0 0 1 .273 1.026 3.72 3.72 0 0 0-.425 1.121c.058.058.118.114.18.168A4.491 4.491 0 0 1 12 2.25c1.413 0 2.673.651 3.497 1.668.06-.054.12-.11.178-.167a3.717 3.717 0 0 0-.426-1.125.75.75 0 1 1 1.298-.752 5.22 5.22 0 0 1 .671 2.046.75.75 0 0 1-.187.582c-.241.27-.505.52-.787.749a4.494 4.494 0 0 1 .216 2.1c-.106.792-.753 1.295-1.417 1.403-.182.03-.364.057-.547.081.152.227.273.476.359.742a23.122 23.122 0 0 0 3.832-.803 23.241 23.241 0 0 0-.345-2.634.75.75 0 0 1 1.474-.28c.21 1.115.348 2.256.404 3.418a.75.75 0 0 1-.516.75c-1.527.499-3.119.854-4.76 1.049-.074.38-.22.735-.423 1.05 2.066.209 4.058.672 5.943 1.358a.75.75 0 0 1 .492.75 24.665 24.665 0 0 1-1.189 6.25.75.75 0 0 1-1.425-.47 23.14 23.14 0 0 0 1.077-5.306c-.5-.169-1.009-.32-1.524-.455.068.234.104.484.104.746 0 3.956-2.521 7.5-6 7.5-3.478 0-6-3.544-6-7.5 0-.262.037-.511.104-.746-.514.135-1.022.286-1.522.455.154 1.838.52 3.616 1.077 5.307a.75.75 0 1 1-1.425.468 24.662 24.662 0 0 1-1.19-6.25.75.75 0 0 1 .493-.749 24.586 24.586 0 0 1 4.964-1.24h.01c.321-.046.644-.085.969-.118a2.983 2.983 0 0 1-.424-1.05 24.614 24.614 0 0 1-4.76-1.05.75.75 0 0 1-.516-.75c.057-1.16.194-2.302.405-3.417a.75.75 0 0 1 1.474.28c-.164.862-.28 1.74-.345 2.634 1.237.371 2.517.642 3.832.803.085-.266.207-.515.359-.742a18.698 18.698 0 0 1-.547-.08c-.664-.11-1.311-.612-1.417-1.404a4.535 4.535 0 0 1 .217-2.103 6.788 6.788 0 0 1-.788-.751.75.75 0 0 1-.187-.583 5.22 5.22 0 0 1 .67-2.04.75.75 0 0 1 1.026-.273Z"
                        clip-rule="evenodd" />
                </svg>
            </div>
            <div class="error-content">
                <h1 class="error-title">
                    <span class="error-type">{!! $type !!}</span>
                </h1>
                <p class="error-message">{!! $message !!}</p>
                <div class="error-location">
                    <div class="location-item">
                        <span class="location-label">File:</span>
                        <span class="location-value">{!! clear_error_trace_file($file) !!}</span>
                    </div>
                    <div class="location-item">
                        <span class="location-label">Line:</span>
                        <span class="location-value location-line">{!! $line !!}</span>
                    </div>
                </div>
            </div>
        </div>

        @if (isset($trace) && !empty($trace))
            <div class="trace-container">
                <div class="trace-header">
                    <h2>Stack Trace</h2>
                    <button class="trace-toggle" onclick="toggleTrace()" id="traceToggle">
                        <span>Hide</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                            xmlns="http://www.w3.org/2000/svg">
                            <polyline points="6,9 12,15 18,9" stroke="currentColor" stroke-width="2" />
                        </svg>
                    </button>
                </div>
                <div class="trace-content" id="traceContent">
                    <div class="trace-frames">
                        @foreach ($trace as $index => $frame)
                            @php
                                $file = $frame['file'] ?? '[internal function]';
                                $line = $frame['line'] ?? '';
                                $function = $frame['function'] ?? '';
                                $class = $frame['class'] ?? '';
                                $type = $frame['type'] ?? '';
                                $args = isset($frame['args'])
                                    ? implode(
                                        ', ',
                                        array_map(
                                            fn($arg) => is_object($arg) ? get_class($arg) : gettype($arg),
                                            $frame['args'],
                                        ),
                                    )
                                    : '';
                            @endphp
                            <div class="trace-frame">
                                <div class="frame-number">#{!! $index + 1 !!}</div>
                                <div class="frame-details">
                                    <div class="frame-location">
                                        <span class="frame-file">{!! clear_error_trace_file($file) !!}</span>
                                        @if ($line)
                                            <span class="frame-line">:{!! $line !!}</span>
                                        @endif
                                    </div>
                                    <div class="frame-function">
                                        @if ($class)
                                            <span class="frame-class">{!! $class !!}</span>
                                            <span class="frame-type">{!! $type !!}</span>
                                        @endif
                                        <span class="frame-method">{!! $function !!}</span>
                                        @if ($args)
                                            <span class="frame-args">({!! $args !!})</span>
                                        @else
                                            <span class="frame-args">()</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <div class="error-actions">
            <button class="action-btn primary" onclick="window.location.reload()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <polyline points="23,4 23,10 17,10" stroke="currentColor" stroke-width="2" />
                    <polyline points="1,20 1,14 7,14" stroke="currentColor" stroke-width="2" />
                    <path d="M20.49,9A9,9,0,0,0,5.64,5.64L1,10m22,4L18.36,18.36A9,9,0,0,1,3.51,15" stroke="currentColor"
                        stroke-width="2" />
                </svg>
                Retry
            </button>
            <button class="action-btn secondary" onclick="window.history.back()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <polyline points="15,18 9,12 15,6" stroke="currentColor" stroke-width="2" />
                </svg>
                Go Back
            </button>
        </div>
    </div>
@stop

@section('head')
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            color: #333;
        }

        .error-container {
            max-width: 1020px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .error-header {
            background: white;
            border-radius: 8px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #dc3545;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .error-icon {
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            color: #dc3545;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .error-content {
            flex: 1;
        }

        .error-title {
            margin: 0 0 10px 0;
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
        }

        .error-type {
            color: #dc3545;
        }

        .error-message {
            font-size: 18px;
            color: #555;
            margin: 0 0 20px 0;
            line-height: 1.6;
        }

        .error-location {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .location-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .location-label {
            font-weight: 600;
            color: #666;
            font-size: 14px;
        }

        .location-value {
            background: #f8f9fa;
            padding: 4px 12px;
            border-radius: 20px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 14px;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 700px;
        }

        .location-line {
            background: #dc3545;
            color: white;
            font-weight: 600;
        }

        .trace-container {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .trace-header {
            background: #495057;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .trace-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .trace-toggle {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .trace-toggle:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .trace-toggle svg {
            transition: transform 0.3s ease;
        }

        .trace-toggle.collapsed svg {
            transform: rotate(-90deg);
        }

        .trace-content {
            max-height: 460px;
            overflow-y: auto;
            transition: max-height 0.3s ease;
        }

        .trace-content.hidden {
            max-height: 0;
            overflow: hidden;
        }

        .trace-frame {
            display: flex;
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            transition: background 0.3s ease;
        }

        .trace-frame:hover {
            background: #f8f9fa;
        }

        .trace-frame:last-child {
            border-bottom: none;
        }

        .frame-number {
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            background: #6c757d;
            color: white;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
            margin-right: 15px;
        }

        .frame-details {
            flex: 1;
        }

        .frame-location {
            margin-bottom: 8px;
        }

        .frame-file {
            font-family: 'Consolas', 'Monaco', monospace;
            color: #666;
            font-size: 14px;
            word-break: break-all;
        }

        .frame-line {
            color: #dc3545;
            font-weight: 600;
            font-size: 14px;
        }

        .frame-function {
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 14px;
        }

        .frame-class {
            color: #2c3e50;
            font-weight: 600;
        }

        .frame-type {
            color: #666;
        }

        .frame-method {
            color: #8e44ad;
            font-weight: 600;
        }

        .frame-args {
            color: #27ae60;
        }

        .error-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .action-btn.primary {
            background: #007bff;
            color: white;
        }

        .action-btn.primary:hover {
            background: #0056b3;
        }

        .action-btn.secondary {
            background: white;
            color: #666;
            border: 2px solid #ddd;
        }

        .action-btn.secondary:hover {
            background: #f8f9fa;
            border-color: #ccc;
        }
    </style>

    <script>
        function toggleTrace() {
            const content = document.getElementById('traceContent');
            const button = document.getElementById('traceToggle');
            const span = button.querySelector('span');

            if (content.classList.contains('hidden')) {
                content.classList.remove('hidden');
                button.classList.remove('collapsed');
                span.textContent = 'Hide';
            } else {
                content.classList.add('hidden');
                button.classList.add('collapsed');
                span.textContent = 'Show';
            }
        }
    </script>
@stop
