<?php

namespace Tests\Feature;

use App\Models\SalesReturnDocument;
use App\Services\SalesReturnReportService;
use Tests\TestCase;

class SalesReturnFirstPatchTest extends TestCase
{
    public function test_index_customer_filter_uses_remote_picker(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/index.blade.php'));
        $partial = file_get_contents(resource_path('views/vouchers/return-from-sale/partials/customer-filter-picker.blade.php'));

        $this->assertStringNotContainsString('type="number" class="form-control form-control-sm" name="customer_id"', $blade);
        $this->assertStringContainsString('data-customer-search', $partial);
        $this->assertStringContainsString('type="hidden" name="customer_id"', $partial);
        $this->assertStringContainsString("route('vouchers.return-from-sale.customers.search')", $partial);
    }

    public function test_index_shows_drafts_with_edit_and_cancel_actions(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/index.blade.php'));

        $this->assertStringContainsString('getIndexRows', $service);
        $this->assertStringContainsString("'can_edit' => \$document->isDraft()", $service);
        $this->assertStringContainsString("'can_cancel' => \$document->isDraft()", $service);
        $this->assertStringContainsString('حذف پیش‌نویس', $blade);
    }

    public function test_applied_document_does_not_show_draft_edit_action(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $this->assertStringContainsString("'edit_url' => \$document->isDraft() ?", $service);
        $this->assertStringContainsString("'print_url' => route('vouchers.return-from-sale.print', \$document)", $service);
    }

    public function test_legacy_document_is_read_only(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $this->assertStringContainsString("'status_label' => 'قدیمی'", $service);
        $this->assertStringContainsString("'can_edit' => false", $service);
        $this->assertStringContainsString("'can_cancel' => false", $service);
    }

    public function test_healthy_return_reason_is_valid(): void
    {
        $this->assertSame('برگشت سالم / بدون ایراد', SalesReturnDocument::returnReasonLabels()['healthy_return']);
    }

    public function test_each_item_can_have_its_own_destination(): void
    {
        $request = file_get_contents(app_path('Http/Requests/StoreSalesReturnRequest.php'));
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $this->assertStringContainsString("'items.*.destination_warehouse_id' => ['required'", $request);
        $this->assertStringContainsString('rowDestinationWarehouse', $service);
        $this->assertStringContainsString('rowCondition', $service);
    }

    public function test_apply_posts_stock_to_each_item_destination(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $this->assertStringContainsString('(int)($item->destination_warehouse_id ?: $item->document?->default_destination_warehouse_id)', $service);
        $this->assertStringContainsString("'warehouse_id'=>\$destinationWarehouseId", $service);
    }

    public function test_draft_does_not_change_stock_or_ledger(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $persistStart = strpos($service, 'private function persistDraft');
        $applyStart = strpos($service, 'public function apply');
        $persist = substr($service, $persistStart, $applyStart - $persistStart);
        $this->assertStringNotContainsString('recordInventoryEntry', $persist);
        $this->assertStringNotContainsString('recordCustomerCredit', $persist);
    }

    public function test_editing_draft_preserves_row_destinations(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));
        $this->assertStringContainsString("'item_condition' => \$item->item_condition", $blade);
        $this->assertStringContainsString("'destination_warehouse_id' => \$item->destination_warehouse_id", $blade);
    }

    public function test_unit_price_request_accepts_clean_integer(): void
    {
        $request = file_get_contents(app_path('Http/Requests/StoreSalesReturnRequest.php'));
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));
        $this->assertStringContainsString('preg_replace', $request);
        $this->assertStringContainsString('type="text" inputmode="numeric"', $blade);
        $this->assertStringContainsString('updateRowAmount', $blade);
    }

    public function test_cancel_draft_has_no_financial_or_inventory_effect(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $this->assertStringContainsString('public function cancelDraft', $service);
        $this->assertStringContainsString("'status'=>SalesReturnDocument::STATUS_CANCELLED", $service);
    }
}
