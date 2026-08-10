<?php

namespace Tests\Feature;

use App\Exports\SalesReturnsExport;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Models\WarehouseTransferItem;
use App\Services\SalesReturnReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReturnsExportSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_returns_export_uses_one_summary_row_per_return_and_filters_customer_name(): void
    {
        $customer = Customer::query()->create(['first_name' => 'محمد', 'last_name' => 'رضایی', 'mobile' => '09120000001']);
        $other = Customer::query()->create(['first_name' => 'علی', 'last_name' => 'کریمی', 'mobile' => '09120000002']);
        $warehouse = Warehouse::query()->create(['name' => 'انبار مرجوعی', 'type' => 'return', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'دسته مرجوعی']);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'کالای تست', 'sku' => 'SRE-001', 'price' => 0]);

        $matching = WarehouseTransfer::query()->create([
            'voucher_type' => WarehouseTransfer::TYPE_CUSTOMER_RETURN,
            'customer_id' => $customer->id,
            'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $warehouse->id,
            'reference' => 'RET-100',
            'transferred_at' => '2026-07-01 10:00:00',
            'total_amount' => 3000,
        ]);
        WarehouseTransferItem::query()->create(['warehouse_transfer_id' => $matching->id, 'product_id' => $product->id, 'quantity' => 1, 'line_total' => 1000, 'return_kind' => 'healthy', 'destination_warehouse_id' => $warehouse->id]);
        WarehouseTransferItem::query()->create(['warehouse_transfer_id' => $matching->id, 'product_id' => $product->id, 'quantity' => 2, 'line_total' => 2000, 'return_kind' => 'damaged', 'destination_warehouse_id' => $warehouse->id]);

        WarehouseTransfer::query()->create([
            'voucher_type' => WarehouseTransfer::TYPE_CUSTOMER_RETURN,
            'customer_id' => $other->id,
            'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $warehouse->id,
            'reference' => 'RET-200',
            'transferred_at' => '2026-07-02 10:00:00',
            'total_amount' => 9000,
        ]);

        $rows = app(SalesReturnReportService::class)->getExcelRows(['customer_name' => 'محمد']);

        $this->assertCount(1, $rows);
        $this->assertSame('RET-100', $rows->first()['document_number']);
        $this->assertSame(3000, $rows->first()['total_amount']);
        $this->assertSame('انبار مرجوعی', $rows->first()['destination_warehouse_name']);
        $this->assertSame('سالم و مرجوعی', $rows->first()['condition_label']);
        $this->assertSame(1000, $rows->first()['healthy_amount']);
        $this->assertSame(2000, $rows->first()['damaged_amount']);

        $mixedRows = app(SalesReturnReportService::class)->getExcelRows([])->filter(fn ($row) => $row['healthy_amount'] > 0 && $row['damaged_amount'] > 0);
        $this->assertCount(1, $mixedRows);
        $this->assertSame('RET-100', $mixedRows->first()['document_number']);
    }
}
