<?php

use Tests\TestCase;

uses(TestCase::class)->in('Feature');

function app_php_files(string $directory): array
{
    $root = dirname(__DIR__);
    $base = $root.DIRECTORY_SEPARATOR.trim($directory, DIRECTORY_SEPARATOR);

    if (! is_dir($base)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function app_class_from_file(string $file): ?string
{
    $code = file_get_contents($file);
    $tokens = token_get_all($code);
    $namespace = '';

    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_NAMESPACE) {
            $parts = [];
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === ';') {
                    break;
                }
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $parts[] = $tokens[$j][1];
                }
            }
            $namespace = implode('', $parts);
        }

        if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    return $namespace.'\\'.$tokens[$j][1];
                }
            }
        }
    }

    return null;
}

function public_methods_declared_in(string $class): array
{
    $reflection = new ReflectionClass($class);

    return array_values(array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $method): bool => $method->class === $class && ! str_starts_with($method->name, '__')
    ));
}
