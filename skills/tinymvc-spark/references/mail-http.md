# Mail and HTTP Client

Read this reference for outbound HTTP requests and email sending. Source paths and command working directories refer to the application root.

---

## HTTP Client: Modern Fluent Requests

TinyCore includes a modern HTTP client accessible through the **`Spark\Facades\Http`** facade (backed by `Spark\Http\Client\Http`). It mirrors modern Laravel ergonomics.

### 1. Basic Requests

```php
use Spark\Facades\Http;

// GET Request with query parameters
$response = Http::get('https://api.example.com/users', [
    'page' => 1,
    'per_page' => 25,
]);

// POST Request with JSON payload
$response = Http::post('https://api.example.com/posts', [
    'title' => 'Building with Spark',
    'published' => true,
]);

// PUT, PATCH, DELETE
$response = Http::put("https://api.example.com/posts/{$id}", ['title' => 'Updated']);
$response = Http::delete("https://api.example.com/posts/{$id}");
```

### 2. Request Configuration

```php
$response = Http::withToken($apiToken)
    ->withHeaders([
        'X-Custom-Header' => 'CustomValue',
    ])
    ->timeout(15) // seconds
    ->post('https://api.example.com/orders', [
        'item_id' => 101,
        'quantity' => 2,
    ]);
```

Supported request chain methods:
- `withToken(string $token)`: Adds Bearer authentication header.
- `withBasicAuth(string $username, string $password)`: Adds Basic authentication.
- `withHeaders(array $headers)`: Adds custom HTTP headers.
- `timeout(int $seconds)`: Sets connection and request timeout.
- `withContentType(string $type)`: Sets request `Content-Type`.
- `withAccept(string $type)`: Sets request `Accept` header.
- `withoutVerifying()`: Disables SSL certificate verification (use only in local testing).
- `attach(string $name, mixed $contents, ?string $filename = null)`: Multipart file attachment.

### 3. Inspecting the Response

`$response` implements `Spark\Http\Client\Contracts\HttpResponseContract`:

```php
// Status checks
if ($response->successful()) { // Status 2xx (alias: $response->ok())
    // Parse JSON directly or grab nested keys with dot notation
    $data = $response->json();
    $userId = $response->json('data.user.id', default: null);
} elseif ($response->failed()) { // Status 4xx or 5xx
    $statusCode = $response->status();
    $errorMessage = $response->json('message') ?? $response->body();
}
```

Response inspection methods:
- `$response->successful()` / `$response->ok()`: True for 2xx status codes.
- `$response->failed()` / `$response->isClientError()`: True for 4xx/5xx status codes.
- `$response->status()`: Returns HTTP status code integer (e.g. 200, 404).
- `$response->json(?string $key = null, mixed $default = null)`: Decodes JSON body; supports dot notation.
- `$response->body()` / `$response->text()`: Returns raw response body string.
- `$response->header(string $key)`: Returns specific response header.
- `$response->headers()`: Returns all response headers array.

### 4. Concurrent Requests (Pool)

```php
use Spark\Facades\Http;

$responses = Http::pool(fn ($pool) => [
    $pool->get('https://api.service-a.com/data'),
    $pool->get('https://api.service-b.com/data'),
]);
```

---

## Mail: Fluent Email Sending

Mail functionality is provided by the **`Spark\Facades\Mail`** facade (backed by `Spark\Utils\Mail`, extending PHPMailer).

### 1. Sending View-Based Emails

Render email templates defined in `resources/views/emails`:

```php
use Spark\Facades\Mail;

Mail::to($user->email, $user->name)
    ->subject('Welcome to TinyMVC!')
    ->view('emails.welcome', ['user' => $user])
    ->send();
```

### 2. Direct Body and Attachments

```php
Mail::to('client@example.com', 'Client Name')
    ->cc('billing@example.com')
    ->bcc('audit@example.com')
    ->subject('Your Monthly Invoice')
    ->view('emails.invoice', ['invoice' => $invoice])
    ->attachFile(storage_dir('invoices/inv-1001.pdf'), 'Invoice-1001.pdf')
    ->send();
```

Supported Mail chain methods:
- `to($address, $name = null)`
- `cc($address, $name = null)`
- `bcc($address, $name = null)`
- `reply($address, $name = null)`
- `subject(string $subject)`
- `view(string $template, array $context = [])`: Renders a Blade template as email body.
- `body(string $body, bool $isHtml = false)`: Sets raw string body.
- `attachFile(string $path, ?string $name = null)`: Attaches a local file.
- `send(): bool`: Sends the email; returns `true` on success, `false` on failure.

### 3. Smart Standard: Queued Mail in Jobs

Never send emails synchronously in web request controllers if the transport is SMTP, as it introduces latency. Wrap emails in a queued job using `JobInterface` and `Dispatchable`:

```php
<?php

namespace App\Jobs;

use App\Models\User;
use Spark\Facades\Mail;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;
use Throwable;

class SendAccountMail implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(private int $userId)
    {
    }

    public function handle(): void
    {
        $user = User::findOrFail($this->userId);

        Mail::to($user->email, $user->name)
            ->subject('Welcome to TinyMVC!')
            ->view('emails.welcome', ['user' => $user])
            ->send();
    }

    public function failed(JobContract $job, Throwable $exception): void
    {
        // Log final failure after retries exhausted
    }
}
```

In your controller or model event:

```php
SendAccountMail::dispatch($user->id)->onQueue('emails');
```
