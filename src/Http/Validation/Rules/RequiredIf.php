<?php

namespace Spark\Http\Validation\Rules;

use Closure;
use Psr\Http\Message\UploadedFileInterface;
use Spark\Contracts\Http\RuleContract;
use function count;
use function is_array;
use function is_callable;
use function is_countable;
use function is_string;
use function trim;

class RequiredIf implements RuleContract
{
    /** @var bool|Closure */
    protected bool|Closure $condition;

    /**
     * Create a new required_if rule instance.
     *
     * @param bool|callable $condition
     */
    public function __construct(bool|callable $condition)
    {
        $this->condition = is_callable($condition) ? Closure::fromCallable($condition) : (bool) $condition;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     * @param mixed $value
     * @return bool
     */
    public function passes(string $attribute, mixed $value): bool
    {
        $isRequired = $this->condition instanceof Closure ? ($this->condition)() : $this->condition;

        if (!$isRequired) {
            return true;
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return false;
        }

        if (is_countable($value) && count($value) === 0) {
            return false;
        }

        if ($value instanceof UploadedFileInterface) {
            return $value->getError() !== UPLOAD_ERR_NO_FILE;
        }

        if (is_array($value) && isset($value['tmp_name'], $value['error'])) {
            return $value['error'] !== UPLOAD_ERR_NO_FILE && $value['tmp_name'] !== '';
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return __('The %s field is required.', [':attribute']);
    }
}
