<?php

namespace Spark\Testing;

/** @internal Stops an early response without exiting the test process. */
final class ResponseException extends \Spark\Exceptions\Http\ResponseException
{
}
