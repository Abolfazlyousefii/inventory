<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

function application_model_classes_for_pest(): array
{
    return collect(app_php_files('app/Models'))
        ->map(fn (string $file): ?string => app_class_from_file($file))
        ->filter(fn (?string $class): bool => $class !== null && class_exists($class) && is_subclass_of($class, Model::class))
        ->mapWithKeys(fn (string $class): array => [class_basename($class) => [$class]])
        ->all();
}

dataset('application models', fn (): array => application_model_classes_for_pest());

it('keeps each model ready for create insert and update operations', function (string $class): void {
    $model = new $class();

    expect($model)->toBeInstanceOf(Model::class)
        ->and($model->getTable())->toBeString()->not->toBe('')
        ->and($model->getKeyName())->toBeString()->not->toBe('')
        ->and($class::query())->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class)
        ->and(is_callable([$class, 'create']))->toBeTrue()
        ->and(is_callable([$class::query(), 'insert']))->toBeTrue()
        ->and(is_callable([$class::query(), 'update']))->toBeTrue();

    expect($model->getFillable() !== [] || $model->getGuarded() === [])->toBeTrue(
        "{$class} must either define fillable fields or intentionally use an unguarded model so create() can be tested safely."
    );
});

it('keeps every declared relationship method executable enough to return a relation object', function (string $class): void {
    $reflection = new ReflectionClass($class);
    $model = new $class();

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || $method->getNumberOfRequiredParameters() > 0) {
            continue;
        }

        $returnType = $method->getReturnType();
        $returnName = $returnType instanceof ReflectionNamedType ? $returnType->getName() : null;

        if ($returnName === null || ! is_a($returnName, Relation::class, true)) {
            continue;
        }

        expect($method->invoke($model))->toBeInstanceOf(Relation::class, "{$class}::{$method->getName()} must return an Eloquent relation.");
    }
});
