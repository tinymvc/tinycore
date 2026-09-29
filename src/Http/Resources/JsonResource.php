<?php

namespace Spark\Http\Resources;

use Closure;
use JsonSerializable;
use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Support\Jsonable;
use Spark\Database\Model;
use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Utils\Paginator;
use function array_key_exists;
use function is_array;
use function is_object;

/** Transform an explicit set of attributes into a JSON response. */
class JsonResource implements Arrayable, Jsonable, JsonSerializable
{
    /** The outer key for the resource; null disables wrapping when no metadata exists. */
    protected ?string $wrap = 'data';

    /** Additional top-level response fields; ignored when this resource is nested. */
    protected array $additional = [];

    /** The resource being transformed. */
    public function __construct(public mixed $resource)
    {
    }

    /** Create a new resource instance. */
    public static function make(mixed $resource): static
    {
        return new static($resource);
    }

    /** Create a new resource collection instance. */
    public static function collection(iterable|Paginator $resource): ResourceCollection
    {
        return new ResourceCollection($resource, static::class);
    }

    /** Override this method to select the fields exposed by your API. */
    public function toArray(?Request $request = null): array
    {
        return match (true) {
            $this->resource === null => [],
            $this->resource instanceof \Spark\Support\Enumerable => $this->resource->all(),
            $this->resource instanceof Arrayable => $this->resource->toArray(),
            is_array($this->resource) => $this->resource,
            is_object($this->resource) => get_object_vars($this->resource),
            default => throw new \InvalidArgumentException('A resource must be an array, object, or null.'),
        };
    }

    /** Resolve nested resources and omit missing fields, without response wrapping. */
    public function resolve(?Request $request = null): array
    {
        $request = $this->resolveRequest($request);
        return self::normalize($this->toArray($request), $request);
    }

    /** Additional top-level response fields; ignored when this resource is nested. */
    public function with(?Request $request = null): array
    {
        return [];
    }

    public function additional(array $data): static
    {
        $this->additional = array_replace_recursive($this->additional, $data);
        return $this;
    }

    /** Set this instance's outer key; null disables wrapping when no metadata exists. */
    public function wrap(?string $key): static
    {
        $this->wrap = $key;
        return $this;
    }

    public function withoutWrapping(): static
    {
        return $this->wrap(null);
    }

    /** Build the HTTP document; nested resources use resolve() instead. */
    public function responseData(?Request $request = null): array
    {
        $request = $this->resolveRequest($request);
        $data = $this->resolve($request);
        $extra = $this->metadata($request);
        $wrap = $this->wrap ?? ($extra !== [] ? 'data' : null);

        // Metadata cannot overwrite the transformed payload.
        return ($wrap === null ? $data : [$wrap => $data]) + $extra;
    }

    public function response(int $status = 200, array $headers = []): Response
    {
        return new Response($this, $status, $headers);
    }

    public function toResponse(?Request $request = null): Response
    {
        return new Response($this->responseData($request));
    }

    public function when(bool|Closure $condition, mixed $value, mixed $default = MissingValue::Missing): mixed
    {
        return $this->evaluate($this->evaluate($condition) ? $value : $default);
    }

    public function unless(bool|Closure $condition, mixed $value, mixed $default = MissingValue::Missing): mixed
    {
        return $this->when(!$this->evaluate($condition), $value, $default);
    }

    public function whenNotNull(mixed $value, mixed $default = MissingValue::Missing): mixed
    {
        return $value !== null ? $value : $this->evaluate($default);
    }

    public function whenHas(string $attribute, mixed $value = MissingValue::Missing, mixed $default = MissingValue::Missing): mixed
    {
        $attributes = $this->resource instanceof Model ? $this->resource->getAttributes()
            : ($this->resource instanceof Arrayable ? $this->resource->toArray()
                : (is_object($this->resource) ? get_object_vars($this->resource) : (array) $this->resource));

        if (!array_key_exists($attribute, $attributes)) {
            return $this->evaluate($default);
        }

        $attributeValue = $this->__get($attribute);

        return $value === MissingValue::Missing ? $attributeValue : $this->evaluate($value, $attributeValue);
    }

    /** Only includes a relation already loaded on a model; never triggers a query. */
    public function whenLoaded(string $relation, mixed $value = MissingValue::Missing, mixed $default = MissingValue::Missing): mixed
    {
        if (!$this->resource instanceof Model || !$this->resource->relationLoaded($relation)) {
            return $this->evaluate($default);
        }

        $loaded = $this->resource->getRelation($relation);
        if ($loaded === null || $value === MissingValue::Missing) {
            return $loaded;
        }

        return $this->evaluate($value, $loaded);
    }

    /** Only includes a relation count already loaded on a model; never triggers a query. */
    public function whenCounted(string $relation): mixed
    {
        return $this->whenHas($relation . '_count');
    }

    public function __get(string $key): mixed
    {
        if (is_array($this->resource) || $this->resource instanceof \ArrayAccess) {
            return $this->resource[$key] ?? null;
        }

        if ($this->resource instanceof Arrayable) {
            return $this->resource->toArray()[$key] ?? null;
        }

        return $this->resource->$key ?? null;
    }

    public function __isset(string $key): bool
    {
        return $this->__get($key) !== null;
    }

    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->resolve(), $options | JSON_THROW_ON_ERROR);
    }

    protected function metadata(?Request $request): array
    {
        return self::normalize(array_replace_recursive($this->with($request), $this->additional), $request);
    }

    protected function resolveRequest(?Request $request): ?Request
    {
        return $request ?? (isset(\Spark\Foundation\Application::$app) ? request() : null);
    }

    private function evaluate(mixed $value, mixed ...$arguments): mixed
    {
        return $value instanceof Closure ? $value(...$arguments) : $value;
    }

    /** Normalize JSON values recursively, without adding resource response wrappers. */
    public static function normalize(mixed $value, ?Request $request = null): mixed
    {
        if ($value instanceof Closure) {
            return self::normalize($value(), $request);
        }

        if ($value instanceof self) {
            return $value->resolve($request);
        }

        if ($value instanceof \Spark\Carbon) {
            return $value->toIsoUtcString();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \Spark\Url) {
            return $value->getUrl();
        }

        if ($value instanceof \Spark\Support\Enumerable) {
            $value = $value->all();
        } elseif ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (!is_array($value)) {
            return $value;
        }

        $list = array_is_list($value);
        $result = [];
        foreach ($value as $key => $item) {
            $item = self::normalize($item, $request);
            if ($item !== MissingValue::Missing) {
                $result[$key] = $item;
            }
        }

        return $list ? array_values($result) : $result;
    }
}
