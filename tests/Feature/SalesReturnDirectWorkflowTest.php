<?php

namespace Tests\Feature;

use App\Http\Controllers\VoucherSalesReturnController;
use App\Http\Requests\UpdateAppliedSalesReturnRequest;
use App\Models\CustomerLedger;
use App\Models\SalesReturnCorrection;
use App\Models\SalesReturnDocument;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseInboundReceipt;
use App\Models\WarehouseStock;
use App\Services\SalesReturnCorrectionService;
use App\Services\SalesReturnQueryService;
use App\Services\SalesReturnService;
use App\Services\WarehouseInboundService;
use App\Support\InboundReviewVersion;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SalesReturnDirectWorkflowTest extends PendingSalesReturnEditTest
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_13_160000_create_sales_return_corrections_table.php'))->up();
        foreach ([1, 2] as $id) {
            User::create(['id' => $id, 'name' => 'Actor '.$id, 'password' => 'test']);
        }
    }

    private function document(bool $central = false): SalesReturnDocument
    {
        $doc = SalesReturnDocument::create(['document_number' => 'DIRECT-1', 'source_type' => 'sazeh_hesab', 'status' => 'applied', 'customer_id' => 1, 'external_invoice_number' => 'OLD-1', 'default_destination_warehouse_id' => $central ? 1 : 2, 'items_count' => 1, 'total_quantity' => 2, 'total_refund_amount' => 200, 'return_reason' => 'other']);
        $doc->items()->create(['item_source' => 'existing_product', 'product_id' => 1, 'product_variant_id' => 1, 'product_name_snapshot' => 'کالا', 'return_quantity' => 2, 'refund_unit_price' => 100, 'refund_amount' => 200, 'destination_warehouse_id' => $central ? 1 : 2, 'item_condition' => $central ? 'healthy' : 'damaged', 'sort_order' => 1]);
        CustomerLedger::create(['customer_id' => 1, 'reference_type' => SalesReturnDocument::class, 'reference_id' => $doc->id, 'type' => 'credit', 'amount' => 200]);
        if ($central) {
            WarehouseStock::create(['warehouse_id' => 1, 'product_id' => 1, 'product_variant_id' => 1, 'quantity' => 2]);
            StockMovement::create(['product_id' => 1, 'product_variant_id' => 1, 'warehouse_id' => 1, 'user_id' => 1, 'type' => 'in', 'reason' => 'return', 'quantity' => 2, 'stock_before' => 0, 'stock_after' => 2, 'reference_type' => SalesReturnDocument::class, 'reference_id' => $doc->id]);
        }

        return $doc->fresh('items');
    }

    private function payload(SalesReturnDocument $doc, int $quantity = 1, int $warehouse = 2): array
    {
        return ['document_version' => InboundReviewVersion::token($doc), 'source_type' => 'sazeh_hesab', 'customer_id' => 1, 'external_invoice_number' => 'OLD-1', 'default_destination_warehouse_id' => $warehouse, 'return_reason' => 'other', 'items' => [['item_source' => 'existing_product', 'product_variant_id' => 1, 'return_quantity' => $quantity, 'refund_unit_price' => 100, 'destination_warehouse_id' => $warehouse, 'item_condition' => $warehouse === 1 ? 'healthy' : 'damaged']]];
    }

    public function test_return_only_edit_changes_only_financial_difference_and_preserves_original_ids(): void
    {
        $doc = $this->document();
        $id = $doc->items->first()->id;
        $before = $doc->created_at;
        $service = app(SalesReturnCorrectionService::class);
        $service->updateDirect($doc, $this->payload($doc), 1, 'اصلاح تعداد');
        $this->assertSame(100, (int) $doc->fresh()->total_refund_amount);
        $this->assertSame($id, $doc->items()->first()->id);
        $this->assertEquals($before, $doc->fresh()->created_at);
        $this->assertSame(0, WarehouseStock::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(100, (int) CustomerLedger::where('type', 'debit')->sum('amount'));
        $this->assertSame(0, SalesReturnCorrection::whereIn('status', ['pending_warehouse', 'pending_finance'])->count());
    }

    public function test_return_only_void_is_idempotent_and_keeps_history(): void
    {
        $doc = $this->document();
        $version = InboundReviewVersion::token($doc);
        $service = app(SalesReturnCorrectionService::class);
        $service->voidDirect($doc, 1, 'ابطال سند', $version);
        $service->voidDirect($doc, 1, 'تکرار ابطال', $version);
        $this->assertSame('cancelled', $doc->fresh()->status);
        $this->assertSame(1, $doc->items()->count());
        $this->assertSame(200, (int) CustomerLedger::where('type', 'debit')->sum('amount'));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, SalesReturnCorrection::count());
    }

    public function test_warranty_cannot_edit_received_central_but_warehouse_applies_only_delta(): void
    {
        $doc = $this->document(true);
        $service = app(SalesReturnCorrectionService::class);
        $data = $this->payload($doc, 1, 1);
        try {
            $service->updateDirect($doc, $data, 1, 'تغییر غیرمجاز');
            $this->fail('Warranty changed central stock');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        }
        $service->updateDirect($doc, $data, 2, 'اصلاح دریافت', true);
        $this->assertSame(1, (int) WarehouseStock::first()->quantity);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(100, (int) CustomerLedger::where('type', 'debit')->sum('amount'));
        try {
            $service->updateDirect($doc, $data, 2, 'تکرار ذخیره', true);
            $this->fail('Stale edit accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        }
        $this->assertSame(2, StockMovement::count());
    }

    public function test_insufficient_central_stock_rolls_back_document_ledger_and_correction(): void
    {
        $doc = $this->document(true);
        WarehouseStock::first()->update(['quantity' => 0]);
        try {
            app(SalesReturnCorrectionService::class)->updateDirect($doc, $this->payload($doc, 1, 1), 2, 'اصلاح دریافت', true);
            $this->fail('Stock below zero accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }
        $this->assertSame(200, (int) $doc->fresh()->total_refund_amount);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
    }

    public function test_return_to_central_requeues_without_increasing_stock_or_leaving_old_credit(): void
    {
        $doc = $this->document();
        app(SalesReturnCorrectionService::class)->updateDirect($doc, $this->payload($doc, 2, 1), 1, 'تغییر به کالای سالم');
        $this->assertSame('pending_warehouse', $doc->fresh()->status);
        $this->assertSame(1, WarehouseInboundReceipt::where('status', 'pending')->count());
        $this->assertSame(0, WarehouseStock::count());
        $this->assertSame(200, (int) CustomerLedger::where('type', 'debit')->sum('amount'));
    }

    public function test_existing_pending_correction_is_preserved_and_blocks_direct_changes(): void
    {
        $doc = $this->document();
        $service = app(SalesReturnCorrectionService::class);
        $old = $service->request($doc, $this->payload($doc), 1, 'درخواست قبلی');
        try {
            $service->updateDirect($doc, $this->payload($doc), 1, 'اصلاح مستقیم');
            $this->fail('Pending correction bypassed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('correction', $e->errors());
        }
        $this->assertSame('pending_warehouse', $old->fresh()->status);
        $this->assertSame(1, CustomerLedger::count());
    }

    public function test_requeued_return_receiving_posts_new_credit_once_without_overwriting_old_credit(): void
    {
        $doc = $this->document();
        app(SalesReturnCorrectionService::class)->updateDirect($doc, $this->payload($doc, 2, 1), 1, 'انتقال به مرکزی');
        $receipt = WarehouseInboundReceipt::first()->load('items');
        $rows = $receipt->items->map(fn ($i) => ['id' => $i->id, 'accepted_quantity' => 2, 'received_warehouse_id' => 1])->all();
        $inbound = app(WarehouseInboundService::class);
        $inbound->receive($receipt, $rows, 2, 'دریافت واقعی', InboundReviewVersion::token($receipt));
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(200, (int) CustomerLedger::where('type', 'credit')->sum('amount') - (int) CustomerLedger::where('type', 'debit')->sum('amount'));
        $this->assertSame(2, CustomerLedger::where('type', 'credit')->count());
        $this->assertTrue(app(SalesReturnQueryService::class)->appliedHealthCheck($doc->fresh('items'))['ok']);
    }

    public function test_requeued_return_can_change_back_to_return_only_without_stock_or_duplicate_credit(): void
    {
        $doc = $this->document();
        app(SalesReturnCorrectionService::class)->updateDirect($doc, $this->payload($doc, 2, 1), 1, 'انتقال به مرکزی');
        $doc = $doc->fresh('items');
        $data = $this->payload($doc, 2, 2);
        $data['adjustment_reason'] = 'اصلاح به مرجوعی';
        app(SalesReturnService::class)->updatePending($doc, $data, 1);
        $this->assertSame('applied', $doc->fresh()->status);
        $this->assertSame(0, WarehouseStock::count());
        $this->assertSame(200, (int) CustomerLedger::where('type', 'credit')->sum('amount') - (int) CustomerLedger::where('type', 'debit')->sum('amount'));
        $this->assertTrue(app(SalesReturnQueryService::class)->appliedHealthCheck($doc->fresh('items'))['ok']);
    }

    public function test_pending_cancel_invalidates_receipt_and_never_changes_stock(): void
    {
        $doc = $this->document();
        app(SalesReturnCorrectionService::class)->updateDirect($doc, $this->payload($doc, 2, 1), 1, 'انتقال به مرکزی');
        $doc = $doc->fresh('items');
        $receipt = WarehouseInboundReceipt::first()->load('items');
        $version = InboundReviewVersion::token($receipt);
        app(SalesReturnService::class)->cancelPending($doc, 1, 'لغو قبل دریافت', InboundReviewVersion::token($doc));
        $this->assertSame('cancelled', $receipt->fresh()->status);
        try {
            app(WarehouseInboundService::class)->receive($receipt, $receipt->items->map(fn ($i) => ['id' => $i->id, 'accepted_quantity' => 2, 'received_warehouse_id' => 1])->all(), 2, 'تأیید نسخه قدیمی', $version);
            $this->fail('Cancelled receipt received');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('receipt', $e->errors());
        }
        $this->assertSame(0, WarehouseStock::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_financial_failure_rolls_back_central_stock_and_audit(): void
    {
        $doc = $this->document(true);
        CustomerLedger::creating(function () {
            throw new \RuntimeException('Injected ledger failure');
        });
        try {
            app(SalesReturnCorrectionService::class)->updateDirect($doc, $this->payload($doc, 1, 1), 2, 'اصلاح دریافت', true);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected ledger failure', $e->getMessage());
        } finally {
            CustomerLedger::flushEventListeners();
        }
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(200, (int) $doc->fresh()->total_refund_amount);
        $this->assertSame(0, SalesReturnCorrection::count());
        $this->assertSame(1, StockMovement::count());
    }

    public function test_central_edit_page_denies_warranty_even_if_the_return_page_is_allowed(): void
    {
        $doc = $this->document(true);
        $user = \Mockery::mock(User::class)->makePartial();
        $user->id = 1;
        $user->shouldReceive('isAdministrator')->andReturn(false);
        $user->shouldReceive('hasRole')->andReturn(false);
        $user->shouldReceive('hasPermission')->andReturnUsing(fn ($p) => in_array($p, ['page.sales.returns', 'sales_returns.edit_applied'], true));
        auth()->setUser($user);
        try {
            app(VoucherSalesReturnController::class)->editApplied($doc);
            $this->fail('Warranty opened central editor');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_reclassification_after_central_receiving_never_unlocks_warranty_editing(): void
    {
        $doc = $this->document(true);
        $service = app(SalesReturnCorrectionService::class);
        $service->updateDirect($doc, $this->payload($doc, 2, 2), 2, 'انتقال دریافت به مرجوعی', true);
        $this->assertSame(0, (int) WarehouseStock::where('warehouse_id', 1)->value('quantity'));
        $this->assertSame(2, (int) WarehouseStock::where('warehouse_id', 2)->value('quantity'));
        $doc = $doc->fresh('items');
        $this->assertTrue($service->requiresWarehouse($doc));
        try {
            $service->updateDirect($doc, $this->payload($doc, 1, 2), 1, 'ویرایش گارانتی');
            $this->fail('Central history protection bypassed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        }
        $service->voidDirect($doc, 2, 'ابطال دریافت', InboundReviewVersion::token($doc), true);
        $this->assertSame(0, (int) WarehouseStock::where('warehouse_id', 2)->value('quantity'));
        $this->assertSame(0, (int) CustomerLedger::where('type', 'credit')->sum('amount') - (int) CustomerLedger::where('type', 'debit')->sum('amount'));
    }

    public function test_price_only_edit_does_not_change_central_stock_or_add_stock_movements(): void
    {
        $doc = $this->document(true);
        $data = $this->payload($doc, 2, 1);
        $data['items'][0]['refund_unit_price'] = 150;
        app(SalesReturnCorrectionService::class)->updateDirect($doc, $data, 2, 'اصلاح مبلغ دریافت', true);
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(300, (int) $doc->fresh()->total_refund_amount);
    }

    public function test_applied_request_keeps_legacy_null_policy_and_cannot_change_source_customer(): void
    {
        $doc = $this->document();
        $doc->update(['return_reason' => null, 'commission_effect_type' => null]);
        $request = UpdateAppliedSalesReturnRequest::create('/return/1', 'PATCH', ['customer_id' => 999, 'return_reason' => 'healthy_return', 'commission_effect_type' => 'commercial']);
        $route = new Route(['PATCH'], '/return/{document}', fn () => null);
        $route->bind($request);
        $route->setParameter('document', $doc);
        $request->setRouteResolver(fn () => $route);
        (new \ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
        $this->assertSame(1, (int) $request->input('customer_id'));
        $this->assertNull($request->input('return_reason'));
        $this->assertNull($request->input('commission_effect_type'));
        $this->assertContains('nullable', $request->rules()['return_reason']);
    }

    public function test_legacy_financial_mismatch_cannot_be_silently_rewritten(): void
    {
        $doc = $this->document();
        CustomerLedger::first()->update(['amount' => 150]);
        try {
            app(SalesReturnCorrectionService::class)->updateDirect($doc, $this->payload($doc), 1, 'اصلاح مرجوعی');
            $this->fail('Broken old ledger accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        }
        $this->assertSame(200, (int) $doc->fresh()->total_refund_amount);
        $this->assertSame(1, CustomerLedger::count());
    }
}
