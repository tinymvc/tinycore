<?php

namespace Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Spark\Http\Request;
use Spark\Http\Response;

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class Psr7HttpTest extends \FrameworkTestCase
{
    public function test_psr17_factories_resolve_to_one_shared_implementation(): void
    {
        $factory = app(Psr17Factory::class);
        foreach ([
            RequestFactoryInterface::class,
            ResponseFactoryInterface::class,
            ServerRequestFactoryInterface::class,
            StreamFactoryInterface::class,
            UploadedFileFactoryInterface::class,
            UriFactoryInterface::class,
        ] as $interface) {
            $this->assertSame($factory, app($interface));
        }
        $this->assertSame('', (string) $factory->createUri());
        $request = $factory->createRequest('GET', 'https://example.test/path');
        $this->assertSame('example.test', $request->getHeaderLine('Host'));
        $this->assertSame('/path', $request->getRequestTarget());
        $this->assertThrows(\InvalidArgumentException::class, fn () => $request->withHeader('X-Test', "bad\r\nheader"));
    }

    public function test_request_export_preserves_snapshot_and_current_parameters(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/articles?q=first', 'HTTP_HOST' => 'example.test',
            'CONTENT_TYPE' => 'application/json', 'SERVER_PROTOCOL' => 'HTTP/1.0', 'REMOTE_ADDR' => '127.0.0.1'];
        $_GET = ['q' => 'first'];
        $_POST = ['title' => 'Hello'];
        $_COOKIE = [];
        $_FILES = [];
        $request = new Request();
        $request->setQueryParam('q', 'changed');
        $request->setRouteParam('id', '42');
        $_COOKIE = ['unrelated' => 'must not leak'];
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';

        $psr = $request->toPsrRequest();
        $this->assertSame('POST', $psr->getMethod());
        $this->assertSame('1.0', $psr->getProtocolVersion());
        $this->assertSame('application/json', $psr->getHeaderLine('Content-Type'));
        $this->assertSame('127.0.0.1', $psr->getServerParams()['REMOTE_ADDR']);
        $this->assertSame(['q' => 'changed'], $psr->getQueryParams());
        $this->assertSame(['title' => 'Hello'], $psr->getParsedBody());
        $this->assertSame([], $psr->getCookieParams());
        $this->assertSame('42', $psr->getAttribute('id'));
        $psr->withParsedBody(['title' => 'Other']);
        $this->assertSame('Hello', $request->post('title'));
    }

    public function test_request_export_includes_nested_uploads_and_upload_errors(): void
    {
        $file = $this->storagePath . '/upload.txt';
        file_put_contents($file, 'upload body');
        $_FILES = ['documents' => [
            'tmp_name' => ['group' => [$file, '']],
            'size' => ['group' => [11, 0]],
            'error' => ['group' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE]],
            'name' => ['group' => ['original.txt', '']],
            'type' => ['group' => ['text/plain', '']],
        ]];
        $uploads = (new Request())->toPsrRequest()->getUploadedFiles()['documents']['group'];
        $this->assertSame('original.txt', $uploads[0]->getClientFilename());
        $this->assertSame('upload body', (string) $uploads[0]->getStream());
        $this->assertSame(UPLOAD_ERR_NO_FILE, $uploads[1]->getError());
    }

    public function test_response_conversion_preserves_repeated_headers_protocol_and_reason(): void
    {
        $factory = new Psr17Factory();
        $psr = $factory->createResponse(201, 'Custom reason')
            ->withProtocolVersion('1.0')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Set-Cookie', ['first=1; Path=/', 'second=2; Path=/'])
            ->withBody($factory->createStream('{"ok":true}'));
        $native = Response::fromPsrResponse($psr);
        $roundtrip = $native->toPsrResponse();
        $this->assertSame(201, $roundtrip->getStatusCode());
        $this->assertSame('Custom reason', $roundtrip->getReasonPhrase());
        $this->assertSame('1.0', $roundtrip->getProtocolVersion());
        $this->assertSame($psr->getHeader('Set-Cookie'), $roundtrip->getHeader('Set-Cookie'));
        $this->assertSame('{"ok":true}', (string) $roundtrip->getBody());
        $this->assertSame('OK', $native->setStatusCode(200)->toPsrResponse()->getReasonPhrase());
    }

    public function test_response_export_prepares_resources_and_keeps_redirects(): void
    {
        $response = (new Response(['ok' => true], 202))->toPsrResponse();
        $this->assertSame('{"ok":true}', (string) $response->getBody());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $redirect = (new Response())->redirect('/next')->toPsrResponse();
        $this->assertSame('/next', $redirect->getHeaderLine('Location'));
        $this->assertSame('', (string) (new Response('discard', 204))->toPsrResponse()->getBody());
    }
}
