<?php

// Run without Composer: php tests/standalone/bundle_stocktake.php
require_once __DIR__.'/../../app/Services/BundleComponentStocktakeService.php';

use App\Services\BundleComponentStocktakeService as BundleStocktake;

$recipe = [
    (object) ['bundle_variant_id' => 100, 'component_variant_id' => 1, 'quantity' => 2],
    (object) ['bundle_variant_id' => 200, 'component_variant_id' => 1, 'quantity' => 1],
    (object) ['bundle_variant_id' => 200, 'component_variant_id' => 2, 'quantity' => 3],
];
$allocation = [
    (object) ['bundle_variant_id' => 100, 'component_variant_id' => 1, 'remaining' => 1],
    (object) ['bundle_variant_id' => 200, 'component_variant_id' => 1, 'remaining' => 2],
    (object) ['bundle_variant_id' => 200, 'component_variant_id' => 2, 'remaining' => 3],
];
$results = BundleStocktake::aggregate($recipe, $allocation, [100 => 1, 200 => 2]);
if ($results !== [1 => ['free' => 3, 'reserved' => 4, 'site_held' => 0], 2 => ['free' => 3, 'reserved' => 6, 'site_held' => 0]]) {
    throw new RuntimeException('Shared components, bundle recipes, or bundle holds calculated incorrectly.');
}
$changed = BundleStocktake::aggregate(
    [(object) ['bundle_variant_id' => 100, 'component_variant_id' => 1, 'quantity' => 1]],
    [(object) ['bundle_variant_id' => 100, 'component_variant_id' => 1, 'remaining' => 0]],
    [100 => 1],
);
if ($changed[1] !== ['free' => 0, 'reserved' => 1, 'site_held' => 0]) {
    throw new RuntimeException('A reserved bundle component must not be counted as a free allocation.');
}
try {
    BundleStocktake::aggregate([], [(object) ['component_variant_id' => 1, 'remaining' => -1]], []);
    throw new RuntimeException('Negative ledger allocations must fail closed.');
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Negative ledger allocations must fail closed.') throw $e;
}
$siteHeld = BundleStocktake::aggregate(
    [(object) ['bundle_variant_id' => 100, 'component_variant_id' => 1, 'quantity' => 1]],
    [(object) ['bundle_variant_id' => 100, 'component_variant_id' => 1, 'remaining' => 0]],
    [],
    [1 => 2], // Site order reserved components, not a preinvoice reservation.
);
if ($siteHeld[1] !== ['free' => 0, 'reserved' => 0, 'site_held' => 2]) {
    throw new RuntimeException('Site-held bundle parts would be double-counted as free stock.');
}
// A corrupted reservation should never increase free stock by using a negative protected quantity.
foreach ([
    [-1, 'negative bundle reservation'],
    [PHP_INT_MAX, 'bundle reservation overflow'],
] as [$quantity, $description]) {
    $failedClosed = false;
    try {
        BundleStocktake::aggregate(
            [(object) ['bundle_variant_id' => 100, 'component_variant_id' => 1, 'quantity' => 2]],
            [],
            [100 => $quantity],
        );
    } catch (\RuntimeException $e) {
        $failedClosed = true;
    }
    if (! $failedClosed) {
        throw new RuntimeException("Unsafe {$description} was accepted during stock count.");
    }
}
// Multiple independent bundle rows must not overflow the physical stock total.
$failedClosed = false;
try {
    BundleStocktake::aggregate([], [
        (object) ['component_variant_id' => 1, 'remaining' => PHP_INT_MAX],
        (object) ['component_variant_id' => 1, 'remaining' => 1],
    ], []);
} catch (\RuntimeException $e) {
    $failedClosed = true;
}
if (! $failedClosed) {
    throw new RuntimeException('Overflowing free bundle stock was accepted during stock count.');
}
echo "7 bundle allocation aggregation assertions passed.\n";
