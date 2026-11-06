<?php

namespace Tests\Feature;

use App\Http\Controllers\VoucherSalesReturnController;
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

class FinalizedCentralSalesReturnLockTest extends PendingSalesReturnEditTest
{
    protected function setUp(): void
    {
        parent::setUp();
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

        try {
            app(VoucherSalesReturnController::class)->editApplied($document);
            $this->fail('Admin opened locked editor.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
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
