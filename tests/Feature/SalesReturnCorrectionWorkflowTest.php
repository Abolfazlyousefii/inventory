<?php

namespace Tests\Feature;

use App\Http\Controllers\SalesReturnCorrectionController;
use App\Http\Controllers\VoucherSalesReturnController;
use App\Http\Requests\UpdateSalesReturnRequest;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseInboundReceipt;
use App\Models\WarehouseInboundReceiptItem;
use App\Models\WarehouseStock;
use App\Services\SalesReturnAppliedAdjustmentService;
use App\Services\SalesReturnCorrectionService;
use App\Services\SalesReturnQueryService;
use App\Support\InboundReviewVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SalesReturnCorrectionWorkflowTest extends PendingSalesReturnEditTest
{
    private function applied(): SalesReturnDocument
    {
        foreach ([1, 2, 3] as $id) {
            User::create(['id' => $id, 'name' => 'Reviewer '.$id, 'email' => 'review'.$id.'@example.test', 'password' => 'test']);
        }
        (require base_path('database/migrations/2026_10_13_160000_create_sales_return_corrections_table.php'))->up();
        $doc = SalesReturnDocument::create(['document_number' => '00001', 'source_type' => 'sazeh_hesab', 'status' => 'applied', 'customer_id' => 1, 'external_invoice_number' => 'OLD-1', 'default_destination_warehouse_id' => 1, 'items_count' => 1, 'total_quantity' => 2, 'total_refund_amount' => 200, 'return_reason' => 'healthy_return']);
        $doc->items()->create(['item_source' => 'existing_product', 'product_id' => 1, 'product_variant_id' => 1, 'product_name_snapshot' => 'کالا', 'return_quantity' => 2, 'refund_unit_price' => 100, 'refund_amount' => 200, 'destination_warehouse_id' => 1, 'item_condition' => 'healthy', 'sort_order' => 1]);
        WarehouseStock::create(['warehouse_id' => 1, 'product_id' => 1, 'product_variant_id' => 1, 'quantity' => 2]);
        CustomerLedger::create(['customer_id' => 1, 'reference_type' => SalesReturnDocument::class, 'reference_id' => $doc->id, 'type' => 'credit', 'amount' => 200]);

        return $doc->fresh('items');
    }

    private function proposal(SalesReturnDocument $doc, int $quantity = 1): array
    {
        return ['document_version' => InboundReviewVersion::token($doc), 'source_type' => 'sazeh_hesab', 'customer_id' => 1, 'external_invoice_number' => 'OLD-1', 'default_destination_warehouse_id' => 1, 'return_reason' => 'healthy_return', 'items' => [['item_source' => 'existing_product', 'product_variant_id' => 1, 'return_quantity' => $quantity, 'item_condition' => 'healthy', 'destination_warehouse_id' => 1, 'refund_unit_price' => 100]]];
    }


    private function oldPending(SalesReturnDocument $document, string $status = 'pending_warehouse'): \App\Models\SalesReturnCorrection
    {
        $snapshot = [
            'document' => $document->getAttributes(),
            'items' => $document->items->map->getAttributes()->all(),
            'total_quantity' => $document->total_quantity,
            'total_refund_amount' => $document->total_refund_amount,
        ];

        return \App\Models\SalesReturnCorrection::create([
            'document_id' => $document->id,
            'base_version' => InboundReviewVersion::token($document),
            'payload' => $this->proposal($document),
            'before_snapshot' => $snapshot,
            'proposed_snapshot' => $snapshot,
            'reason' => 'درخواست قدیمی پیش از قفل',
            'requested_by' => 1,
            'status' => $status,
            'warehouse_approved_by' => $status === 'pending_finance' ? 2 : null,
        ]);
    }

    private function expectFinalized(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An immutable central return accepted a correction.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
            $this->assertStringContainsString('نهایی', $e->errors()['document'][0]);
        }
    }

    public function test_request_for_received_central_is_rejected_without_stock_or_ledger_changes(): void
    {
        $doc = $this->applied();
        $this->expectFinalized(fn () => app(SalesReturnCorrectionService::class)->request(
            $doc, $this->proposal($doc), 1, 'اصلاح تعداد'
        ));
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(200, (int) $doc->fresh()->total_refund_amount);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, \App\Models\SalesReturnCorrection::count());
    }

    public function test_existing_warehouse_review_request_cannot_be_approved_after_finalization(): void
    {
        $doc = $this->applied();
        $request = $this->oldPending($doc);
        $this->expectFinalized(fn () => app(SalesReturnCorrectionService::class)->approve(
            $request, 'warehouse', 2, 'تأیید درخواست قدیمی'
        ));
        $this->assertSame('pending_warehouse', $request->fresh()->status);
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(1, CustomerLedger::count());
    }

    public function test_existing_finance_review_request_cannot_apply_stock_deltas(): void
    {
        $doc = $this->applied();
        $request = $this->oldPending($doc, 'pending_finance');
        $this->expectFinalized(fn () => app(SalesReturnCorrectionService::class)->approve(
            $request, 'finance', 3, 'اعمال اصلاح قدیمی'
        ));
        $this->assertSame('pending_finance', $request->fresh()->status);
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_old_pending_request_can_be_rejected_without_mutating_finalized_document(): void
    {
        $doc = $this->applied();
        $request = $this->oldPending($doc);
        app(SalesReturnCorrectionService::class)->reject($request, 2, 'قفل پس از دریافت مرکزی');
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame('applied', $doc->fresh()->status);
        $this->assertSame(200, (int) $doc->fresh()->total_refund_amount);
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(1, CustomerLedger::count());
    }

    public function test_price_only_increase_and_warehouse_change_requests_are_all_locked(): void
    {
        $doc = $this->applied();
        $service = app(SalesReturnCorrectionService::class);
        $price = $this->proposal($doc, 2);
        $price['items'][0]['refund_unit_price'] = 150;
        $increase = $this->proposal($doc, 3);
        $destination = $this->proposal($doc, 2);
        $destination['items'][0]['item_condition'] = 'damaged';
        $destination['items'][0]['destination_warehouse_id'] = 2;
        foreach ([$price, $increase, $destination] as $proposal) {
            $this->expectFinalized(fn () => $service->request($doc, $proposal, 1, 'اصلاح پس از دریافت'));
        }
        $this->assertSame(0, \App\Models\SalesReturnCorrection::count());
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_received_receipt_and_stock_links_cannot_be_rewritten_by_corrections(): void
    {
        $doc = $this->applied();
        $item = $doc->items->first();
        $receipt = WarehouseInboundReceipt::create([
            'receipt_number' => 'WI-1', 'source_type' => 'sales_return',
            'source_id' => $doc->id, 'source_document_number' => $doc->document_number,
            'operation_key' => 'initial', 'status' => 'received',
            'expected_quantity' => 2, 'accepted_quantity' => 2,
            'reviewed_by' => 2, 'reviewed_at' => now(),
        ]);
        $received = $receipt->items()->create([
            'source_item_type' => SalesReturnDocumentItem::class, 'source_item_id' => $item->id,
            'product_id' => 1, 'product_variant_id' => 1,
            'suggested_warehouse_id' => 1, 'received_warehouse_id' => 1,
            'expected_quantity' => 2, 'accepted_quantity' => 2,
        ]);
        $beforeReceipt = $receipt->fresh()->getAttributes();
        $beforeItem = $received->fresh()->getAttributes();
        $this->expectFinalized(fn () => app(SalesReturnCorrectionService::class)->request(
            $doc, $this->proposal($doc), 1, 'اصلاح رسید'
        ));
        $this->assertSame($beforeReceipt, $receipt->fresh()->getAttributes());
        $this->assertSame($beforeItem, $received->fresh()->getAttributes());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_direct_legacy_adjustment_endpoint_remains_disabled(): void
    {
        $doc = $this->applied();
        $this->expectException(ValidationException::class);
        app(SalesReturnAppliedAdjustmentService::class)->updateApplied($doc, $this->proposal($doc), 1, 'ویرایش مستقیم');
    }

    public function test_confirmed_central_edit_page_is_denied_even_to_warehouse_manager(): void
    {
        $doc = $this->applied();
        $warehouse = \Mockery::mock(User::class)->makePartial();
        $warehouse->id = 2;
        $warehouse->shouldReceive('isAdministrator')->andReturn(false);
        $warehouse->shouldReceive('hasPermission')->andReturnUsing(fn ($p) => $p === 'page.warehouse.inbound_queue');
        auth()->setUser($warehouse);
        try {
            app(VoucherSalesReturnController::class)->editApplied($doc);
            $this->fail('Warehouse manager opened confirmed central editor.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_pending_edit_reason_validation_is_in_persian(): void
    {
        $request = new UpdateSalesReturnRequest;
        $validator = app('validator')->make(['adjustment_reason' => ''], ['adjustment_reason' => ['required']], $request->messages());
        $this->assertStringContainsString('دلیل اصلاح', $validator->errors()->first('adjustment_reason'));
    }
}
