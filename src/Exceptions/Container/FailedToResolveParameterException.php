<?php

namespace Spark\Exceptions\Container;

use Exception;
use Psr\Container\ContainerExceptionInterface;

class FailedToResolveParameterException extends Exception implements ContainerExceptionInterface
{
}