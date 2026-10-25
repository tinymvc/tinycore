<?php

namespace Spark\Paginator;

use InvalidArgumentException;
use Spark\Contracts\Support\Arrayable;
use function is_array;
use function is_bool;
use function is_float;
use function is_scalar;
use function is_string;

/** The ordered values and direction of a cursor pagination boundary. */
class Cursor implements Arrayable, \Stringable
{
    public function __construct(private array $parameters, private bool $pointsToNextItems = true)
    {
        foreach ($parameters as $key => $value) {
            if (
                !is_string($key) || $key === '_pointsToNextItems' || !is_scalar($value)
                || (is_float($value) && !is_finite($value))
            ) {
                throw new InvalidArgumentException('Cursor parameters must be named, non-null scalar values.');
            }
        }
    }

    public function parameter(string $name): mixed
    {
        return $this->parameters[$name] ?? throw new InvalidArgumentException("Missing cursor parameter [$name].");
    }

    public function pointsToNextItems(): bool
    {
        return $this->pointsToNextItems;
    }

    public function pointsToPreviousItems(): bool
    {
        return !$this->pointsToNextItems;
    }

    public function toArray(): array
    {
        return [...$this->parameters, '_pointsToNextItems' => $this->pointsToNextItems];
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(json_encode($this->toArray(), JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** Invalid URL cursors resolve to the first page. */
    public static function fromEncoded(?string $encoded): ?self
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $parameters = $decoded === false ? null : json_decode($decoded, true);

        if (
            !is_array($parameters) || !isset($parameters['_pointsToNextItems'])
            || !is_bool($parameters['_pointsToNextItems'])
        ) {
            return null;
        }

        $direction = $parameters['_pointsToNextItems'];
        unset($parameters['_pointsToNextItems']);

        try {
            return $parameters === [] ? null : new self($parameters, $direction);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function __toString(): string
    {
        return $this->encode();
    }
}
