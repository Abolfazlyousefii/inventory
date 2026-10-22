<?php
require_once __DIR__.'/../../app/Support/HoldLinePlan.php';
use App\Support\HoldLinePlan;

$normalized = HoldLinePlan::normalize([
    ['variant_id' => 11, 'quantity' => 2],
    ['variant_id' => 7, 'quantity' => 1],
    ['variant_id' => 11, 'quantity' => 3],
]);
if ($normalized !== [7 => 1, 11 => 5]) {
    throw new RuntimeException('All physical checkout lines must aggregate and sort by variant ID.');
}
$fail = function ($input) {
    try {
        HoldLinePlan::normalize($input);
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('Invalid hold plan should fail.');
};
$fail([]);
$fail([['variant_id' => 1, 'quantity' => 0]]);
$fail([['variant_id' => 1, 'quantity' => -1]]);
$fail([['variant_id' => 1, 'quantity' => 9223372036854775807], ['variant_id' => 1, 'quantity' => 1]]);
if (HoldLinePlan::fingerprint($normalized) !== HoldLinePlan::fingerprint([11 => 5, 7 => 1])) {
    throw new RuntimeException('Identical orders must have identical fingerprints.');
}
print "HoldLinePlan contract checks passed.\n";
