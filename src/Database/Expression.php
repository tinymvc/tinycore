<?php

namespace Spark\Database;

/** Trusted SQL embedded verbatim in a query; constructing it never executes SQL. */
final class Expression implements \Stringable
{
    public function __construct(private readonly string $value)
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
