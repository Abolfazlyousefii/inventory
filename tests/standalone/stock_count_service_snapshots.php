<?php

// Exercise the real snapshot and presentation methods without Laravel bootstrap.
require_once __DIR__.'/../../app/Support/StockCountQuantityCalculator.php';
require_once __DIR__.'/../../app/Services/StockCountDocumentService.php';

use App\Services\StockCountDocumentService;

function now(): string { return '2026-10-29 00:00:00'; }

$service = (new ReflectionClass(StockCountDocumentService::class))->newInstanceWithoutConstructor();
$doc = (object) ['id' => 1];
$warehouse = (object) ['id' => 1];
$product = (object) ['id' => 5, 'name' => 'Component'];
$variant = (object) ['id' => 9, 'variant_name' => 'Model', 'variety_name' => null, 'variant_code' => 'SKU9', 'variety_code' => null, 'updated_at' => null];
$stock = (object) ['id' => 10, 'updated_at' => null];

foreach ([
    [8, 0, 2, 0, 0, 10, 8],
    [4, 2, 1, 3, 0, 10, 4],
    [8, 0, 0, 0, 2, 10, 8],
] as [$available, $reserved, $bundleFree, $bundleReserved, $siteHeld, $actual, $expectedFree]) {
    $payload = (new ReflectionMethod($service, 'itemPayload'))->invoke(
        $service, $doc, $warehouse, $product, $variant, $stock, $available, $reserved, $actual, null, $bundleFree, $bundleReserved, $siteHeld,
    );
    if ($payload['bundle_free_at_start'] !== $bundleFree || $payload['bundle_reserved_at_start'] !== $bundleReserved || $payload['site_held_at_start'] !== $siteHeld || $payload['expected_physical_at_start'] !== 10) {
        throw new RuntimeException('Stock-count draft does not persist protected bundle quantities in physical total.');
    }
    $item = (object) $payload;
    $item->variant = (object) ['sales_enabled' => true, 'is_active' => true];
    $result = (new ReflectionMethod($service, 'itemResource'))->invoke($service, $item);
    if ($result['new_available'] !== $expectedFree || $result['difference'] !== 0 || $result['bundle_free'] !== $bundleFree || $result['bundle_reserved'] !== $bundleReserved || $result['site_held'] !== $siteHeld) {
        throw new RuntimeException('Stock-count screen is inconsistent with the persisted snapshot.');
    }
}
echo "3 stock count snapshot/presentation assertions passed.\n";
