<?php

namespace Tests\Feature;

use Tests\TestCase;

class WarehouseCollectionItemAdjustmentTest extends TestCase
{
    public function test_collection_edit_browser_contract_allows_existing_item_deletion(): void
    {
        $blade = file_get_contents(base_path('resources/views/vouchers/sales/edit.blade.php'));

        $this->assertStringContainsString('route(\'vouchers.sales.collection.update\'', $blade);
        $this->assertStringContainsString('@method(\'PATCH\')', $blade);
        $this->assertStringContainsString('name="items[{{ $loop->index }}][_delete]"', $blade);
        $this->assertStringContainsString('class="js-delete-flag"', $blade);
        $this->assertStringContainsString('<input type="number" min="0" name="items[{{ $loop->index }}][quantity]"', $blade);
        $this->assertStringContainsString('q.value=0', $blade);
        $this->assertStringContainsString('flag.value=1', $blade);
        $this->assertStringContainsString('row.remove()', $blade);
    }

    public function test_controller_validates_delete_flag_and_allowed_reasons(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/InvoiceController.php'));

        $this->assertStringContainsString('items.*._delete', $controller);
        $this->assertStringContainsString("Rule::in(['physical_shortage', 'customer_cancelled', 'wrong_item', 'warehouse_correction', 'replacement', 'other'])", $controller);
        $this->assertStringContainsString('required_if:change_reason,other', $controller);
        $this->assertStringContainsString('salesVoucherUpdate(string $uuid, Request $request)', $controller);
        $this->assertStringContainsString('updateCollectedItems($invoice', $controller);
    }

    public function test_service_uses_atomic_delta_stock_line_totals_snapshots_and_document_totals(): void
    {
        $service = file_get_contents(base_path('app/Services/WarehouseCollectionService.php'));

        $this->assertStringContainsString('lockForUpdate()->firstOrFail()', $service);
        $this->assertStringContainsString('InvoiceItem::query()->with([\'product\', \'variant\'])->where(\'invoice_id\'', $service);
        $this->assertStringContainsString('$deleteRequested = filter_var', $service);
        $this->assertStringContainsString('$newByVariant', $service);
        $this->assertStringContainsString('$delta = (int) ($newByVariant[$variantId] ?? 0) - (int) ($oldByVariant[$variantId] ?? 0)', $service);
        $this->assertStringContainsString('WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), (int) $variant->product_id, -$delta', $service);
        $this->assertStringContainsString("'line_total' => max", $service);
        $this->assertStringContainsString('SalesDocumentTotals::calculate($invoice->items, $documentDiscount, (int) $invoice->shipping_price', $service);
        $this->assertStringContainsString("'invoice_item_id' => ", $service);
        $this->assertStringContainsString('فاکتور باید حداقل یک قلم کالا داشته باشد.', $service);
        $this->assertStringContainsString('مبلغ جدید فاکتور کمتر از مبلغ پرداخت‌شده است.', $service);
    }

    public function test_revision_schema_already_supports_removed_item_null_on_delete(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_07_11_000001_create_invoice_collection_revision_tables.php'));

        $this->assertStringContainsString("foreignId('invoice_item_id')->nullable()->constrained('invoice_items')->nullOnDelete()", $migration);
    }
}
