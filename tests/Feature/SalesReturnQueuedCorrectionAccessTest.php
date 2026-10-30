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

class SalesReturnQueuedCorrectionAccessTest extends PendingSalesReturnEditTest
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

    private function returnsUser(): User
    {
        $user = \Mockery::mock(User::class)->makePartial();
        $user->id = 1;
        $user->shouldReceive('isAdministrator', 'isSuperAdmin', 'hasRole', 'can')->andReturn(false);
        $user->shouldReceive('hasPermission')->andReturnUsing(fn ($permission) => in_array($permission,
            ['page.sales.returns', 'sales_returns.edit_applied', 'sales_returns.void_applied'], true));
        auth()->setUser($user);
        return $user;
    }

    private function request(string $class, array $payload, string $method = 'PATCH')
    {
        $request = $class::create('/test-sales-return', $method, $payload);
        $request->setUserResolver(fn () => auth()->user());
        if ($request instanceof \Illuminate\Foundation\Http\FormRequest) {
            // Exercise the real controller/service authorization seam with an
            // already valid form, as middleware would provide in production.
            $request->setValidator(app('validator')->make($payload, array_fill_keys(array_keys($payload), ['present'])));
        }
        return $request;
    }

    private function effects(): array
    {
        return collect(['sales_return_documents', 'sales_return_document_items', 'warehouse_stocks', 'stock_movements', 'customer_ledgers'])
            ->mapWithKeys(fn ($table) => [$table=>\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_returns_access_can_open_queued_editor_for_applied_central_document(): void
    {
        $document = $this->document(true);
        $this->returnsUser();
        $before = $this->effects();
        $view = app(VoucherSalesReturnController::class)->editApplied($document);
        $this->assertSame('vouchers.return-from-sale.create', $view->name());
        $this->assertTrue($view->getData()['isAppliedEdit']);
        $this->assertSame($before, $this->effects());
    }

    public function test_returns_access_central_update_queues_request_without_applying_stock_or_customer_ledger(): void
    {
        $document = $this->document(true);
        $this->returnsUser();
        $before = $this->effects();
        $data = $this->payload($document, 1, 1) + ['adjustment_reason'=>'اصلاح تعداد برای بررسی انبار'];
        $request = $this->request(UpdateAppliedSalesReturnRequest::class, $data);
        $request->headers->set('Accept', 'application/json');
        $response = app(VoucherSalesReturnController::class)->updateApplied($request, $document);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith(route('sales-return-corrections.index'), $response->getData(true)['redirect_url']);
        $correction = SalesReturnCorrection::sole();
        $this->assertSame('pending_warehouse', $correction->status);
        $this->assertSame(1, (int) $correction->requested_by);
        $this->assertSame($document->id, (int) $correction->document_id);
        $this->assertSame($before, $this->effects());
    }

    public function test_returns_access_central_void_queues_explicit_cancellation_instead_of_direct_reversal(): void
    {
        $document = $this->document(true);
        $this->returnsUser();
        $before = $this->effects();
        $request = $this->request(\App\Http\Requests\VoidAppliedSalesReturnRequest::class, [
            'reason'=>'درخواست ابطال سند مرکزی', 'document_version'=>InboundReviewVersion::token($document),
        ], 'POST');
        $response = app(VoucherSalesReturnController::class)->voidApplied($request, $document);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith(route('sales-return-corrections.index'), $response->getTargetUrl());
        $correction = SalesReturnCorrection::sole();
        $this->assertSame('pending_warehouse', $correction->status);
        $this->assertTrue($correction->payload['void_document']);
        $this->assertSame($before, $this->effects());
    }

    public function test_returns_access_return_warehouse_update_and_void_still_apply_directly(): void
    {
        $document = $this->document(false);
        $this->returnsUser();
        $data = $this->payload($document, 1, 2) + ['adjustment_reason'=>'اصلاح مستقیم مرجوعی'];
        app(VoucherSalesReturnController::class)->updateApplied($this->request(UpdateAppliedSalesReturnRequest::class, $data), $document);
        $this->assertSame(1, (int) $document->fresh()->total_quantity);
        $this->assertSame(100, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(0, SalesReturnCorrection::whereIn('status', ['pending_warehouse', 'pending_finance'])->count());
        $request = $this->request(\App\Http\Requests\VoidAppliedSalesReturnRequest::class, [
            'reason'=>'ابطال مستقیم مرجوعی', 'document_version'=>InboundReviewVersion::token($document->fresh('items')),
        ], 'POST');
        app(VoucherSalesReturnController::class)->voidApplied($request, $document->fresh('items'));
        $this->assertSame('cancelled', $document->fresh()->status);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, WarehouseStock::count());
    }

    public function test_returns_access_cannot_approve_queued_correction_as_warehouse_or_finance(): void
    {
        $document = $this->document(true);
        $correction = app(SalesReturnCorrectionService::class)->request($document, $this->payload($document, 1, 1), 1, 'درخواست بررسی انبار');
        $this->returnsUser();
        $before = $this->effects();
        $controller = app(\App\Http\Controllers\SalesReturnCorrectionController::class);
        foreach (['warehouse', 'finance'] as $method) {
            $request = $this->request(\Illuminate\Http\Request::class, ['note'=>'تلاش تأیید غیرمجاز'], 'POST');
            try {
                $controller->$method($request, $correction, app(SalesReturnCorrectionService::class));
                $this->fail('Returns user approved a protected correction');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertSame('pending_warehouse', $correction->fresh()->status);
        $this->assertSame($before, $this->effects());
    }

    public function test_queued_void_applies_reversal_only_after_warehouse_and_different_finance_actor_approve(): void
    {
        $document = $this->document(true);
        User::create(['id'=>3, 'name'=>'Finance actor', 'password'=>'test']);
        $before = $this->effects();
        $service = app(SalesReturnCorrectionService::class);
        $correction = $service->requestVoid($document, 1, 'درخواست ابطال سند دریافت‌شده', InboundReviewVersion::token($document));
        $this->assertSame('pending_warehouse', $correction->status);
        $this->assertTrue($correction->payload['void_document']);
        $this->assertSame($before, $this->effects());
        $service->approve($correction, 'warehouse', 2, 'دریافت و درخواست ابطال بررسی شد');
        $this->assertSame('pending_finance', $correction->fresh()->status);
        $this->assertSame($before, $this->effects());
        $service->approve($correction->fresh(), 'finance', 3, 'اثر حساب و ابطال تأیید شد');
        $this->assertSame('applied', $correction->fresh()->status);
        $this->assertSame('cancelled', $document->fresh()->status);
        $this->assertSame(2, (int) $correction->fresh()->warehouse_approved_by);
        $this->assertSame(3, (int) $correction->fresh()->finance_approved_by);
        $this->assertSame(0, (int) WarehouseStock::where('warehouse_id', 1)->value('quantity'));
        $this->assertSame(1, $document->items()->count());
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(2, (int) StockMovement::where('type', 'out')->sum('quantity'));
        $this->assertSame(200, (int) CustomerLedger::where('type', 'credit')->sum('amount'));
        $this->assertSame(200, (int) CustomerLedger::where('type', 'debit')->sum('amount'));
    }

    public function test_queued_void_rejects_duplicate_pending_request_without_altering_document_or_queue(): void
    {
        $document = $this->document(true);
        $service = app(SalesReturnCorrectionService::class);
        $version = InboundReviewVersion::token($document);
        $correction = $service->requestVoid($document, 1, 'درخواست نخست ابطال', $version);
        $before = $this->effects();
        try {
            $service->requestVoid($document, 1, 'تکرار درخواست ابطال', $version);
            $this->fail('Duplicate pending cancellation accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('correction', $error->errors());
        }
        $this->assertSame(1, SalesReturnCorrection::count());
        $this->assertSame('pending_warehouse', $correction->fresh()->status);
        $this->assertSame($before, $this->effects());
    }

    public function test_queued_void_stale_document_version_blocks_warehouse_approval_without_effects(): void
    {
        $document = $this->document(true);
        $service = app(SalesReturnCorrectionService::class);
        $correction = $service->requestVoid($document, 1, 'درخواست ابطال پیش از تغییر سند', InboundReviewVersion::token($document));
        // The underlying document changed after the request was reviewed.
        $document->items()->first()->update(['product_name_snapshot'=>'نام اصلاح‌شده پس از درخواست']);
        $before = $this->effects();
        try {
            $service->approve($correction, 'warehouse', 2, 'تأیید درخواست قدیمی');
            $this->fail('Stale cancellation request approved');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('document', $error->errors());
        }
        $this->assertSame('pending_warehouse', $correction->fresh()->status);
        $this->assertNull($correction->fresh()->warehouse_approved_at);
        $this->assertSame($before, $this->effects());
    }

    public function test_queued_void_insufficient_stock_rolls_back_finance_document_ledger_and_movements(): void
    {
        $document = $this->document(true);
        User::create(['id'=>3, 'name'=>'Finance actor', 'password'=>'test']);
        $service = app(SalesReturnCorrectionService::class);
        $correction = $service->requestVoid($document, 1, 'درخواست ابطال دریافت', InboundReviewVersion::token($document));
        $service->approve($correction, 'warehouse', 2, 'تأیید بررسی انبار');
        WarehouseStock::first()->update(['quantity'=>1]);
        $before = $this->effects();
        $queueBefore = SalesReturnCorrection::orderBy('id')->get()->toJson();
        try {
            $service->approve($correction->fresh(), 'finance', 3, 'تأیید مالی ابطال');
            $this->fail('Cancellation removed more stock than available');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('stock', $error->errors());
        }
        $this->assertSame($before, $this->effects());
        $this->assertSame($queueBefore, SalesReturnCorrection::orderBy('id')->get()->toJson());
        $this->assertSame('pending_finance', $correction->fresh()->status);
        $this->assertSame('applied', $document->fresh()->status);
    }

    public function test_queued_void_repeated_finance_approval_after_success_is_idempotent(): void
    {
        $document = $this->document(true);
        User::create(['id'=>3, 'name'=>'Finance actor', 'password'=>'test']);
        $service = app(SalesReturnCorrectionService::class);
        $correction = $service->requestVoid($document, 1, 'درخواست ابطال دریافت', InboundReviewVersion::token($document));
        $service->approve($correction, 'warehouse', 2, 'تأیید بررسی انبار');
        $service->approve($correction->fresh(), 'finance', 3, 'تأیید مالی ابطال');
        $before = $this->effects();
        $queueBefore = SalesReturnCorrection::orderBy('id')->get()->toJson();
        $applied = $service->approve($correction->fresh(), 'finance', 3, 'تکرار پس از گم‌شدن پاسخ');
        $this->assertSame('applied', $applied->status);
        $this->assertSame($before, $this->effects());
        $this->assertSame($queueBefore, SalesReturnCorrection::orderBy('id')->get()->toJson());
    }
}

