<?php

namespace Spark\Exceptions\Http;

use Spark\Http\Response;

/** @internal Return an early response to the application without emitting it. */
class ResponseException extends \RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('The request sent an early response.');
    }
}
