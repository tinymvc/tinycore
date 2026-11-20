@props(['peakMemory', 'copyPayload', 'type', 'message', 'icons'])

<header class="tr-bar">
    <div class="tr-bar-l">
        <span class="tr-brand"><span class="tr-dot"></span>Spark</span>
        <span class="tr-meta">PHP {{ PHP_VERSION }}</span>
        <span class="tr-meta">{{ $peakMemory }}</span>
    </div>
    <div class="tr-bar-r">
        <button class="btn" type="button" data-copy="{{ $copyPayload }}" title="Copy error and stack trace">
            {!! $icons['copy'] !!}
        </button>
        <a class="btn btn-icon" href="https://www.google.com/search?q={{ urlencode('PHP ' . $type . ' ' . $message) }}"
            target="_blank" rel="noopener noreferrer" title="Search this error">
            {!! $icons['search'] !!}
        </a>
    </div>
</header>
