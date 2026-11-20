@props([
    'requestMethod',
    'fullUrl',
    'clientIp',
    'maskedGet' => [],
    'maskedPost' => [],
    'headers' => [],
    'sensitiveHeaders' => [],
    'serverSoftware',
    'peakMemory',
])

<section class="card">
    <nav class="tabs" role="tablist">
        <button type="button" class="tab active" onclick="switchTab('tab-request', this)">Request</button>
        <button type="button" class="tab" onclick="switchTab('tab-headers', this)">Headers</button>
        <button type="button" class="tab" onclick="switchTab('tab-env', this)">Environment</button>
    </nav>

    <!-- Request -->
    <div class="pane active" id="tab-request">
        <table class="kv">
            <tr>
                <td class="k">URL</td>
                <td class="v"><span class="method">{{ $requestMethod }}</span>{{ $fullUrl }}</td>
            </tr>
            <tr>
                <td class="k">Client IP</td>
                <td class="v">{{ $clientIp }}</td>
            </tr>
            @if (!empty($maskedGet))
                <tr>
                    <td class="k">Query</td>
                    <td class="v">
                        <pre>{{ json_encode($maskedGet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </td>
                </tr>
            @endif
            @if (!empty($maskedPost))
                <tr>
                    <td class="k">Body</td>
                    <td class="v">
                        <pre>{{ json_encode($maskedPost, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </td>
                </tr>
            @endif
        </table>
    </div>

    <!-- Headers -->
    <div class="pane" id="tab-headers">
        <table class="kv">
            @forelse ($headers as $hName => $hVal)
                <tr>
                    <td class="k">{{ $hName }}</td>
                    <td class="v">
                        {{ in_array(strtolower((string) $hName), $sensitiveHeaders, true) ? '********' : (is_array($hVal) ? implode(', ', $hVal) : $hVal) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="2" class="v muted">No headers available.</td>
                </tr>
            @endforelse
        </table>
    </div>

    <!-- Environment -->
    <div class="pane" id="tab-env">
        <table class="kv">
            <tr>
                <td class="k">Framework</td>
                <td class="v">TinyMVC / Spark v{{ Spark\Foundation\Application::VERSION }}</td>
            </tr>
            <tr>
                <td class="k">PHP</td>
                <td class="v">{{ PHP_VERSION }} ({{ PHP_SAPI }})</td>
            </tr>
            <tr>
                <td class="k">OS</td>
                <td class="v">{{ PHP_OS_FAMILY }} ({{ PHP_OS }})</td>
            </tr>
            <tr>
                <td class="k">Server</td>
                <td class="v">{{ $serverSoftware }}</td>
            </tr>
            <tr>
                <td class="k">Peak memory</td>
                <td class="v">{{ $peakMemory }}</td>
            </tr>
            <tr>
                <td class="k">Time</td>
                <td class="v">{{ date('Y-m-d H:i:s T') }}</td>
            </tr>
        </table>
    </div>
</section>
