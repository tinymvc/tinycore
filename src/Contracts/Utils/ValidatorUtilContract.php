<?php

namespace Spark\Contracts\Utils;

interface ValidatorUtilContract
{
    public function validate(array $rules, array $inputData): bool|array;

    public function getErrors(): array;
}