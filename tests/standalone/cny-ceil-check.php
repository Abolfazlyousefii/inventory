<?php
// Composer-free exact-money regression: php tests/standalone/cny-ceil-check.php
require __DIR__.'/../../app/Services/CnyPriceCalculator.php';

use App\Services\CnyPriceCalculator;

$calc = new CnyPriceCalculator();
foreach ([
    ['5', 400000, 2000000, 2920000],
    ['5', 390000, 1950000, 2850000],
    ['2.5125', 400000, 1005000, 1470000],
    ['3.5', 100000, 350000, 510000],
    ['100000', 100000000, 10000000000000, 14571428580000],
] as [$yuan, $rate, $buyExpected, $saleExpected]) {
    $out = $calc->calculate($yuan, $rate);
    if ($out['buy_rial'] !== $buyExpected || $out['sell_rial'] !== $saleExpected) {
        throw new RuntimeException("Unexpected currency ceiling for {$yuan}/{$rate}: " . json_encode($out));
    }
    if ($out['sell_rial'] % 10000 !== 0 || $out['sell_rial'] <= 0) {
        throw new RuntimeException('Selling price must be a positive multiple of 10,000 IRR');
    }
}
foreach ([
    ['0.01', 2050000],
    ['20', 2550000],
    ['۱۲٫۵', 2340000],
] as [$margin, $saleExpected]) {
    $out = $calc->calculate('5', 400000, $margin);
    if ($out['sell_rial'] !== $saleExpected || $calc->marginFromPolicy($out['policy']) === null) {
        throw new RuntimeException("Unexpected custom yuan margin: {$margin}");
    }
}
foreach (['', '0', '-0', '-0.01', '-20', '100', '-100', '99.01', 'abc', '20.123'] as $invalidMargin) {
    try { $calc->calculate('5', 400000, $invalidMargin); }
    catch (InvalidArgumentException) { continue; }
    throw new RuntimeException("Invalid yuan margin was accepted: {$invalidMargin}");
}
foreach (['margin0-buffer2-ceil1000-v2', 'margin-20-buffer2-ceil1000-v2'] as $invalidPolicy) {
    if ($calc->marginFromPolicy($invalidPolicy) !== null) throw new RuntimeException('Nonpositive CNY policy was accepted');
}
echo "Exact CNY integer ceiling tests passed\n";
