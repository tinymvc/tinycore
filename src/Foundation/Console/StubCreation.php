<?php

namespace Spark\Foundation\Console;

use RuntimeException;
use Spark\Console\Prompt;
use Spark\Support\Pluralizer;
use Spark\Support\Str;
use Spark\Utils\FileManager;
use function is_array;
use function is_string;

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
        if (!isset($stubConfig['stub'], $stubConfig['destination'])) {
            throw new RuntimeException('Invalid stub configuration: stub and destination are required');
        }

        // Load the stub file contents
        $stub = FileManager::get((string) $stubConfig['stub']);
        if ($stub === false) {
            throw new RuntimeException("Unable to read stub file: {$stubConfig['stub']}");
        }

        // Perform replacements in the stub content
        $stub = self::replacement($name, $stub, $stubConfig['replacements'] ?? []);

        // Determine the destination path and replace placeholders
        $destination = root_dir(self::replacement($name, (string) $stubConfig['destination']));
        if (!is_string($destination) || $destination === '') {
            throw new RuntimeException('Invalid stub destination generated');
        }

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
        $directory = dirname($destination);
        if ($directory !== '' && $directory !== '.') {
            FileManager::ensureDirectoryWritable($directory);
        }

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
            if (str_contains($name, $char)) {
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
        if ($contents === false || !preg_match('/return\s*(\[.*\]);/sU', $contents, $matches)) {
            throw new RuntimeException("Could not find return array in {$destination}");
        }

        $arrayContent = rtrim($matches[1]);
        $arrayContent = preg_replace('/\]\s*$/', '', $arrayContent); // remove closing bracket
        if (!is_string($arrayContent)) {
            throw new RuntimeException("Unable to parse return array in {$destination}");
        }

        // Add new array element to the end
        if ($key !== null) {
            $arrayContent .= "    '{$key}' => {$element},";
        } else {
            $arrayContent .= "    {$element},";
        }

        $arrayContent .= "\n]";

        // Replace in original contents
        $newContents = preg_replace('/return\s*\[.*\];/sU', "return {$arrayContent};", $contents);
        if ($newContents === null || $newContents === $contents) {
            throw new RuntimeException("Unable to update return array in {$destination}");
        }

        if (FileManager::put($destination, $newContents) === false) {
            throw new RuntimeException("Unable to write updated array in {$destination}");
        }
    }
}
