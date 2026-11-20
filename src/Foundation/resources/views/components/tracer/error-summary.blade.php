@props(['type', 'message', 'file', 'line', 'requestMethod', 'requestUri', 'relativize', 'icons'])

<section class="tr-err">
    <div class="tr-err-head">
        <span class="tag">{{ $type }}</span>
        <span class="tr-req"><b>{{ $requestMethod }}</b><span>{{ $requestUri }}</span></span>
    </div>
    <h1 class="tr-msg">{{ $message }}</h1>
    <div class="tr-loc">
        <span class="path">{{ $relativize($file) }}<span class="ln">:{{ $line }}</span></span>
        <button class="icon-btn" type="button" data-copy="{{ $file }}" title="Copy absolute file path">
            {!! $icons['copy-sm'] !!}
        </button>
    </div>
</section>
