@props(['allFrames', 'file', 'line', 'editorUrl', 'contextLines', 'relativize', 'icons'])

<section class="card viewer">
    <div class="card-head">
        <div class="viewer-loc">
            <span class="viewer-file" id="viewerFile">{{ $relativize($file) }}</span>
            <span class="viewer-line" id="viewerLine">:{{ $line }}</span>
        </div>
        <div class="head-actions">
            <a class="icon-btn" id="editorLink" href="{{ $editorUrl }}" title="Open in VS Code">
                {!! $icons['code'] !!}
            </a>
            <button class="icon-btn" type="button" id="copyPathBtn" onclick="copyCurrentPath()" title="Copy file path">
                {!! $icons['copy-sm'] !!}
            </button>
        </div>
    </div>

    <div class="code" id="codeBox">
        @foreach ($allFrames as $idx => $fr)
            @php
                $frameFile = $fr['raw'];
                $frameLine = (int) ($fr['line'] ?: 1);
            @endphp
            <div class="code-snippet-pane" id="code-snippet-{{ $idx }}"
                style="{{ $idx === 0 ? '' : 'display: none;' }}">
                @if ($frameFile && is_file($frameFile))
                    {!! \Spark\Utils\Highlight::file($frameFile, $frameLine, $contextLines) !!}
                @else
                    <div class="syntax-highlight-empty">
                        <p class="t">Source unavailable</p>
                        <p class="s">{{ $frameFile ?: 'Internal function or compiled PHP core' }}</p>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</section>
