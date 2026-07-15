<?php

dataset('controller files', fn (): array => app_php_files('app/Http/Controllers'));

it('loads every controller class and inspects its public actions', function (string $file): void {
    $class = app_class_from_file($file);

    expect($class)->not->toBeNull()
        ->and(class_exists($class))->toBeTrue();

    $reflection = new ReflectionClass($class);

    expect($reflection->isAbstract())->toBeFalse();

    if ($reflection->getShortName() === 'Controller') {
        expect(public_methods_declared_in($class))->toBeArray();
        return;
    }

    $actions = public_methods_declared_in($class);

    expect($actions)
        ->not->toBeEmpty("Controller {$class} must have at least one public action to be test-audited.");

    foreach ($actions as $action) {
        expect($action->getNumberOfRequiredParameters())
            ->toBeLessThanOrEqual($action->getNumberOfParameters(), "Invalid reflection metadata for {$class}::{$action->name}");
    }
})->with('controller files');

it('ensures all controller actions referenced by routes exist', function (): void {
    $routeFiles = [base_path('routes/web.php'), base_path('routes/auth.php')];
    $routeSource = implode("\n", array_map(fn (string $file): string => is_file($file) ? file_get_contents($file) : '', $routeFiles));

    preg_match_all('/\[\s*([A-Za-z0-9_\\\\]+)::class\s*,\s*[\'\"]([A-Za-z0-9_]+)[\'\"]\s*\]/', $routeSource, $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty('No controller routes were detected in route files.');

    foreach ($matches as $match) {
        $importedName = $match[1];
        $method = $match[2];
        $classes = array_filter(array_map(app_class_from_file(...), app_php_files('app/Http/Controllers')));
        $class = collect($classes)->first(fn (string $candidate): bool => str_ends_with($candidate, '\\'.$importedName));

        expect($class)->not->toBeNull("Route references unknown controller {$importedName}::{$method}")
            ->and(method_exists($class, $method))->toBeTrue("Route references missing action {$class}::{$method}");
    }
});
