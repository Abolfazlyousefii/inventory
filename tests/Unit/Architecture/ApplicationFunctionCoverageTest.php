<?php

dataset('application php files', fn (): array => app_php_files('app'));

it('keeps every application php file syntactically loadable for function-level testing', function (string $file): void {
    $contents = file_get_contents($file);

    expect($contents)->toStartWith('<?php')
        ->and(substr_count($contents, 'function '))->toBeGreaterThanOrEqual(0);
})->with('application php files');

it('rejects debug side effects from all application functions and controllers', function (string $file): void {
    $contents = file_get_contents($file);

    expect($contents)->not->toMatch('/\b(dd|dump|ray|var_dump|print_r)\s*\(/', "Debug call found in {$file}")
        ->and($contents)->not->toContain('withoutExceptionHandling()', "Test-only exception handling bypass leaked into {$file}");
})->with('application php files');

it('can reflect every declared public method in app classes', function (string $file): void {
    $class = app_class_from_file($file);

    if ($class === null || ! class_exists($class)) {
        expect($class)->toBeNull();
        return;
    }

    foreach (public_methods_declared_in($class) as $method) {
        expect($method->getFileName())->toBe($file)
            ->and($method->getStartLine())->toBeInt()
            ->and($method->getEndLine())->toBeGreaterThanOrEqual($method->getStartLine());
    }
})->with('application php files');
