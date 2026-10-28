<?php

namespace Spark\Http\Validation\Concerns;

use Closure;
use Spark\Contracts\Http\RuleContract;
use Spark\Support\Arr;
use function array_keys;
use function array_map;
use function array_pad;
use function array_shift;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function str_contains;
use function str_split;
use function strtolower;
use function strtr;
use function trim;

trait ExpandsFields
{
    /**
     * Split field paths while preserving escaped literal dots.
     *
     * @param string $field
     * @return array<int, string>
     */
    protected function fieldSegments(string $field): array
    {
        $segments = [''];
        $index = 0;
        $escaped = false;

        foreach (str_split($field) as $character) {
            if ($character === '.' && !$escaped) {
                $segments[++$index] = '';
                continue;
            }

            $segments[$index] .= $character;
            $escaped = $character === '\\' && !$escaped;
        }

        return $segments;
    }

    /**
     * Escape special characters in a field segment.
     *
     * @param string|int $segment
     * @return string
     */
    protected function escapeFieldSegment(string|int $segment): string
    {
        return strtr((string) $segment, ['\\' => '\\\\', '.' => '\\.', '*' => '\\*']);
    }

    /**
     * Unescape special characters in a field segment.
     *
     * @param string $segment
     * @return string
     */
    protected function unescapeFieldSegment(string $segment): string
    {
        return strtr($segment, ['\\\\' => '\\', '\\.' => '.', '\\*' => '*']);
    }

    /**
     * Keep array containers as well as leaves, so array and presence rules still work.
     *
     * @param array $data
     * @param array $path
     * @return array
     */
    protected function flattenInputData(array $data, array $path = []): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $segments = [...$path, $this->escapeFieldSegment($key)];
            $result[implode('.', $segments)] = $value;
            if (is_array($value)) {
                $result += $this->flattenInputData($value, $segments);
            }
        }
        return $result;
    }

    /**
     * Resolve wildcard segments from existing arrays, retaining missing explicit children.
     *
     * @param array $segments
     * @param mixed $data
     * @param array $path
     * @param array $keys
     * @return array
     */
    protected function expandFieldPaths(array $segments, mixed $data, array $path = [], array $keys = []): array
    {
        if ($segments === []) {
            return [implode('.', $path) => $keys];
        }

        $segment = array_shift($segments);
        if ($segment === '*') {
            $result = [];
            foreach (is_array($data) ? $data : [] as $key => $value) {
                $key = $this->escapeFieldSegment($key);
                $result += $this->expandFieldPaths($segments, $value, [...$path, $key], [...$keys, $key]);
            }
            return $result;
        }

        $key = $this->unescapeFieldSegment($segment);
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return $this->expandFieldPaths($segments, $value, [...$path, $segment], $keys);
    }

    /**
     * Expand item rules and their sibling references without rewriting literal rule values.
     *
     * @param array $rules
     * @param array $inputData
     * @return array{0: array, 1: array}
     */
    protected function expandFieldRules(array $rules, array $inputData): array
    {
        $expanded = $wildcardFields = [];
        foreach ($rules as $field => $fieldRules) {
            $fieldRules = $this->normalizeFieldRules($fieldRules);
            $paths = $this->expandFieldPaths($this->fieldSegments((string) $field), $inputData);

            foreach ($paths as $path => $keys) {
                $resolvedRules = [];
                foreach ($fieldRules as $rule) {
                    $name = $rule['name'];
                    $parameters = $rule['parameters'];
                    if (is_string($name) && isset($parameters[0]) && is_scalar($parameters[0]) && $keys !== [] && in_array($name, ['required_if', 'required_unless', 'same', 'equal', 'same_as', 'gt', 'gte', 'lt', 'lte', 'ls'], true)) {
                        $segments = $this->fieldSegments(trim($parameters[0]));

                        $index = 0;
                        foreach ($segments as &$segment) {
                            if ($segment === '*') {
                                $segment = $keys[$index++] ?? '*';
                            }
                        }
                        unset($segment);

                        $parameters[0] = implode('.', $segments);
                        $rule['parameters'] = $parameters;
                    }

                    $resolvedRules[] = $rule;
                }

                $expanded[$path] = [...($expanded[$path] ?? []), ...$resolvedRules];
                if ($keys !== []) {
                    $wildcardFields[$path] ??= array_keys($paths);
                }
            }
        }

        return [$expanded, $wildcardFields];
    }

    /**
     * Rebuild the original array shape instead of returning flattened field names.
     *
     * @param array &$data
     * @param string $field
     * @param mixed $value
     * @return void
     */
    protected function setValidatedField(array &$data, string $field, mixed $value): void
    {
        if (!str_contains($field, '\\')) {
            Arr::set($data, $field, $value);
            return;
        }

        $target = &$data;
        foreach ($this->fieldSegments($field) as $segment) {
            $key = $this->unescapeFieldSegment($segment);
            if (!is_array($target)) {
                $target = [];
            }

            $target = &$target[$key];
        }

        $target = $value;
    }

    /**
     * Get the column name from a dot-notated field path.
     *
     * @param string $field
     * @return string
     */
    protected function fieldColumn(string $field): string
    {
        $segments = $this->fieldSegments($field);

        return $this->unescapeFieldSegment(end($segments));
    }

    /**
     * Parse string rules, associative parameter arrays, RuleContract instances,
     * and Closures into a common representation.
     *
     * @param mixed $fieldRules
     * @return array<int, array{name: string|RuleContract|Closure, parameters: array}>
     */
    protected function normalizeFieldRules(mixed $fieldRules): array
    {
        if (is_string($fieldRules)) {
            $fieldRules = explode('|', $fieldRules);
        }

        if ($fieldRules instanceof RuleContract || $fieldRules instanceof Closure) {
            $fieldRules = [$fieldRules];
        }

        if (!is_array($fieldRules)) {
            return [];
        }

        $rules = [];

        foreach ($fieldRules as $name => $rule) {
            if ($rule instanceof RuleContract || $rule instanceof Closure) {
                $rules[] = [
                    'name' => $rule,
                    'parameters' => [],
                ];
                continue;
            }

            if (is_string($name)) {
                $name = strtolower(trim($name));
                if ($name !== '') {
                    $rules[] = ['name' => $name, 'parameters' => is_array($rule) ? array_values($rule) : [$rule]];
                }
                continue;
            }

            if (is_array($rule)) {
                $rules = [...$rules, ...$this->normalizeFieldRules($rule)];
                continue;
            }

            if (!is_scalar($rule)) {
                continue;
            }

            $rule = trim((string) $rule);
            if ($rule !== '') {
                [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, null);
                $rules[] = [
                    'name' => strtolower(trim($name)),
                    'parameters' => $parameters === null ? [] : array_map('trim', explode(',', $parameters)),
                ];
            }
        }

        return $rules;
    }
}
