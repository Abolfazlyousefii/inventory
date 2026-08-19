<?php

namespace Tests\Feature;

use App\Models\SalesReturnDocument;
use Tests\TestCase;

class WarehouseInboundQueueArchitectureTest extends TestCase
{
    public function test_queue_has_schema_models_routes_page_permission_and_ui(): void
    {
        $this->assertFileExists(database_path('migrations/2026_08_19_000001_create_warehouse_inbound_queue_tables.php'));
        $this->assertFileExists(app_path('Models/WarehouseInboundReceipt.php'));
        $this->assertFileExists(app_path('Models/WarehouseInboundReceiptItem.php'));
        $this->assertFileExists(app_path('Services/WarehouseInboundService.php'));
        $this->assertFileExists(app_path('Http/Controllers/WarehouseInboundController.php'));
        $this->assertFileExists(resource_path('views/warehouse/inbound-queue/index.blade.php'));

        $routes = file_get_contents(base_path('routes/web.php'));
        $pages = file_get_contents(app_path('Support/PageAccessCatalog.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/sidebar.blade.php'));

        $this->assertStringContainsString("Route::prefix('warehouse/inbound-queue')->name('warehouse.inbound.')", $routes);
        $this->assertStringContainsString("'warehouse.inbound.index' => ['warehouse.inbound_queue']", $pages);
        $this->assertStringContainsString("'warehouse.inbound_queue' => ['انبارداری', 'صف ورودی موجودی', []]", $pages);
        $this->assertStringContainsString("page.warehouse.inbound_queue", $sidebar);
    }

    public function test_sales_return_apply_queues_without_direct_stock_ledger_or_commission_finalization(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $start = strpos($service, 'public function apply(');
        $end = strpos($service, 'public function prepareInternalItems', $start);
        $apply = substr($service, $start, $end - $start);

        $this->assertStringContainsString('SalesReturnDocument::STATUS_PENDING_WAREHOUSE', $apply);
        $this->assertStringContainsString('queueSalesReturn', $apply);
        $this->assertStringNotContainsString('recordInventoryEntry(', $apply);
        $this->assertStringNotContainsString('recordCustomerCredit(', $apply);
        $this->assertStringNotContainsString('reconcileReturn(', $apply);
    }

    public function test_pending_sales_return_reserves_returnable_quantity_until_warehouse_decides(): void
    {
        $calculation = file_get_contents(app_path('Services/SalesReturnCalculationService.php'));

        $this->assertStringContainsString('SalesReturnDocument::STATUS_PENDING_WAREHOUSE', $calculation);
        $this->assertSame('در انتظار دریافت انبار', SalesReturnDocument::statusLabels()[SalesReturnDocument::STATUS_PENDING_WAREHOUSE]);
    }

    public function test_invoice_cancel_queues_stock_instead_of_restoring_it_immediately(): void
    {
        $service = file_get_contents(app_path('Services/SalesHavalehService.php'));
        $start = strpos($service, 'public function cancelAndRestore');
        $end = strpos($service, 'private function hasCancellationStockReturn', $start);
        $cancel = substr($service, $start, $end - $start);

        $this->assertStringContainsString('queueInvoiceCancellation', $cancel);
        $this->assertStringContainsString('STATUS_PENDING_WAREHOUSE', $cancel);
        $this->assertStringNotContainsString('adjustSaleItemStock(', $cancel);
    }

    public function test_finance_positive_stock_returns_go_to_one_queue_receipt(): void
    {
        $service = file_get_contents(app_path('Services/SalesHavalehService.php'));
        $start = strpos($service, 'public function updateInvoiceByFinance');
        $end = strpos($service, 'private function invoiceSnapshotPayload', $start);
        $finance = substr($service, $start, $end - $start);

        $this->assertStringContainsString('$pendingInboundLines = []', $finance);
        $this->assertStringContainsString('queueInvoiceAdjustment', $finance);
        $this->assertStringContainsString("'variant_changed'", $finance);
        $this->assertStringContainsString("'quantity_decreased'", $finance);
        $this->assertStringNotContainsString('StockMovement::REASON_RETURN', $finance);
        $this->assertStringContainsString('StockMovement::REASON_SALE', $finance);
    }

    public function test_receive_allows_over_expected_and_creates_stock_only_on_confirmation(): void
    {
        $service = file_get_contents(app_path('Services/WarehouseInboundService.php'));

        $this->assertStringNotContainsString('$accepted > (int) $item->expected_quantity', $service);
        $this->assertStringContainsString('if ($accepted < 0)', $service);
        $this->assertStringContainsString('WarehouseStockService::change(', $service);
        $this->assertStringContainsString("'type' => StockMovement::TYPE_IN", $service);
        $this->assertStringContainsString("STATUS_DISCREPANCY", $service);
        $this->assertStringContainsString('برای ثبت دریافت با مغایرت', $service);
        $this->assertStringContainsString('finalizeSalesReturnFromReceipt', $service);
    }

    public function test_invoice_cancel_undo_cancels_pending_queue_but_blocks_after_physical_receive(): void
    {
        $service = file_get_contents(app_path('Services/WarehouseInboundService.php'));

        $this->assertStringContainsString('prepareInvoiceCancellationUndo', $service);
        $this->assertStringContainsString('کالای این فاکتور قبلاً توسط انبار دریافت شده است', $service);
        $this->assertStringContainsString("'status' => WarehouseInboundReceipt::STATUS_CANCELLED", $service);
    }

    public function test_purchase_flow_is_not_routed_to_warehouse_inbound_queue(): void
    {
        $purchaseController = file_get_contents(app_path('Http/Controllers/PurchaseController.php'));
        $this->assertStringNotContainsString('WarehouseInboundService', $purchaseController);
        $this->assertStringNotContainsString('queueFinanceAdjustment', $purchaseController);
        $this->assertStringNotContainsString('queueInvoiceCancellation', $purchaseController);
        $this->assertStringNotContainsString('queueSalesReturn', $purchaseController);
    }

    public function test_collection_reductions_are_queued_while_increases_still_consume_stock(): void
    {
        $service = file_get_contents(app_path('Services/WarehouseCollectionService.php'));

        $this->assertStringContainsString('inboundLinesForReducedItems', $service);
        $this->assertStringContainsString('queueInvoiceAdjustment', $service);
        $this->assertStringContainsString('if ($delta <= 0) { continue; }', $service);
        $this->assertStringContainsString('if ($delta > 0)', $service);
    }

    public function test_database_and_service_have_operation_level_idempotency(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_20_000001_harden_warehouse_inbound_queue.php'));
        $service = file_get_contents(app_path('Services/WarehouseInboundService.php'));

        $this->assertStringContainsString('wir_source_operation_unique', $migration);
        $this->assertStringContainsString("->where('operation_key', \$operationKey)", $service);
        $this->assertStringContainsString('lockForUpdate()', $service);
        $this->assertStringContainsString('wiri_stock_movement_unique', $migration);
    }
}
