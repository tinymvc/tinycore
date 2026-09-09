<?php

namespace Spark\Testing;

use Spark\Http\Response;

/** @internal Stops an early response without exiting the test process. */
final class ResponseException extends \RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('The request sent an early response.');
    }
}
