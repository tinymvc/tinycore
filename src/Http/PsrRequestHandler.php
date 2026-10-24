<?php

namespace Spark\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Adapt an application entry point or middleware continuation to PSR-15. */
final class PsrRequestHandler implements RequestHandlerInterface
{
    public function __construct(private Closure $handler)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return ($this->handler)($request);
        } catch (\Spark\Exceptions\Http\ResponseException $exception) {
            return $exception->response->toPsrResponse();
        }
    }
}
