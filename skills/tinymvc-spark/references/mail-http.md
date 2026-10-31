# Mail and HTTP Client

> **Read when:** calling external APIs, executing prepared/pool requests, or sending mail.
> **Related:** [Queues](queues.md) · [Advanced workflows](advanced-workflows.md) (side effects) · [Realtime and Relay](realtime-relay.md) · [Telemetry](container-pipelines.md#runtime-telemetry)
> Outbound HTTP requires cURL; mail requires PHPMailer. Check the installed dependencies.

Contents:

- [Fluent HTTP requests](#fluent-http-requests)
- [Responses and concurrent requests](#responses-and-concurrent-requests)
- [Sending mail](#sending-mail)
- [Queued mail](#queued-mail)

## Fluent HTTP requests

`Spark\Facades\Http` creates clients backed by `Spark\Http\Client\Http`:

```php
use Spark\Facades\Http;

$response = Http::withToken($apiToken)
    ->timeout(15)
    ->withRetry(0)
    ->post('https://api.example.com/orders', ['item_id' => 101, 'quantity' => 2]);

if (!$response->successful()) {
    throw new RuntimeException('Order API failed with status ' . $response->status());
}
$id = $response->json('data.id');
```

- `get($url, $params)` sends query parameters; `post()`/`put()`/`patch()`/`delete()` take array or string bodies. Array bodies default to JSON unless configured otherwise.
- Configure `withHeaders()`, `withContentType()`, `withAccept()`, `withToken()`, `withBasicAuth()`, or `withOptions()`. `timeout()` / `withTimeout()` sets the overall cURL timeout in seconds; configure connection timeout separately through options if needed.
- `withRetry($times, $delayMs = 200)` counts retries after the initial attempt (default two). Retries concern transport failures, not HTTP 4xx/5xx. After exhaustion, single requests return status **0**. Retrying writes can duplicate effects after a lost response; use idempotency keys or disable retries.
- `attach($name, $contents, $filename)` supplies multipart files. `download($path, force: true)` atomically replaces a file after transport success; transport failure preserves the old file. Without force, existing targets are never replaced. Download configuration applies to the next send only.
- Keep TLS verification enabled. Treat destination URLs and redirects as part of the application's trust boundary.

## Responses and concurrent requests

| Inspection | Contract |
| --- | --- |
| `successful()` / `ok()` | Any 2xx |
| `failed()` | Any non-2xx, including status 0 and redirects |
| `isClientError()` / `isServerError()` | 4xx / 5xx respectively |
| `status()` | Integer HTTP status, or 0 after single-request transport failure |
| `json($key = null, $default = null)` | Decoded JSON with optional dot lookup |
| `body()` / `text()` | Raw response body |
| `header($name)` / `headers()` | Parsed response headers |

```php
$responses = Http::pool(fn($pool) => [
    $pool->as('users')->get('https://api.example.com/users'),
    $pool->as('posts')->get('https://api.example.com/posts'),
]);
$users = $responses['users']->json();
```

`HttpRequest::make($method, $url, $params, $data)->execute()` runs a prepared request. `HttpPool` uses curl_multi and preserves request keys; duplicate keys are rejected. Pools throw on transport failure and do not apply the single-request retry loop. Prepared requests and pools block until completion; they are not promises or background jobs.

`app:http.request` covers fluent sends, downloads, direct `execute()`, and each completed pool transfer, including failed transfers. Single requests emit once after retries. Its positional payload is `(url, payload, response, duration_ms, error)`; status 0 denotes transport failure, and `error` is present for thrown failures. Payload describes configured request fields, not a wire capture. Filter sensitive URLs, headers, bodies, and responses before exporting telemetry.

## Sending mail

Use `Spark\Facades\Mail` (`Spark\Utils\Mail`, extending PHPMailer):

```php
use Spark\Facades\Mail;

$sent = Mail::to($user->email, $user->name)
    ->subject('Welcome')
    ->view('emails.welcome', ['user' => $user])
    ->send();

if (!$sent) {
    throw new RuntimeException('Welcome email could not be sent.');
}
```

Supported chains include `cc()`, `bcc()`, `reply()`, `body($text, isHtml: false)`, and `attachFile($path, $name)`. Templates live under `resources/views/`. `send()` returns bool; exceptions are logged and converted to false. Telemetry emits `mail.sent` or `mail.failed`.

## Queued mail

Use a [class job](queues.md#class-jobs-and-dispatch) for mail that need not delay the response. Pass a user/record ID, load fresh state in `handle()`, and **throw when `send()` returns false** so worker retries run. Configure `$tries` and `$backoff`; report exhausted attempts in `failed()`.

```php
SendAccountMail::dispatch($user->id)->onQueue('emails');
```

Dispatch after the required database state commits. Mail delivery can succeed even when acknowledgement is lost; retries need an application-level delivery/deduplication policy. Verify response parsing, status 0, HTTP errors, timeouts/retries, download preservation, and mail success/failure paths.
