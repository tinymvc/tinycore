<?php

namespace Spark\Support;

/**
 * Class SuffixHelper
 * 
 * A utility class for managing suffixes on strings.
 * Provides methods to add suffixes only if they don't already exist.
 */
class SuffixHelper
{
    public const CONTROLLER = 'Controller';
    public const COMMAND = 'Command';
    public const SEEDER = 'Seeder';
    public const MODEL = 'Model';

    /**
     * Add a suffix to a string if it doesn't already have it.
     *
     * @param string|null $name
     * @param string $suffix
     * @param bool $caseInsensitive
     * @return string|null
     */
    public static function addSuffix(?string $name, string $suffix, bool $caseInsensitive = true): ?string
    {
        if (empty($name)) {
            return null;
        }

        $pattern = '/' . preg_quote($suffix, '/') . '$/' . ($caseInsensitive ? 'i' : '');
        
        if (preg_match($pattern, $name)) {
            return $name;
        }

        return $name . $suffix;
    }

    /**
     * Remove a suffix from a string if it exists.
     *
     * @param string|null $name
     * @param string $suffix
     * @param bool $caseInsensitive
     * @return string|null
     */
    public static function removeSuffix(?string $name, string $suffix, bool $caseInsensitive = true): ?string
    {
        if (empty($name)) {
            return null;
        }

        $pattern = '/' . preg_quote($suffix, '/') . '$/' . ($caseInsensitive ? 'i' : '');
        
        return preg_replace($pattern, '', $name);
    }

    /**
     * Check if a string has a specific suffix.
     *
     * @param string|null $name
     * @param string $suffix
     * @param bool $caseInsensitive
     * @return bool
     */
    public static function hasSuffix(?string $name, string $suffix, bool $caseInsensitive = true): bool
    {
        if (empty($name)) {
            return false;
        }

        $pattern = '/' . preg_quote($suffix, '/') . '$/' . ($caseInsensitive ? 'i' : '');
        
        return preg_match($pattern, $name) === 1;
    }    
}
