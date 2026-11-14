<?php

use App\Models\{Category, Customer, CustomerLedger, Product, ProductVariant, SalesReturnDocument, SalesReturnDocumentItem, StockMovement, User, Warehouse, WarehouseInboundReceipt};
use App\Services\{SalesReturnService, WarehouseInboundService, WarehouseStockService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function physicalReturnFixture(array $destinations = ['return'], string $flow = 'physical'): array
{
    Http::fake();
    $actor = User::factory()->create();
    $customer = Customer::factory()->create();
    $central = Warehouse::findOrFail(WarehouseStockService::centralWarehouseId());
    $returns = Warehouse::firstOrCreate(['type' => 'return'], ['name' => 'انبار مرجوعی', 'is_active' => true]);
    $doc = SalesReturnDocument::create([
        'document_number' => 'FLOW-'.SalesReturnDocument::count(), 'source_type' => 'sazeh_hesab',
        'status' => 'draft', 'return_flow' => $flow,
        'financial_only_reason' => $flow === 'financial_only' ? 'اصلاح اعتبار مشتری بدون تحویل کالا' : null,
        'customer_id' => $customer->id, 'external_invoice_number' => 'EXT-1',
        'external_invoice_date' => now()->toDateString(), 'default_destination_warehouse_id' => $returns->id,
        'return_reason' => 'damaged_product', 'created_by' => $actor->id,
    ]);
    foreach ($destinations as $index => $type) {
        $product = Product::create(['category_id' => Category::create(['name' => 'FLOW-'.$index])->id,
            'name' => 'کالای '.$index, 'sku' => 'FLOW-'.$index, 'stock' => 0, 'price' => 1000]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'variant_name' => 'مشکی',
            'variant_code' => 'FLOW-'.$index, 'stock' => 0, 'reserved' => 4, 'sell_price' => 1000, 'is_active' => true, 'sales_enabled' => true]);
        $doc->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id,
            'product_name_snapshot' => $product->name, 'variant_name_snapshot' => 'مشکی',
            'item_source' => 'existing_product', 'item_condition' => $type === 'return' ? 'damaged' : 'healthy',
            'destination_warehouse_id' => $type === 'return' ? $returns->id : $central->id,
            'return_quantity' => 3, 'refund_unit_price' => 100, 'refund_amount' => 300, 'sell_price' => 1000, 'sort_order' => $index + 1]);
    }
    return compact('actor', 'customer', 'central', 'returns', 'doc');
}

function receivePhysicalReturn(array $f, ?int $accepted = null, ?int $warehouse = null): WarehouseInboundReceipt
{
    $receipt = WarehouseInboundReceipt::where('source_id', $f['doc']->id)->sole();
    $rows = $receipt->items->map(fn ($i) => ['id' => $i->id,
        'accepted_quantity' => $accepted ?? $i->expected_quantity,
        'received_warehouse_id' => $warehouse ?? $i->suggested_warehouse_id])->all();
    return app(WarehouseInboundService::class)->receive($receipt, $rows, $f['actor']->id, 'تعداد و وضعیت واقعی بررسی شد');
}

it('queues every physical destination and posts its accepted stock and customer credit exactly once', function (array $destinations): void {
    $f = physicalReturnFixture($destinations);
    $service = app(SalesReturnService::class);
    $service->apply($f['doc'], $f['actor']->id);
    expect($f['doc']->fresh()->status)->toBe('pending_warehouse');
    $receipt = WarehouseInboundReceipt::sole();
    expect($receipt->items()->count())->toBe(count($destinations));
    expect(CustomerLedger::where('customer_id', $f['customer']->id)->count())->toBe(0);
    $service->apply($f['doc']->fresh(), $f['actor']->id);
    expect(WarehouseInboundReceipt::count())->toBe(1);
    receivePhysicalReturn($f);
    foreach ($f['doc']->fresh()->items as $item) {
        expect(WarehouseStockService::available($item->destination_warehouse_id, $item->product_id, $item->product_variant_id))->toBe(3)
            ->and((int) $item->variant->reserved)->toBe(4);
    }
    foreach (StockMovement::all() as $movement) {
        expect($movement->reference_type)->toBe(\App\Models\WarehouseInboundReceiptItem::class)
            ->and((int) $movement->stock_after - (int) $movement->stock_before)->toBe((int) $movement->quantity);
    }
    expect(app(\App\Services\SalesReturnQueryService::class)->appliedHealthCheck($f['doc']->fresh())['ok'])->toBeTrue();
    expect(StockMovement::count())->toBe(count($destinations))
        ->and((int) CustomerLedger::where('customer_id', $f['customer']->id)->sum('amount'))->toBe(count($destinations) * 300);
    try { receivePhysicalReturn($f); test()->fail('Duplicate receipt accepted'); } catch (ValidationException) {}
    expect(StockMovement::count())->toBe(count($destinations))
        ->and(CustomerLedger::where('customer_id', $f['customer']->id)->count())->toBe(1);
})->with(['central' => [['central']], 'returns' => [['return']], 'mixed' => [['central', 'return']]]);

it('posts an explicit financial-only return without creating stock or receipts', function (): void {
    $f = physicalReturnFixture(['return'], 'financial_only');
    app(SalesReturnService::class)->apply($f['doc'], $f['actor']->id);
    app(SalesReturnService::class)->apply($f['doc']->fresh(), $f['actor']->id);
    expect($f['doc']->fresh()->status)->toBe('applied')
        ->and(StockMovement::count())->toBe(0)->and(WarehouseInboundReceipt::count())->toBe(0)
        ->and(CustomerLedger::where('customer_id', $f['customer']->id)->count())->toBe(1);
});

