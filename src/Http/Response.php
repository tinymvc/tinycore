<?php

namespace Spark\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

use Spark\Contracts\Http\ResponseContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Foundation\Application;
use Spark\Http\Resources\JsonResource;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use Stringable;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Class Response
 * 
 * Manages HTTP response handling, including setting headers, content, status codes,
 * JSON responses, redirects, and output filtering.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Response implements ResponseContract
{
    use Macroable, Conditionable;

    private ResponseInterface $message;

    private bool $defaultContentType = false;

    private bool $head = false;

    /**
     * Constructor
     * 
     * Initializes a new response instance with the provided content, status code, and headers.
     * 
     * @param array|string|Arrayable|Stringable|StreamInterface $content The response content.
     *   This can be a string, an array, or an Arrayable object that will be converted to a string.
     *   If an array is provided, it will be converted to a JSON string.
     * @param int $statusCode The HTTP status code.
     * @param array $headers An associative array of headers to send with the response.
     */
    public function __construct(private mixed $content = '', int $statusCode = self::STATUS_OK, array $headers = [])
    {
        $factory = Application::$app?->get(ResponseFactoryInterface::class) ?? new Psr17Factory();
        $this->message = $factory->createResponse($statusCode);
        $this->withHeaders($headers);
    }

    /**
     * Creates a new response instance with the specified content, status code, and headers.
     *
     * @param mixed $content The response content.
     * @param int $statusCode The HTTP status code.
     * @param array $headers An associative array of headers to send with the response.
     * @return self A new instance of the Response class.
     */
    public static function make(mixed $content = '', int $statusCode = self::STATUS_OK, array $headers = []): self
    {
        return new self($content, $statusCode, $headers);
    }

    /**
     * Sets the response content to a specified string, replacing any existing content.
     *
     * @param array|string|Arrayable|Stringable|StreamInterface $content The content to set in the response body.
     * @return $this Current response instance for method chaining.
     */
    public function setContent(array|string|Arrayable|Stringable|StreamInterface $content): self
    {
        $this->clearDefaultContentType();
        $this->content = $content;
        return $this;
    }

    /**
     * Appends content to the existing response body.
     *
     * @param string $content The content to append to the response body.
     * @return $this Current response instance for method chaining.
     */
    public function write(string $content): self
    {
        if ($this->content instanceof StreamInterface) {
            $this->content = $this->getContent();
        } elseif (!is_string($this->content)) {
            $this->content = '';
        }

        $this->content .= $content;
        return $this;
    }

    /**
     * Sets headers to prevent caching of the response.
     *
     * This method sets the appropriate HTTP headers to instruct clients and proxies
     * not to cache the response. It is useful for dynamic content that should not be
     * stored in caches.
     *
     * @return $this Current response instance for method chaining.
     */
    public function noCache(): self
    {
        $this->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->setHeader('Pragma', 'no-cache');
        $this->setHeader('Expires', '0');
        return $this;
    }

    /**
     * Sets headers to enable caching of the response for a specified duration.
     *
     * This method sets the appropriate HTTP headers to instruct clients and proxies
     * to cache the response for a given number of seconds. It is useful for static
     * content that does not change frequently.
     *
     * @param int $seconds The duration in seconds for which the response should be cached. Default is 3600 seconds (1 hour).
     * @return $this Current response instance for method chaining.
     */
    public function cache(int $seconds = 3600): self
    {
        $this->setHeader('Cache-Control', "public, max-age=$seconds");
        $this->setHeader('Pragma', 'cache');
        $this->setHeader('Expires', gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
        return $this;
    }

    /**
     * Sets the response to have no content with a 204 No Content status code.
     *
     * This method sets the HTTP status code to 204 and clears any existing content
     * in the response body. It is typically used when the server successfully processes
     * a request but does not need to return any content.
     *
     * @return $this Current response instance for method chaining.
     */
    public function noContent(): self
    {
        $this->setStatusCode(self::STATUS_NO_CONTENT);
        $this->setContent('');
        return $this;
    }

    /**
     * Sends a file as a response to the client for download.
     *
     * @param string $filePath The path to the file to be sent.
     * @param array $headers Optional headers to include in the response.
     * @return void
     */
    public function file(string $filePath, array $headers = []): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            $this->setStatusCode(self::STATUS_NOT_FOUND)->setContent('File not found.')->send();
            return;
        }

        $factory = Application::$app?->get(StreamFactoryInterface::class) ?? new Psr17Factory();

        $this->setContent($factory->createStreamFromFile($filePath));
        $this->sendAttachment(basename($filePath), [
            'Content-Type' => @mime_content_type($filePath) ?: 'application/octet-stream',
            'Content-Length' => (string) filesize($filePath),
            ...$headers,
        ]);
    }

    /** Send raw content as a download through the normal response lifecycle. */
    public function download(string $content, string $filename, array $headers = []): void
    {
        $this->setContent($content);
        $this->sendAttachment($filename, ['Content-Length' => (string) strlen($content), ...$headers]);
    }

    private function sendAttachment(string $filename, array $headers): void
    {
        $this->withHeaders([
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode(basename($filename)),
            'Cache-Control' => 'must-revalidate',
            'Expires' => '0',
            ...$headers,
        ]);
        $this->send();
        exit;
    }

    /**
     * Sends a JSON response to the client.
     *
     * @param array|Arrayable $data The data to be encoded as JSON.
     * @param int $statusCode The HTTP status code to send with the response. Defaults to 200.
     * @param int $flags The JSON encoding flags. Defaults to 320.
     * @return $this Current response instance for method chaining.
     */
    public function json(array|Arrayable $data, int $statusCode = self::STATUS_OK, int $flags = 320, int $depth = 512): self
    {
        $content = json_encode(JsonResource::normalize($data), $flags | JSON_THROW_ON_ERROR, $depth);
        $this->setStatusCode($statusCode);
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        $this->setContent($content);
        return $this;
    }

    /**
     * Redirects the user to a specified URL and optionally terminates script execution.
     *
     * @param string $url The URL to redirect to.
     * @param int $httpCode Optional HTTP status code for the redirect (default is 302).
     * @return $this Current response instance for method chaining.
     */
    public function redirect(string $url, int $httpCode = self::STATUS_FOUND): self
    {
        $this->setHeader('Location', $url);
        $this->setStatusCode($httpCode); // Default to 302 if no code is provided
        return $this;
    }

    /**
     * Redirects the user to a named route with optional parameters and HTTP status code.
     *
     * @param string $routeName The name of the route to redirect to.
     * @param array $params Optional parameters to include in the route URL.
     * @param int $httpCode Optional HTTP status code for the redirect (default is 302).
     * @return $this Current response instance for method chaining.
     */
    public function routeRedirect(string $routeName, array $params = [], int $httpCode = self::STATUS_FOUND): self
    {
        $url = route_url($routeName, $params);
        return $this->redirect($url, $httpCode);
    }

    /**
     * Sets the HTTP status code for the response.
     *
     * @param int $statusCode The HTTP status code to set (e.g., 200, 404).
     * @return $this Current response instance for method chaining.
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->message = $this->message->withStatus($statusCode);
        return $this;
    }

    /**
     * Sets a header for the response.
     *
     * @param string $key The header name (e.g., 'Content-Type').
     * @param string|array $value The header value(s) (e.g., 'application/json').
     * @return $this Current response instance for method chaining.
     */
    public function setHeader(string $key, string|array $value): self
    {
        if (strcasecmp($key, 'Content-Type') === 0) {
            $this->defaultContentType = false;
        }
        $this->message = $this->message->withHeader($key, $value);
        return $this;
    }

    /**
     * Sets multiple headers for the response.
     *
     * @param array $headers An associative array of headers to set (e.g., ['Content-Type' => 'application/json']).
     * @return $this Current response instance for method chaining.
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $key => $value) {
            $this->setHeader($key, $value);
        }
        return $this;
    }

    /**
     * Sets the response content to the provided data, which can be an array, Arrayable, or Stringable.
     *
     * @param array|string|Arrayable|Stringable $data The data to set as the response content.
     * @return $this Current response instance for method chaining.
     */
    public function withData(array|string|Arrayable|Stringable $data): self
    {
        return $this->setContent($data);
    }

    /**
     * Sets the response content to a JSON-encoded version of the provided data.
     *
     * @param array|Arrayable $data The data to be encoded as JSON and set as the response content.
     * @param int $statusCode The HTTP status code to send with the response. Defaults to 200.
     * @param int $flags The JSON encoding flags. Defaults to 0.
     * @return $this Current response instance for method chaining.
     */
    public function withJson(array|Arrayable $data, int $statusCode = self::STATUS_OK, int $flags = 0, int $depth = 512): self
    {
        return $this->json($data, $statusCode, $flags, $depth);
    }

    /**
     * Flash a key-value pair to the session.
     *
     * This method stores a key-value pair in the session flash data,
     * which is available for the next request. The session flash data
     * is usually used to store temporary data like status messages.
     *
     * @param string $key The key to store in the session flash data.
     * @param mixed $value The value associated with the key.
     * @return $this Current response instance for method chaining.
     */
    public function with(string $key, mixed $value): self
    {
        session()->flash($key, $value);
        return $this;
    }

    /**
     * Flash the validation errors to the session.
     *
     * This method stores the validation errors in the session flash data,
     * which is available for the next request. The validation errors are
     * usually used to display error messages to the user.
     *
     * @param array $errors The validation errors to flash to the session.
     * @return $this Current response instance for method chaining.
     */
    public function withErrors(array $errors): self
    {
        session()->flash('errors', $errors);
        return $this;
    }

    /**
     * Flash the old input data to the session.
     *
     * This method stores the old input data in the session flash data,
     * which is available for the next request. The old input data is
     * usually used to repopulate form fields after a validation error.
     *
     * @param null|array|Arrayable $input The old input data to flash to the session.
     * @return $this Current response instance for method chaining.
     */
    public function withInput(null|array|Arrayable $input = null): self
    {
        if ($input === null) {
            $input = request()->getPostParams();
        }

        if ($input instanceof Arrayable) {
            $input = $input->toArray();
        }

        session()->flash('input', $input);
        return $this;
    }

    /**
     * Redirects the user back to the previous page.
     *
     * This method retrieves the "referer" header from the request and
     * redirects the user to the URL specified in the referer. It is
     * typically used to navigate back to the last page visited by the user.
     *
     * @return self
     */
    public function back(): self
    {
        $referer = request()->header('referer', '/'); // Get the referer URL
        return $this->redirect($referer);
    }

    /**
     * Clears the response content, status code, headers, and session flash data.
     *
     * This method is typically used to reset the response object to its initial state.
     * It clears the response content, status code, headers, and any session flash data.
     *
     * @return void
     */
    public function clear(): void
    {
        // Clear the response content and headers
        $factory = Application::$app?->get(ResponseFactoryInterface::class) ?? new Psr17Factory();

        $this->content = '';
        $this->message = $factory->createResponse();
        $this->head = false;
        $this->defaultContentType = false;

        // Clear session flash data
        session()->clearFlash();

        // Clear any output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * Sends the HTTP response to the client, including headers, status code, and content.
     * Applies any output filters to the content before outputting it.
     */
    public function send(): void
    {
        if (isset(Application::$app)) {
            if (Application::$app->isTesting()) {
                throw new \Spark\Testing\ResponseException($this);
            }
            if (Application::$app->isHandlingRequest()) {
                throw new \Spark\Exceptions\Http\ResponseException($this);
            }
            Application::$app->prepareResponse($this);
        } else {
            $this->head = strcasecmp($_SERVER['REQUEST_METHOD'] ?? Request::METHOD_GET, Request::METHOD_HEAD) === 0;
        }

        $message = $this->toPsrResponse();
        $version = $message->getProtocolVersion();
        if (in_array($version, ['1.0', '1.1'], true)) {
            header(sprintf('HTTP/%s %d %s', $version, $message->getStatusCode(), $message->getReasonPhrase()));
        } else {
            http_response_code($message->getStatusCode());
        }
        foreach ($message->getHeaders() as $key => $values) {
            foreach ($values as $index => $value) {
                header("$key: $value", $index === 0);
            }
        }

        if (!$this->head) {
            $body = $message->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '' && !$body->eof()) {
                    throw new \RuntimeException('Response stream did not produce data.');
                }
                echo $chunk;
            }
        }

        if ($message->hasHeader('Location') && $message->getStatusCode() >= 300 && $message->getStatusCode() < 400) {
            exit;
        }
    }

    /** Apply transport rules without reading or discarding a stream body. */
    public function prepareForRequest(Request $request): self
    {
        $this->head = $request->isMethod(Request::METHOD_HEAD);
        $this->prepare();
        return $this;
    }

    /**
     * Retrieves the response content as a string.
     *
     * This method prepares the response content and returns it as a string.
     * It does not send any headers or output to the client.
     *
     * @return string The response content as a string.
     */
    public function getContent(): string
    {
        $this->prepare();
        if ($this->head) {
            return '';
        }
        if (!$this->content instanceof StreamInterface) {
            return $this->content;
        }

        $body = $this->content;
        if (!$body->isSeekable()) {
            return $body->getContents();
        }
        $position = $body->tell();
        try {
            $body->rewind();
            return $body->getContents();
        } finally {
            $body->seek($position);
        }
    }

    /**
     * Retrieves the HTTP status code of the response.
     *
     * @return int The HTTP status code (e.g., 200, 404).
     */
    public function getStatusCode(): int
    {
        return $this->message->getStatusCode();
    }

    public function toPsrResponse(): ResponseInterface
    {
        $this->prepare();

        $body = $this->head ? '' : $this->content;
        if (!$body instanceof StreamInterface) {
            $factory = Application::$app?->get(StreamFactoryInterface::class) ?? new Psr17Factory();
            $body = $factory->createStream($body);
        }

        return $this->message->withBody($body);
    }

    public static function fromPsrResponse(ResponseInterface $response): static
    {
        $instance = new static($response->getBody());
        $instance->message = $response;
        return $instance;
    }

    /** Build an error response without emitting it or ending the middleware pipeline. */
    public static function error(string|int $error, ?string $message = null, ?int $code = null): static
    {
        $code ??= is_int($error) ? $error : self::STATUS_INTERNAL_SERVER_ERROR;

        if (Application::$app?->resolved(Request::class) && request()->expectsJson()) {
            return new static(['message' => $message ?? _e('Internal Server Error'), 'code' => $code], $code);
        }

        /** @var \Spark\View\Blade $view */
        $view = get(\Spark\View\Blade::class);

        $originalPath = $view->getPath();
        $errorTemplate = "errors/$error";

        // Set the view path to the errors folder
        if (!$view->templateExists("errors/$error")) {
            $view->setPath(dirname(__DIR__) . '/Foundation/resources/views');
        }

        // Set the error template
        if (!$view->templateExists($errorTemplate)) {
            $errorTemplate = 'errors/error';
        }

        // Render the error view
        try {
            $viewHtml = $view->render($errorTemplate, compact('code', 'message'));
        } catch (\Throwable) {
            $viewHtml = '<div id="error-page"><p id="error-code">' . $code . '</p><span id="error-separator"></span><p>' . _e($message ?? 'Error') . '</p></div>';
        } finally {
            $view->setPath($originalPath);
        }

        return new static($viewHtml, $code);
    }

    /** Normalize controller, middleware, and exception-handler results in one place. */
    public static function from(mixed $response): self
    {
        return match (true) {
            $response instanceof self => $response,
            $response instanceof ResponseInterface => static::fromPsrResponse($response),
            is_int($response) => new static(statusCode: $response),
            default => new static($response),
        };
    }

    /**
     * Retrieves the headers of the response.
     *
     * This method prepares the response and returns an associative array of headers.
     * If a redirect URL is set, it includes the "Location" header in the returned array.
     *
     * @return array An associative array of headers (e.g., ['Content-Type' => 'application/json']).
     */
    public function getHeaders(): array
    {
        $this->prepare();
        return array_map(
            fn(array $values) => count($values) === 1 ? $values[0] : $values,
            $this->message->getHeaders(),
        );
    }

    /**
     * Prepares the response for sending.
     *
     * This method ensures that the response is properly formatted before being sent.
     * It handles the conversion of content to a string and sets appropriate headers.
     *
     * @return void
     */
    private function prepare(): void
    {
        $statusCode = $this->getStatusCode();

        // These statuses have no message content. Do not invent a media type.
        if (
            ($statusCode >= 100 && $statusCode < 200)
            || in_array($statusCode, [
                self::STATUS_NO_CONTENT,
                self::STATUS_RESET_CONTENT,
                self::STATUS_NOT_MODIFIED,
            ], true)
        ) {
            $this->content = '';
            $this->clearDefaultContentType();
            if ($statusCode < 200 || $statusCode === self::STATUS_NO_CONTENT) {
                $this->removeHeader('Content-Length');
            } elseif ($statusCode === 205 && $this->hasHeader('Content-Length')) {
                $this->setHeader('Content-Length', '0');
            }
            return;
        }

        if ($this->content instanceof StreamInterface) {
            return;
        }

        if ($this->content instanceof JsonResource) {
            $this->content = $this->content->responseData();
        }

        if (is_array($this->content) || $this->content instanceof Arrayable) {
            $this->setContent(json_encode(
                JsonResource::normalize($this->content),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
            $this->setDefaultContentType('application/json; charset=utf-8');
        } else {
            if (!is_string($this->content)) {
                $this->content = (string) $this->content;
            }
            // Strings are HTML by framework convention; text/binary types are explicit.
            $this->setDefaultContentType('text/html; charset=utf-8');
        }
    }

    public function setDefaultContentType(string $value): void
    {
        if (!$this->hasHeader('Content-Type')) {
            $this->setHeader('Content-Type', $value);
            $this->defaultContentType = true;
        }
    }

    public function clearDefaultContentType(): void
    {
        if ($this->defaultContentType) {
            $this->removeHeader('Content-Type');
            $this->defaultContentType = false;
        }
    }

    public function hasHeader(string $name): bool
    {
        return $this->message->hasHeader($name);
    }

    public function removeHeader(string $name): void
    {
        $this->message = $this->message->withoutHeader($name);
    }

}
