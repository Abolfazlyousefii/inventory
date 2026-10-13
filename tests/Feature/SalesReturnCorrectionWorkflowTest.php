<?php

namespace Tests\Feature;

use App\Http\Controllers\SalesReturnCorrectionController;
use App\Http\Controllers\VoucherSalesReturnController;
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

    public function test_request_and_warehouse_approval_have_no_stock_or_financial_effect_then_only_delta_is_applied(): void
    {
        $doc = $this->applied();
        $original = $doc->items->first()->id;
        $service = app(SalesReturnCorrectionService::class);
        $request = $service->request($doc, $this->proposal($doc), 1, 'اصلاح تعداد');
        $this->assertSame(2, WarehouseStock::first()->quantity);
        $this->assertSame(200, $doc->fresh()->total_refund_amount);
        $this->assertSame(1, CustomerLedger::count());
        $service->approve($request, 'warehouse', 2, 'دریافت اختلاف بررسی شد');
        $this->assertSame(2, WarehouseStock::first()->quantity);
        $service->approve($request->fresh(), 'finance', 3, 'تأیید مبلغ اختلاف');
        $this->assertSame('applied', $request->fresh()->status);
        $this->assertSame(1, WarehouseStock::first()->quantity);
        $this->assertSame(100, $doc->fresh()->total_refund_amount);
        $this->assertSame($original, $doc->items()->first()->id);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(1, (int) StockMovement::first()->quantity);
        $this->assertSame('debit', CustomerLedger::latest('id')->first()->type);
        $this->assertSame(100, (int) CustomerLedger::latest('id')->first()->amount);
        $this->assertSame(2, (int) $request->fresh()->before_snapshot['items'][0]['return_quantity']);
        $service->approve($request->fresh(), 'finance', 3, 'تکرار تأیید');
        $this->assertSame(1, StockMovement::count());
    }

    public function test_requester_cannot_approve_and_finance_cannot_skip_warehouse(): void
    {
        $doc = $this->applied();
        $service = app(SalesReturnCorrectionService::class);
        $request = $service->request($doc, $this->proposal($doc), 1, 'اصلاح تعداد');
        foreach ([['warehouse', 1], ['finance', 3]] as [$stage,$actor]) {
            try {
                $service->approve($request, $stage, $actor, 'تأیید');
                $this->fail('Unauthorized sequence accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('correction', $e->errors());
            }
        }
        $this->assertSame(2, WarehouseStock::first()->quantity);
    }

    public function test_changed_base_and_insufficient_stock_roll_back_final_approval(): void
    {
        $doc = $this->applied();
        $service = app(SalesReturnCorrectionService::class);
        $request = $service->request($doc, $this->proposal($doc), 1, 'اصلاح تعداد');
        $service->approve($request, 'warehouse', 2, 'بررسی شد');
        WarehouseStock::first()->update(['quantity' => 0]);
        try {
            $service->approve($request->fresh(), 'finance', 3, 'تأیید');
            $this->fail('Stock shortage accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }
        $this->assertSame('pending_finance', $request->fresh()->status);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(2, $doc->fresh()->total_quantity);
        $doc->update(['description' => 'تغییر هم‌زمان']);
        try {
            $service->approve($request->fresh(), 'finance', 3, 'تأیید');
            $this->fail('Stale base accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        }
    }

    public function test_rejection_and_duplicate_pending_request_do_not_mutate_original(): void
    {
        $doc = $this->applied();
        $service = app(SalesReturnCorrectionService::class);
        $request = $service->request($doc, $this->proposal($doc), 1, 'اصلاح تعداد');
        try {
            $service->request($doc, $this->proposal($doc), 1, 'درخواست دوباره');
            $this->fail('Duplicate accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('correction', $e->errors());
        }
        $service->reject($request, 2, 'نیازی به اصلاح نیست');
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame(2, $doc->fresh()->total_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_direct_applied_edit_is_blocked_even_when_called_without_controller(): void
    {
        $doc = $this->applied();
        $this->expectException(ValidationException::class);
        app(SalesReturnAppliedAdjustmentService::class)->updateApplied($doc, $this->proposal($doc), 1, 'ویرایش مستقیم');
    }

    public function test_condition_change_moves_only_the_difference_between_warehouses(): void
    {
        $doc = $this->applied();
        $data = $this->proposal($doc, 2);
        $data['items'][0]['item_condition'] = 'damaged';
        $data['items'][0]['destination_warehouse_id'] = 2;
        $service = app(SalesReturnCorrectionService::class);
        $request = $service->request($doc, $data, 1, 'اصلاح سالم به معیوب');
        $service->approve($request, 'warehouse', 2, 'بررسی وضعیت کالا');
        $service->approve($request->fresh(), 'finance', 3, 'بدون اختلاف مبلغ');
        $this->assertSame(0, WarehouseStock::where('warehouse_id', 1)->first()->quantity);
        $this->assertSame(2, WarehouseStock::where('warehouse_id', 2)->first()->quantity);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame('damaged', $doc->items()->first()->item_condition);
    }

    public function test_increasing_quantity_adds_only_extra_stock_and_credit(): void
    {
        $doc = $this->applied();
        $service = app(SalesReturnCorrectionService::class);
        $request = $service->request($doc, $this->proposal($doc, 3), 1, 'اصلاح تعداد دریافت');
        $service->approve($request, 'warehouse', 2, 'یک عدد بیشتر دریافت شد');
        $service->approve($request->fresh(), 'finance', 3, 'مبلغ بررسی شد');
        $this->assertSame(3, WarehouseStock::first()->quantity);
        $this->assertSame(1, (int) StockMovement::first()->quantity);
        $this->assertSame('in', StockMovement::first()->type);
        $this->assertSame('credit', CustomerLedger::latest('id')->first()->type);
        $this->assertSame(100, (int) CustomerLedger::latest('id')->first()->amount);
        $this->assertSame(300, app(SalesReturnQueryService::class)->appliedHealthCheck($doc->fresh('items'))['ledger_amount']);
    }

    public function test_price_only_correction_creates_ledger_delta_without_stock_movement(): void
    {
        $doc = $this->applied();
        $data = $this->proposal($doc, 2);
        $data['items'][0]['refund_unit_price'] = 150;
        $service = app(SalesReturnCorrectionService::class);
        $request = $service->request($doc, $data, 1, 'اصلاح مبلغ واحد');
        $service->approve($request, 'warehouse', 2, 'تعداد ثابت است');
        $service->approve($request->fresh(), 'finance', 3, 'قیمت بررسی شد');
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(2, WarehouseStock::first()->quantity);
        $this->assertSame(100, (int) CustomerLedger::latest('id')->first()->amount);
    }

    public function test_internal_invoice_cap_is_rechecked_before_applying_and_cannot_be_silently_clamped(): void
    {
        $doc = $this->applied();
        $invoice = Invoice::create(['uuid' => 'INV-1', 'customer_id' => 1, 'status' => 'shipped', 'subtotal' => 500, 'total' => 500, 'discount_amount' => 0, 'invoice_discount_amount' => 0, 'shipping_price' => 0]);
        $sold = InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => 1, 'variant_id' => 1, 'quantity' => 5, 'price' => 100, 'line_total' => 500, 'line_discount_amount' => 0]);
        $doc->update(['source_type' => 'internal_invoice', 'invoice_id' => $invoice->id]);
        $doc->items()->first()->update(['invoice_item_id' => $sold->id, 'item_source' => 'invoice_item']);
        $doc = $doc->fresh('items');
        $data = $this->proposal($doc, 4);
        $data['items'][0]['item_source'] = 'invoice_item';
        $data['items'][0]['invoice_item_id'] = $sold->id;
        $service = app(SalesReturnCorrectionService::class);
        $request = $service->request($doc, $data, 1, 'اصلاح تعداد داخلی');
        $service->approve($request, 'warehouse', 2, 'بررسی شد');
        $other = SalesReturnDocument::create(['document_number' => '00002', 'source_type' => 'internal_invoice', 'status' => 'pending_warehouse', 'customer_id' => 1, 'invoice_id' => $invoice->id]);
        $other->items()->create(['invoice_item_id' => $sold->id, 'return_quantity' => 3, 'refund_amount' => 300]);
        try {
            $service->approve($request->fresh(), 'finance', 3, 'تأیید مبلغ');
            $this->fail('Return cap exceeded');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
        $this->assertSame(2, $doc->fresh()->total_quantity);
        $this->assertSame(2, WarehouseStock::first()->quantity);
    }

    public function test_applied_form_is_a_versioned_request_and_review_route_denies_warranty_approval(): void
    {
        $doc = $this->applied();
        Schema::create('permissions', function ($t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('key')->nullable();
            $t->string('guard_name')->nullable();
        });
        view()->share('errors', new ViewErrorBag);
        $html = app(VoucherSalesReturnController::class)->editApplied($doc)->render();
        $this->assertStringContainsString('درخواست اصلاح برگشت دریافت‌شده', $html);
        $this->assertStringContainsString('name="document_version"', $html);
        $this->assertStringContainsString('ثبت درخواست اصلاح', $html);
        file_put_contents(storage_path('app/purchase-returns-20261011/correction-form.html'), $html);
        $user = \Mockery::mock(User::class)->makePartial();
        $user->id = 1;
        $user->shouldReceive('isSuperAdmin')->andReturn(false);
        $user->shouldReceive('hasPermission')->andReturnUsing(fn ($p) => $p === 'page.sales.returns');
        $request = Request::create('/sales-return-corrections/1/warehouse', 'POST', ['note' => 'تأیید انبار']);
        $request->setUserResolver(fn () => $user);
        $correction = app(SalesReturnCorrectionService::class)->request($doc, $this->proposal($doc), 1, 'اصلاح تعداد');
        try {
            app(SalesReturnCorrectionController::class)->warehouse($request, $correction, app(SalesReturnCorrectionService::class));
            $this->fail('Warranty approval allowed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('pending_warehouse', $correction->fresh()->status);
    }

    public function test_received_receipt_and_its_item_links_are_preserved_and_health_uses_receipt_movements(): void
    {
        $doc = $this->applied();
        $item = $doc->items->first();
        $receipt = WarehouseInboundReceipt::create(['receipt_number' => 'WI-1', 'source_type' => 'sales_return', 'source_id' => $doc->id, 'source_document_number' => $doc->document_number, 'operation_key' => 'initial', 'status' => 'received', 'expected_quantity' => 2, 'accepted_quantity' => 2, 'reviewed_by' => 2, 'reviewed_at' => now()]);
        $received = $receipt->items()->create(['source_item_type' => SalesReturnDocumentItem::class, 'source_item_id' => $item->id, 'product_id' => 1, 'product_variant_id' => 1, 'suggested_warehouse_id' => 1, 'received_warehouse_id' => 1, 'expected_quantity' => 2, 'accepted_quantity' => 2]);
        StockMovement::create(['product_id' => 1, 'product_variant_id' => 1, 'warehouse_id' => 1, 'type' => 'in', 'quantity' => 2, 'reason' => 'sales_return', 'reference_type' => WarehouseInboundReceiptItem::class, 'reference_id' => $received->id]);
        $beforeReceipt = $receipt->fresh()->getAttributes();
        $beforeItem = $received->fresh()->getAttributes();
        $service = app(SalesReturnCorrectionService::class);
        $request = $service->request($doc, $this->proposal($doc), 1, 'اصلاح تعداد');
        $service->approve($request, 'warehouse', 2, 'بررسی شد');
        $service->approve($request->fresh(), 'finance', 3, 'تأیید مبلغ');
        $this->assertSame($beforeReceipt, $receipt->fresh()->getAttributes());
        $this->assertSame($beforeItem, $received->fresh()->getAttributes());
        $this->assertSame(1, WarehouseInboundReceipt::count());
        $this->assertTrue(app(SalesReturnQueryService::class)->appliedHealthCheck($doc->fresh('items'))['ok']);
    }

    public function test_review_queue_renders_request_details_and_stage_actions(): void
    {
        $doc = $this->applied();
        $service = app(SalesReturnCorrectionService::class);
        $correction = $service->request($doc, $this->proposal($doc), 1, 'اصلاح تعداد');
        Schema::create('permissions', function ($t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('key')->nullable();
            $t->string('guard_name')->nullable();
        });
        $user = \Mockery::mock(User::class)->makePartial();
        $user->id = 2;
        $user->name = 'انبار';
        $user->setRelation('roles', collect());
        $user->setRelation('permissions', collect());
        $user->shouldReceive('getRoleNames')->andReturn(collect());
        $user->shouldReceive('getAllPermissions')->andReturn(collect());
        $user->shouldReceive('hasRole')->andReturn(false);
        $user->shouldReceive('isSuperAdmin')->andReturn(true);
        $user->shouldReceive('can')->andReturn(true);
        auth()->setUser($user);
        view()->share('errors', new ViewErrorBag);
        $request = Request::create('/sales-return-corrections');
        $request->setUserResolver(fn () => $user);
        $html = app(SalesReturnCorrectionController::class)->index($request)->render();
        $this->assertStringContainsString('تأیید بررسی انبار', $html);
        $this->assertStringContainsString('پیشنهاد اصلاح', $html);
        $this->assertStringContainsString('انبار مرکزی', $html);
        file_put_contents(storage_path('app/purchase-returns-20261011/correction-review.html'), $html);
        $service->approve($correction, 'warehouse', 2, 'بررسی انبار');
        $request = Request::create('/sales-return-corrections?status=pending_finance');
        $request->setUserResolver(fn () => $user);
        $html = app(SalesReturnCorrectionController::class)->index($request)->render();
        $this->assertStringNotContainsString('تأیید مالی و اعمال اختلاف</button>', $html);
    }

    public function test_partial_stock_delta_is_rolled_back_if_a_later_warehouse_has_shortage(): void
    {
        $doc = $this->applied();
        $doc->items()->create(['item_source' => 'existing_product', 'product_id' => 1, 'product_variant_id' => 1, 'return_quantity' => 2, 'refund_unit_price' => 100, 'refund_amount' => 200, 'destination_warehouse_id' => 2, 'item_condition' => 'damaged', 'sort_order' => 2]);
        $doc->update(['total_quantity' => 4, 'total_refund_amount' => 400, 'items_count' => 2]);
        $doc = $doc->fresh('items');
        $data = $this->proposal($doc, 3);
        $data['items'][] = ['item_source' => 'existing_product', 'product_variant_id' => 1, 'return_quantity' => 1, 'item_condition' => 'damaged', 'destination_warehouse_id' => 2, 'refund_unit_price' => 100];
        $service = app(SalesReturnCorrectionService::class);
        $request = $service->request($doc, $data, 1, 'اصلاح ترکیب دریافت');
        $service->approve($request, 'warehouse', 2, 'بررسی شد');
        try {
            $service->approve($request->fresh(), 'finance', 3, 'تأیید مالی');
            $this->fail('Partial delta committed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }
        $this->assertSame(2, WarehouseStock::where('warehouse_id', 1)->first()->quantity);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame('pending_finance', $request->fresh()->status);
    }

    public function test_metadata_correction_has_no_stock_or_ledger_effect_and_approval_identity_and_stale_rejection_are_enforced(): void
    {
        $doc = $this->applied();
        $data = $this->proposal($doc, 2);
        $data['description'] = 'یادداشت اصلاح‌شده';
        $data['reference_number'] = 'REF-1';
        $service = app(SalesReturnCorrectionService::class);
        $request = $service->request($doc, $data, 1, 'اصلاح توضیحات');
        $service->approve($request, 'warehouse', 2, 'تعداد بدون تغییر');
        try {
            $service->approve($request->fresh(), 'finance', 2, 'تأیید مالی');
            $this->fail('Same reviewer accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('correction', $e->errors());
        }
        try {
            $service->reject($request, 2, 'رد درخواست', 'pending_warehouse');
            $this->fail('Stale stage accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('correction', $e->errors());
        }
        $service->approve($request->fresh(), 'finance', 3, 'توضیحات بررسی شد');
        $this->assertSame('یادداشت اصلاح‌شده', $doc->fresh()->description);
        $this->assertSame('REF-1', $doc->fresh()->reference_number);
        $this->assertSame(0,StockMovement::count());
        $this->assertSame(1,CustomerLedger::count());
    }
}
