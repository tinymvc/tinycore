<?php

namespace Spark\Contracts\Http\Input;

/**
 * Interface SanitizerUtilContract
 *
 * Defines the contract for a utility that provides methods
 * to sanitize and validate different data types.
 */
interface SanitizerContract
{
    /**
     * Sanitizes an email address.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized email or null if invalid.
     */
    public function email(string $key): ?string;

    /**
     * Sanitizes plain text, with optional HTML tag stripping.
     *
     * @param string $key Key in the data array to sanitize.
     * @param bool $stripTags Whether to strip HTML tags from the text.
     * @return string|null Sanitized text or null if invalid.
     */
    public function text(string $key, bool $stripTags = true): ?string;

    /**
     * Sanitizes an integer value.
     *
     * @param string $key Key in the data array to sanitize.
     * @return int|null Sanitized integer or null if invalid.
     */
    public function number(string $key): ?int;

    /**
     * Sanitizes a floating-point number.
     *
     * @param string $key Key in the data array to sanitize.
     * @return float|null Sanitized float or null if invalid.
     */
    public function float(string $key): ?float;

    /**
     * Sanitizes a boolean value.
     *
     * @param string $key Key in the data array to validate.
     * @return bool|null Sanitized boolean or null if invalid.
     */
    public function boolean(string $key): ?bool;

    /**
     * Sanitizes a URL.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized URL or null if invalid.
     */
    public function url(string $key): ?string;
}