<?php

namespace Spark\Exceptions\Container;

use Exception;
use Psr\Container\ContainerExceptionInterface;

class InvalidAliasException extends Exception implements ContainerExceptionInterface
{
}