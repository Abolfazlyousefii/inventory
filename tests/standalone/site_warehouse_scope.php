<?php

// Safe, explicit warehouse allow-list resolution without Laravel bootstrapping.
require_once __DIR__.'/../../app/Support/SiteSellableWarehouseScope.php';

use App\Support\SiteSellableWarehouseScope;

if (SiteSellableWarehouseScope::resolve(1, null) !== [1]
    || SiteSellableWarehouseScope::resolve(7, []) !== [7]
    || SiteSellableWarehouseScope::resolve(1, [1, '3', 3]) !== [1, 3]
    || SiteSellableWarehouseScope::resolve(1, [4, 2]) !== [2, 4]) {
    throw new RuntimeException('Sellable warehouse scope did not honor explicit IDs and central fallback.');
}

foreach (['1,2', [0], [-1], ['abc'], [true], [1.3]] as $invalid) {
    try {
        SiteSellableWarehouseScope::resolve(1, $invalid);
        throw new RuntimeException('Invalid warehouse allow-list was accepted.');
    } catch (InvalidArgumentException $expected) {
        // Misconfiguration must fail rather than accidentally include all warehouses.
    }
}

echo "10 sellable warehouse scope assertions passed.\n";
