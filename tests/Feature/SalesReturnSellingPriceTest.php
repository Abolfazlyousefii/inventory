<?php

namespace Tests\Feature;

use App\Http\Requests\StoreSalesReturnRequest;
use App\Models\CustomerLedger;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesReturnDocument;
use App\Models\StockMovement;
use App\Models\WarehouseInboundReceipt;
use App\Models\WarehouseStock;
use App\Services\SalesReturnService;
use App\Services\WarehouseInboundService;
use App\Support\InboundReviewVersion;
use Illuminate\Validation\ValidationException;

class SalesReturnSellingPriceTest extends PendingSalesReturnEditTest
{
    private function draft(int $refund = 100, int $sale = 350): SalesReturnDocument
    {
        return app(SalesReturnService::class)->createDraft([
            'source_type' => 'sazeh_hesab', 'customer_id' => 1,
            'external_invoice_number' => 'LEGACY', 'external_invoice_date' => '2026-10-01',
            'default_destination_warehouse_id' => 1, 'return_reason' => 'other', 'description' => 'Test',
            'items' => [['item_source' => 'existing_product', 'product_variant_id' => 1,
                'return_quantity' => 2, 'refund_unit_price' => $refund, 'sell_price' => $sale,
                'destination_warehouse_id' => 1, 'item_condition' => 'healthy']],
        ], 1);
    }

    public function test_price_proposal_waits_for_receipt_and_aligns_all_variants_without_changing_refund_or_purchase_price(): void
    {
        ProductVariant::create(['product_id' => 1, 'variant_name' => 'Second', 'variant_code' => 'SECOND', 'sell_price' => 0, 'buy_price' => 80, 'stock' => 0]);
        $doc = $this->draft();
        $this->assertSame(350, (int) $doc->items->first()->sell_price);
        $this->assertSame(0, (int) Product::find(1)->price);
        app(SalesReturnService::class)->apply($doc, 1);
        $this->assertSame(0, (int) Product::find(1)->price);
        $receipt = WarehouseInboundReceipt::where('source_id', $doc->id)->first()->load('items');
        $rows = $receipt->items->map(fn ($item) => ['id' => $item->id, 'accepted_quantity' => 2, 'received_warehouse_id' => 1])->all();
        app(WarehouseInboundService::class)->receive($receipt, $rows, 1, 'Partial receipt test', InboundReviewVersion::token($receipt));
        $this->assertSame(350, (int) Product::find(1)->uniform_sale_price);
        $this->assertEquals([350, 350], ProductVariant::orderBy('id')->pluck('sell_price')->map(fn ($p) => (int) $p)->all());
        $this->assertSame(80, (int) ProductVariant::where('variant_code', 'SECOND')->value('buy_price'));
        $this->assertSame(200, (int) CustomerLedger::where('type', 'credit')->sum('amount'));
        app(SalesReturnService::class)->apply($doc->fresh(), 1);
        $this->assertSame(1, CustomerLedger::count());
    }

    public function test_standalone_apply_rejects_incomplete_refund_without_queuing_stock(): void
    {
        $doc = $this->draft(0);
        $this->expectException(ValidationException::class);
        try {
            app(SalesReturnService::class)->apply($doc, 1);
        } finally {
            $this->assertSame('draft', $doc->fresh()->status);
            $this->assertSame(0, WarehouseInboundReceipt::count());
        }
    }

    public function test_a_new_catalog_price_while_waiting_is_preserved(): void
    {
        $doc = $this->draft();
        app(SalesReturnService::class)->apply($doc, 1);
        Product::find(1)->forceFill(['uniform_sale_price' => 500, 'price' => 500])->save();
        $receipt = WarehouseInboundReceipt::where('source_id', $doc->id)->first()->load('items');
        $rows = $receipt->items->map(fn ($item) => ['id' => $item->id, 'accepted_quantity' => 1, 'received_warehouse_id' => 1])->all();
        app(WarehouseInboundService::class)->receive($receipt, $rows, 1, 'Partial receipt test', InboundReviewVersion::token($receipt));
        $this->assertSame(500, (int) Product::find(1)->price);
        $this->assertSame(500, (int) ProductVariant::find(1)->sell_price);
        $this->assertSame(100, (int) CustomerLedger::where('type', 'credit')->sum('amount'));
    }

    public function test_rejected_receipt_never_sets_catalog_price_or_credit(): void
    {
        $doc = $this->draft();
        app(SalesReturnService::class)->apply($doc, 1);
        $receipt = WarehouseInboundReceipt::where('source_id', $doc->id)->first()->load('items');
        $rows = $receipt->items->map(fn ($item) => ['id' => $item->id, 'accepted_quantity' => 0, 'received_warehouse_id' => 1])->all();
        app(WarehouseInboundService::class)->receive($receipt, $rows, 1, 'No goods received', InboundReviewVersion::token($receipt));
        $this->assertSame(0, (int) Product::find(1)->price);
        $this->assertSame(0, CustomerLedger::count());
        $this->assertSame('cancelled', $doc->fresh()->status);
    }

    public function test_draft_allows_incomplete_prices_but_final_request_requires_refund(): void
    {
        $doc = $this->draft(0, 0);
        $payload = ['source_type' => 'sazeh_hesab', 'action' => 'draft', 'items' => [['item_source' => 'existing_product', 'product_variant_id' => 1, 'refund_unit_price' => 0]]];
        $request = StoreSalesReturnRequest::create('/', 'POST', $payload);
        $validator = validator()->make($payload, []);
        $request->withValidator($validator);
        $this->assertTrue($validator->passes());
        $request->merge(['action' => 'apply']);
        $validator = validator()->make($request->all(), []);
        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('items.0.refund_unit_price', $validator->errors()->messages());
        $this->assertSame('draft', $doc->status);
    }

    public function test_conflicting_proposals_for_one_product_are_rejected_before_stock_changes(): void
    {
        $doc = $this->draft();
        $row = $doc->items->first()->replicate();
        $row->sell_price = 450;
        $row->save();
        $this->expectException(ValidationException::class);
        try {
            app(SalesReturnService::class)->apply($doc->fresh(), 1);
        } finally {
            $this->assertSame(0, WarehouseInboundReceipt::count());
            $this->assertSame(0, (int) Product::find(1)->price);
        }
    }

    public function test_financial_failure_rolls_back_stock_catalog_price_and_receipt_together(): void
    {
        $doc = $this->draft();
        app(SalesReturnService::class)->apply($doc, 1);
        $receipt = WarehouseInboundReceipt::where('source_id', $doc->id)->first()->load('items');
        $rows = $receipt->items->map(fn ($item) => ['id' => $item->id, 'accepted_quantity' => 2, 'received_warehouse_id' => 1])->all();
        CustomerLedger::creating(fn () => throw new \RuntimeException('Injected financial failure'));
        try {
            app(WarehouseInboundService::class)->receive($receipt, $rows, 1, null, InboundReviewVersion::token($receipt));
            $this->fail('Expected rollback');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected financial failure', $error->getMessage());
        } finally {
            CustomerLedger::flushEventListeners();
        }
        $this->assertSame(0, (int) Product::find(1)->price);
        $this->assertSame(0, (int) ProductVariant::find(1)->stock);
        $this->assertSame(0, (int) WarehouseStock::sum('quantity'));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertSame('pending_warehouse', $doc->fresh()->status);
    }
}
