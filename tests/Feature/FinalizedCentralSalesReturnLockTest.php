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
}
