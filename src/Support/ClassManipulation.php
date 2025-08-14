<?php

namespace Spark\Support;

use Spark\Utils\FileManager;

/**
 * Class ClassManipulation
 * 
 * A utility class for manipulating PHP class files, including use statements,
 * namespace operations, and other class-related file modifications.
 */
class ClassManipulation
{
    /**
     * Append a use statement to a PHP file.
     *
     * @param string $path The path to the file
     * @param string $use The use statement to append
     * @return void
     */
    public static function appendUse(string $path, string $use): void
    {
        $content = FileManager::get($path);

        // Check if the use statement already exists
        if (preg_match('/^' . preg_quote($use, '/') . '\s*$/m', $content)) {
            return;
        }

        $matches = [];
        preg_match_all('/^use [^;]+;/m', $content, $matches, PREG_OFFSET_CAPTURE);

        if (!empty($matches[0])) {
            // Insert after the last existing use statement
            $lastMatch = end($matches[0]);
            $insertPos = $lastMatch[1] + strlen($lastMatch[0]);
            $content = substr($content, 0, $insertPos) . "\n" . $use . substr($content, $insertPos);
        } else {
            // No existing use statements, insert after <?php tag
            if (strpos($content, "<?php") !== false) {
                $insertPos = strpos($content, "<?php") + 5;
                $content = substr($content, 0, $insertPos) . "\n\n" . $use . substr($content, $insertPos);
            } else {
                // No <?php tag found, prepend to file
                $content = $use . "\n" . $content;
            }
        }

        FileManager::put($path, $content);
    }

    /**
     * Check if a use statement exists in a PHP file.
     *
     * @param string $path The path to the file
     * @param string $use The use statement to check for
     * @return bool True if the use statement exists, false otherwise
     */
    public static function hasUse(string $path, string $use): bool
    {
        $content = FileManager::get($path);
        return preg_match('/^' . preg_quote($use, '/') . '\s*$/m', $content) === 1;
    }

    /**
     * Get all use statements from a PHP file.
     *
     * @param string $path The path to the file
     * @return array Array of use statements found in the file
     */
    public static function getUseStatements(string $path): array
    {
        $content = FileManager::get($path);
        $matches = [];
        preg_match_all('/^use [^;]+;/m', $content, $matches);
        
        return $matches[0] ?? [];
    }

    /**
     * Add a namespace to a PHP file if it doesn't already have one.
     *
     * @param string $path The path to the file
     * @param string $namespace The namespace to add
     * @return void
     */
    public static function addNamespace(string $path, string $namespace): void
    {
        $content = FileManager::get($path);

        // Check if namespace already exists
        if (preg_match('/^namespace\s+[^;]+;/m', $content)) {
            return;
        }

        // Add namespace after <?php tag
        if (strpos($content, "<?php") !== false) {
            $insertPos = strpos($content, "<?php") + 5;
            $namespaceDeclaration = "\n\nnamespace $namespace;\n";
            $content = substr($content, 0, $insertPos) . $namespaceDeclaration . substr($content, $insertPos);
            FileManager::put($path, $content);
        }
    }

    /**
     * Get the namespace from a PHP file.
     *
     * @param string $path The path to the file
     * @return string|null The namespace if found, null otherwise
     */
    public static function getNamespace(string $path): ?string
    {
        $content = FileManager::get($path);
        $matches = [];
        
        if (preg_match('/^namespace\s+([^;]+);/m', $content, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }

    /**
     * Build namespace with subfolders from a given name.
     *
     * @param string $name The name containing potential subfolders
     * @param string $baseNamespace The base namespace template with ::subfolder:namespace placeholder
     * @return string The namespace with subfolders replaced
     */
    public static function buildNamespaceWithSubfolders(string $name, string $baseNamespace): string
    {
        $subFolders = [];
        foreach (['/', '\\', '.'] as $char) {
            if (strpos($name, $char) !== false) {
                $subFolders = explode($char, $name);
                array_pop($subFolders); // Remove the class name, keep only subfolders
                break;
            }
        }

        $replace = !empty($subFolders) ? '\\' . implode(
            '\\',
            array_map('ucfirst', $subFolders)
        ) : '';
        
        return str_replace('::subfolder:namespace', $replace, $baseNamespace);
    }
}
