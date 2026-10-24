<?php

namespace Spark\Exceptions\Container;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

class ClassDoesNotExistsException extends Exception implements NotFoundExceptionInterface
{
}