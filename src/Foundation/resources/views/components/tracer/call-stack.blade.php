@props(['allFrames', 'frameCount', 'vendorCount', 'appFrameCount', 'hideVendor'])

<section class="card">
    <div class="card-head">
        <div class="card-title">
            <span>Call stack</span>
            <span class="count">{{ $frameCount }}</span>
        </div>
        @if ($vendorCount > 0 && $appFrameCount > 0)
            <button class="btn" type="button" id="vendorToggle" onclick="toggleVendor()"
                title="Show or hide frames from /vendor">
                <span id="vendorToggleLabel">{{ $hideVendor ? 'Show' : 'Hide' }} vendor frames</span>
                <span class="count">{{ $vendorCount }}</span>
            </button>
        @endif
    </div>

    <div class="frames" id="framesList">
        @foreach ($allFrames as $idx => $fr)
            <button type="button"
                class="frame {{ $idx === 0 ? 'active' : '' }} {{ $fr['vendor'] ? 'vendor' : '' }} {{ $fr['vendor'] && $hideVendor ? 'hidden' : '' }}"
                id="frame-item-{{ $idx }}" onclick="selectFrame({{ $idx }})"
                data-file="{{ $fr['rel'] }}" data-raw-file="{{ $fr['raw'] }}" data-line="{{ $fr['line'] }}">
                <span class="frame-idx">{{ $idx }}</span>
                <span class="frame-func">{{ $fr['func'] }}</span>
                <span class="frame-path">
                    <bdi>{{ $fr['rel'] }}{{ $fr['line'] !== '' ? ':' . $fr['line'] : '' }}</bdi>
                </span>
            </button>
        @endforeach
    </div>
</section>
