<?php

namespace Spark\Exceptions\Container;

use Exception;
use Psr\Container\ContainerExceptionInterface;

class MethodDoesNotExistsException extends Exception implements ContainerExceptionInterface
{
}