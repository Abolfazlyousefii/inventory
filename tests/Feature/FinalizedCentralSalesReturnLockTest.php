<?php

namespace Tests\Feature;

use App\Http\Controllers\VoucherSalesReturnController;
use App\Models\Warehouse;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Commissions\CommissionReturnSyncOutboxService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use App\Models\CustomerLedger;
use App\Models\SalesReturnCorrection;
use App\Models\SalesReturnDocument;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseInboundReceipt;
use App\Services\SalesReturnCorrectionService;
use App\Services\SalesReturnReportService;
use App\Support\InboundReviewVersion;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinalizedCentralSalesReturnLockTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('app/purchase-returns-20261011'));
        Http::fake();
        Schema::create('site_stock_sync_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->default(0);
            $table->string('origin', 16)->default('inventory');
            $table->dateTime('created_at');
            $table->dateTime('available_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('claimed_by', 36)->nullable();
            $table->dateTime('locked_until')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->index(
                ['available_at', 'id'],
                'site_stock_outbox_due_idx'
            );
        });
        $models = ['SalesReturnDocument', 'SalesReturnDocumentItem', 'SalesReturnDocumentRevision', 'WarehouseInboundReceipt', 'WarehouseInboundReceiptItem', 'Customer', 'CustomerLedger', 'ActivityLog', 'StockMovement', 'Warehouse', 'WarehouseStock', 'Product', 'ProductVariant', 'Category', 'Invoice', 'InvoiceItem', 'WarehouseTransfer', 'WarehouseTransferItem', 'Color', 'ModelList', 'User'];
        foreach ($models as $name) {
            $class = 'App\\Models\\'.$name;
            $m = new $class;
            $table = $m->getTable();
            if (Schema::hasTable($table)) {
                continue;
            }$fields = array_unique(array_merge($m->getFillable(), ['created_at', 'updated_at', 'deleted_at']));
            Schema::create($table, function (Blueprint $t) use ($fields) {
                $t->id();
                foreach ($fields as $f) {
                    if ($f !== 'id') {
                        $t->text($f)->nullable();
                    }
                }
            });
        }
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->id();
            $t->string('type')->unique();
            $t->integer('last_number')->default(0);
            $t->timestamps();
        });
        $this->mock(CommissionReturnSyncOutboxService::class, fn ($m) => $m->shouldReceive('stage')->andReturnNull());
        Warehouse::create(['id' => 1, 'name' => 'انبار مرکزی', 'type' => 'central', 'is_active' => true]);
        Warehouse::create(['id' => 2, 'name' => 'مرجوعی', 'type' => 'return', 'is_active' => true]);
        Customer::create(['name' => 'مشتری نمونه', 'mobile' => '09121111111', 'opening_balance' => 0]);
        $p = Product::create(['name' => 'کالای نمونه', 'stock' => 0, 'reserved' => 0]);
        ProductVariant::create(['product_id' => $p->id, 'variant_name' => 'مشکی', 'variant_code' => '123', 'stock' => 0, 'reserved' => 0]);
        (require database_path('migrations/2026_10_13_160000_create_sales_return_corrections_table.php'))->up();
        foreach ([1, 2, 3] as $id) {
            User::create(['id' => $id, 'name' => 'Reviewer '.$id, 'password' => 'test']);
        }
    }
    private function document(bool $central = true): SalesReturnDocument
    {
        $warehouse = $central ? 1 : 2;
        $document = SalesReturnDocument::create([
            'document_number' => 'LOCK-1',
            'source_type' => 'sazeh_hesab',
            'status' => 'applied',
            'customer_id' => 1,
            'external_invoice_number' => 'REF-1',
            'default_destination_warehouse_id' => $warehouse,
            'total_quantity' => 2,
            'items_count' => 1,
            'total_refund_amount' => 200,
            'return_reason' => 'other',
        ]);
        $document->items()->create([
            'item_source' => 'existing_product',
            'product_id' => 1,
            'product_variant_id' => 1,
            'product_name_snapshot' => 'کالا',
            'return_quantity' => 2,
            'refund_unit_price' => 100,
            'refund_amount' => 200,
            'destination_warehouse_id' => $warehouse,
            'item_condition' => $central ? 'healthy' : 'damaged',
            'sort_order' => 1,
        ]);
        CustomerLedger::create([
            'customer_id' => 1, 'reference_type' => SalesReturnDocument::class,
            'reference_id' => $document->id, 'type' => 'credit', 'amount' => 200,
        ]);
        if ($central) {
            WarehouseStock::create([
                'warehouse_id' => 1, 'product_id' => 1,
                'product_variant_id' => 1, 'quantity' => 2,
            ]);
            StockMovement::create([
                'product_id' => 1, 'product_variant_id' => 1,
                'warehouse_id' => 1, 'user_id' => 2,
                'type' => 'in', 'reason' => 'return',
                'quantity' => 2, 'stock_before' => 0, 'stock_after' => 2,
                'reference_type' => SalesReturnDocument::class,
                'reference_id' => $document->id,
            ]);
        }

        return $document->fresh('items');
    }

    private function payload(SalesReturnDocument $document, int $quantity = 1): array
    {
        $warehouse = (int) $document->items->first()->destination_warehouse_id;

        return [
            'document_version' => InboundReviewVersion::token($document),
            'source_type' => 'sazeh_hesab',
            'customer_id' => 1,
            'external_invoice_number' => 'REF-1',
            'return_reason' => 'other',
            'default_destination_warehouse_id' => $warehouse,
            'items' => [[
                'item_source' => 'existing_product',
                'product_variant_id' => 1,
                'return_quantity' => $quantity,
                'refund_unit_price' => 100,
                'destination_warehouse_id' => $warehouse,
                'item_condition' => $warehouse === 1 ? 'healthy' : 'damaged',
            ]],
        ];
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Confirmed central return was modified.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
            $this->assertStringContainsString('نهایی', $exception->errors()['document'][0]);
        }
    }

    public function test_confirmed_central_cannot_be_directly_edited_even_with_warehouse_mode(): void
    {
        $document = $this->document();
        $this->assertDenied(fn () => app(SalesReturnCorrectionService::class)->updateDirect(
            $document, $this->payload($document), 2, 'اصلاح پس از دریافت', true
        ));
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
    }

    public function test_confirmed_central_cannot_be_voided_even_with_warehouse_mode(): void
    {
        $document = $this->document();
        $this->assertDenied(fn () => app(SalesReturnCorrectionService::class)->voidDirect(
            $document, 2, 'ابطال پس از دریافت', InboundReviewVersion::token($document), true
        ));
        $this->assertSame('applied', $document->fresh()->status);
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(1, CustomerLedger::count());
    }

    public function test_confirmed_central_cannot_accept_new_correction_request(): void
    {
        $document = $this->document();
        $this->assertDenied(fn () => app(SalesReturnCorrectionService::class)->request(
            $document, $this->payload($document), 1, 'درخواست تغییر'
        ));
        $this->assertSame(0, SalesReturnCorrection::count());
    }

    public function test_admin_sees_locked_list_row_without_edit_and_void_actions(): void
    {
        $document = $this->document();
        $admin = \Mockery::mock(User::class)->makePartial();
        $admin->id = 3;
        $admin->shouldReceive('isAdministrator')->andReturn(true);
        auth()->setUser($admin);

        $row = app(SalesReturnReportService::class)->normalizeNewRow($document);
        $this->assertFalse($row['can_edit']);
        $this->assertFalse($row['can_cancel']);
        $this->assertTrue($row['is_locked']);
        $this->assertStringContainsString('نهایی', $row['status_label']);

        $html = view('vouchers.return-from-sale.partials.index-results', ['returnRows' => collect([$row])])->render();
        $this->assertStringContainsString('نهایی شده؛ غیرقابل اصلاح', $html);
        $this->assertStringNotContainsString('data-void-url=', $html);
        $this->assertStringNotContainsString('href="'.route('vouchers.return-from-sale.applied.edit', $document).'"', $html);

        try {
            app(VoucherSalesReturnController::class)->editApplied($document);
            $this->fail('Admin opened locked editor.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_approved_inbound_remains_locked_when_all_items_are_received_into_returns_warehouse(): void
    {
        $document = $this->document(false);
        $receipt = \App\Models\WarehouseInboundReceipt::create([
            'receipt_number' => 'WI-RECLASS-1',
            'source_type' => 'sales_return',
            'source_id' => $document->id,
            'source_document_number' => $document->document_number,
            'operation_key' => 'initial',
            'status' => 'received',
            'expected_quantity' => 2,
            'accepted_quantity' => 2,
            'reviewed_by' => 2,
            'reviewed_at' => now(),
        ]);
        $receipt->items()->create([
            'source_item_type' => \App\Models\SalesReturnDocumentItem::class,
            'source_item_id' => $document->items->first()->id,
            'product_id' => 1,
            'product_variant_id' => 1,
            'suggested_warehouse_id' => 1,
            'received_warehouse_id' => 2,
            'expected_quantity' => 2,
            'accepted_quantity' => 2,
        ]);
        $this->assertTrue(\App\Support\SalesReturnFinalization::isLocked($document));
        $this->assertDenied(fn () => app(SalesReturnCorrectionService::class)->updateDirect(
            $document, $this->payload($document), 2, 'اصلاح پس از دریافت در مرجوعی', true
        ));
        $this->assertDenied(fn () => app(SalesReturnCorrectionService::class)->voidDirect(
            $document, 2, 'ابطال پس از دریافت', InboundReviewVersion::token($document), true
        ));
        $this->assertSame('applied', $document->fresh()->status);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_confirmed_central_void_endpoint_returns_forbidden_even_with_admin_permissions(): void
    {
        $document = $this->document();
        $admin = \Mockery::mock(User::class)->makePartial();
        $admin->id = 3;
        $admin->shouldReceive('isAdministrator')->andReturn(true);
        auth()->setUser($admin);

        try {
            app(VoucherSalesReturnController::class)->voidApplied(
                new \App\Http\Requests\VoidAppliedSalesReturnRequest(),
                $document
            );
            $this->fail('Admin voided a received central return.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('applied', $document->fresh()->status);
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_warehouse_discrepancy_confirmation_also_finalizes_a_return(): void
    {
        // A warehouse manager has completed inspection, but the received
        // quantity or destination differed from the original request.
        $document = $this->document(false);
        \App\Models\WarehouseInboundReceipt::create([
            'receipt_number' => 'LOCK-DISCREPANCY-1',
            'source_type' => 'sales_return',
            'source_id' => $document->id,
            'operation_key' => 'initial',
            'status' => 'discrepancy',
            'expected_quantity' => 2,
            'accepted_quantity' => 1,
            'reviewed_by' => 2,
            'reviewed_at' => now(),
        ]);

        $this->assertTrue(\App\Support\SalesReturnFinalization::isLocked($document));

        $this->assertDenied(fn () => app(SalesReturnCorrectionService::class)->updateDirect(
            $document, $this->payload($document), 2, 'اصلاح سند با مغایرت', true
        ));
        $this->assertDenied(fn () => app(SalesReturnCorrectionService::class)->voidDirect(
            $document, 2, 'ابطال سند با مغایرت', InboundReviewVersion::token($document), true
        ));

        $this->assertSame('applied', $document->fresh()->status);
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
    }

    public function test_unconfirmed_inbound_receipt_does_not_lock_return_only_document(): void
    {
        $document = $this->document(false);
        \App\Models\WarehouseInboundReceipt::create([
            'receipt_number' => 'LOCK-PENDING-1',
            'source_type' => 'sales_return',
            'source_id' => $document->id,
            'operation_key' => 'initial',
            'status' => 'pending',
            'expected_quantity' => 2,
            'accepted_quantity' => 0,
        ]);

        $this->assertFalse(\App\Support\SalesReturnFinalization::isLocked($document));
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_cancelled_inbound_receipt_does_not_lock_return_only_document(): void
    {
        $document = $this->document(false);
        \App\Models\WarehouseInboundReceipt::create([
            'receipt_number' => 'LOCK-CANCELLED-1',
            'source_type' => 'sales_return',
            'source_id' => $document->id,
            'operation_key' => 'initial',
            'status' => 'cancelled',
            'expected_quantity' => 2,
            'accepted_quantity' => 0,
            'cancelled_by' => 2,
            'cancelled_at' => now(),
        ]);

        $this->assertFalse(\App\Support\SalesReturnFinalization::isLocked($document));
        $this->assertSame('applied', $document->fresh()->status);
    }

    public function test_receipt_from_another_operation_does_not_lock_sales_return_with_same_numeric_id(): void
    {
        $document = $this->document(false);
        \App\Models\WarehouseInboundReceipt::create([
            'receipt_number' => 'LOCK-UNRELATED-1',
            'source_type' => 'invoice_cancel',
            'source_id' => $document->id,
            'operation_key' => 'initial',
            'status' => 'received',
            'expected_quantity' => 2,
            'accepted_quantity' => 2,
            'reviewed_by' => 2,
            'reviewed_at' => now(),
        ]);

        $this->assertFalse(\App\Support\SalesReturnFinalization::isLocked($document));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, CustomerLedger::count());
    }

    public function test_return_only_document_still_allows_direct_edit_without_creating_stock(): void
    {
        $document = $this->document(false);
        app(SalesReturnCorrectionService::class)->updateDirect(
            $document, $this->payload($document), 1, 'اصلاح مرجوعی'
        );
        $this->assertSame(1, (int) $document->fresh()->total_quantity);
        $this->assertSame(0, WarehouseStock::count());
        $this->assertSame(0, StockMovement::count());
    }


    /** A received CENTRAL line may change its monetary value, never its stock movement. */
    private function confirmCentralPriceOnlyReceipt(SalesReturnDocument $document, int $warehouseId = 1): void
    {
        $receipt = \App\Models\WarehouseInboundReceipt::create([
            'receipt_number' => 'WI-PRICE-'.$document->id,
            'source_type' => 'sales_return',
            'source_id' => $document->id,
            'operation_key' => 'initial',
            'status' => 'received',
            'expected_quantity' => 2,
            'accepted_quantity' => 2,
            'reviewed_by' => 2,
            'reviewed_at' => now(),
        ]);
        $receipt->items()->create([
            'source_item_type' => \App\Models\SalesReturnDocumentItem::class,
            'source_item_id' => $document->items->first()->id,
            'product_id' => 1,
            'product_variant_id' => 1,
            'received_warehouse_id' => $warehouseId,
            'expected_quantity' => 2,
            'accepted_quantity' => 2,
        ]);
    }

    public function test_confirmed_central_sazeh_return_price_can_be_corrected_without_stock_or_quantity_changes(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $version = InboundReviewVersion::token($document);
        $beforeMovement = StockMovement::first()->getAttributes();
        $beforeStock = WarehouseStock::first()->getAttributes();
        $itemId = $document->items->first()->id;

        app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
            $document, $itemId, 175, $version, 'اصلاح قیمت توافق شده', 2
        );

        $fresh = $document->fresh('items');
        $this->assertSame('applied', $fresh->status);
        $this->assertSame(2, (int) $fresh->total_quantity);
        $this->assertSame(350, (int) $fresh->total_refund_amount);
        $this->assertSame(175, (int) $fresh->items->first()->refund_unit_price);
        $this->assertSame(350, (int) $fresh->items->first()->refund_amount);
        $this->assertSame(2, (int) \App\Models\WarehouseInboundReceipt::first()->accepted_quantity);
        $this->assertSame($beforeStock, WarehouseStock::first()->getAttributes());
        $this->assertSame($beforeMovement, StockMovement::first()->getAttributes());
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(200, (int) CustomerLedger::where('reference_type', SalesReturnDocument::class)->sum('amount'));
        $this->assertSame(150, (int) CustomerLedger::where('reference_type', SalesReturnCorrection::class)->where('type', 'credit')->sum('amount'));
        $this->assertTrue(app(\App\Services\SalesReturnQueryService::class)->appliedHealthCheck($fresh)['checks']['ledger_amount_matches']);
        $this->assertSame(1, SalesReturnCorrection::where('status', 'applied')->count());
        $this->assertSame(1, \App\Models\SalesReturnDocumentRevision::where('action', 'received_price_corrected')->count());
    }

    public function test_duplicate_or_stale_price_correction_does_not_create_another_credit(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $version = InboundReviewVersion::token($document);
        $service = app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class);
        $service->correct($document, $document->items->first()->id, 175, $version, 'اصلاح قیمت', 2);

        try {
            $service->correct($document, $document->items->first()->id, 175, $version, 'تکرار درخواست', 2);
            $this->fail('A stale price correction was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document_version', $exception->errors());
        }
        $this->assertSame(2, CustomerLedger::count());
        $this->assertSame(1, SalesReturnCorrection::count());
        $this->assertSame(350, (int) $document->fresh()->total_refund_amount);
    }

    public function test_price_correction_rejects_receipt_that_did_not_enter_central_warehouse(): void
    {
        $document = $this->document(false);
        $this->confirmCentralPriceOnlyReceipt($document, 2);
        $service = app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class);

        try {
            $service->correct($document, $document->items->first()->id, 175, InboundReviewVersion::token($document), 'اصلاح قیمت', 2);
            $this->fail('Non-central received item accepted a central-only price correction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('item_id', $exception->errors());
        }
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
    }

    public function test_price_correction_rejects_mismatched_historical_ledger(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        CustomerLedger::where('reference_type', SalesReturnDocument::class)->update(['amount' => 180]);

        try {
            app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
                $document, $document->items->first()->id, 175, InboundReviewVersion::token($document), 'اصلاح قیمت', 2
            );
            $this->fail('Mismatched historical ledger was not rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(0, SalesReturnCorrection::count());
    }


    public function test_decreasing_a_received_central_return_price_creates_debit_not_stock_movement(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $movementBefore = StockMovement::first()->getAttributes();

        app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
            $document, $document->items->first()->id, 75,
            InboundReviewVersion::token($document), 'اصلاح قیمت برگشتی', 2
        );

        $fresh = $document->fresh('items');
        $this->assertSame(150, (int) $fresh->total_refund_amount);
        $this->assertSame(2, (int) $fresh->total_quantity);
        $this->assertSame(50, (int) CustomerLedger::where('reference_type', SalesReturnCorrection::class)
            ->where('type', 'debit')->sum('amount'));
        $this->assertSame($movementBefore, StockMovement::first()->getAttributes());
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertTrue(app(\App\Services\SalesReturnQueryService::class)->appliedHealthCheck($fresh)['checks']['ledger_amount_matches']);
    }

    public function test_internal_invoice_return_is_not_subject_to_sazeh_price_override(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $document->update(['source_type' => 'internal_invoice']);
        $this->assertSame([], app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)
            ->eligibleItemIds($document->fresh('items')));

        try {
            app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
                $document, $document->items->first()->id, 175,
                InboundReviewVersion::token($document->fresh('items')), 'اصلاح قیمت', 2
            );
            $this->fail('Internal invoice price override was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(1, CustomerLedger::count());
    }

    private function returnEditor(array $permissions): User
    {
        $user = \Mockery::mock(User::class)->makePartial();
        $user->id = 2;
        $user->shouldReceive('isAdministrator')->andReturn(false);
        $user->shouldReceive('hasRole')->andReturn(false);
        $user->shouldReceive('hasPermission')->andReturnUsing(
            fn ($permission) => in_array($permission, $permissions, true)
        );

        return $user;
    }

    public function test_existing_return_editors_can_correct_received_prices_without_a_separate_permission(): void
    {
        foreach ([['sales_returns.edit_applied'], ['page.sales.returns']] as $permissions) {
            $user = $this->returnEditor($permissions);
            $this->assertTrue(\App\Services\ReceivedSalesReturnPriceCorrectionService::allowed($user));
            $this->assertTrue(\App\Support\PageAccessCatalog::userCanRoute($user, 'vouchers.return-from-sale.applied.price'));
        }
        $this->assertFalse(\App\Services\ReceivedSalesReturnPriceCorrectionService::allowed(null));
        $this->assertFalse(\App\Services\ReceivedSalesReturnPriceCorrectionService::allowed(
            $this->returnEditor(['sales_returns.view'])
        ));
    }

    private function priceRequest(SalesReturnDocument $document, User $user, int $price, array $extra = []): \Illuminate\Http\Request
    {
        $session = app('session')->driver();
        $session->regenerateToken();
        $request = \Illuminate\Http\Request::create('/', 'PATCH', array_merge([
            '_token' => $session->token(),
            'item_id' => $document->items->first()->id,
            'refund_unit_price' => $price,
            'document_version' => InboundReviewVersion::token($document),
            'reason' => 'اصلاح قیمت توافق‌شده با مشتری',
        ], $extra));
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_return_editor_can_update_document_and_customer_balance_without_changing_received_stock(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $user = $this->returnEditor(['sales_returns.edit_applied']);
        $receiptsBefore = WarehouseInboundReceipt::first()->getAttributes();
        $receiptItemsBefore = \App\Models\WarehouseInboundReceiptItem::first()->getAttributes();
        $stockBefore = WarehouseStock::first()->getAttributes();
        $movementBefore = StockMovement::first()->getAttributes();
        $service = app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class);

        foreach ([175 => 350, 75 => 150] as $price => $total) {
            $document = $document->fresh('items');
            $response = app(VoucherSalesReturnController::class)->correctReceivedPrice(
                $this->priceRequest($document, $user, $price), $document, $service
            );
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame($total, (int) $document->fresh()->total_refund_amount);
            $this->assertSame(-$total, Customer::find(1)->balance);
            $this->assertSame(-$total, Customer::withBalance()->find(1)->balance);
            $this->assertTrue(app(\App\Services\SalesReturnQueryService::class)
                ->appliedHealthCheck($document->fresh('items'))['checks']['ledger_amount_matches']);
        }

        $this->assertSame($receiptsBefore, WarehouseInboundReceipt::first()->getAttributes());
        $this->assertSame($receiptItemsBefore, \App\Models\WarehouseInboundReceiptItem::first()->getAttributes());
        $this->assertSame($stockBefore, WarehouseStock::first()->getAttributes());
        $this->assertSame($movementBefore, StockMovement::first()->getAttributes());
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(2, (int) $document->fresh()->total_quantity);
        $this->assertSame(2, (int) $document->fresh('items')->items->first()->return_quantity);
        $this->assertSame(200, (int) CustomerLedger::where('reference_type', SalesReturnDocument::class)->sum('amount'));
        $this->assertSame(2, SalesReturnCorrection::count());
        $this->assertSame(2, \App\Models\SalesReturnDocumentRevision::where('action', 'received_price_corrected')->count());
    }

    public function test_price_endpoint_rejects_quantity_and_destination_changes_atomically(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $user = $this->returnEditor(['page.sales.returns']);
        try {
            app(VoucherSalesReturnController::class)->correctReceivedPrice(
                $this->priceRequest($document, $user, 175, ['return_quantity' => 99, 'destination_warehouse_id' => 2]),
                $document, app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)
            );
            $this->fail('A price-only request accepted warehouse fields.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('return_quantity', $exception->errors());
            $this->assertArrayHasKey('destination_warehouse_id', $exception->errors());
        }
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
    }

    public function test_view_only_user_cannot_submit_received_price_correction(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        try {
            app(VoucherSalesReturnController::class)->correctReceivedPrice(
                $this->priceRequest($document, $this->returnEditor(['sales_returns.view']), 175),
                $document, app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)
            );
            $this->fail('A view-only user changed a received price.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(1, CustomerLedger::count());
    }

    public function test_price_correction_remains_possible_after_the_received_stock_has_been_sold(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        WarehouseStock::query()->update(['quantity' => 0]);
        $itemBefore = $document->items->first()->getAttributes();

        app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
            $document, $document->items->first()->id, 175,
            InboundReviewVersion::token($document), 'اصلاح قیمت پس از فروش موجودی', 2
        );

        $this->assertSame(0, (int) WarehouseStock::first()->quantity);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(350, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(-350, Customer::find(1)->balance);
        $itemAfter = $document->fresh('items')->items->first()->getAttributes();
        foreach (['refund_unit_price', 'refund_amount', 'updated_at'] as $field) {
            unset($itemBefore[$field], $itemAfter[$field]);
        }
        $this->assertSame($itemBefore, $itemAfter);
    }

    public function test_price_form_is_available_to_existing_editors_and_hidden_from_view_only_users(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $controller = app(VoucherSalesReturnController::class);
        foreach ([['sales_returns.edit_applied'], ['sales_returns.view']] as $permissions) {
            auth()->setUser($this->returnEditor($permissions));
            $view = $controller->show(
                $document->fresh('items'), app(\App\Services\SalesDocumentAccessService::class),
                app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)
            );
            $this->assertSame(
                $permissions === ['sales_returns.edit_applied'] ? [$document->items->first()->id] : [],
                $view->getData()['correctablePriceItemIds']
            );
        }
    }

    public static function invalidPriceInputs(): array
    {
        return [
            'zero' => [0, 'اصلاح قیمت', 'refund_unit_price'],
            'negative' => [-1, 'اصلاح قیمت', 'refund_unit_price'],
            'over limit' => [1_000_000_000_001, 'اصلاح قیمت', 'refund_unit_price'],
            'unchanged' => [100, 'اصلاح قیمت', 'refund_unit_price'],
            'blank reason' => [175, '   ', 'reason'],
            'short reason' => [175, 'اب', 'reason'],
            'long reason' => [175, str_repeat('ا', 1001), 'reason'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPriceInputs')]
    public function test_invalid_price_or_reason_has_no_financial_effect(int $price, string $reason, string $field): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        try {
            app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
                $document, $document->items->first()->id, $price,
                InboundReviewVersion::token($document), $reason, 2
            );
            $this->fail('Invalid monetary correction was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(-200, Customer::find(1)->balance);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
        $this->assertSame(0, \App\Models\SalesReturnDocumentRevision::count());
    }

    public static function unconfirmedReceiptStates(): array
    {
        return [['pending'], ['cancelled']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unconfirmedReceiptStates')]
    public function test_price_correction_requires_confirmed_positive_central_receiving(string $status): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        WarehouseInboundReceipt::query()->update(['status' => $status]);
        $service = app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class);
        $this->assertSame([], $service->eligibleItemIds($document));
        try {
            $service->correct($document, $document->items->first()->id, 175,
                InboundReviewVersion::token($document), 'اصلاح قیمت', 2);
            $this->fail('An unconfirmed receipt allowed price correction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('item_id', $exception->errors());
        }
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
    }

    public function test_a_row_from_another_document_cannot_be_corrected(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $other = $document->replicate();
        $other->document_number = 'LOCK-OTHER';
        $other->save();
        $otherItem = $document->items->first()->replicate();
        $otherItem->document_id = $other->id;
        $otherItem->save();
        try {
            app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
                $document, $otherItem->id, 175, InboundReviewVersion::token($document), 'اصلاح قیمت', 2
            );
            $this->fail('A row belonging to another document was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('item_id', $exception->errors());
        }
        $this->assertSame(100, (int) $otherItem->fresh()->refund_unit_price);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
    }

    public function test_pending_financial_review_prevents_a_second_price_correction(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        SalesReturnCorrection::create([
            'document_id' => $document->id, 'base_version' => InboundReviewVersion::token($document),
            'payload' => [], 'before_snapshot' => [], 'proposed_snapshot' => [],
            'reason' => 'در انتظار بررسی', 'requested_by' => 2, 'status' => 'pending_finance',
        ]);
        try {
            app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
                $document, $document->items->first()->id, 175,
                InboundReviewVersion::token($document), 'اصلاح قیمت', 2
            );
            $this->fail('An outstanding correction was bypassed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(1, SalesReturnCorrection::count());
    }

    public function test_failure_while_recording_audit_rolls_back_all_monetary_changes(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $before = $document->getAttributes();
        $itemBefore = $document->items->first()->getAttributes();
        \App\Models\ActivityLog::creating(function () {
            throw new \RuntimeException('Simulated audit failure');
        });
        try {
            app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
                $document, $document->items->first()->id, 175,
                InboundReviewVersion::token($document), 'اصلاح قیمت', 2
            );
            $this->fail('Simulated audit failure did not interrupt correction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $document->fresh()->getAttributes());
        $this->assertSame($itemBefore, $document->fresh('items')->items->first()->getAttributes());
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
        $this->assertSame(0, \App\Models\SalesReturnDocumentRevision::count());
        $this->assertSame(-200, Customer::find(1)->balance);
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
    }

    public function test_real_http_route_updates_price_and_rejects_stale_resubmission(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $user = $this->returnEditor(['sales_returns.edit_applied']);
        $user->is_active = true;
        $user->can_access_erp = true;
        config(['services.request_driven_site_stock' => false]);
        $payload = [
            '_token' => 'price-test-token', 'item_id' => $document->items->first()->id,
            'refund_unit_price' => 175, 'document_version' => InboundReviewVersion::token($document),
            'reason' => 'اصلاح از مسیر واقعی نرم‌افزار',
        ];
        $this->actingAs($user)->withSession(['_token' => 'price-test-token'])
            ->patch(route('vouchers.return-from-sale.applied.price', $document), $payload)
            ->assertRedirect(route('vouchers.return-from-sale.show', $document));
        $this->assertSame(350, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(-350, Customer::find(1)->balance);
        $this->patchJson(route('vouchers.return-from-sale.applied.price', $document), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('document_version');
        $this->assertSame(2, CustomerLedger::count());
        $this->assertSame(1, SalesReturnCorrection::count());
        $this->assertSame(1, StockMovement::count());
    }

    public function test_real_http_route_rejects_missing_csrf_token_before_mutating_money(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $user = $this->returnEditor(['page.sales.returns']);
        $user->is_active = true;
        $user->can_access_erp = true;
        config(['services.request_driven_site_stock' => false]);
        $this->actingAs($user)->withSession(['_token' => 'valid-price-token'])
            ->patchJson(route('vouchers.return-from-sale.applied.price', $document), [
                'item_id' => $document->items->first()->id, 'refund_unit_price' => 175,
                'document_version' => InboundReviewVersion::token($document), 'reason' => 'اصلاح قیمت',
            ])->assertStatus(419);
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
    }

    public function test_multi_item_correction_changes_only_the_selected_rows_price(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $otherItem = $document->items->first()->replicate();
        $otherItem->return_quantity = 3;
        $otherItem->refund_amount = 300;
        $otherItem->sort_order = 2;
        $otherItem->save();
        $document->update(['total_quantity' => 5, 'items_count' => 2, 'total_refund_amount' => 500]);
        CustomerLedger::query()->update(['amount' => 500]);
        $otherBefore = $otherItem->fresh()->getAttributes();
        $document = $document->fresh('items');

        app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
            $document, $document->items->first()->id, 175,
            InboundReviewVersion::token($document), 'اصلاح قیمت فقط ردیف اول', 2
        );

        $this->assertSame($otherBefore, $otherItem->fresh()->getAttributes());
        $this->assertSame(650, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(5, (int) $document->fresh()->total_quantity);
        $this->assertSame(-650, Customer::find(1)->balance);
        $this->assertSame(150, (int) CustomerLedger::where('reference_type', SalesReturnCorrection::class)->sum('amount'));
    }

    public static function forbiddenDocumentStates(): array
    {
        return [['draft'], ['pending_warehouse'], ['cancelled']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenDocumentStates')]
    public function test_non_applied_documents_cannot_use_the_received_price_path(string $status): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $document->update(['status' => $status]);
        $service = app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class);
        $this->assertSame([], $service->eligibleItemIds($document));
        try {
            $service->correct($document, $document->items->first()->id, 175,
                InboundReviewVersion::token($document), 'اصلاح قیمت', 2);
            $this->fail('A non-applied document accepted a received-price change.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
    }

    public static function excessiveTotals(): array
    {
        return ['document amount limit' => [10_000], 'integer overflow' => [10_000_000]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('excessiveTotals')]
    public function test_amount_limits_do_not_partially_update_customer_or_document(int $quantity): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $document->items->first()->update(['return_quantity' => $quantity, 'refund_amount' => $quantity * 100]);
        $document->update(['total_quantity' => $quantity, 'total_refund_amount' => $quantity * 100]);
        CustomerLedger::query()->update(['amount' => $quantity * 100]);
        $document = $document->fresh('items');
        try {
            app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
                $document, $document->items->first()->id, 1_000_000_000_000,
                InboundReviewVersion::token($document), 'آزمون سقف مبلغ', 2
            );
            $this->fail('An excessive amount was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund_unit_price', $exception->errors());
        }
        $this->assertSame($quantity * 100, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(-$quantity * 100, Customer::find(1)->balance);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
    }

    public function test_inconsistent_line_price_is_rejected_without_rewriting_financial_history(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        $document->items->first()->update(['refund_unit_price' => 99]);
        $document = $document->fresh('items');
        try {
            app(\App\Services\ReceivedSalesReturnPriceCorrectionService::class)->correct(
                $document, $document->items->first()->id, 175,
                InboundReviewVersion::token($document), 'اصلاح قیمت', 2
            );
            $this->fail('An inconsistent historical line was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
    }

    public function test_real_http_route_denies_view_only_users_and_warehouse_payload_tampering(): void
    {
        $document = $this->document();
        $this->confirmCentralPriceOnlyReceipt($document);
        config(['services.request_driven_site_stock' => false]);
        $payload = [
            '_token' => 'valid-token', 'item_id' => $document->items->first()->id,
            'refund_unit_price' => 175, 'document_version' => InboundReviewVersion::token($document),
            'reason' => 'اصلاح قیمت',
        ];
        foreach ([['sales_returns.view'], ['page.sales.returns']] as $permissions) {
            $user = $this->returnEditor($permissions);
            $user->is_active = true;
            $user->can_access_erp = true;
            $request = $this->actingAs($user)->withSession(['_token' => 'valid-token']);
            $response = $request->patchJson(route('vouchers.return-from-sale.applied.price', $document),
                $payload + ['items' => [['return_quantity' => 999, 'destination_warehouse_id' => 2]]]);
            if ($permissions === ['sales_returns.view']) {
                $response->assertForbidden();
            } else {
                $response->assertUnprocessable()->assertJsonValidationErrors('items');
            }
        }
        $this->assertSame(200, (int) $document->fresh()->total_refund_amount);
        $this->assertSame(2, (int) $document->fresh()->total_quantity);
        $this->assertSame(2, (int) WarehouseStock::first()->quantity);
        $this->assertSame(1, CustomerLedger::count());
        $this->assertSame(0, SalesReturnCorrection::count());
    }
}
