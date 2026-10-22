<?php

// Minimal, dependency-free contract test because the uploaded ZIP has no Composer vendor or PHPUnit runtime.
// Run: php tests/Unit/BundleRecipeTest.php
require_once __DIR__.'/../../app/Support/BundleRecipe.php';

use App\Support\BundleRecipe;

function expectedFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $exception) {
        return;
    }

    throw new RuntimeException($message);
}

$components = BundleRecipe::normalize(100, [
    ['variant_id' => 10, 'quantity' => 2],
    ['variant_id' => 3, 'quantity' => 1],
]);
if ($components !== [3 => 1, 10 => 2]) {
    throw new RuntimeException('Recipe must be sorted and preserve component quantities.');
}

expectedFailure(fn () => BundleRecipe::normalize(100, [
    ['variant_id' => 3, 'quantity' => 1],
    ['variant_id' => 3, 'quantity' => 2],
]), 'Duplicate component variant should be rejected.');
expectedFailure(fn () => BundleRecipe::normalize(100, [['variant_id' => 100, 'quantity' => 1]]), 'Self-reference must fail.');
expectedFailure(fn () => BundleRecipe::normalize(100, [['variant_id' => 2, 'quantity' => 0]]), 'Zero quantity must fail.');
expectedFailure(fn () => BundleRecipe::normalize(100, [['variant_id' => -1, 'quantity' => 1]]), 'Invalid variant must fail.');
expectedFailure(fn () => BundleRecipe::normalize(100, []), 'Empty recipe must fail.');
expectedFailure(fn () => BundleRecipe::normalize(100, [['variant_id' => 2, 'quantity' => 100001]]), 'Unreasonably high quantity must fail.');

if (BundleRecipe::requirements([3 => 1, 10 => 2], 5) !== [3 => 5, 10 => 10]) {
    throw new RuntimeException('Assembly quantities must multiply correctly.');
}
expectedFailure(fn () => BundleRecipe::requirements([3 => 1], 0), 'Zero production quantity must fail.');

print "BundleRecipe contract checks passed.\n";
