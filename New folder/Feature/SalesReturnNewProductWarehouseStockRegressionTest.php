<?php

use App\Services\WarehouseStockService;
use Illuminate\Validation\ValidationException;

it('rejects zero product and variant ids before warehouse stock insertion', function () {
    expect(fn () => WarehouseStockService::change(1, 0, 1, 0))
        ->toThrow(ValidationException::class, 'ارتباط کالا نامعتبر است');
});

it('materializes new products before applied adjustment inventory delta', function () {
    $adjustment = file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'));
    $restore = strpos($adjustment, 'restoreMaterializedNewProducts($doc');
    $materialize = strpos($adjustment, 'materializeNewProductGroups($doc');
    $inventoryDelta = strpos($adjustment, 'applyInventoryDelta($previousInventory');

    expect($restore)->toBeInt()->toBeLessThan($materialize)
        ->and($materialize)->toBeInt()->toBeLessThan($inventoryDelta)
        ->and($adjustment)->toContain(
            'materializedNewProductMap($doc)',
            'temporaryVariantUuid',
            "where('product_id', \$ids['product_id'])",
            'created_product_id',
            'created_variant_id'
        );
});

it('fails at the domain boundary if any inventory group still has unresolved ids', function () {
    $adjustment = file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'));

    expect($adjustment)->toContain(
        '(int) $item->product_id <= 0',
        '(int) $item->product_variant_id <= 0',
        'کالای جدید هنوز برای ثبت موجودی نهایی نشده است.'
    );
});

it('keeps draft persistence free of inventory and product materialization', function () {
    $service = file_get_contents(app_path('Services/SalesReturnService.php'));
    $start = strpos($service, 'private function persistDraft');
    $end = strpos($service, 'public function cancelDraft');
    $persistDraft = substr($service, $start, $end - $start);

    expect($persistDraft)->not->toContain(
        'WarehouseStock',
        'StockMovement',
        'materializeNewProductGroups',
        'recordInventoryEntry'
    );
});

it('keeps new product ids nullable until materialization', function () {
    $service = file_get_contents(app_path('Services/SalesReturnService.php'));
    $start = strpos($service, 'public function prepareSazehItems');
    $end = strpos($service, 'private function documentDestinationWarehouse');
    $prepare = substr($service, $start, $end - $start);

    expect($prepare)->toContain("'new_product_payload'=>\$payload")
        ->not->toContain("'product_id'=>0")
        ->not->toContain("'product_variant_id'=>0");
});
