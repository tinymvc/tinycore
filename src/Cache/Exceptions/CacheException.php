<?php

namespace Spark\Cache\Exceptions;

use Exception;
use Psr\SimpleCache\CacheException as PsrCacheException;

class CacheException extends Exception implements PsrCacheException
{
}
