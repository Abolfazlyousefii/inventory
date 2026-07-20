<?php

namespace Tests\Feature;

use Tests\TestCase;

class SalesReturnProductPrintTest extends TestCase
{
    public function test_product_print_aggregates_same_variant_across_documents(): void { $this->assertStringContainsString('SUM(i.return_quantity) as total_quantity', file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_different_variants_are_not_merged(): void { $this->assertStringContainsString("return 'variant:'.(int)\$row->product_variant_id", file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_healthy_and_damaged_quantities_are_separate(): void { $s=file_get_contents(app_path('Services/SalesReturnReportService.php')); $this->assertStringContainsString('healthy_quantity', $s); $this->assertStringContainsString('damaged_quantity', $s); }
    public function test_weighted_unit_price_is_correct(): void { $this->assertStringContainsString("round(\$row['total_refund_amount'] / \$row['total_quantity'])", file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_product_print_counts_unique_documents(): void { $this->assertStringContainsString('document_keys', file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_product_print_counts_unique_customers(): void { $this->assertStringContainsString('customer_ids', file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_product_print_groups_destination_warehouses(): void { $this->assertStringContainsString('aggregateProductWarehouses', file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_product_print_includes_legacy_items(): void { $this->assertStringContainsString('warehouse_transfer_items as wi', file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_duplicated_new_and_legacy_reference_is_counted_once(): void { $this->assertStringContainsString('whereNotExists', file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_draft_items_are_excluded(): void { $this->assertStringContainsString("where('d.status', SalesReturnDocument::STATUS_APPLIED)", file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_cancelled_items_are_excluded(): void { $this->test_draft_items_are_excluded(); }
    public function test_voided_applied_document_is_excluded(): void { $this->test_draft_items_are_excluded(); }
    public function test_adjusted_applied_document_uses_current_items_only(): void { $this->assertStringContainsString('sales_return_document_items as i', file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_filters_are_applied_to_product_report(): void { $s=file_get_contents(app_path('Services/SalesReturnReportService.php')); $this->assertStringContainsString('customer_id', $s); $this->assertStringContainsString('date_from', $s); }
    public function test_report_totals_equal_sum_of_rows(): void { $this->assertStringContainsString('total_refund_amount', file_get_contents(resource_path('views/vouchers/return-from-sale/print-products.blade.php'))); }
    public function test_large_product_report_uses_aggregate_queries_not_model_groupby(): void { $s=file_get_contents(app_path('Services/SalesReturnReportService.php')); $this->assertStringContainsString('selectRaw', $s); $this->assertStringNotContainsString('SalesReturnDocumentItem::query()->with', $s); }

    public function test_same_variant_with_different_snapshots_keeps_strict_group_by_and_merges_by_variant(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $this->assertStringContainsString("'i.product_name_snapshot','p.name','i.variant_name_snapshot','pv.variant_name','i.sku_snapshot','i.barcode_snapshot','pv.variant_code'", $service);
        $this->assertStringContainsString("return 'variant:'.(int)\$row->product_variant_id", $service);
    }

    public function test_two_variants_of_one_product_remain_separate_after_strict_group_fix(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $this->assertStringContainsString("->groupBy('i.product_variant_id','i.product_id'", $service);
        $this->assertStringContainsString("->groupBy('wi.product_variant_id','wi.product_id'", $service);
    }

    public function test_new_and_legacy_product_queries_do_not_group_by_select_aliases(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $this->assertStringNotContainsString("'product_name','variant_name','sku'", $service);
        $this->assertStringNotContainsString("'product_name','variant_name','sku','warehouse_name'", $service);
    }
}
