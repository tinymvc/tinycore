<?php

namespace Spark\Facades;

use Spark\Contracts\Http\InputContract;
use Spark\Http\Validator as BaseValidator;

/**
 * Facade Validator
 *
 * Static interface to the data validation utility.
 *
 * @method static BaseValidator make(?array $rules = null, ?array $data = null)
 * @method static bool|InputContract validate(?array $rules = null, ?array $data = null)
 * @method static void setErrorMessages(array $messages)
 * @method static void mergeErrorMessages(array $messages)
 * @method static void setErrorMessage(string $rule, string $message)
 * @method static array getErrors()
 * @method static bool hasErrors()
 * @method static bool passes()
 * @method static bool fails()
 * @method static array errors(?string $field = null)
 * @method static ?string getFirstError()
 * @method static mixed validated()
 *
 * @package Spark\Facades
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Validator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseValidator::class;
    }
}
