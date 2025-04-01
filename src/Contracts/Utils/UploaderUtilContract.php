<?php

namespace Spark\Contracts\Utils;

interface UploaderUtilContract
{
    public function upload(array $files): string|array;
}