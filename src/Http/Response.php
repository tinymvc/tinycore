<?php

namespace Spark\Http;

use Closure;
use Spark\Contracts\Http\ResponseContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Support\Traits\Macroable;
use Stringable;
use function is_array;
use function is_string;
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
    use Macroable;

    /**
     * This property holds the URL to which the user will be redirected.
     * It is set when the `redirect` method is called and used in the `send` method
     * to perform the actual redirection.
     * 
     * @var string The URL to redirect to.
     */
    private string $redirectUrl;

    /**
     * Constructor
     * 
     * Initializes a new response instance with the provided content, status code, and headers.
     * 
     * @param array|string|Arrayable|Stringable $content The response content.
     *   This can be a string, an array, or an Arrayable object that will be converted to a string.
     *   If an array is provided, it will be converted to a JSON string.
     * @param int $statusCode The HTTP status code.
     * @param array $headers An associative array of headers to send with the response.
     */
    public function __construct(private mixed $content = '', private int $statusCode = 200, private array $headers = [])
    {
    }

    /**
     * Sets the response content to a specified string, replacing any existing content.
     *
     * @param array|string|Arrayable|Stringable $content The content to set in the response body.
     * @return $this Current response instance for method chaining.
     */
    public function setContent(array|string|Arrayable|Stringable $content): self
    {
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
        if (!is_string($this->content)) {
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
        $this->setStatusCode(204);
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
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->setStatusCode(404);
            $this->setContent('File not found.');
            $this->send();
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);

        header('Content-Description: File Transfer');
        header("Content-Type: $mimeType");
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        foreach ($headers as $key => $value) {
            header("$key: $value");
        }

        readfile($filePath);
        exit;
    }

    /**
     * Sends raw content as a downloadable file to the client.
     *
     * @param string $content The content to be sent as a file.
     * @param string $filename The name of the file to be downloaded.
     * @param array $headers Optional headers to include in the response.
     * @return void
     */
    public function download(string $content, string $filename, array $headers = []): void
    {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . (string) strlen($content));

        foreach ($headers as $key => $value) {
            header("$key: $value");
        }

        echo $content;
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
    public function json(array|Arrayable $data, int $statusCode = 200, int $flags = 320, int $depth = 512): self
    {
        $this->setStatusCode($statusCode);
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        $this->setContent(
            json_encode($this->toPureArray($data), $flags, $depth)
        );
        return $this;
    }

    /**
     * Redirects the user to a specified URL and optionally terminates script execution.
     *
     * @param string $url The URL to redirect to.
     * @param int $httpCode Optional HTTP status code for the redirect (default is 0).
     * @return $this Current response instance for method chaining.
     */
    public function redirect(string $url, int $httpCode = 0): self
    {
        $this->redirectUrl = $url;
        $this->setStatusCode($httpCode); // Default to 302 if no code is provided
        return $this;
    }

    /**
     * Redirects the user to a named route with optional parameters and HTTP status code.
     *
     * @param string $routeName The name of the route to redirect to.
     * @param array $params Optional parameters to include in the route URL.
     * @param int $httpCode Optional HTTP status code for the redirect (default is 0).
     * @return $this Current response instance for method chaining.
     */
    public function routeRedirect(string $routeName, array $params = [], int $httpCode = 0): self
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
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Sets a header for the response.
     *
     * @param string $key The header name (e.g., 'Content-Type').
     * @param string $value The header value (e.g., 'application/json').
     * @return $this Current response instance for method chaining.
     */
    public function setHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;
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
        $this->content = $data;
        return $this;
    }

    /**
     * Sets the response content to a JSON-encoded version of the provided data.
     *
     * @param array|Arrayable $data The data to be encoded as JSON and set as the response content.
     * @param int $statusCode The HTTP status code to send with the response. Defaults to 200.
     * @param int $flags The JSON encoding flags. Defaults to 0.
     * @return $this Current response instance for method chaining.
     */
    public function withJson(array|Arrayable $data, int $statusCode = 200, int $flags = 0, int $depth = 512): self
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
        $this->content = '';
        $this->statusCode = 200;
        $this->headers = [];

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
        // If a redirect URL is set, perform the redirect.
        if (isset($this->redirectUrl)) {
            header("Location: {$this->redirectUrl}", true, $this->statusCode);
            exit; // Terminate script execution after redirect
        }

        // Convert content to string if it's an array, Arrayable, or Stringable.
        if (is_array($this->content) || $this->content instanceof Arrayable) {
            $this->setHeader('Content-Type', 'application/json; charset=utf-8');
            $this->setContent(
                json_encode($this->toPureArray($this->content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } elseif (!is_string($this->content)) {
            $this->setContent((string) $this->content); // Ensure content is a string
        }

        // Set http response code and headers.
        http_response_code($this->statusCode);
        foreach ($this->headers as $key => $value) {
            header("$key: $value");
        }

        echo $this->content; // send output to client.
    }

    /**
     * Recursively converts any Arrayable objects and nested arrays into pure arrays.
     *
     * @param  mixed  $data  An Arrayable, an array of mixed values, or any other value.
     * @return mixed         A pure array if input was Arrayable/array; otherwise the original value.
     */
    private function toPureArray(mixed $data): mixed
    {
        // If it's a Stringable or specific object, cast to string
        if (
            $data instanceof \Spark\Url ||
            $data instanceof \Spark\Utils\Carbon
        ) {
            return (string) $data;
        }

        if ($data instanceof Closure) {
            return $data(); // Call the closure and return its result
        }

        // If it's an object that knows how to cast itself to array, do it and recurse
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        // If it's an array, recurse into each element
        if (is_array($data)) {
            return array_map($this->toPureArray(...), $data);
        }

        // Otherwise return as-is (string/int/etc)
        return $data;
    }
}
