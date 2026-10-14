<?php

use App\Models\SalesReturnDocument;
use App\Services\SalesReturnAppliedAdjustmentService;
use App\Services\WarehouseStockService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('rejects zero product and variant ids before warehouse stock insertion', function () {
    expect(fn () => WarehouseStockService::change(1, 0, 1, 0))
        ->toThrow(ValidationException::class, 'ارتباط کالا نامعتبر است');
});

it('blocks the legacy applied edit before any product or stock operation', function () {
    $document = new SalesReturnDocument(['status' => 'applied', 'document_number' => '00001']);
    expect(fn () => app(SalesReturnAppliedAdjustmentService::class)->updateApplied($document, ['items' => [['product_id' => 0, 'product_variant_id' => 0]]], 1, 'اصلاح'))
        ->toThrow(ValidationException::class);
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
