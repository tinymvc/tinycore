<?php

namespace Spark\Foundation\Console;

use RuntimeException;
use Spark\Console\Prompt;
use Spark\Support\Pluralizer;
use Spark\Support\Str;
use Spark\Utils\FileManager;

/**
 * Class StubCreation
 *
 * This class is responsible for creating new stub files from a given stub
 * configuration. The stub configuration is an array of key-value pairs
 * containing the path to the stub file, the destination path for the new
 * file, and any replacements to make in the stub file.
 *
 * The class uses the Prompt service to ask the user for input if required.
 *
 * @package Spark\Foundation\Console
 */

class StubCreation
{
    /**
     * Create a new stub file based on the provided configuration and arguments.
     *
     * @param  string  $name
     *         The name of the stub file.
     * @param  array  $stubConfig
     *         The configuration for the stub file, including stub path and replacements.
     * @return void
     */
    public static function create(string $name, array $stubConfig): void
    {
        // Load the stub file contents
        $stub = FileManager::get($stubConfig['stub']);

        // Perform replacements in the stub content
        $stub = self::replacement($name, $stub, $stubConfig['replacements'] ?? []);

        // Determine the destination path and replace placeholders
        $destination = root_dir(self::replacement($name, $stubConfig['destination']));

        // Check if the file already exists and prompt for override confirmation
        if (FileManager::isFile($destination)) {
            $override = Prompt::confirm(
                "The file: {$destination}\n is already exists. Do you want to override it?",
                true
            );
            if (!$override) {
                return;
            }
        }

        // Create directory if it doesn't exist
        FileManager::ensureDirectoryWritable($dirName = dirname($destination));

        // Write the stub content to the destination file
        if (FileManager::put($destination, $stub)) {
            Prompt::message("File: {$destination}\n created successfully.");
        } else {
            Prompt::message("File: {$destination}\n could not be created.", 'warning');
        }
    }

    /**
     * Replace placeholders in the stub content with actual values.
     *
     * @param  string  $name
     * @param  string  $stub
     * @param  ?array  $replacements
     * @return string
     */
    public static function replacement(string $name, string $stub, ?array $replacements = null): string
    {
        // Determine subfolders from the name using delimiters
        $subFolders = [];
        foreach (['/', '\\', '.'] as $char) {
            if (strpos($name, $char) !== false) {
                $subFolders = explode($char, $name);
                $name = array_pop($subFolders);
                break;
            }
        }

        // Get the plural and singular versions of the name
        $namePlural = Pluralizer::plural($name);
        $nameSingular = Pluralizer::singular($name);

        // Prepare replacement values for the stub
        $replacementValues = [
            '::subfolder:namespace' => !empty($subFolders) ? '\\' . implode(
                '\\',
                array_map('ucfirst', $subFolders)
            ) : '',
            '::subfolder:lowercase' => dir_path(
                implode('/', array_map('strtolower', $subFolders))
            ),
            '::subfolder:ucfirst' => dir_path(
                implode('/', array_map('ucfirst', $subFolders))
            ),
            '::subfolder' => dir_path(
                implode('/', $subFolders)
            ),
            '::name:pluralize:lowercase' => strtolower($namePlural),
            '::name:singularize:lowercase' => strtolower($nameSingular),
            '::name:pluralize:ucfirst' => ucfirst($namePlural),
            '::name:singularize:ucfirst' => ucfirst($nameSingular),
            '::name:lowercase' => strtolower($name),
            '::name:ucfirst' => ucfirst($name),
            '::name:pluralize' => $namePlural,
            '::name:singularize' => $nameSingular,
            '::name' => $name,
        ];

        // Function to replace keys in a string with their corresponding values
        $replaceAny = function ($string) use ($replacementValues) {
            foreach ($replacementValues as $search => $replace) {
                $string = Str::replace($search, $replace, $string);
            }
            return $string;
        };

        // Perform replacements in the stub content
        if (is_array($replacements)) {
            foreach ($replacements as $search => $replace) {
                $stub = Str::replace($search, $replaceAny($replace), $stub);
            }
            return $stub;
        }

        return $replaceAny($stub);
    }

    /**
     * Append a line to an array in a PHP file.
     *
     * @param string $destination The path to the PHP file.
     * @param string $element The element to append.
     * @param string|null $key The key to set (optional).
     * @return void
     */
    public static function appendLineToArray(string $destination, string $element, ?string $key = null): void
    {
        $contents = FileManager::get($destination);

        // Find the array start
        if (!preg_match('/return\s*(\[.*\]);/sU', $contents, $matches)) {
            throw new RuntimeException("Could not find return array in {$destination}");
        }

        $arrayContent = rtrim($matches[1]);
        $arrayContent = preg_replace('/\]\s*$/', '', $arrayContent); // remove closing bracket

        // Add new array element to the end
        if ($key !== null) {
            $arrayContent .= "    '{$key}' => {$element},";
        } else {
            $arrayContent .= "    {$element},";
        }

        $arrayContent .= "\n]";

        // Replace in original contents
        $newContents = preg_replace('/return\s*\[.*\];/sU', "return {$arrayContent};", $contents);

        FileManager::put($destination, $newContents);
    }
}