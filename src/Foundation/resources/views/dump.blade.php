@php
    $args ??= [];
    $file ??= '';
    $rawFile ??= '';
    $line ??= 0;
    $isDd ??= false;
    $dumpId ??= 'dump-' . substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
    $editorUrl = $rawFile ? 'vscode://file/' . str_replace('\\', '/', (string) $rawFile) . ':' . (int) $line : '';
    $fileName = basename(str_replace('\\', '/', $file));
    $fileDir = substr($file, 0, strlen($file) - strlen($fileName));
@endphp

@if ($isDd && !headers_sent())
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dump and Die - {{ $file }}:{{ $line }}</title>
        <style>
            html {
                color-scheme: dark;
                scrollbar-color: #2e2e33 #0a0a0a;
                background: #0a0a0a;
            }

            body {
                margin: 0;
                padding: 0;
                width: 100%;
                min-height: 100vh;
                box-sizing: border-box;
                background: #0a0a0a;
                color: #d4d4d8;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                -webkit-font-smoothing: antialiased;
            }

            .spark-dump-wrapper {
                width: 100%;
                margin: 0;
                padding: 0;
                min-height: 100vh;
            }

            ::-webkit-scrollbar {
                width: 10px;
                height: 10px;
            }

            ::-webkit-scrollbar-track {
                background: #0a0a0a;
            }

            ::-webkit-scrollbar-thumb {
                background: #27272a;
                border-radius: 5px;
                border: 2px solid #0a0a0a;
            }

            ::-webkit-scrollbar-thumb:hover {
                background: #3f3f46;
            }
        </style>
    </head>

    <body>
        <div class="spark-dump-wrapper">
@endif

<div class="spark-dump {{ $isDd ? 'is-dd' : '' }}" id="{{ $dumpId }}" data-file="{{ $file }}">
    <div class="spark-dump-header">
        <div class="spark-dump-meta-left">
            <span class="spark-dump-badge {{ $isDd ? 'dd' : '' }}">{{ $isDd ? 'dd' : 'dump' }}</span>
            @if ($file)
                <span class="spark-dump-loc" title="{{ $rawFile ?: $file }}" data-file="{{ $file }}"
                    data-location="{{ $file }}:{{ $line }}"><span
                        class="dir">{{ $fileDir }}</span><span class="name">{{ $fileName }}</span><span
                        class="ln">:{{ $line }}</span></span>
                @if ($editorUrl)
                    <a href="{{ $editorUrl }}" class="spark-dump-btn" title="Open in VS Code">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="16 18 22 12 16 6"></polyline>
                            <polyline points="8 6 2 12 8 18"></polyline>
                        </svg>
                    </a>
                @endif
            @endif
        </div>
        <div class="spark-dump-meta-right">
            <span class="spark-dump-time">{{ date('H:i:s') }}</span>
            <button type="button" class="spark-dump-btn"
                onclick="SparkDump.toggleAll('{{ $dumpId }}', this)">Collapse</button>
            <button type="button" class="spark-dump-btn"
                onclick="SparkDump.copy('{{ $dumpId }}', this)">Copy</button>
        </div>
    </div>
    <div class="spark-dump-body">
        @foreach ($args as $arg)
            <div class="spark-dump-entry">
                {!! \Spark\Utils\Highlight::valueToHtml($arg) !!}
            </div>
        @endforeach
    </div>
</div>

