<?php

// Run without Laravel dependencies: php tests/standalone/stock_count_quantities.php
require_once __DIR__.'/../../app/Support/StockCountQuantityCalculator.php';

use App\Support\StockCountQuantityCalculator as Quantities;

$cases = [
    // [free, ordinary reservation, free bundle allocations, reserved bundle components, physical, final free]
    [8, 0, 2, 0, 10, 8],
    [4, 2, 1, 3, 10, 4],
    [5, 1, 4, 0, 10, 5], // several bundles protect the same component
    [4, 2, 0, 4, 10, 4], // bundle parts moved from free allocation to bundle reservation
    [5, 1, 1, 1, 10, 7], // physical count is two higher than expected
];
foreach ($cases as $index => [$free, $reserved, $bundleFree, $bundleReserved, $actual, $expectedFree]) {
    $expectedPhysical = Quantities::expectedPhysical($free, $reserved, $bundleFree, $bundleReserved);
    $newFree = Quantities::availableAfterCount($actual, $reserved, $bundleFree, $bundleReserved);
    if ($expectedPhysical !== ($free + $reserved + $bundleFree + $bundleReserved) || $newFree !== $expectedFree) {
        throw new RuntimeException("Scenario {$index} failed: expected physical {$expectedPhysical}, available {$newFree}");
    }
}
if (Quantities::availableAfterCount(3, 1, 2, 1) >= 0) {
    throw new RuntimeException('Insufficient physical count must be detectable.');
}
$siteExpected = Quantities::expectedPhysical(8, 0, 0, 0, 2);
$siteAvailable = Quantities::availableAfterCount(10, 0, 0, 0, 2);
if ($siteExpected !== 10 || $siteAvailable !== 8) {
    throw new RuntimeException('Physical site holds must remain excluded from free stock.');
}
// Invalid protected values and arithmetic overflows must fail before creating
// a stocktake document; a negative free balance can otherwise be manufactured.
foreach ([
    fn () => Quantities::expectedPhysical(PHP_INT_MAX, 1, 0, 0),
    fn () => Quantities::expectedPhysical(8, -1, 2, 0),
    fn () => Quantities::availableAfterCount(-1, 0, 0, 0),
    fn () => Quantities::availableAfterCount(10, 0, -3, 0),
    fn () => Quantities::availableAfterCount(10, PHP_INT_MAX, 1, 0),
] as $index => $calculation) {
    $failedClosed = false;
    try {
        $calculation();
    } catch (\InvalidArgumentException $e) {
        $failedClosed = true;
    }
    if (! $failedClosed) {
        throw new RuntimeException("Unsafe stocktake quantity case {$index} was accepted.");
    }
}
echo count($cases) + 7, " stock count quantity assertions passed.\n";
