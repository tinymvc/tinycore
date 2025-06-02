<?php

namespace Spark\Http;

use Spark\Contracts\Http\ResponseContract;
use Spark\Support\Traits\Macroable;

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

    private string $redirectUrl;

    /**
     * Constructor
     * 
     * Initializes a new response instance with the provided content, status code, and headers.
     * 
     * @param string $content The response content.
     * @param int $statusCode The HTTP status code.
     * @param array $headers An associative array of headers to send with the response.
     */
    public function __construct(private string $content = '', private int $statusCode = 200, private array $headers = [])
    {
    }

    /**
     * Sets the response content to a specified string, replacing any existing content.
     *
     * @param string $content The content to set in the response body.
     * @return $this Current response instance for method chaining.
     */
    public function setContent(string $content): self
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
        $this->content .= $content;
        return $this;
    }

    /**
     * Sends a JSON response to the client.
     *
     * @param array $data The data to be encoded as JSON.
     * @param int $statusCode The HTTP status code to send with the response. Defaults to 200.
     * @param int $flags The JSON encoding flags. Defaults to 0.
     * @return $this Current response instance for method chaining.
     */
    public function json(array $data, int $statusCode = 200, int $flags = 0, int $depth = 512): self
    {
        $this->setStatusCode($statusCode);
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        $this->setContent(
            json_encode($data, $flags, $depth)
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
     * Redirects the user back to the previous page.
     *
     * This method retrieves the "referer" header from the request and
     * redirects the user to the URL specified in the referer. It is
     * typically used to navigate back to the last page visited by the user.
     *
     * @return void
     */
    public function back(): void
    {
        $referer = request()->header('referer', '/'); // Get the referer URL
        $this->redirect($referer);
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

        // Set http response code and headers.
        http_response_code($this->statusCode);
        foreach ($this->headers as $key => $value) {
            header("$key: $value");
        }

        // send output to client.
        echo $this->content;
    }

    /**
     * Converts the response to a string by returning the response content.
     *
     * This method is automatically called when the response is used in a string context.
     * For example, when echo-ing the response or when using the response in a concatenation.
     *
     * @return string The response content as a string.
     */
    public function __toString(): string
    {
        return $this->content;
    }
}
