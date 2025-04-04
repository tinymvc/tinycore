<?php

namespace Spark\Foundation\Console;

use Spark\Console\Prompt;

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
     * Create a new instance of the stub creation class.
     *
     * @param  \Spark\Console\Prompt  $prompt
     *         The prompt service.
     */
    public function __construct(private Prompt $prompt)
    {
    }

    /**
     * Create a new stub file from a given stub configuration.
     *
     * @param  string  $name
     *         The name of the stub file.
     * @param  string  $question
     *         The question to ask the user for input.
     * @param  array  $stubConfig
     *         The configuration for the stub file.
     * @return void
     */
    public static function make(?string $name, string $question, array $stubConfig): void
    {
        $stub = new self(new Prompt());

        // Create the stub file based on the provided configuration
        $stub->create($name, $question, $stubConfig);
    }

    /**
     * Create a new stub file based on the provided configuration and arguments.
     *
     * @param  string  $name
     *         The name of the stub file.
     * @param  string  $question
     *         The question to ask the user if the name is not provided.
     * @param  array  $stubConfig
     *         The configuration for the stub file, including stub path and replacements.
     * @return void
     */
    public function create(?string $name, string $question, array $stubConfig): void
    {
        // Get the name from the arguments or prompt the user
        if (!$name) {
            do {
                $name = $this->prompt->ask($question);
            } while (!$name);
        }

        // Determine subfolders from the name using delimiters
        $subFolders = [];
        foreach (['/', '\\', '.'] as $char) {
            if (strpos($name, $char) !== false) {
                $subFolders = explode($char, $name);
                $name = array_pop($subFolders);
                break;
            }
        }

        // Load the stub file contents
        $stub = file_get_contents($stubConfig['stub']);

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
            '::name:lowercase' => strtolower($name),
            '::name:ucfirst' => ucfirst($name),
            '::name' => $name,
        ];

        // Function to replace keys in a string with their corresponding values
        $replaceAny = function ($string) use ($replacementValues) {
            foreach ($replacementValues as $search => $replace) {
                $string = str_replace($search, $replace, $string);
            }
            return $string;
        };

        // Perform replacements in the stub content
        foreach ($stubConfig['replacements'] ?? [] as $search => $replace) {
            $stub = str_replace($search, $replaceAny($replace), $stub);
        }

        // Determine the destination path and replace placeholders
        $destination = root_dir($replaceAny($stubConfig['destination']));

        // Check if the file already exists and prompt for override confirmation
        if (file_exists($destination)) {
            $override = $this->prompt->confirm(
                "The file: {$destination}\n is already exists. Do you want to override it?",
                true
            );
            if (!$override) {
                return;
            }
        }

        // Create directory if it doesn't exist
        $dirName = dirname($destination);
        if (!is_dir($dirName)) {
            mkdir($dirName, 0755, true);
        }

        // Write the stub content to the destination file
        if (file_put_contents($destination, $stub)) {
            $this->prompt->message("File: {$destination}\n created successfully.");
        } else {
            $this->prompt->message("File: {$destination}\n could not be created.", 'warning');
        }
    }
}