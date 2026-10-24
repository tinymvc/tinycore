<?php

namespace Spark\Cache\Exceptions;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

class CacheInvalidArgumentException extends InvalidArgumentException implements PsrInvalidArgumentException
{
}
