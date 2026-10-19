<?php

// Read declarations only: never require the application, vendor autoloader, or source files.
// Adapted from TinyMVC's documentation declaration extractor.
$args = array_slice($argv, 1);
$query = '';
$sourcePath = getcwd() . '/vendor/tinymvc/tinycore/src';
$fileFilter = '';
$limit = 25;
$includeSupport = false;

foreach ($args as $arg) {
    if ($arg === '--help') {
        echo "Usage: php api-lookup.php SEARCH [--source=PATH] [--file=SUBSTRING] [--limit=1..200] [--include-support]\n";
        echo "SEARCH matches a declared method, class, trait, or source filename (case-insensitive).\n";
        echo "Run from the application root; default source: vendor/tinymvc/tinycore/src.\n";
        exit(0);
    }

    if (str_starts_with($arg, '--source=')) {
        $sourcePath = substr($arg, 9);
    } elseif (str_starts_with($arg, '--file=')) {
        $fileFilter = substr($arg, 7);
    } elseif (str_starts_with($arg, '--limit=')) {
        $value = substr($arg, 8);

        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 200) {
            fwrite(STDERR, "Limit must be between 1 and 200.\n");
            exit(2);
        }

        $limit = (int) $value;
    } elseif ($arg === '--include-support') {
        $includeSupport = true;
    } elseif (str_starts_with($arg, '--') || $query !== '') {
        fwrite(STDERR, "Unknown option or extra search argument; use --help.\n");
        exit(2);
    } else {
        $query = $arg;
    }
}

$root = realpath($sourcePath);

if ($query === '' || !$root || !is_dir($root)) {
    fwrite(STDERR, "Supply a search term and an existing source directory; use --help.\n");
    exit(2);
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$result = [];
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
    if (!$includeSupport && str_starts_with($relative, 'Support/')) {
        continue;
    }
    if ($fileFilter !== '' && stripos($relative, $fileFilter) === false) {
        continue;
    }

    $source = file_get_contents($file->getPathname());
    $tokens = token_get_all($source);
    preg_match('/namespace\s+([^;]+);/', $source, $namespace);
    preg_match('/^(?:abstract\s+|final\s+)?(class|interface|trait|enum)\s+(\w+)/m', $source, $class);
    $methods = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while (isset($tokens[$j]) && ((is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG])) || $tokens[$j] === '&')) {
            $j++;
        }
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tokens[$j][1])) {
            continue;
        }
        $afterName = $j + 1;
        while (isset($tokens[$afterName]) && is_array($tokens[$afterName]) && $tokens[$afterName][0] === T_WHITESPACE) {
            $afterName++;
        }
        if (($tokens[$afterName] ?? null) !== '(') { // Exclude `use function` imports.
            continue;
        }
        $name = $tokens[$j][1];
        $modifiers = [];
        for ($k = $i - 1; $k >= 0; $k--) {
            $prior = $tokens[$k];
            if (is_string($prior) && in_array($prior, [';', '{', '}'])) {
                break;
            }
            if (is_array($prior)) {
                if ($prior[0] === T_DOC_COMMENT) {
                    break;
                }
                if (in_array($prior[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL])) {
                    $modifiers[] = strtolower($prior[1]);
                }
            }
        }
        if (in_array('private', $modifiers)) {
            continue;
        }
        $subclassApi = str_starts_with($relative, 'Testing/')
            || ($relative === 'Database/Model.php' && $name === 'events')
            || ($relative === 'Database/Concerns/InteractsWithOrm.php'
                && in_array($name, ['hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'hasManyThrough']));
        if (in_array('protected', $modifiers) && !$subclassApi) {
            continue;
        }
        $signature = implode(' ', array_reverse($modifiers));
        if ($signature !== '') {
            $signature .= ' ';
        }
        $depth = 0;
        for ($k = $i; isset($tokens[$k]); $k++) {
            $t = $tokens[$k];
            $value = is_array($t) ? $t[1] : $t;
            if ($value === '(') {
                $depth++;
            }
            if ($value === ')') {
                $depth--;
            }
            if ($depth === 0 && in_array($value, ['{', ';'])) {
                break;
            }
            if (is_array($t) && in_array($t[0], [T_DOC_COMMENT, T_COMMENT])) {
                continue;
            }
            // Normalize layout tokens only; quoted default values must remain exact.
            $signature .= is_array($t) && $t[0] === T_WHITESPACE ? ' ' : $value;
        }
        $signature = trim($signature);
        $methods[] = [
            'name' => $name,
            'signature' => $signature,
            'line' => $token[2],
        ];
    }
    if (!$methods) {
        continue;
    }
    $result[] = [
        'file' => $relative,
        'name' => isset($class[2]) ? (($namespace[1] ?? '') . '\\' . $class[2]) : $relative,
        'methods' => $methods,
    ];
}
usort($result, fn($a, $b) => strcmp($a['file'], $b['file']));
$matches = [];

foreach ($result as $declaration) {
    $classMatches = stripos($declaration['name'], $query) !== false
        || stripos($declaration['file'], $query) !== false;

    foreach ($declaration['methods'] as $method) {
        if ($classMatches || stripos($method['name'], $query) !== false) {
            $matches[] = [$declaration, $method];
        }
    }
}

echo "Source: {$root}\n";
echo "Declared APIs only; follow traits, parents, facade accessors and __call for forwarded behavior.\n\n";

foreach (array_slice($matches, 0, $limit) as [$declaration, $method]) {
    echo $declaration['name'] . "\n";
    echo $method['signature'] . "\n";
    echo $root . '/' . $declaration['file'] . ':' . $method['line'] . "\n\n";
}

echo count($matches) . " match(es); showing " . min(count($matches), $limit) . ".\n";

if (count($matches) > $limit) {
    echo "Narrow SEARCH or --file, or increase --limit.\n";
}

if (!$matches) {
    echo "No matching declaration. Inspect forwarding, inheritance and optional packages before concluding an API is absent.\n";
    exit(1);
}