it('requires a reason for financial-only finalization even for direct service calls', function (): void {
    $f = physicalReturnFixture(['return'], 'financial_only');
    $f['doc']->update(['financial_only_reason' => null]);
    app(SalesReturnService::class)->apply($f['doc'], $f['actor']->id);
})->throws(ValidationException::class);

it('links general stock movements to the receipt and shows the correct warehouse and variant', function (): void {
    $f = physicalReturnFixture();
    $f['actor']->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Owner','web'));
    test()->actingAs($f['actor']);
    app(SalesReturnService::class)->apply($f['doc'], $f['actor']->id);
    receivePhysicalReturn($f);
    $data = app(\App\Http\Controllers\StockMovementReportController::class)->index(\Illuminate\Http\Request::create('/movements'))->getData();
    $movement = $data['movements']->sole();
    expect($data['sourceLinks'][$movement->id])->toBe(route('warehouse.inbound.show',WarehouseInboundReceipt::sole()->id))
        ->and($movement->warehouse->id)->toBe($f['returns']->id)
        ->and($movement->variant->id)->toBe($f['doc']->items->sole()->product_variant_id);
});

it('accounts only for accepted quantities after partial or discrepant receiving', function (): void {
    $f = physicalReturnFixture();
    app(SalesReturnService::class)->apply($f['doc'], $f['actor']->id);
    receivePhysicalReturn($f, 2, $f['central']->id);
    expect((int) $f['doc']->fresh()->total_refund_amount)->toBe(200)
        ->and(WarehouseInboundReceipt::sole()->status)->toBe('discrepancy')
        ->and((int) StockMovement::sole()->quantity)->toBe(2)
        ->and((int) CustomerLedger::sole()->amount)->toBe(200);
});

it('rejects a mismatched receipt product before any stock or financial posting', function (): void {
    $f = physicalReturnFixture(['return', 'central']);
    app(SalesReturnService::class)->apply($f['doc'], $f['actor']->id);
    $items = WarehouseInboundReceipt::sole()->items;
    $items[0]->update(['product_id' => $items[1]->product_id, 'product_variant_id' => $items[1]->product_variant_id]);
    try { receivePhysicalReturn($f); test()->fail('Mismatched source was accepted'); } catch (ValidationException) {}
    expect(StockMovement::count())->toBe(0)->and(CustomerLedger::count())->toBe(0)
        ->and($f['doc']->fresh()->status)->toBe('pending_warehouse');
});

it('rejects duplicate receipt input IDs even through a direct service call', function (): void {
    $f = physicalReturnFixture();
    app(SalesReturnService::class)->apply($f['doc'], $f['actor']->id);
    $receipt = WarehouseInboundReceipt::sole();
    $row = ['id' => $receipt->items->sole()->id, 'accepted_quantity' => 3, 'received_warehouse_id' => $f['returns']->id];
    try { app(WarehouseInboundService::class)->receive($receipt, [$row, $row], $f['actor']->id); test()->fail('Duplicate IDs accepted'); } catch (ValidationException) {}
    expect(StockMovement::count())->toBe(0)->and(CustomerLedger::count())->toBe(0);
});

it('keeps a fully received physical return immutable even in the returns warehouse', function (): void {
    $f = physicalReturnFixture();
    app(SalesReturnService::class)->apply($f['doc'], $f['actor']->id);
    receivePhysicalReturn($f);
    expect(\App\Support\SalesReturnFinalization::isLocked($f['doc']->fresh()))->toBeTrue();
    \App\Support\SalesReturnFinalization::assertMutable($f['doc']->fresh());
})->throws(ValidationException::class);

it('defaults new service-created returns to physical and reuses an identical submission token', function (): void {
    $f = physicalReturnFixture();
    $item = $f['doc']->items->sole();
    $data = ['submission_token'=>(string)\Illuminate\Support\Str::uuid(), 'source_type'=>'sazeh_hesab',
        'customer_id'=>$f['customer']->id,'external_invoice_number'=>'QA-TOKEN','external_invoice_date'=>now()->toDateString(),
        'default_destination_warehouse_id'=>$f['returns']->id,'return_reason'=>'damaged_product',
        'items'=>[['item_source'=>'existing_product','product_variant_id'=>$item->product_variant_id,
            'destination_warehouse_id'=>$f['returns']->id,'item_condition'=>'damaged','return_quantity'=>3,'refund_unit_price'=>100,'sell_price'=>1000]]];
    $service=app(SalesReturnService::class);
    $doc=$service->createDraft($data,$f['actor']->id);
    $same=$service->createDraft($data,$f['actor']->id);
    expect($same->id)->toBe($doc->id)->and($doc->return_flow)->toBe('physical');
    $service->apply($doc,$f['actor']->id);
    $same=$service->createDraft($data,$f['actor']->id);
    $service->apply($same,$f['actor']->id);
    expect(WarehouseInboundReceipt::where('source_id',$doc->id)->count())->toBe(1);
    $data['items'][0]['return_quantity']=4;
    try {$service->createDraft($data,$f['actor']->id);test()->fail('A confirmed request was overwritten');}catch(ValidationException){}
    expect((int)$doc->fresh()->items->sole()->return_quantity)->toBe(3);
});
