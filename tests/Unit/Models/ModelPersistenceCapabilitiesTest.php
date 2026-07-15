<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

it('keeps every application model ready for persistence operations', function (): void {
    $classes = collect(app_php_files('app/Models'))
        ->map(
            fn (string $file): ?string => app_class_from_file($file)
        )
        ->filter(
            fn (?string $class): bool =>
                $class !== null
                && class_exists($class)
                && is_subclass_of($class, Model::class)
        )
        ->values()
        ->all();

    expect($classes)->not->toBeEmpty(
        'No Eloquent models were found inside app/Models.'
    );

    foreach ($classes as $class) {
        $model = new $class();

        expect($model)->toBeInstanceOf(
            Model::class,
            "{$class} must extend Illuminate\Database\Eloquent\Model."
        );

        expect($model->getTable())->toBeString()->not->toBeEmpty(
            "{$class} must have a valid database table name."
        );

        expect($model->getKeyName())->toBeString()->not->toBeEmpty(
            "{$class} must have a valid primary key name."
        );

        expect($class::query())->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Builder::class,
            "{$class}::query() must return an Eloquent builder."
        );

        expect(is_callable([$class, 'create']))->toBeTrue(
            "{$class} must support create()."
        );

        expect(is_callable([$class::query(), 'insert']))->toBeTrue(
            "{$class} must support insert()."
        );

        expect(is_callable([$class::query(), 'update']))->toBeTrue(
            "{$class} must support update()."
        );

        expect(
            $model->getFillable() !== []
            || $model->getGuarded() === []
        )->toBeTrue(
            "{$class} must define fillable fields or intentionally use guarded = []."
        );
    }
});

it('keeps every declared model relationship executable', function (): void {
    $classes = collect(app_php_files('app/Models'))
        ->map(
            fn (string $file): ?string => app_class_from_file($file)
        )
        ->filter(
            fn (?string $class): bool =>
                $class !== null
                && class_exists($class)
                && is_subclass_of($class, Model::class)
        )
        ->values()
        ->all();

    expect($classes)->not->toBeEmpty(
        'No Eloquent models were found inside app/Models.'
    );

    foreach ($classes as $class) {
        $reflection = new \ReflectionClass($class);
        $model = new $class();

        foreach (
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC)
            as $method
        ) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            if ($method->isStatic()) {
                continue;
            }

            $returnType = $method->getReturnType();

            if (! $returnType instanceof \ReflectionNamedType) {
                continue;
            }

            $returnName = $returnType->getName();

            if (! is_a($returnName, Relation::class, true)) {
                continue;
            }

            $result = $method->invoke($model);

            expect($result)->toBeInstanceOf(
                Relation::class,
                "{$class}::{$method->getName()} must return an Eloquent relation."
            );
        }
    }
});