<style>
    .spark-dump {
        --sd-bg: #0a0a0a;
        --sd-bar: #0f0f10;
        --sd-line: #1f1f22;
        --sd-text: #d4d4d8;
        --sd-muted: #71717a;
        --sd-dim: #52525b;
        --sd-danger: #f87171;

        position: relative;
        z-index: 99999;
        width: 100%;
        margin: 0;
        background: var(--sd-bg);
        border: none;
        border-bottom: 1px solid var(--sd-line);
        border-radius: 0;
        color: var(--sd-text);
        font: 12px/1.6 ui-monospace, "Geist Mono", "JetBrains Mono", "SF Mono", Menlo, Consolas, monospace;
        font-variant-ligatures: none;
        text-align: left;
        color-scheme: dark;
        box-sizing: border-box;
    }

    .spark-dump *,
    .spark-dump *::before,
    .spark-dump *::after {
        box-sizing: border-box;
    }

    .spark-dump.is-dd {
        min-height: 100vh;
        border-bottom: none;
    }

    /* Header */
    .spark-dump-header {
        position: sticky;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        width: 100%;
        padding: 8px 16px;
        background: var(--sd-bar);
        border-bottom: 1px solid var(--sd-line);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
        font-size: 11.5px;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
    }

    .spark-dump-meta-left,
    .spark-dump-meta-right {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .spark-dump-badge {
        padding: 2px 7px;
        border-radius: 4px;
        background: rgba(56, 189, 248, 0.12);
        color: #7dd3fc;
        border: 1px solid rgba(56, 189, 248, 0.22);
        font: 600 10.5px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .spark-dump-badge.dd {
        background: rgba(248, 113, 113, 0.15);
        color: #fca5a5;
        border-color: rgba(248, 113, 113, 0.28);
    }

    .spark-dump-loc {
        min-width: 0;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .spark-dump-loc .dir {
        color: var(--sd-dim);
    }

    .spark-dump-loc .name {
        color: #a1a1aa;
    }

    .spark-dump-loc .ln {
        color: var(--sd-danger);
    }

    .spark-dump-time {
        margin-right: 2px;
        color: var(--sd-dim);
        font-variant-numeric: tabular-nums;
    }

    .spark-dump-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 22px;
        padding: 0 8px;
        background: transparent;
        border: 1px solid var(--sd-line);
        border-radius: 5px;
        color: var(--sd-muted);
        font: inherit;
        font-size: 11px;
        text-decoration: none;
        cursor: pointer;
        transition: color 0.12s, background 0.12s, border-color 0.12s;
    }

    .spark-dump-btn:hover {
        background: rgba(255, 255, 255, 0.05);
        border-color: #3f3f46;
        color: #fafafa;
    }

    /* Body */
    .spark-dump-body {
        padding: 14px 16px 40px;
        overflow-x: auto;
        scrollbar-width: thin;
        scrollbar-color: #2a2a2e transparent;
    }

    .spark-dump-entry {
        padding-left: 13px;
    }

    .spark-dump-entry+.spark-dump-entry {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px dashed var(--sd-line);
    }

    /* Tree */
    .sf-item {
        padding-left: 13px;
        overflow-wrap: anywhere;
    }

    .sf-summary {
        display: block;
        margin-left: -13px;
        border-radius: 3px;
        list-style: none;
        outline: none;
        cursor: pointer;
        user-select: none;
    }

    .sf-summary::-webkit-details-marker {
        display: none;
    }

    .sf-summary::before {
        content: "";
        display: inline-block;
        width: 5px;
        height: 5px;
        margin: 0 5px 2px 3px;
        border-right: 1.5px solid var(--sd-dim);
        border-bottom: 1.5px solid var(--sd-dim);
        transform: rotate(-45deg);
        transition: transform 0.1s;
    }

    .sf-details[open]>.sf-summary::before {
        transform: rotate(45deg);
    }

    .sf-details:not([open])>.sf-summary::after {
        content: "…";
        margin-left: 4px;
        color: var(--sd-dim);
    }

    .sf-summary:hover {
        background: rgba(255, 255, 255, 0.035);
    }

    .sf-summary:hover::before {
        border-color: var(--sd-text);
    }

    .sf-summary:focus-visible {
        outline: 1px solid #3f3f46;
    }

    .sf-children {
        margin-left: -9px;
        padding-left: 9px;
        border-left: 1px solid rgba(255, 255, 255, 0.06);
    }

    .sf-pre {
        margin: 0;
        padding: 4px 0;
        color: #86efac;
        font: inherit;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    /* Tokens */
    .sf-type {
        color: var(--sd-muted);
    }

    .sf-class {
        color: #c4b5fd;
        font-weight: 500;
    }

    .sf-prop {
        color: #7dd3fc;
    }

    .sf-key {
        color: #e4e4e7;
    }

    .sf-vis {
        margin-right: 4px;
        color: var(--sd-dim);
        cursor: default;
    }

    .sf-arrow {
        margin-right: 0.6ch;
        color: var(--sd-dim);
    }

    .sf-key+.sf-arrow {
        margin-left: 0.6ch;
    }

    .sf-str {
        color: #86efac;
    }

    .sf-num {
        color: #fcd34d;
    }

    .sf-bool {
        color: #f9a8d4;
    }

    .sf-null {
        color: #fb7185;
    }

    .sf-meta {
        color: var(--sd-dim);
        font-size: 11px;
    }

    .sf-punct {
        color: var(--sd-dim);
    }
</style>

<script>
    window.SparkDump = window.SparkDump || {
        toggleAll: function(dumpId, btn) {
            const dump = document.getElementById(dumpId);
            if (!dump) return;

            const shouldOpen = btn.textContent.trim() === 'Expand';
            dump.querySelectorAll('details').forEach(function(d) {
                d.open = shouldOpen;
            });
            btn.textContent = shouldOpen ? 'Collapse' : 'Expand';
        },

        copy: function(dumpId, btn) {
            const dump = document.getElementById(dumpId);
            const body = dump && dump.querySelector('.spark-dump-body');
            if (!body || !navigator.clipboard) return;

            navigator.clipboard.writeText(body.innerText || body.textContent).then(function() {
                const label = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(function() {
                    btn.textContent = label;
                }, 1200);
            });
        }
    };
</script>

@if ($isDd && !headers_sent())
    </div>
    </body>

    </html>
@endif
